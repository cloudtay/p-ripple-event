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

use Revolt\EventLoop\Suspension;
use Ripple\Types\Undefined;

use function Co\getSuspension;
use function spl_object_hash;
use function array_merge;
use function is_array;

/**
 *
 */
class Context
{
    /**
     * @var array
     */
    protected static array $context = [];

    /**
     * @param array|string $key
     * @param mixed        $value
     *
     * @return void
     */
    public static function define(array|string $key, mixed $value = null): void
    {
        $hash = Context::getHash();
        if (is_array($key)) {
            Context::$context[$hash] = array_merge(Context::$context[$hash] ?? [], $key);
        }

        Context::$context[$hash][$key] = $value;
    }

    /**
     * @param string|null $key
     *
     * @return mixed
     */
    public static function get(string|null $key = null): mixed
    {
        $hash = Context::getHash();
        if (!$key) {
            return Context::$context[$hash] ?? new Undefined();
        }
        return Context::$context[$hash][$key] ?? new Undefined();
    }

    /**
     * @param string $key
     *
     * @return void
     */
    public static function remove(string $key): void
    {
        $hash = Context::getHash();
        unset(Context::$context[$hash][$key]);
    }

    /**
     * @param \Revolt\EventLoop\Suspension $targetSuspension
     *
     * @return void
     */
    public static function extend(Suspension $targetSuspension): void
    {
        $currentSuspension = getSuspension();
        if ($currentSuspension === $targetSuspension) {
            return;
        }

        $currentHash                    = Context::getHash($currentSuspension);
        $targetHash                     = Context::getHash($targetSuspension);
        Context::$context[$currentHash] = (Context::$context[$targetHash] ?? []);
    }

    /**
     * @return void
     */
    public static function clear(): void
    {
        unset(Context::$context[Context::getHash()]);
    }

    /**
     * @param \Revolt\EventLoop\Suspension|null $suspension
     *
     * @return string
     */
    public static function getHash(Suspension|null $suspension = null): string
    {
        if (!$suspension) {
            $suspension = getSuspension();
        }
        return spl_object_hash($suspension);
    }
}
