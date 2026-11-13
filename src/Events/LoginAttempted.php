<?php

namespace Darvis\Mfa\Events;

use Darvis\Mfa\Models\LoginAttempt;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Fired for every login attempt the package handles, successful or not.
 */
class LoginAttempted
{
    public function __construct(
        public readonly string $guard,
        public readonly string $result,
        public readonly ?string $channel = null,
        public readonly ?string $identifier = null,
        public readonly ?Authenticatable $user = null,
    ) {}

    public function succeeded(): bool
    {
        return in_array($this->result, [LoginAttempt::SUCCEEDED, LoginAttempt::EMERGENCY], true);
    }
}
