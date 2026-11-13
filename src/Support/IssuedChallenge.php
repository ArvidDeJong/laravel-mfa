<?php

namespace Darvis\Mfa\Support;

use Darvis\Mfa\Models\Challenge;

/**
 * A freshly issued challenge together with the plain code and link token,
 * which are never stored.
 */
class IssuedChallenge
{
    public function __construct(
        public readonly Challenge $challenge,
        public readonly string $code,
        public readonly ?string $token,
    ) {}

    /**
     * The link that signs in, when this challenge carries one.
     */
    public function url(): ?string
    {
        return $this->token === null ? null : route('mfa.link', ['token' => $this->token]);
    }

    public function minutes(): int
    {
        return (int) max(1, ceil(now()->diffInSeconds($this->challenge->expires_at, false) / 60));
    }
}
