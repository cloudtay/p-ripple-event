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

namespace Ripple\Coroutine;

use Closure;
use Fiber;
use JetBrains\PhpStorm\NoReturn;
use Revolt\EventLoop;
use Ripple\Coroutine\Exception\EscapeException;
use Ripple\Coroutine\Exception\PromiseRejectException;
use Ripple\Process\Process;
use Ripple\Promise;
use Ripple\Support;
use Ripple\Utils\Output;
use Throwable;
use WeakMap;
use WeakReference;

use function Co\delay;
use function Co\forked;
use function Co\getSuspension;
use function Co\promise;

/**
 * 2024-07-13 principle
 *
 * async is a single-line Fiber independent of EventLoop. Operations on Fiber must take into account the coroutine space of EventLoop.
 * Any suspend/resume should be responsible for the Fiber of the current operation, including the return processing of the results
 *
 * 2024-07-13 Compatible with Process module
 */
class Coroutine extends Support
{
    /*** @var Support */
    protected static Support $instance;

    /*** @var WeakMap<object,WeakReference<Suspension>> */
    private WeakMap $fiber2suspension;

    /**
     *
     */
    public function __construct()
    {
        $this->registerOnFork();
        $this->fiber2suspension = new WeakMap();
    }

    /**
     * @return void
     */
    private function registerOnFork(): void
    {
        forked(function () {
            $this->fiber2suspension = new WeakMap();
            $this->registerOnFork();
        });
    }

    /**
     * @return EventLoop\Suspension
     */
    public function getSuspension(): EventLoop\Suspension
    {
        if (!$fiber = Fiber::getCurrent()) {
            return EventLoop::getSuspension();
        }
        return ($this->fiber2suspension[$fiber] ?? null)?->get() ?? EventLoop::getSuspension();
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
        if ($promise->getStatus() === Promise::FULFILLED) {
            $result = $promise->getResult();
            if ($result instanceof Promise) {
                return $this->await($result);
            }
            return $result;
        }

        if ($promise->getStatus() === Promise::REJECTED) {
            if ($promise->getResult() instanceof Throwable) {
                throw $promise->getResult();
            } else {
                throw new PromiseRejectException($promise->getResult());
            }
        }

        $suspension = getSuspension();
        if (!$suspension instanceof Suspension) {
            $promise->then(static fn ($result) => Coroutine::resume($suspension, $result));
            $promise->except(static function (mixed $result) use ($suspension) {
                $result instanceof Throwable
                    ? Coroutine::throw($suspension, $result)
                    : Coroutine::throw($suspension, new PromiseRejectException($result));
            });
            $result = Coroutine::suspend($suspension);
            if ($result instanceof Promise) {
                return $this->await($result);
            }
            return $result;
        }

        /**
         * To determine your own control over preparing Fiber, you must be responsible for the subsequent status of Fiber.
         */
        // When the status of the awaited Promise is completed
        $promise
            ->then(static fn (mixed $result) => Coroutine::resume($suspension, $result))
            ->except(static function (mixed $result) use ($suspension) {
                $result instanceof Throwable
                    ? Coroutine::throw($suspension, $result)
                    : Coroutine::throw($suspension, new PromiseRejectException($result));
            });

        // Confirm that you have prepared to handle Fiber recovery and take over control of Fiber by suspending it
        $result = Coroutine::suspend($suspension);
        if ($result instanceof Promise) {
            return $this->await($result);
        }
        return $result;
    }

    /**
     * @param Closure $closure
     *
     * @return Promise
     */
    public function async(Closure $closure): Promise
    {
        return promise(function (Closure $resolve, Closure $reject, Promise $promise) use ($closure) {
            $currentSuspension = getSuspension();
            $suspension        = new Suspension(function () use ($closure, $resolve, $reject, $currentSuspension) {
                Context::extend($currentSuspension);
                try {
                    $resolve($closure());
                } catch (EscapeException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    $reject($exception);
                    return;
                } finally {
                    Context::clear();
                }
            }, $resolve, $reject, $promise);

            $this->fiber2suspension[$suspension->fiber] = WeakReference::create($suspension);

            try {
                $suspension->start();
            } catch (EscapeException $exception) {
                $this->handleEscapeException($exception);
            }
        });
    }

    /**
     * @param int|float $second
     *
     * @return int|float
     * @throws Throwable
     */
    public function sleep(int|float $second): int|float
    {
        $suspension = getSuspension();
        delay(static fn () => Coroutine::resume($suspension, $second), $second);
        return Coroutine::suspend($suspension);
    }

    /**
     * @param EscapeException $exception
     *
     * @return void
     */
    #[NoReturn]
    public function handleEscapeException(EscapeException $exception): void
    {
        Process::getInstance()->processedInMain($exception->lastWords);
    }

    /**
     *
     * The coroutine that cannot be restored can only throw an exception.
     * If it is a ripple type exception, it will be caught and the contract will be rejected.
     *
     * This method attempts to resume a suspended coroutine and take over the coroutine context.
     * When the recovery fails or an exception occurs within the coroutine, an exception will be thrown.
     * This method will not return any value yet
     *
     * @param \Revolt\EventLoop\Suspension $suspension
     * @param mixed|null                   $result
     *
     * @return mixed
     */
    public static function resume(EventLoop\Suspension $suspension, mixed $result = null): mixed
    {
        try {
            $suspension->resume($result);
        } catch (EscapeException $exception) {
            Coroutine::getInstance()->handleEscapeException($exception);
        } catch (Throwable $exception) {
            Output::warning($exception->getMessage());
        }

        return null;
    }

    /**
     * @param \Revolt\EventLoop\Suspension $suspension
     * @param Throwable $exception
     *
     * @return void
     */
    public static function throw(EventLoop\Suspension $suspension, Throwable $exception): void
    {
        try {
            $suspension->throw($exception);
        } catch (Throwable $exception) {
        }
    }

    /**
     * @param \Revolt\EventLoop\Suspension|null $suspension
     *
     * @return mixed
     */
    public static function suspend(EventLoop\Suspension|null $suspension = null): mixed
    {
        if (!$suspension) {
            $suspension = getSuspension();
        }
        try {
            return $suspension->suspend();
        } catch (EscapeException $exception) {
            Coroutine::getInstance()->handleEscapeException($exception);
        }
    }
}
