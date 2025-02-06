<?php declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Ripple\Kernel;
use Ripple\Process\Exception\ProcessException;
use Throwable;

use function Co\async;
use function Co\process;
use function Co\thread;
use function mt_rand;
use function sleep;

class ProcessTest extends TestCase
{
    /**
     * @return void
     * @throws Throwable
     */
    public function test_process(): void
    {
        $code = mt_rand(0, 255);
        $task = process(function () use ($code) {
            \Co\sleep(1);
            exit($code);
        });

        $runtime  = $task->run();
        try {
            $exitCode = $runtime->await();
        } catch (ProcessException $exception) {
            $exitCode = $exception->getCode();
        }
        $this->assertEquals($code, $exitCode, 'Process exit code');
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function test_coroutine(): void
    {
        $code     = mt_rand(0, 255);
        $async    = async(function () use ($code) {
            $task = process(function () use ($code) {
                \Co\sleep(0.1);
                exit($code);
            });
            $runtime = $task->run();
            return $runtime->getPromise()->await();
        });
        try {
            $exitCode = $async->await();
        } catch (ProcessException $exception) {
            $exitCode = $exception->getCode();
        }
        $this->assertEquals($code, $exitCode, 'Process exit code');
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function test_parallel(): void
    {
        if (!Kernel::getInstance()->supportParallel()) {
            $this->markTestSkipped('The current environment does not support parallel processing');
        }

        $code    = mt_rand(0, 255);
        $task = process(function () use ($code) {
            $thread = thread(static function ($context) {
                sleep($context->argv[0]);
                return $context->argv[1];
            });
            $future = $thread->run(1, $code);
            $future->onValue(function ($value = null) {
                exit($value);
            });
        });
        $runtime = $task->run();
        $runtime->then(function ($exitCode) use ($code) {
            $this->assertEquals($code, $exitCode, 'Process exit code');
        });

        $runtime->except(function (ProcessException $exception) use ($code) {
            $this->assertEquals($code, $exception->getCode(), 'Process exit code');
        });
        try {
            $runtime->await();
        } catch (Throwable) {
        }
    }
}
