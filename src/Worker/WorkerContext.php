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
use Ripple\Process\Runtime;
use Ripple\Socket;
use Ripple\Utils\Serialization\Zx7e;

use function Co\delay;
use function Co\process;
use function socket_create_pair;
use function socket_export_stream;

use const AF_INET;
use const AF_UNIX;
use const SOCK_STREAM;

/**
 *
 */
abstract class WorkerContext
{
    public const COMMAND_RELOAD    = '__worker__.reload';
    public const COMMAND_TERMINATE = '__worker__.terminate';
    public const COMMAND_SYNC_ID   = '__worker__.sync.id';

    /**
     * @Context manager
     * @var Runtime[]
     */
    public array $runtimes = array();

    /**
     * @Context manager
     * @var Socket[]
     */
    public array $streams = array();

    /**
     * @Context share
     * @var bool
     */
    protected bool $parent = true;

    /**
     * @Context worker
     * @var Zx7e
     */
    protected Zx7e $zx7e;

    /*** @var string */
    protected string $name;

    /*** @var int */
    protected int $count = 1;

    /*** @var bool */
    protected bool $running = false;

    /**
     * @Context worker
     * @var Socket
     */
    protected Socket $parentSocket;

    /*** @var array */
    protected array $queue = [];

    /*** @var bool */
    protected bool $booted = false;

    /*** @var bool */
    protected bool $terminated = false;

    /*** @var \Ripple\Worker\Manager */
    protected Manager $manager;

    /**
     * @return void
     */
    public function terminate(): void
    {
        $this->terminated = true;

        $this->manager->sendCommand(
            Command::make(WorkerContext::COMMAND_TERMINATE),
            $this->getName()
        );

        foreach ($this->runtimes as $runtime) {
            $runtime->stop();
        }

        foreach ($this->streams as $stream) {
            $stream->close();
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 14:25
     *
     * @param Manager $manager
     * @param int     $index
     *
     * @return bool
     */
    private function guard(Manager $manager, int $index): bool
    {
        $this->manager = $manager;
        /*** @compatible:Windows */
        $domain = !Kernel::getInstance()->supportProcessControl() ? AF_INET : AF_UNIX;

        if (!socket_create_pair($domain, SOCK_STREAM, 0, $sockets)) {
            return false;
        }

        $streamA = new Socket(socket_export_stream($sockets[0]));
        $streamB = new Socket(socket_export_stream($sockets[1]));
        $streamA->setBlocking(false);
        $streamB->setBlocking(false);
        $streamA->onClose(fn () => $streamB->close());

        $zx7e                  = new Zx7e();
        $this->streams[$index] = $streamA;
        $this->streams[$index]->onReadable(function (Socket $Socket) use ($streamA, $index, &$zx7e, $manager) {
            $content = $Socket->readContinuously(1024);
            foreach ($zx7e->decodeStream($content) as $string) {
                $manager->onCommand(Command::fromString($string), $this->getName(), $index);
            }
        });

        $this->runtimes[$index] = $runtime = process(function () use ($streamB) {
            $this->parent       = false;
            $this->parentSocket = $streamB;
            $this->boot();
            $this->booted = true;

            $this->zx7e = new Zx7e();
            $this->parentSocket->onReadable(function (Socket $Socket) {
                $content = $Socket->readContinuously(1024);
                foreach ($this->zx7e->decodeStream($content) as $string) {
                    $command = Command::fromString($string);
                    switch ($command->name) {
                        case WorkerContext::COMMAND_RELOAD:
                            $this->onReload();
                            break;

                        case WorkerContext::COMMAND_TERMINATE:
                            $this->onTerminate();
                            break;

                        case WorkerContext::COMMAND_SYNC_ID:
                            $id   = $command->arguments['id'];
                            $sync = $command->arguments['sync'];

                            if ($callback = $this->queue[$id] ?? null) {
                                unset($this->queue[$id]);
                                $callback['resolve']($sync);
                            }
                            break;

                        default:
                            $this->onCommand($command);
                    }
                }
            });
        })->run();

        $runtime->finally(fn () => $this->onExit($index));
        return true;
    }

    /**
     * @param int $index
     *
     * @return void
     */
    private function onExit(int $index): void
    {
        if (isset($this->streams[$index])) {
            $this->streams[$index]->close();
            unset($this->streams[$index]);
        }

        if (isset($this->runtimes[$index])) {
            unset($this->runtimes[$index]);
        }

        if (!$this->terminated) {
            delay(function () use ($index) {
                $this->guard($this->manager, $index);
            }, 0.1);
        }
    }

    /**
     * @Context manager
     * @Author  cclilshy
     * @Date    2024/8/16 23:50
     *
     * @param Manager $manager
     *
     * @return bool
     */
    public function run(Manager $manager): bool
    {
        $this->register($manager);
        /*** @compatible:Windows */
        $count = !Kernel::getInstance()->supportProcessControl() ? 1 : $this->getCount();
        for ($index = 1; $index <= $count; $index++) {
            if (!$this->guard($manager, $index)) {
                return false;
            }
        }

        $this->running = true;
        return true;
    }

    /**
     * @Context  share
     * @Author   cclilshy
     * @Date     2024/8/17 01:05
     * @return string
     */
    public function getName(): string
    {
        if (!isset($this->name)) {
            $this->name = static::class;
        }
        return $this->name;
    }

    /**
     * @Context  share
     * @Author   cclilshy
     * @Date     2024/8/17 01:06
     * @return int
     */
    public function getCount(): int
    {
        return $this->count;
    }

    /**
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * @Context  manager
     * @Author   cclilshy
     * @Date     2024/8/16 11:53
     *
     * @param Manager $manager
     *
     * @return void
     */
    abstract protected function register(Manager $manager): void;

    /**
     * @Context  worker
     * @Author   cclilshy
     * @Date     2024/8/16 11:53
     * @return void
     */
    abstract protected function boot(): void;

    /**
     * Triggered during hot restart. The notified process should follow the hot restart rules to release resources and then exit.
     *
     * @Context  worker
     * @Author   cclilshy
     * @Date     2024/8/17 00:59
     * @return void
     */
    abstract protected function onReload(): void;

    /**
     * @return void
     */
    abstract protected function onTerminate(): void;

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
    abstract public function onCommand(Command $command): void;
}
