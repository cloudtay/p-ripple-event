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

use Closure;
use Ripple\Promise;
use Throwable;

class Future
{
    /*** @var \Ripple\Promise */
    protected Promise $promise;

    /*** @var string */
    protected string $output;

    /*** @var string */
    protected string $result;

    /*** @var string */
    protected string $error;

    /***
     * @param \Ripple\Proc\Session $session
     */
    public function __construct(protected readonly Session $session)
    {
        $this->error  = '';
        $this->output = '';

        $this->promise = \Co\promise(function (Closure $resolve) {
            $this->session->onMessage = function ($message) use (&$context) {

                $this->output .= $message;
            };

            $this->session->onErrorMessage = function ($message) use (&$context) {
                $this->error  .= $message;
                $this->output .= $message;
            };

            $this->session->onClose = function () use (&$context, $resolve) {
                $resolve($context);
            };
        });
    }

    /**
     * @return string|false
     */
    public function getOutput(): string|false
    {
        try {
            $this->promise->await();
        } catch (Throwable $e) {
            return false;
        }
        return $this->output;
    }

    /**
     * @return string|false
     */
    public function getError(): string|false
    {
        try {
            $this->promise->await();
        } catch (Throwable $e) {
            return false;
        }
        $this->result = $this->error;
        return $this->error;
    }

    /**
     * @return string|false
     */
    public function getResult(): string|false
    {
        try {
            $this->promise->await();
        } catch (Throwable $e) {
            return false;
        }
        $this->result = $this->output;
        return $this->result;
    }

    /**
     * @return \Ripple\Promise
     */
    public function getPromise(): Promise
    {
        return $this->promise;
    }

    /**
     * @return \Ripple\Proc\Session
     */
    public function getSession(): Session
    {
        return $this->session;
    }
}
