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

namespace Ripple\Utils;

use Ripple\Kernel;
use Throwable;

use function explode;
use function fwrite;
use function get_class;
use function implode;
use function posix_getpid;
use function posix_getppid;

use const PHP_EOL;
use const STDOUT;

/**
 * @class Output Output helper class
 */
final class Output
{
    /**
     * @param Throwable $exception
     *
     * @return void
     */
    public static function exception(Throwable $exception): void
    {
        /*** @compatible:Windows */
        if (Kernel::getInstance()->supportProcessControl()) {
            fwrite(STDOUT, "\033[31mProcess: " . posix_getpid() . '=>' . posix_getppid() . "\033[0m\n");
        }

        fwrite(STDOUT, "\033[31mException: " . get_class($exception) . "\033[0m\n");
        fwrite(STDOUT, "\033[33mMessage: " . $exception->getMessage() . "\033[0m\n");
        fwrite(STDOUT, "\033[34mFile: " . $exception->getFile() . "\033[0m\n");
        fwrite(STDOUT, "\033[34mLine: " . $exception->getLine() . "\033[0m\n");
        fwrite(STDOUT, "\033[0;32mStack trace:\033[0m\n");
        $trace      = $exception->getTraceAsString();
        $traceLines = explode("\n", $trace);
        foreach ($traceLines as $line) {
            fwrite(STDOUT, "\033[0;32m{$line}\033[0m\n");
        }
        fwrite(STDOUT, PHP_EOL);
    }

    /**
     * @param string $title
     * @param string ...$contents
     *
     * @return void
     */
    public static function info(string $title, string ...$contents): void
    {
        Output::writeln("\033[32m{$title}\033[0m \033[33m" . implode(' ', $contents) . "\033[0m");
    }

    /**
     * @param string $message
     *
     * @return void
     */
    public static function writeln(string $message): void
    {
        Output::write($message . PHP_EOL);
    }

    /**
     * @param string $title
     * @param string ...$contents
     *
     * @return void
     */
    public static function warning(string $title, string ...$contents): void
    {
        Output::writeln("\033[33m{$title}\033[0m \033[33m" . implode(' ', $contents) . "\033[0m");
    }

    /**
     * @param string $title
     * @param string ...$contents
     *
     * @return void
     */
    public static function error(string $title, string ...$contents): void
    {
        Output::writeln("\033[31m{$title}\033[0m \033[33m" . implode(' ', $contents) . "\033[0m");
    }

    /**
     * @param string $message
     *
     * @return void
     */
    public static function write(string $message): void
    {
        fwrite(STDOUT, $message);
    }
}
