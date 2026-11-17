<?php

namespace Darvis\Mfa\Firewall;

use Darvis\Mfa\Contracts\CountryResolver;

/**
 * Knows no country at all, for an application without a country source.
 */
class NullCountryResolver implements CountryResolver
{
    public function country(?string $ip): ?string
    {
        return null;
    }
}
