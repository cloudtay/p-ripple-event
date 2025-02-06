<?php declare(strict_types=1);
/**
 * Copyright © 2024 cclilshy
 * Email: jingnigg@gmail.com
 *
 * This software is licensed under the MIT License.
 * For full license details, please visit: https://opensource.org/licenses/MIT
 *
 * By using this software, you agree to the terms of the license.
 * Contributions, suggestions, and feedback are always welcome!
 */

namespace Ripple\Process;

use Closure;
use Fiber;
use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;
use Ripple\Coroutine\Exception\EscapeException;
use Ripple\Coroutine\Suspension;
use Ripple\Kernel;
use Ripple\Process\Exception\ProcessException;
use Ripple\Support;
use Ripple\Utils\Format;
use Ripple\Utils\Output;
use Throwable;

use function call_user_func;
use function call_user_func_array;
use function Co\cancel;
use function Co\getSuspension;
use function Co\promise;
use function Co\wait;
use function getmypid;
use function pcntl_fork;
use function pcntl_wait;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function pcntl_wifsignaled;
use function pcntl_wifstopped;
use function pcntl_wstopsig;
use function pcntl_wtermsig;
use function posix_getpid;

use const SIGCHLD;
use const WNOHANG;
use const WUNTRACED;
use const SIGTERM;

/**
 * @compatible:Windows
 * 2024/08/30 Adjustments made to Windows support
 *
 * @Author    cclilshy
 * @Date      2024/8/16 09:36
 */
class Process extends Support
{
    /*** @var Support */
    protected static Support $instance;

    /*** @var array */
    private array $process2promiseCallback = array();

    /*** @var Runtime[] */
    private array $process2runtime = array();

    /*** @var array */
    private array $onFork = array();

    /*** @var int */
    private int $rootProcessID;

    /*** @var int */
    private int $processID;

    /*** @var string */
    private string $signalHandlerEventID;

    /*** @var int */
    private int $index = 0;

    public function __construct()
    {
        /*** @compatible:Windows */
        if (!Kernel::getInstance()->supportProcessControl()) {
            $this->rootProcessID = getmypid();
            $this->processID     = getmypid();
            return;
        }

        $this->rootProcessID = posix_getpid();
        $this->processID     = posix_getpid();
    }

    /**
     *
     */
    public function __destruct()
    {
        $this->destroy();
    }

    /**
     * @return void
     */
    private function destroy(): void
    {
        foreach ($this->process2runtime as $runtime) {
            $runtime->signal(SIGTERM);
        }
    }

    /**
     * @param Closure $closure
     *
     * @return string
     */
    public function forked(Closure $closure): string
    {
        $this->onFork[$key = Format::int2string($this->index++)] = $closure;
        return $key;
    }

    /**
     * @param string $index
     *
     * @return void
     */
    public function cancelForked(string $index): void
    {
        unset($this->onFork[$index]);
    }

    /**
     * @param Closure $closure
     *
     * @return Task|false
     */
    public function task(Closure $closure): Task|false
    {
        return new Task(function (...$args) use ($closure) {
            /**
             * @compatible:Windows
             *
             * Windows allows the use of the Process module to simulate a Runtime
             * But Runtime is not a real child process
             *
             * Due to the life cycle of __destruct and Fiber
             * Once the Runtime under Windows is destroyed, it will cause the entire process to exit and no promise callback will be triggered.
             */
            if (!Kernel::getInstance()->supportProcessControl()) {
                call_user_func($closure, ...$args);
                return new Runtime(promise(static function () {
                }), getmypid());
            }

            $processID = pcntl_fork();

            if ($processID === -1) {
                Output::warning('Fork failed.');
                return false;
            }

            if ($processID === 0) {
                $this->processedInMain(function () use ($closure, $args) {
                    $this->forgetEvents();
                    call_user_func_array($closure, $args);
                });
            }

            if (empty($this->process2runtime)) {
                $this->registerSignalHandler();
            }

            $promise = promise(function (Closure $resolve, Closure $reject) use ($processID) {
                $this->process2promiseCallback[$processID] = array(
                    'resolve' => $resolve,
                    'reject'  => $reject,
                );
            });

            $runtime = new Runtime(
                $promise,
                $processID,
            );

            $this->process2runtime[$processID] = $runtime;
            return $runtime;
        });
    }

