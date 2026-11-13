<?php

namespace Darvis\Mfa\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * One way to prove who you are.
 */
interface Channel
{
    /**
     * The name used in config/mfa.php, such as "mail" or "authenticator".
     */
    public function name(): string;

    /**
     * The outside service this channel cannot work without, such as "mail" or
     * "sms". Null when the channel needs nothing but this application.
     */
    public function dependency(): ?string;

    /**
     * Whether this user has set the channel up and can use it right now.
     */
    public function enrolled(Authenticatable $user): bool;
}
