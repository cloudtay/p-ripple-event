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

use Closure;
use JetBrains\PhpStorm\NoReturn;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Utils\Output;
use Throwable;

use function Co\promise;
use function spl_object_hash;

/**
 * @Author cclilshy
 * @Date   2024/8/16 11:53
 */
abstract class Worker extends WorkerContext
{
    /**
     * @return bool
     */
    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * 发送指令
     *
     * @Context  worker
     * @Author   cclilshy
     * @Date     2024/8/17 00:07
     *
     * @param Command $command
     *
     * @return void
     */
    public function sendCommandToManager(Command $command): void
    {
        try {
            $this->parentSocket->write($this->zx7e->encodeFrame($command->__toString()));
        } catch (ConnectionException $exception) {
            Output::error($exception->getMessage());

            // Writing a message to the parent process fails. There is only one possibility that the parent process has exited.
            exit(1);
        }
    }

    /**
     * Send instructions to the specified Worker
     *
     * @Context  worker
     * @Author   cclilshy
     * @Date     2024/8/17 00:18
     *
     * @param Command $command
     * @param string  $name
     *
     * @return void
     * @deprecated
     */
    public function commandToWorker(Command $command, string $name): void
    {
        $this->sendCommandToManager(Command::make(Manager::COMMAND_COMMAND_TO_WORKER, [
            'command' => $command,
            'name'    => $name
        ]));
    }

    /**
     * @param \Ripple\Worker\Command $command
     * @param string|null            $name
     *
     * @return bool
     */
    public function sendCommand(Command $command, string|null $name = null): bool
    {
        if ($name) {
            $this->sendCommandToManager(Command::make(Manager::COMMAND_COMMAND_TO_WORKER, [
                'command' => $command,
                'name'    => $name
            ]));
        } else {
            $this->sendCommandToManager(Command::make(Manager::COMMAND_COMMAND_TO_ALL, [
                'command' => $command
            ]));
        }
        return true;
    }

    /**
     * @Context  worker
     * @Author   cclilshy
     * @Date     2024/8/17 09:48
     *
     * @param Command $command
     *
     * @return void
     * @deprecated
     */
    public function commandToAll(Command $command): void
    {
        $this->sendCommandToManager(Command::make(Manager::COMMAND_COMMAND_TO_ALL, [
            'command' => $command
        ]));
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 17:32
     * @return int|false
     */
    public function syncID(): int|false
    {
        try {
            return promise(function (Closure $resolve, Closure $reject) {
                $id      = spl_object_hash($resolve);
                $command = Command::make(WorkerContext::COMMAND_SYNC_ID, ['id' => $id]);
                $this->sendCommandToManager($command);

                $this->queue[$id] = [
                    'resolve' => $resolve,
                    'reject'  => $reject
                ];
            })->await();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Triggered during hot restart. The notified process should follow the hot restart rules to release resources and then exit.
     *
     * @Context  worker
     * @Author   cclilshy
     * @Date     2024/8/17 00:59
     * @return void
     */
    #[NoReturn] protected function onReload(): void
    {
        exit(0);
    }

    /**
     * @return void
     */
    #[NoReturn] protected function onTerminate(): void
    {
        exit(0);
    }

    /**
     * Triggered when command is received
     *
     * @Context  worker
     * @Author   cclilshy
     * @Date     2024/8/16 11:54
     *
     * @param Command $command
     *
     * @return void
     */
    public function onCommand(Command $command): void
    {
    }
}
