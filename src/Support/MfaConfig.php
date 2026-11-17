<?php

namespace Darvis\Mfa\Support;

/**
 * The one place that reads the package config.
 *
 * Every default lives in config/mfa.php and nowhere else. Callers ask a named
 * question here instead of carrying their own fallback, so a default cannot
 * quietly disagree with the config file.
 */
class MfaConfig
{
    /**
     * The guards this package serves.
     *
     * @return list<string>
     */
    public static function guards(): array
    {
        $guards = config('mfa.guards');

        return is_array($guards) ? array_map('strval', array_keys($guards)) : [];
    }

    public static function hasGuard(string $guard): bool
    {
        return in_array($guard, self::guards(), true);
    }

    /**
     * The channel names configured for a guard, in the order they are offered.
     *
     * @return list<string>
     */
    public static function channels(string $guard): array
    {
        $channels = config("mfa.guards.{$guard}.channels");

        return is_array($channels) ? array_values(array_map('strval', $channels)) : [];
    }

    public static function home(string $guard): string
    {
        return (string) config("mfa.guards.{$guard}.home", '/');
    }

    public static function identifier(string $guard): string
    {
        return (string) config("mfa.guards.{$guard}.identifier", 'email');
    }

    /**
     * The Blade layout the screens of a guard render in: the guard's own, else
     * the general one, else null for Livewire's default.
     */
    public static function layout(?string $guard = null): ?string
    {
        $layout = $guard === null ? null : config("mfa.guards.{$guard}.layout");
        $layout = is_string($layout) && $layout !== '' ? $layout : config('mfa.ui.layout');

        return is_string($layout) && $layout !== '' ? $layout : null;
    }

    public static function remember(string $guard): bool
    {
        return config("mfa.guards.{$guard}.remember") === true;
    }

    public static function codeLength(): int
    {
        return max(4, (int) config('mfa.challenge.code_length'));
    }

    public static function lifetimeMinutes(): int
    {
        return max(1, (int) config('mfa.challenge.lifetime_minutes'));
    }

    public static function sendAfterResponse(): bool
    {
        return config('mfa.challenge.send_after_response') !== false;
    }

    public static function maxAttempts(): int
    {
        return max(1, (int) config('mfa.challenge.max_attempts'));
    }

    public static function emergencyAllowPasswordOnly(): bool
    {
        return config('mfa.emergency.allow_password_only') === true;
    }

    public static function emergencyMaxHours(): int
    {
        return max(1, (int) config('mfa.emergency.max_hours'));
    }

    /**
     * The raw "unavailable until" value for a dependency, unparsed.
     */
    public static function emergencyUnavailableUntil(string $dependency): ?string
    {
        $value = config("mfa.emergency.unavailable_until.{$dependency}");

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public static function firewallEnabled(): bool
    {
        return config('mfa.firewall.enabled') === true;
    }

    /**
     * A fixed list of addresses or CIDR ranges: "allow", "deny" or "locations".
     *
     * @return list<string>
     */
    public static function firewallList(string $list): array
    {
        $ranges = config("mfa.firewall.{$list}");

        return is_array($ranges) ? array_values(array_filter(array_map('strval', $ranges), fn (string $range) => $range !== '')) : [];
    }

    /**
     * The ISO country codes that may sign in, in upper case: the list of the
     * guard when it has one of its own, else the general list. Empty means no
     * country filter.
     *
     * @return list<string>
     */
    public static function firewallCountries(?string $guard = null): array
    {
        $countries = $guard === null ? null : config("mfa.guards.{$guard}.countries");
        $countries = is_array($countries) ? $countries : config('mfa.firewall.countries');

        return is_array($countries) ? array_values(array_map(fn ($code) => strtoupper((string) $code), $countries)) : [];
    }

    /**
     * Whether any guard, or the general list, filters on country.
     */
    public static function firewallFiltersCountries(): bool
    {
        if (self::firewallCountries() !== []) {
            return true;
        }

        foreach (self::guards() as $guard) {
            if (self::firewallCountries($guard) !== []) {
                return true;
            }
        }

        return false;
    }

    public static function firewallAllowUnknownCountry(): bool
    {
        return config('mfa.firewall.allow_unknown_country') === true;
    }

    public static function firewallBlockAfterAttempts(): int
    {
        return max(1, (int) config('mfa.firewall.block_after_attempts'));
    }

    /**
     * The size of the IPv6 network that failed logins count and block.
     */
    public static function firewallIpv6Prefix(): int
    {
        return min(128, max(16, (int) (config('mfa.firewall.ipv6_prefix') ?? 64)));
    }

    public static function firewallBlockMinutes(): int
    {
        return max(1, (int) config('mfa.firewall.block_minutes'));
    }

    public static function countryDriver(): string
    {
        return (string) config('mfa.firewall.country_driver');
    }

    public static function countryDatabase(): string
    {
        return (string) config('mfa.firewall.country_database');
    }

    public static function countryHeader(): string
    {
        return (string) config('mfa.firewall.country_header');
    }

    public static function pinMaxAttempts(): int
    {
        return max(1, (int) config('mfa.pin.max_attempts'));
    }

    public static function pinMinLength(): int
    {
        return max(4, (int) config('mfa.pin.min_length'));
    }

    public static function linkConfirm(): bool
    {
        return config('mfa.link.confirm') !== false;
    }

    /**
     * Whether the package registers its own link routes.
     */
    public static function linkEnabled(): bool
    {
        return config('mfa.link.enabled') !== false;
    }

    /**
     * A deliberately empty list is a choice; only a non-array falls back.
     *
     * @return list<string>
     */
    public static function linkMiddleware(): array
    {
        $middleware = config('mfa.link.middleware');

        return is_array($middleware) ? array_values(array_map('strval', $middleware)) : ['web'];
    }

    public static function linkPrefix(): string
    {
        return trim((string) config('mfa.link.prefix'), '/');
    }

    public static function logEnabled(): bool
    {
        return config('mfa.log.enabled') !== false;
    }

    public static function logRetentionDays(): int
    {
        return max(1, (int) config('mfa.log.retention_days'));
    }

    /**
     * Wrong tries allowed per account over every address; 0 is off.
     */
    public static function rateLimitAccountAttempts(): int
    {
        return max(0, (int) config('mfa.rate_limit.account_attempts'));
    }

    public static function rateLimitAccountDecaySeconds(): int
    {
        return max(1, (int) config('mfa.rate_limit.account_decay_seconds'));
    }

    public static function rateLimitAttempts(): int
    {
        return max(1, (int) config('mfa.rate_limit.attempts'));
    }

    public static function rateLimitDecaySeconds(): int
    {
        return max(1, (int) config('mfa.rate_limit.decay_seconds'));
    }

    public static function smsDriver(): string
    {
        return (string) config('mfa.sms.driver');
    }

    /**
     * One setting of an SMS driver; an empty string counts as not set.
     */
    public static function smsDriverSetting(string $driver, string $key): ?string
    {
        $value = config("mfa.sms.drivers.{$driver}.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function smsMaxPerSession(): int
    {
        return max(0, (int) config('mfa.sms.max_per_session'));
    }

    public static function totpIssuer(): string
    {
        return (string) config('mfa.totp.issuer');
    }

    public static function totpWindow(): int
    {
        return max(0, (int) config('mfa.totp.window'));
    }
}
