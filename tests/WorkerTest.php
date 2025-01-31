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

namespace Tests;

use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Ripple\Worker\Command;
use Ripple\Worker\Manager;
use Ripple\Worker\Worker;

use function Co\cancelAll;
use function Co\wait;

#[RunClassInSeparateProcess]
class WorkerTest extends TestCase
{
    /**
     * @return void
     */
    public function testWorkerBasicOperations(): void
    {
        $manager = new Manager();
        $worker  = new TestWorker();

        $manager->add($worker);
        $this->assertCount(1, $manager->getWorkers());
        $result = $manager->run();
        $this->assertTrue($result);
        $this->assertEquals('Tests\TestWorker', $worker->getName());
        $this->assertEquals(1, $worker->getCount());
        $manager->terminate();
        cancelAll();
        wait();
    }

    /**
     * @return void
     */
    public function testWorkerCommands(): void
    {
        $manager = new Manager();
        $worker  = new TestWorker();

        $manager->add($worker);
        $manager->run();
        $command = Command::make('test.command', ['data' => 'test']);
        $manager->sendCommand($command, $worker->getName());
        $manager->sendCommand($command);
        $manager->reload($worker->getName());
        $manager->terminate();
        cancelAll();
        wait();
    }

    /**
     * Test worker removal
     */
    public function testWorkerRemoval(): void
    {
        $manager = new Manager();
        $worker  = new TestWorker();

        $manager->add($worker);
        $this->assertCount(1, $manager->getWorkers());
        $manager->removeWorker($worker->getName());
        $this->assertCount(0, $manager->getWorkers());
        cancelAll();
        wait();
    }

    /**
     * @return void
     */
    public function testMultipleWorkers(): void
    {
        $manager       = new Manager();
        $worker1       = new TestWorker();
        $worker2       = new TestWorker();
        $worker2->name = 'worker2';
        $manager->add($worker1);
        $manager->add($worker2);
        $this->assertCount(2, $manager->getWorkers());
        $manager->run();
        $command = Command::make('test.broadcast', ['data' => 'test']);
        $manager->sendCommand($command);
        $manager->terminate();
        cancelAll();
        wait();
    }
}

/**
 * Test Worker implementation
 */
class TestWorker extends Worker
{
    /*** @var string */
    public string $name = 'Tests\TestWorker';

    /*** @var int */
    protected int $count = 1;

    /**
     * @param \Ripple\Worker\Manager $manager
     *
     * @return void
     */
    protected function register(Manager $manager): void
    {
    }

    /**
     * @return void
     */
    protected function boot(): void
    {
    }
}
