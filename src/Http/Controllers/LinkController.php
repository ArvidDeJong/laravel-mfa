<?php

namespace Darvis\Mfa\Http\Controllers;

use Darvis\Mfa\Exceptions\BlockedException;
use Darvis\Mfa\Mfa;
use Darvis\Mfa\Support\MfaConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * Where a mailed link lands.
 */
class LinkController extends Controller
{
    /**
     * Show the confirm button, or sign in straight away when confirming is
     * switched off.
     */
    public function show(Mfa $mfa, string $token): Response|RedirectResponse
    {
        if (! MfaConfig::linkConfirm()) {
            return $this->signIn($mfa, $token);
        }

        $usable = $mfa->linkIsUsable($token);

        return response()->view('mfa::link', ['token' => $token, 'usable' => $usable], $usable ? 200 : 410);
    }

    public function store(Mfa $mfa, string $token): Response|RedirectResponse
    {
        return $this->signIn($mfa, $token);
    }

    private function signIn(Mfa $mfa, string $token): Response|RedirectResponse
    {
        try {
            $user = $mfa->loginWithLink($token);
        } catch (BlockedException $exception) {
            abort(403, $exception->getMessage());
        }

        if ($user === null) {
            return response()->view('mfa::link', ['token' => $token, 'usable' => false], 410);
        }

        foreach (MfaConfig::guards() as $guard) {
            if (Auth::guard($guard)->user() === $user) {
                return redirect()->intended(MfaConfig::home($guard));
            }
        }

        return redirect()->intended('/');
    }
}
