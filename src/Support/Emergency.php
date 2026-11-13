<?php

namespace Darvis\Mfa\Support;

use Carbon\CarbonInterface;
use Darvis\Mfa\Exceptions\EmergencyException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Knows which outside services are declared down, and until when.
 *
 * A declaration comes from config/mfa.php or from the mfa:emergency command
 * (kept in the cache, so it needs no deploy). Either way it fails closed: a
 * moment that cannot be read, is already past or lies further ahead than
 * "emergency.max_hours" counts as no declaration at all.
 */
class Emergency
{
    private const CACHE_PREFIX = 'mfa:emergency:';

    public function __construct(private readonly Repository $cache) {}

    /**
     * Until when a service is unavailable, or null when it is not.
     */
    public function unavailableUntil(string $dependency): ?CarbonInterface
    {
        $candidates = array_filter([
            $this->parse(MfaConfig::emergencyUnavailableUntil($dependency)),
            $this->parse($this->cache->get(self::CACHE_PREFIX.$dependency)),
        ]);

        if ($candidates === []) {
            return null;
        }

        return collect($candidates)->sortBy(fn (CarbonInterface $until) => $until->getTimestamp())->last();
    }

    public function isUnavailable(?string $dependency): bool
    {
        return $dependency !== null && $this->unavailableUntil($dependency) !== null;
    }

    /**
     * Declare a service down until a moment in time.
     *
     * @throws EmergencyException When the moment is past or beyond the maximum.
     */
    public function declare(string $dependency, CarbonInterface $until): void
    {
        if ($until->isPast()) {
            throw new EmergencyException('The end of an emergency must lie in the future.');
        }

        if ($until->greaterThan($this->limit())) {
            throw new EmergencyException('An emergency may last at most '.MfaConfig::emergencyMaxHours().' hours.');
        }

        $this->cache->put(self::CACHE_PREFIX.$dependency, $until->toIso8601String(), $until);
    }

    public function clear(string $dependency): void
    {
        $this->cache->forget(self::CACHE_PREFIX.$dependency);
    }

    private function limit(): CarbonInterface
    {
        return now()->addHours(MfaConfig::emergencyMaxHours());
    }

    private function parse(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $until = Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }

        return $until->isPast() || $until->greaterThan($this->limit()) ? null : $until;
    }
}
