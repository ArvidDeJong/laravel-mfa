<?php

namespace Darvis\Mfa\Firewall;

use Darvis\Mfa\Contracts\CountryResolver;
use Darvis\Mfa\Exceptions\ConfigurationException;
use GeoIp2\Database\Reader;
use Throwable;

/**
 * Looks the country up in a local MaxMind format database, such as DB-IP's
 * free IP to Country Lite ("php artisan mfa:geoip-update"). No request leaves
 * the server.
 *
 * A missing or broken file is reported once per request and reads as "no
 * country"; the firewall turns that into a refusal.
 */
class DatabaseCountryResolver implements CountryResolver
{
    private ?Reader $reader = null;

    private bool $failed = false;

    public function __construct(private readonly string $path)
    {
        if (! class_exists(Reader::class)) {
            throw new ConfigurationException('The "database" country driver needs the geoip2/geoip2 package: composer require geoip2/geoip2.');
        }
    }

    public function country(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        try {
            return $this->reader()?->country($ip)->country->isoCode;
        } catch (Throwable) {
            // An address the database does not know, or one that is no address.
            return null;
        }
    }

    /**
     * The type the file says it is, such as "DBIP-Country-Lite", or null when
     * it cannot be opened.
     */
    public function databaseType(): ?string
    {
        return $this->reader()?->metadata()->databaseType;
    }

    private function reader(): ?Reader
    {
        if ($this->reader instanceof Reader || $this->failed) {
            return $this->reader;
        }

        try {
            return $this->reader = new Reader($this->path);
        } catch (Throwable $exception) {
            $this->failed = true;

            report(new ConfigurationException("The country database [{$this->path}] cannot be read. Run php artisan mfa:geoip-update.", previous: $exception));

            return null;
        }
    }
}
