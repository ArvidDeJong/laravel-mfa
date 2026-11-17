<?php

namespace Darvis\Mfa\Firewall;

use Darvis\Mfa\Contracts\CountryResolver;

/**
 * Reads the country from a request header set by a proxy in front of the
 * application, such as Cloudflare's CF-IPCountry.
 *
 * Only use it when every request really passes that proxy: a visitor who
 * reaches the server directly can send the header themselves. The header
 * belongs to the current request, so it only answers for the visitor's own
 * address.
 */
class HeaderCountryResolver implements CountryResolver
{
    public function __construct(private readonly string $header) {}

    public function country(?string $ip): ?string
    {
        if ($ip === null || $ip !== request()->ip()) {
            return null;
        }

        $code = strtoupper(trim((string) request()->header($this->header)));

        // Cloudflare answers XX for an unknown country and T1 for Tor.
        return preg_match('/^[A-Z]{2}$/', $code) === 1 && $code !== 'XX' ? $code : null;
    }
}
