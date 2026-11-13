<?php

namespace Darvis\Mfa\Exceptions;

use RuntimeException;

/**
 * Too many requests or attempts for one identifier from one address.
 */
class ThrottledException extends RuntimeException
{
    public function __construct(public readonly int $seconds)
    {
        parent::__construct("Too many attempts. Try again in {$seconds} seconds.");
    }
}
