---
title: "From a hand written staff login by mail"
description: "Move a second guard that signs in with a mailed code or link to darvis/laravel-mfa, next to Laravel Fortify."
nav_order: 1
---

# From a hand written staff login by mail

For an application where Laravel Fortify serves the `web` guard and a second guard (here `staff`) signs in with a mailed link, a mailed code or both, through classes written in the application itself.

Written so that an AI coding agent can carry it out step by step. Do the steps in order and run the check at the end.

## 1. Recognise the situation

This guide applies when all of these are true:

1. `config/auth.php` has a second session guard, such as `staff`.
2. `config/fortify.php` exists and its `guard` is **not** that second guard.
3. `app/` holds a model for pending logins on that guard. Common names: `StaffLoginToken`, `LoginToken`, `LoginCode`, `UserLoginLink`.
4. A Livewire component or controller calls `Auth::guard('staff')->login(...)` after checking that token or code.

Write down, for later steps:

1. the guard name;
2. the login component or controller;
3. the token model, its table and its migration;
4. the mail or notification class that sends the code;
5. the login log model, if there is one (for example `StaffLoginAttempt`);
6. the named route of the mailed link, and where a login redirects to.

## 2. Install

```bash
composer require darvis/laravel-mfa
php artisan vendor:publish --tag=mfa-config
php artisan migrate
```

## 3. Configure the guard

In `config/mfa.php`, replace the `guards` array. Leave out the guard that Fortify serves.

```php
'guards' => [
    'staff' => [
        'channels' => ['mail', 'authenticator', 'recovery'],
        'home' => '/cms',
        'identifier' => 'email',
        'remember' => true,
    ],
],
```

Set `home` to the path a login redirects to today and `remember` to what the old code passed to `login()`.

When the old code used another lifetime or number of tries, set `challenge.lifetime_minutes` and `challenge.max_attempts` to match.

## 4. Replace the login logic

In the login component or controller, replace the old calls. Keep the screen, its fields and its texts.

| Old code | New code |
| --- | --- |
| Create a token and send the mail | `Mfa::request('staff', $email);` |
| Look up the token, compare the code, call `login()` | `$user = Mfa::loginWithCode('staff', $email, $code);` |
| Rate limiter calls around both | Remove; catch `ThrottledException` instead |
| Writing to the login log | Remove; the package logs every attempt |
| Check on `is_active` | Remove; the package refuses inactive accounts |

A complete Livewire example:

```php
use Darvis\Mfa\Exceptions\ThrottledException;
use Darvis\Mfa\Facades\Mfa;
use Illuminate\Validation\ValidationException;

public function requestCode(): void
{
    $this->validateOnly('email');

    try {
        Mfa::request('staff', $this->email);
    } catch (ThrottledException $exception) {
        throw ValidationException::withMessages([
            'email' => 'Te veel pogingen. Probeer het over '.ceil($exception->seconds / 60).' minuten opnieuw.',
        ]);
    }

    // The same message whether or not the address has an account.
    $this->sent = true;
}

public function signInWithCode(): void
{
    $this->validate();

    try {
        $user = Mfa::loginWithCode('staff', $this->email, $this->code);
    } catch (ThrottledException $exception) {
        throw ValidationException::withMessages([
            'code' => 'Te veel pogingen. Probeer het over '.ceil($exception->seconds / 60).' minuten opnieuw.',
        ]);
    }

    if ($user === null) {
        throw ValidationException::withMessages([
            'code' => 'Deze code klopt niet of is verlopen. Vraag zo nodig een nieuwe aan.',
        ]);
    }

    $this->redirectIntended(route('cms.dashboard'), navigate: true);
}
```

The package regenerates the session, marks the password as confirmed and sets `last login` in its own log. If the application keeps a `last_login_at` column, set it after a successful login.

## 5. Remove the old link route

The link in the mail now points at the package (`/mfa/link/{token}`), which shows a confirm button and redirects to `home`.

1. Remove the old link route and the `mount($token)` branch (or controller method) that handled it.
2. Search the application for the old route name and remove the leftovers.

## 6. Delete what is no longer used

1. The token model and its factory.
2. The mail or notification class and its view.
3. Add a migration that drops the old token table. Do not edit the old migration.
4. Tests of the deleted classes. Rewrite the login tests against the `Mfa` facade (see the skill for a test example).

Keep the old login log table when its history matters. New attempts are in `mfa_login_attempts`.

## 7. Let staff set up a fallback

Mail alone is exactly what failed on 13 November 2026. On the account page of the guard, add:

1. authenticator setup with `Enrollment::startAuthenticator()`, `authenticatorUri()` and `confirmAuthenticator()`;
2. recovery codes with `Enrollment::generateRecoveryCodes()`, shown once.

On the login screen, offer the field for an authenticator or recovery code to every visitor, not only to addresses that have one set up. Otherwise the screen reveals who uses what.

## 8. Check

```bash
php artisan mfa:check --strict
php artisan test
```

`mfa:check` must report the guard as `OK`. Then test by hand: request a mail, sign in with the code, sign out, sign in with the link.
