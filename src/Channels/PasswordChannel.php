<?php

namespace Darvis\Mfa\Channels;

use Darvis\Mfa\Contracts\VerifiesSecret;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;

/**
 * The account password. List it for a guard only when its users actually
 * know one; a column filled with a random hash is not a way in.
 */
class PasswordChannel implements VerifiesSecret
{
    public function name(): string
    {
        return 'password';
    }

    public function dependency(): ?string
    {
        return null;
    }

    public function enrolled(Authenticatable $user): bool
    {
        return $user->getAuthPassword() !== '';
    }

    public function verify(Authenticatable $user, string $input): bool
    {
        $hash = $user->getAuthPassword();

        return $input !== '' && $hash !== '' && Hash::check($input, $hash);
    }
}
