---
title: "Password plus a second step"
nav_order: 5
description: "Build a Laravel login in two steps with darvis/laravel-mfa: check a password without signing in, then sign in with a mailed code, and switch a channel off for one account."
---

# Password plus a second step

A login in two steps asks for something the user knows and then for something the user has: a password followed by a mailed code, for example.

## Check the first step without signing in

`Mfa::checkSecret()` checks a password, PIN, authenticator code or recovery code and returns the user, without signing in.

```php
use Darvis\Mfa\Facades\Mfa;

// Screen one: the password.
if (Mfa::checkSecret('staff', $email, 'password', $password) !== null) {
    Mfa::request('staff', $email, 'mail');

    session(['login.email' => $email]);
}

// Always go to screen two, whatever the outcome.
```

```php
// Screen two: the code from the mail.
$user = Mfa::loginWithCode('staff', session('login.email'), $code);
```

A wrong first step counts towards the rate limit and towards the firewall block, and it is written to the login log as a failed try. A right one is logged as `verified`.

Go to the second screen whatever the first step said. A screen that only moves on for a right password tells an attacker which passwords are right.

The package does not hold the two steps together for you: your application decides that the second screen only works after the first. Keep what the first step proved in the session, as above.

## Switch a channel off for one account

Sometimes one account should not use a channel the guard offers. An account with a passkey that must no longer get a mailed code, for example. Implement `Darvis\Mfa\Contracts\RestrictsChannels` on the model:

```php
use Darvis\Mfa\Contracts\RestrictsChannels;

class Staff extends Authenticatable implements RestrictsChannels
{
    public function mfaAllowsChannel(string $channel, string $guard): bool
    {
        return ! ($channel === 'mail' && $this->signs_in_with_passkey_only);
    }
}
```

For a channel that is switched off:

1. nothing is sent;
2. every code and link fails, also one that was mailed before the channel was switched off;
3. the screen looks the same as for an unknown address;
4. the login log says `not_allowed`, so you can see what happened.

## Related

1. [Channels](channels.md) lists every channel and how a user sets it up.
2. [Security](security.md) explains how tries are limited.
