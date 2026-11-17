# Laravel MFA: two factor authentication, magic link login and a login firewall for every guard

`darvis/laravel-mfa` is a Laravel package for multi factor authentication (MFA, also called two factor authentication or 2FA) and passwordless login. It gives every Laravel guard more than one way to sign in: a magic link and a one time code by email, a code by text message, an authenticator app (TOTP, such as Google Authenticator, Microsoft Authenticator or 1Password), recovery codes, a password or a PIN at the office. A built in login firewall adds an IP allow list, a deny list, a country filter and a block after too many failed logins.

What sets it apart: login that survives an outage. The package warns you when every way in leans on the same outside service, and gives you a controlled emergency mode for the day your mail or SMS provider goes down.

> **Status:** stable since 1.0.0. Passkeys follow in a later release.

Full documentation: **https://arviddejong.github.io/laravel-mfa/**

## In short

| Question | Answer |
| --- | --- |
| What is it for? | Signing people in to a Laravel website or CMS with a second factor, or without a password at all |
| Who is it for? | Any Laravel developer, from a first project to an agency with a `staff` guard next to normal users |
| What does it need? | PHP 8.2 or newer and Laravel 11, 12 or 13. Livewire and Flux are optional, for the ready made screens |
| What does it cost? | Nothing. MIT licence |
| Does it replace Laravel Fortify? | No. It works next to Fortify, for the guards Fortify does not serve |

## Contents

1. [Why this package exists](#why-this-package-exists)
2. [What it does](#what-it-does)
3. [Requirements](#requirements)
4. [Quick start](#quick-start)
5. [Documentation](#documentation)
6. [Laravel Boost](#laravel-boost)

## Why this package exists

On 12 and 13 November 2026 a worldwide Microsoft 365 outage stopped email delivery for many organisations. Every application that relied on a magic link or an emailed code had the same problem that day: the application itself was healthy, but nobody could log in.

That outage is the reason this package exists. Login should never depend on one single service.

## What it does

| Channel | Depends on | Works during a mail outage |
| --- | --- | --- |
| `mail` (code and link in one message) | mail | no |
| `sms` | SMS provider | yes |
| `authenticator` (TOTP app) | nothing | yes |
| `recovery` (one time codes) | nothing | yes |
| `password` | nothing | yes |
| `pin` (on site only) | nothing | yes |

Around those channels:

1. A resilience check, `php artisan mfa:check`, that tells you which guards would be locked out when one service fails.
2. An emergency mode with an end time, for the day a provider is down.
3. A login firewall: allow and deny list, a block after failed logins, a country filter per guard, on site locations.
4. Screens for the login, the user's security settings, a sign-in overview, the firewall and the login log, when Livewire and Flux are installed.
5. A Laravel Boost guideline and skill, and migration guides written for AI coding agents.

It works per guard and next to Laravel Fortify. Fortify serves one guard; list the other guards (for example `staff`) here.

## Requirements

PHP 8.2 or newer and Laravel 11, 12 or 13.

The package signs people in on session guards, the kind a website and a CMS use. It is not for a token API such as Laravel Sanctum's.

## Quick start

```bash
composer require darvis/laravel-mfa
php artisan vendor:publish --tag=mfa-config
php artisan migrate
```

Tell the package which guards it serves, in `config/mfa.php`:

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

The fastest way to a working login, with Livewire and Flux installed, is the screen the package brings. One route is enough:

```php
use Darvis\Mfa\Livewire\Login;

Route::get('cms/login', Login::class)->defaults('guard', 'staff')->name('cms.login');
```

Or use the facade from your own login screen:

```php
use Darvis\Mfa\Facades\Mfa;

// Send the mail. Says nothing about whether the address exists.
Mfa::request('staff', $email);

// Sign in with the code from the mail (or a text message).
$user = Mfa::loginWithCode('staff', $email, $code);

// Sign in with an authenticator code, a recovery code or a password.
$user = Mfa::loginWithSecret('staff', $email, 'authenticator', $code);
```

Each login method returns the user on success and `null` otherwise, with one answer for every way it can fail.

Check the setup:

```bash
php artisan mfa:check
```

## Documentation

Full documentation: **https://arviddejong.github.io/laravel-mfa/**

1. [Installation](docs/installation.md): requirements, the steps, and how to check that it works
2. [Your first login screen](docs/first-login.md): a working login with one route, or your own screen on the facade
3. [Channels](docs/channels.md): mail, text message, authenticator app, recovery codes, password and PIN
4. [Password plus a second step](docs/two-step.md): a login in two steps, and switching a channel off for one account
5. [Firewall](docs/firewall.md): allow and deny list, the block after failed logins, the country filter, locations and PIN
6. [Screens for your admin](docs/admin.md): who is blocked, which countries may sign in and which methods each user has
7. [When a provider is down](docs/outage.md): the resilience check, the emergency mode and a one time link
8. [Configuration](docs/configuration.md): every setting, the hooks for your own mail and link route, and the events
9. [Commands](docs/commands.md): every Artisan command with an example
10. [Testing](docs/testing.md): test a login that uses the package in your own application
11. [Security](docs/security.md): what is stored, how tries are limited and what an attacker can learn
12. [Troubleshooting](docs/troubleshooting.md): no mail arrives, everybody is refused, and every error message
13. [Migrating](docs/migrating.md): guides for moving an existing login or firewall to the package
14. [FAQ](docs/faq.md): short answers to common questions

## Laravel Boost

The package ships a Boost guideline and the `laravel-mfa-development` skill in `resources/boost`. Boost only installs those of third party packages listed under `packages` in your `boost.json`:

```json
{
    "packages": [
        "darvis/laravel-mfa"
    ]
}
```

Then run `php artisan boost:update`. An interactive `php artisan boost:install` or `boost:update` offers the package and writes that line for you; `boost:update` from a Composer script or with `--no-interaction` never adds a new package by itself. The skill also needs skills switched on in Boost. Boost reads only direct dependencies, so require the package in the application's own `composer.json`.

## Support the package

If darvis/laravel-mfa saves you time, buy me a beer 🍺: [sponsor me on GitHub](https://github.com/sponsors/ArvidDeJong). A star on [GitHub](https://github.com/ArvidDeJong/laravel-mfa) helps others find it.

## Maintainer

Built and maintained by [Arvid de Jong](https://github.com/ArvidDeJong) of [ARVID.NL](https://arvid.nl).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: see [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
