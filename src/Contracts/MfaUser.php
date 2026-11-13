<?php

namespace Darvis\Mfa\Contracts;

/**
 * Optional. Implement it (or use the HasMfa trait) on a model whose email
 * address, phone number or "may sign in" flag is not a plain attribute.
 */
interface MfaUser
{
    public function mfaEmail(): ?string;

    public function mfaPhone(): ?string;

    public function mfaActive(): bool;
}
