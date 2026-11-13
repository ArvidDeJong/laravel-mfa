<?php

namespace Darvis\Mfa\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A channel where the user types something only they have or know: an
 * authenticator code, a recovery code or a password.
 */
interface VerifiesSecret extends Channel
{
    public function verify(Authenticatable $user, string $input): bool;
}
