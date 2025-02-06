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

namespace Ripple;

use Closure;
use Fiber;
use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;
use Ripple\Coroutine\Coroutine;
use Ripple\Coroutine\Suspension;
use Ripple\Process\Process;
use Throwable;

use function call_user_func;
use function Co\async;
use function Co\getSuspension;
use function Co\wait;
use function define;
use function defined;
use function extension_loaded;
use function file_exists;
use function file_get_contents;
use function fopen;
use function ini_set;
use function intval;
use function preg_match;
use function shell_exec;

use const PHP_OS_FAMILY;

/**
 * @Author cclilshy
 * @Date   2024/8/29 23:28
 */
class Kernel
{
    /*** @var Kernel */
    public static Kernel $instance;

    /*** @var EventLoop\Suspension */
    private EventLoop\Suspension $mainSuspension;

    /*** @var bool */
    private bool $parallel;

    /*** @var bool */
    private bool $processControl;

    /*** @var bool */
    private bool $mainRunning = true;

    /*** @var int */
    private int $memorySize;

    public function __construct()
    {
        ini_set('memory_limit', -1);
        ini_set('max_execution_time', 0);

        if (!defined('STDIN')) {
            define('STDIN', fopen('php://stdin', 'r'));
        }
        if (!defined('STDOUT')) {
            define('STDOUT', fopen('php://stdout', 'w'));
        }

        $this->parallel       = extension_loaded('parallel');
        $this->processControl = extension_loaded('pcntl') && extension_loaded('posix');
    }

    /**
     * This method is different from onReject, which allows accepting any type of rejected futures object.
     * When await promise is rejected, an error will be thrown instead of returning the rejected value.
     *
     * If the rejected value is a non-Error object, it will be wrapped into a `PromiseRejectException` object,
     * The `getReason` method of this object can obtain the rejected value
     *
     * @param Promise $promise
     *
     * @return mixed
     * @throws Throwable
     */
    public function await(Promise $promise): mixed
    {
        return Coroutine::getInstance()->await($promise);
    }

    /**
     * @return Kernel
     */
    public static function getInstance(): Kernel
    {
        if (!isset(Kernel::$instance)) {
            Kernel::$instance = new self();
        }
        return Kernel::$instance;
    }

    /**
     * The location of the exception thrown in the async closure may be the calling context/suspension recovery location,
     * so exceptions must be managed carefully.
     *
     * @param Closure $closure
     *
     * @return Promise
     */
    public function async(Closure $closure): Promise
    {
        return Coroutine::getInstance()->async($closure);
    }

    /**
     * @param Closure $closure
     *
     * @return Promise
     */
    public function promise(Closure $closure): Promise
    {
        return new Promise($closure);
    }

    /**
     * @param Closure   $closure
     * @param int|float $second
     *
     * @return string
     */
    public function delay(Closure $closure, int|float $second): string
    {
        return EventLoop::delay($second, static function () use ($closure) {
            async($closure);
        });
    }

    /**
     * @param Closure $closure
     *
     * @return void
     */
    public function defer(Closure $closure): void
    {
        $suspension = getSuspension();
        if (!$suspension instanceof Suspension) {
            EventLoop::queue(static fn () => async($closure));
            return;
        }

        $suspension->promise->finally(static fn () => async($closure));
    }

    /**
     * @param Closure(Closure):void $closure
     * @param int|float             $second
     *
     * @return string
     */
    public function repeat(Closure $closure, int|float $second): string
    {
        return EventLoop::repeat($second, function (string $cancelID) use ($closure) {
            call_user_func($closure, fn () => $this->cancel($cancelID));
        });
    }

    /**
     * @param string $id
     *
     * @return void
     */
    public function cancel(string $id): void
    {
        EventLoop::cancel($id);
    }

    /**
     * @param int     $signal
     * @param Closure $closure
     *
     * @return string
     * @throws UnsupportedFeatureException
     */
    public function onSignal(int $signal, Closure $closure): string
    {
        return Process::getInstance()->onSignal($signal, $closure);
    }

    /**
     * @param Closure $closure
     *
     * @return string
     */
    public function forked(Closure $closure): string
    {
        return Process::getInstance()->forked($closure);
    }

    /**
     * @param string $index
     *
     * @return void
     */
    public function cancelForked(string $index): void
    {
        Process::getInstance()->cancelForked($index);
    }

    /**
     * @param Closure|null $result
     *
     * @return void
     */
    public function wait(Closure|null $result = null): void
    {
        if (!isset($this->mainSuspension)) {
            $this->mainSuspension = getSuspension();
        }

        if (!$this->mainRunning) {
            try {
                Coroutine::resume($this->mainSuspension, $result);
                if (Fiber::getCurrent()) {
                    Fiber::suspend();
                }
            } catch (Throwable) {
                exit(0);
            }
            return;
        }

        if ($result instanceof Closure) {
            $result();
        }

        try {
            $this->mainRunning = false;
            $result = Coroutine::suspend($this->mainSuspension);
            $this->mainRunning = true;

            if ($result instanceof Closure) {
                try {
                    $result();
                } catch (Throwable) {
                }
            }

            /*** The Event object may be reset during the mainRunning of $result, so mainSuspension needs to be reacquired.*/
            unset($this->mainSuspension);
            wait();
        } catch (Throwable) {
        }
    }

    /**
     * @return void
     */
    public function stop(): void
    {
        wait(static fn () => EventLoop::getDriver()->stop());
    }

    /**
     * @return void
     */
    public function cancelAll(): void
    {
        foreach (EventLoop::getIDentifiers() as $identifier) {
            $this->cancel($identifier);
        }
    }

    /**
     * @return bool
     */
    public function supportParallel(): bool
    {
        return $this->parallel;
    }

    /**
     * @return bool
     */
    public function supportProcessControl(): bool
    {
        return $this->processControl;
    }

    /**
     * @Author cclilshy
     * @Date   2024/9/24 14:27
     * @return int
     */
    public function getMemorySize(): int
    {
        if (isset($this->memorySize)) {
            return $this->memorySize;
        }

        switch (PHP_OS_FAMILY) {
            case 'Linux':
                if (file_exists('/proc/meminfo')) {
                    $data = file_get_contents("/proc/meminfo");
                    if ($data && preg_match("/MemTotal:\s+(\d+)\skB/", $data, $matches)) {
                        return $this->memorySize = intval(($matches[1] * 1024));
                    }
                }
                break;
            case 'Windows':
                $memory = shell_exec("wmic computersystem get totalphysicalmemory");
                if (preg_match("/\d+/", $memory, $matches)) {
                    return $this->memorySize = intval($matches[0]);
                }
                break;
            case 'Darwin':
                $memory = shell_exec("sysctl -n hw.memsize");
                if ($memory) {
                    return $this->memorySize = intval($memory);
                }
                break;
        }

        return $this->memorySize = 0;
    }
}