    /**
     * @param Closure $closure
     *
     * @return void
     */
    public function processedInMain(Closure $closure): void
    {
        $suspension = getSuspension();
        if ($suspension instanceof Suspension) {
            throw new EscapeException($closure);
        } else {
            // this is main
            if (!Fiber::getCurrent()) {
                $closure();
                wait();
                exit(0);
            }

            // in fiber
            wait($closure);
            exit(0);
        }
    }

    /**
     * @return void
     */
    public function forgetEvents(): void
    {
        foreach (EventLoop::getIDentifiers() as $identifier) {
            @cancel($identifier);
        }
        EventLoop::run();
        EventLoop::setDriver((new EventLoop\DriverFactory())->create());
        $this->distributeForked();
    }

    /**
     * @return void
     */
    public function distributeForked(): void
    {
        if (!empty($this->process2runtime)) {
            $this->unregisterSignalHandler();
        }

        foreach ($this->onFork as $key => $closure) {
            try {
                unset($this->onFork[$key]);
                $closure();
            } catch (Throwable $exception) {
                Output::error($exception->getMessage());
            }
        }

        $this->process2promiseCallback = array();
        $this->process2runtime         = array();
        $this->processID               = Kernel::getInstance()->supportProcessControl() ? posix_getpid() : getmypid();
    }

    /**
     * @return void
     */
    private function unregisterSignalHandler(): void
    {
        cancel($this->signalHandlerEventID);
    }

    /**
     * @return void
     * @throws UnsupportedFeatureException
     */
    private function registerSignalHandler(): void
    {
        /*** @compatible:Windows */
        if (!Kernel::getInstance()->supportProcessControl()) {
            return;
        }

        $this->signalHandlerEventID = $this->onSignal(SIGCHLD, fn () => $this->signalSIGCHLDHandler());
    }

    /**
     * @param int     $signalCode
     * @param Closure $handler
     *
     * @return string
     * @throws UnsupportedFeatureException
     */
    public function onSignal(int $signalCode, Closure $handler): string
    {
        return EventLoop::onSignal($signalCode, $handler);
    }

    /**
     * @return void
     */
    private function signalSIGCHLDHandler(): void
    {
        while (1) {
            $childrenID = pcntl_wait($status, WNOHANG | WUNTRACED);

            if ($childrenID <= 0) {
                break;
            }

            $this->onProcessExit($childrenID, $status);
        }
    }

    /**
     * @param int $processID
     * @param int $status
     *
     * @return void
     */
    private function onProcessExit(int $processID, int $status): void
    {
        $exitCode   = -1;
        $exitReason = '';

        if (pcntl_wifexited($status)) {
            $exitCode   = pcntl_wexitstatus($status);
            $exitReason = 'normal exit';
        } elseif (pcntl_wifsignaled($status)) {
            $exitCode   = pcntl_wtermsig($status);
            $exitReason = 'terminated by signal';
        } elseif (pcntl_wifstopped($status)) {
            $exitCode   = pcntl_wstopsig($status);
            $exitReason = 'stopped by signal';
        }

        $promiseCallback = $this->process2promiseCallback[$processID] ?? null;
        if (!$promiseCallback) {
            return;
        }

        if ($exitCode !== 0) {
            call_user_func(
                $promiseCallback['reject'],
                new ProcessException("Process failed: {$exitReason}", $exitCode)
            );
        } else {
            call_user_func($promiseCallback['resolve'], $exitCode);
        }

        // Clean up resources
        unset($this->process2promiseCallback[$processID]);
        unset($this->process2runtime[$processID]);

        if (empty($this->process2runtime)) {
            $this->unregisterSignalHandler();
        }
    }

    /**
     * @return int
     */
    public function getRootProcessID(): int
    {
        return $this->rootProcessID;
    }

    /**
     * @return int
     */
    public function getProcessID(): int
    {
        return $this->processID;
    }
}
