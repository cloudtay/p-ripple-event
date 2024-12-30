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

namespace Ripple\Proc;

use function implode;
use function is_array;
use function is_resource;
use function proc_open;

/**
 * @Author cclilshy
 * @Date   2024/8/16 09:36
 */
class Proc
{
    /**
     * @param string|array $entrance
     *
     * @return Session|false
     */
    public static function open(string|array $entrance = '/bin/sh'): Session|false
    {
        if (is_array($entrance)) {
            $entrance = implode(' ', $entrance);
        }

        $process = proc_open($entrance, array(
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ), $pipes);

        if (is_resource($process)) {
            return new Session(
                $process,
                $pipes[0],
                $pipes[1],
                $pipes[2],
            );
        }

        return false;
    }

    /**
     * @param string|array $entrance
     *
     * @return \Ripple\Proc\Future
     */
    public static function exec(string|array $entrance = '/bin/sh'): Future
    {
        return new Future(Proc::open($entrance));
    }
}
