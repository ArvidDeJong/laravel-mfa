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
