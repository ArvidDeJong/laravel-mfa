<?php

namespace Darvis\Mfa\Http\Middleware;

use Closure;
use Darvis\Mfa\Firewall\Firewall;
use Darvis\Mfa\Mfa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request from an address the firewall does not let in, and signs
 * out whoever was signed in on the guard (and only on that guard): a session that began at the office
 * does not keep working from somewhere it may not. The block for failed
 * logins does not count here: it stops new logins, and one colleague with a
 * wrong password should not sign out the whole office.
 *
 * Use as "mfa.firewall" or, for one guard, "mfa.firewall:staff".
 */
class EnforceFirewall
{
    public function __construct(
        private readonly Firewall $firewall,
        private readonly Mfa $mfa,
    ) {}

    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        $verdict = $this->firewall->decide($request->ip(), $guard, ignoreBlock: true);

        if ($verdict->allowed) {
            return $next($request);
        }

        $user = $guard === null ? null : Auth::guard($guard)->user();

        $this->mfa->record($guard ?? '', (string) $verdict->reason, null, null, $user);

        if ($guard !== null && $user !== null) {
            Auth::guard($guard)->logout();

            // A new session id and token, but the session itself stays: a
            // login on another guard in the same browser is not this one's
            // to end.
            if ($request->hasSession()) {
                $request->session()->regenerate(true);
                $request->session()->regenerateToken();
            }
        }

        abort(403, __('mfa::mfa.'.$verdict->reason));
    }
}
