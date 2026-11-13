# Laravel MFA

Login that survives an outage. Give every Laravel guard more than one way in: a mailed link and code, a text message, an authenticator app, recovery codes or a password. The package warns you when all of them lean on the same outside service, and gives you a controlled emergency mode for the day a provider goes down.

> **Status:** in development, not released yet. The core described here is built and tested; Livewire screens, passkeys and the documentation site follow before 1.0.

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

It works per guard and next to Laravel Fortify. Fortify serves one guard; list the other guards (for example `staff`) here.

## Requirements

PHP 8.2 or newer and Laravel 11, 12 or 13.

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

Use it from your own login screen:

```php
use Darvis\Mfa\Facades\Mfa;

// Send the mail. Says nothing about whether the address exists.
Mfa::request('staff', $email);

// Sign in with the code from the mail (or a text message).
$user = Mfa::loginWithCode('staff', $email, $code);

// Sign in with an authenticator code, a recovery code or a password.
$user = Mfa::loginWithSecret('staff', $email, 'authenticator', $code);
```

Each login method returns the user on success and `null` otherwise, with one answer for every way it can fail. `Darvis\Mfa\Exceptions\ThrottledException` is thrown after too many tries.

The link in the mail is handled by the package (`/mfa/link/{token}`) and lands on the guard's `home`.

## Check your setup

```bash
php artisan mfa:check
```

Shows per guard whether login survives the loss of one service, and how many users have set up a fallback. Add `--strict` in CI to fail when a guard is at risk.

## When a provider is down

```bash
php artisan mfa:emergency mail --until=18:00    # skip mail channels until 18:00
php artisan mfa:emergency                       # show what is active
php artisan mfa:emergency mail --off            # end it now
php artisan mfa:link staff jan@example.com      # one time link for one user
```

Users with another working channel keep using it. Only with `MFA_EMERGENCY_PASSWORD_ONLY=true`, and only for users without any working channel, a password alone is accepted. An emergency ends by itself, lasts at most `emergency.max_hours` and every login during it is logged.

## Documentation

See [docs/](docs/), including the [migration guides](docs/migrating/) for moving an existing login to this package.

## Support the package

If this package helps you, a star on [GitHub](https://github.com/ArvidDeJong/laravel-mfa) helps others find it.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: see [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
