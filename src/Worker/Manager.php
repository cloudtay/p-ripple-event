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

namespace Ripple\Worker;

use Ripple\Kernel;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Utils\Output;
use Ripple\Utils\Serialization\Zx7e;
use BadMethodCallException;

use function getmypid;
use function posix_getpid;

/**
 * @Author cclilshy
 * @Date   2024/8/16 11:51
 * @method void onCommand(Command $workerCommand, string $name, int $index)
 */
class Manager
{
    public const COMMAND_COMMAND_TO_WORKER = '__manager__.commandToWorker';
    public const COMMAND_COMMAND_TO_ALL    = '__manager__.commandToAll';

    /**
     * @var Worker[]
     */
    protected array $workers = [];

    /**
     * @var Zx7e
     */
    protected Zx7e $zx7e;

    /**
     * @var int
     */
    protected int $index = 1;

    /**
     * @var int
     */
    protected int $processID;

    /**
     * @Author cclilshy
     * @Date   2024/8/16 12:28
     *
     * @param Worker $worker
     *
     * @return void
     * @deprecated
     */
    public function addWorker(Worker $worker): void
    {
        $this->add($worker);
    }

    /**
     * @param \Ripple\Worker\Worker $worker
     *
     * @return void
     */
    public function add(Worker $worker): void
    {
        $workerName = $worker->getName();
        if (isset($this->workers[$workerName])) {
            Output::warning("Worker {$workerName} already exists");
            return;
        }
        $this->workers[$workerName] = $worker;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 10:11
     *
     * @param string $name
     *
     * @return void
     * @deprecated use remove()
     */
    public function removeWorker(string $name): void
    {
        $this->remove($name);
    }

    /**
     * @param string $name
     *
     * @return void
     */
    public function remove(string $name): void
    {
        if ($worker = $this->workers[$name] ?? null) {
            $worker->isRunning() && $this->terminate($name);
            unset($this->workers[$name]);
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 00:44
     *
     * @param string|null $name
     *
     * @return void
     */
    public function terminate(string|null $name = null): void
    {
        if ($name) {
            if ($worker = $this->workers[$name] ?? null) {
                $worker->terminate();
            }
            return;
        }

        foreach ($this->workers as $worker) {
            $worker->terminate();
        }
    }

    /**
     * @return \Ripple\Worker\Worker[]
     */
    public function getWorkers(): array
    {
        return $this->workers;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 00:13
     *
     * @param Command $workerCommand
     * @param string  $name
     * @param int     $index
     *
     * @return void
     */
    protected function __onCommand(Command $workerCommand, string $name, int $index): void
    {
        switch ($workerCommand->name) {
            case WorkerContext::COMMAND_RELOAD:
                $name = $workerCommand->arguments['name'] ?? null;
                $this->reload($name);
                break;

            case WorkerContext::COMMAND_SYNC_ID:
                if ($stream = $this->workers[$name]?->streams[$index] ?? null) {
                    $sync    = $this->index++;
                    $id      = $workerCommand->arguments['id'];
                    $command = Command::make(WorkerContext::COMMAND_SYNC_ID, ['sync' => $sync, 'id' => $id]);
                    try {
                        $stream->write($this->zx7e->encodeFrame($command->__toString()));
                    } catch (ConnectionException $e) {
                        Output::warning($e->getMessage());
                    }
                }
                break;

            case Manager::COMMAND_COMMAND_TO_WORKER:
                $command = $workerCommand->arguments['command'];
                $target  = $workerCommand->arguments['name'];
                $this->sendCommand($command, $target);
                break;

            case Manager::COMMAND_COMMAND_TO_ALL:
                $command = $workerCommand->arguments['command'];
                $this->sendCommand($command);
                break;
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 02:01
     *
     * @param string|null $name
     *
     * @return void
     */
    public function reload(string|null $name = null): void
    {
        if ($name) {
            if (isset($this->workers[$name])) {
                $this->sendCommand(Command::make(WorkerContext::COMMAND_RELOAD), $name);
            }
            return;
        }
        $this->sendCommand(Command::make(WorkerContext::COMMAND_RELOAD));
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 09:28
     *
     * @param Command $command
     * @param string  $name
     *
     * @return void
     * @deprecated
     */
    public function commandToWorker(Command $command, string $name): void
    {
        if (isset($this->workers[$name])) {
            foreach ($this->workers[$name]->streams as $stream) {
                try {
                    $stream->write($this->zx7e->encodeFrame($command->__toString()));
                } catch (ConnectionException $e) {
                    Output::warning($e->getMessage());
                }
            }
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 09:23
     *
     * @param Command $command
     *
     * @return void
     * @deprecated
     */
    public function commandToAll(Command $command): void
    {
        $workers = $this->workers;
        foreach ($workers as $worker) {
            foreach ($worker->streams as $stream) {
                try {
                    $stream->write($this->zx7e->encodeFrame($command->__toString()));
                } catch (ConnectionException $e) {
                    Output::warning($e->getMessage());
                }
            }
        }
    }

    /**
     * @param \Ripple\Worker\Command $command
     * @param string|null            $name
     *
     * @return void
     */
    public function sendCommand(Command $command, string|null $name = null): void
    {
        if ($name) {
            if (isset($this->workers[$name])) {
                foreach ($this->workers[$name]->streams as $stream) {
                    try {
                        $stream->write($this->zx7e->encodeFrame($command->__toString()));
                    } catch (ConnectionException $e) {
                        Output::warning($e->getMessage());
                    }
                }
            }
        } else {
            $workers = $this->workers;
            foreach ($workers as $worker) {
                foreach ($worker->streams as $stream) {
                    try {
                        $stream->write($this->zx7e->encodeFrame($command->__toString()));
                    } catch (ConnectionException $e) {
                        Output::warning($e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/16 12:28
     * @return bool
     */
    public function run(): bool
    {
        /*** @compatible:Windows */
        if (!Kernel::getInstance()->supportProcessControl()) {
            $this->processID = getmypid();
        } else {
            $this->processID = posix_getpid();
        }
        $this->zx7e = new Zx7e();
        foreach ($this->workers as $worker) {
            if (!$worker->run($this)) {
                Output::error("worker {$worker->getName()} failed to start");
                $this->terminate();
            }
        }
        return true;
    }

    /**
     *
     */
    public function __destruct()
    {
        if (!Kernel::getInstance()->supportProcessControl()) {
            return;
        }

        if (isset($this->processID) && $this->processID === posix_getpid()) {
            $this->terminate();
        }
    }

    /**
     * @param string $name
     * @param array  $arguments
     *
     * @return void
     */
    public function __call(string $name, array $arguments): void
    {
        if ($name === 'onCommand') {
            $this->__onCommand(...$arguments);
            return;
        }

        throw new BadMethodCallException("Call to undefined method " . static::class . "::{$name}()");
    }
}
