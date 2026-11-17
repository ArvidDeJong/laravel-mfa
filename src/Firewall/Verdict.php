<?php

namespace Darvis\Mfa\Firewall;

use Carbon\CarbonInterface;

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
        public readonly ?CarbonInterface $until = null,
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    /**
     * @param  CarbonInterface|null  $until  When the refusal ends by itself, for a block after failed logins.
     */
    public static function refuse(string $reason, ?CarbonInterface $until = null): self
    {
        return new self(false, $reason, $until);
    }
}
