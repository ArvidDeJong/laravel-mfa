<?php

namespace Darvis\Mfa\Firewall;

use Darvis\Mfa\Contracts\CountryResolver;
use Darvis\Mfa\Support\MfaConfig;
use Illuminate\Support\Manager;

/**
 * Picks the source of "which country is this address in". Register a source
 * of your own with:
 *
 *     app(CountryManager::class)->extend('ipinfo', fn () => new IpinfoResolver);
 *
 * @mixin CountryResolver
 */
class CountryManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return MfaConfig::countryDriver();
    }

    protected function createDatabaseDriver(): CountryResolver
    {
        return new DatabaseCountryResolver(MfaConfig::countryDatabase());
    }

    protected function createHeaderDriver(): CountryResolver
    {
        return new HeaderCountryResolver(MfaConfig::countryHeader());
    }

    protected function createNoneDriver(): CountryResolver
    {
        return new NullCountryResolver;
    }
}
