<?php

namespace Darvis\Mfa\Contracts;

use Darvis\Mfa\Support\IssuedChallenge;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A channel that delivers a one time code to the user.
 */
interface SendsChallenge extends Channel
{
    /**
     * Whether the message should also carry a link that signs in.
     */
    public function withLink(): bool;

    public function send(Authenticatable $user, IssuedChallenge $issued): void;
}
