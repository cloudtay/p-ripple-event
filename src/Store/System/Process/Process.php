<?php

declare(strict_types=1);
/*
 * Copyright (c) 2024.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * 特此免费授予任何获得本软件及相关文档文件（“软件”）副本的人，不受限制地处理
 * 本软件，包括但不限于使用、复制、修改、合并、出版、发行、再许可和/或销售
 * 软件副本的权利，并允许向其提供本软件的人做出上述行为，但须符合以下条件：
 *
 * 上述版权声明和本许可声明应包含在本软件的所有副本或主要部分中。
 *
 * 本软件按“原样”提供，不提供任何形式的保证，无论是明示或暗示的，
 * 包括但不限于适销性、特定目的的适用性和非侵权性的保证。在任何情况下，
 * 无论是合同诉讼、侵权行为还是其他方面，作者或版权持有人均不对
 * 由于软件或软件的使用或其他交易而引起的任何索赔、损害或其他责任承担责任。
 */

namespace Psc\Store\System\Process;

use Closure;
use JetBrains\PhpStorm\NoReturn;
use Psc\Core\StoreAbstract;
use Psc\Store\System\Exception\ProcessException;
use Revolt\EventLoop;

use function call_user_func;
use function P\onSignal;
use function P\promise;
use function P\run;
use function pcntl_fork;
use function pcntl_wait;
use function pcntl_wexitstatus;
use function pcntl_wifexited;

use const SIGCHLD;
use const SIGINT;
use const SIGQUIT;
use const SIGTERM;
use const WNOHANG;
use const WUNTRACED;

/**
 *
 */
class Process extends StoreAbstract
{
    /**
     * @throws EventLoop\UnsupportedFeatureException
     */
    public function __construct()
    {
        onSignal(SIGCHLD, function () {
            $this->signalSIGCHLDHandler();
        });

        onSignal(SIGTERM, function () {
            $this->onQuitSignal(SIGTERM);
        });

        onSignal(SIGINT, function () {
            $this->onQuitSignal(SIGINT);
        });

        onSignal(SIGQUIT, function () {
            $this->onQuitSignal(SIGQUIT);
        });
    }

    /**
     * @var StoreAbstract
     */
    protected static StoreAbstract $instance;

    /**
     * @var array
     */
    private array $process2promiseCallback = [];

    /**
     * @var Runtime[]
     */
    private array $process2runtime = [];

    /**
     * @param Closure $closure
     * @return Task
     */
    public function task(Closure $closure): Task
    {
        return new Task(function (...$args) use ($closure) {
            $processId = pcntl_fork();

            if ($processId === 0) {
                if (EventLoop::getDriver()->isRunning()) {
                    EventLoop::defer(fn () => EventLoop::setDriver((new EventLoop\DriverFactory())->create()));
                } else {
                    EventLoop::setDriver((new EventLoop\DriverFactory())->create());
                }
                call_user_func($closure, ...$args);
                run();
            }

            $promise = promise(function ($r, $d) use ($processId) {
                $this->process2promiseCallback[$processId] = [
                    'resolve' => $r,
                    'reject'  => $d,
                ];
            });

            $runtime = new Runtime(
                $promise,
                $processId,
            );

            $this->process2runtime[$processId] = $runtime;

            return $runtime;
        });
    }

    /**
     * @return void
     */
    public function signalSIGCHLDHandler(): void
    {
        while ($childrenId = pcntl_wait(
            $status,
            WNOHANG | WUNTRACED
        )) {
            $exit            = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1;
            $promiseCallback = $this->process2promiseCallback[$childrenId] ?? null;

            if (!$promiseCallback) {
                return;
            }

            if ($exit === -1) {
                call_user_func($promiseCallback['reject'], new ProcessException('The process is abnormal.', $exit));
            } else {
                call_user_func($promiseCallback['resolve'], $exit);
            }

            unset($this->process2promiseCallback[$childrenId]);
            unset($this->process2runtime[$childrenId]);
        }
    }

    /**
     * @param $signal
     * @return void
     */
    #[NoReturn] public function onQuitSignal($signal): void
    {
        $this->destroy($signal);
        exit;
    }

    /**
     * @param int $signal
     * @return void
     */
    private function destroy(int $signal = SIGTERM): void
    {
        foreach ($this->process2runtime as $runtime) {
            $runtime->signal($signal);
        }
    }
}
