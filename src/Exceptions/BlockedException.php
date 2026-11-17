<?php

namespace Darvis\Mfa\Exceptions;

use RuntimeException;

/**
 * The firewall refused the address the request came from. Says nothing about
 * any account, so it is safe to show the visitor why.
 */
class BlockedException extends RuntimeException
{
    /**
     * @param  string  $reason  One of the Verdict constants.
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct(__('mfa::mfa.'.$reason));
    }
}
