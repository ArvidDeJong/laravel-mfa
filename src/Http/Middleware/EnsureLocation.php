<?php

namespace Darvis\Mfa\Http\Middleware;

use Closure;
use Darvis\Mfa\Firewall\Firewall;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens the routes behind it only on site: from an address in
 * "firewall.locations" or under a firewall rule marked as a location.
 *
 * Use as "mfa.location" or, for the locations of one guard,
 * "mfa.location:staff". It does not need the firewall to be switched on.
 */
class EnsureLocation
{
    public function __construct(private readonly Firewall $firewall) {}

    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        abort_unless($this->firewall->isLocation($request->ip(), $guard), 403, __('mfa::mfa.location_only'));

        return $next($request);
    }
}
