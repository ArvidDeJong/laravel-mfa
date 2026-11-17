<?php

namespace Darvis\Mfa\Contracts;

interface CountryResolver
{
    /**
     * The ISO 3166-1 alpha-2 code of the country an address is in, such as
     * "NL", or null when that cannot be told. Never throws.
     */
    public function country(?string $ip): ?string;
}
