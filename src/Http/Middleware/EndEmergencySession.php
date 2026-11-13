<?php

namespace Darvis\Mfa\Http\Middleware;

use Closure;
use Darvis\Mfa\Contracts\Channel;
use Darvis\Mfa\Mfa;
use Darvis\Mfa\Support\Emergency;
use Darvis\Mfa\Support\MfaConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a session that began with only a password during an emergency, as soon
 * as the emergency is over. The user then signs in the normal way again.
 *
 * Add the "mfa.emergency" alias to the routes behind your login.
 */
class EndEmergencySession
{
    public function __construct(
        private readonly Mfa $mfa,
        private readonly Emergency $emergency,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        foreach (MfaConfig::guards() as $guard) {
            $key = Mfa::SESSION_EMERGENCY.$guard;

            if (! $request->session()->has($key) || $this->emergencyContinues($guard)) {
                continue;
            }

            $request->session()->forget($key);

            Auth::guard($guard)->logout();

            return redirect()->guest(MfaConfig::home($guard));
        }

        return $next($request);
    }

    private function emergencyContinues(string $guard): bool
    {
        return array_filter(
            $this->mfa->channels($guard),
            fn (Channel $channel) => $this->emergency->isUnavailable($channel->dependency()),
        ) !== [];
    }
}
