<?php

namespace Darvis\Mfa\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * The firewall refused the address the request came from. Says nothing about
 * any account, so it is safe to show the visitor why.
 */
class BlockedException extends RuntimeException
{
    /**
     * @param  string  $reason  One of the Verdict constants.
     * @param  CarbonInterface|null  $until  When a block after failed logins ends by itself.
     */
    public function __construct(
        public readonly string $reason,
        public readonly ?CarbonInterface $until = null,
    ) {
        parent::__construct(__('mfa::mfa.'.$reason));
    }
}
