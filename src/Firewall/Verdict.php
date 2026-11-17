<?php

namespace Darvis\Mfa\Firewall;

/**
 * What the firewall decided about one address, and why.
 */
class Verdict
{
    public const DENIED = 'blocked_ip';

    public const TOO_MANY_FAILURES = 'blocked_attempts';

    public const COUNTRY = 'blocked_country';

    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason = null,
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    public static function refuse(string $reason): self
    {
        return new self(false, $reason);
    }
}
