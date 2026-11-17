---
title: "Configuration"
nav_order: 9
description: "Every setting in config/mfa.php of darvis/laravel-mfa, the environment variables, the hooks for your own login mail and link route, and the events it fires."
---

# Configuration

```bash
php artisan vendor:publish --tag=mfa-config
```

A published `config/mfa.php` only needs what differs from the defaults. Each group is merged with the package defaults. `guards` is the exception and is taken from your config as a whole.

Run `php artisan config:clear` after a change, or `php artisan config:cache` again on a server.

## guards

One entry per guard the package serves.

| Key | Default for `web` | Meaning |
| --- | --- | --- |
| `guards.<guard>.channels` | `mail`, `authenticator`, `recovery` | The ways to sign in, see [Channels](channels.md) |
| `guards.<guard>.countries` | `null` | Countries this guard may sign in from. `null` follows `firewall.countries`, `[]` is no filter |
| `guards.<guard>.home` | `/` | Where a mailed link lands |
| `guards.<guard>.identifier` | `email` | The column a user signs in with |
| `guards.<guard>.layout` | `null` | The Blade layout of the screens for this guard |
| `guards.<guard>.remember` | `false` | Remember a login after the browser closes |

## challenge

| Key | Default | Meaning |
| --- | --- | --- |
| `challenge.code_length` | 6 | Digits in a mailed or texted code |
| `challenge.lifetime_minutes` | 15 | How long a code and link work |
| `challenge.max_attempts` | 5 | Wrong tries before a code is burned |
| `challenge.send_after_response` | true | Send the mail or text message after the response, so the screen answers as fast for an address that exists as for one that does not. Needs no queue worker. Commands and tests send at once |

## link

| Key | Default | Meaning |
| --- | --- | --- |
| `link.confirm` | true | Show a button before signing in with a link |
| `link.enabled` | true | Register the package route for the link |
| `link.middleware` | `web` | Middleware of that route |
| `link.prefix` | `mfa` | The route is `/<prefix>/link/{token}` |

## rate_limit

| Key | Default | Meaning |
| --- | --- | --- |
| `rate_limit.attempts` | 5 | Tries per action, guard, account and address |
| `rate_limit.decay_seconds` | 60 | The time those tries are counted in |
| `rate_limit.account_attempts` | 20 | Wrong codes and secrets for one account over every address. 0 switches it off |
| `rate_limit.account_decay_seconds` | 900 | The time those are counted in |

## firewall

See [Firewall](firewall.md).

| Key | Default | Env |
| --- | --- | --- |
| `firewall.enabled` | false | `MFA_FIREWALL` |
| `firewall.allow` | none | |
| `firewall.deny` | none | |
| `firewall.locations` | none | |
| `firewall.block_after_attempts` | 5 | |
| `firewall.block_minutes` | 15 | |
| `firewall.ipv6_prefix` | 64 | |
| `firewall.countries` | none | |
| `firewall.allow_unknown_country` | false | |
| `firewall.country_driver` | `database` | `MFA_COUNTRY_DRIVER` |
| `firewall.country_database` | `storage/app/mfa/country.mmdb` | |
| `firewall.country_header` | `CF-IPCountry` | |

## emergency

See [When a provider is down](outage.md).

| Key | Default | Env |
| --- | --- | --- |
| `emergency.allow_password_only` | false | `MFA_EMERGENCY_PASSWORD_ONLY` |
| `emergency.max_hours` | 24 | |
| `emergency.unavailable_until.mail` | none | `MFA_MAIL_DOWN_UNTIL` |
| `emergency.unavailable_until.sms` | none | `MFA_SMS_DOWN_UNTIL` |

## log

| Key | Default | Meaning |
| --- | --- | --- |
| `log.enabled` | true | Write every try to `mfa_login_attempts` |
| `log.retention_days` | 90 | How long log lines and ended firewall rules are kept |

## pin, sms, totp, ui

| Key | Default | Meaning |
| --- | --- | --- |
| `pin.max_attempts` | 4 | Wrong tries before a PIN is locked |
| `pin.min_length` | 4 | Shortest PIN |
| `sms.driver` | `log` | `MFA_SMS_DRIVER`: `log`, `bird` or `messagebird` |
| `sms.drivers` | | The keys per provider, see [Channels](channels.md#text-message) |
| `sms.max_per_session` | 3 | Text messages one visitor can ask for |
| `totp.issuer` | `APP_NAME` | The name an authenticator app shows |
| `totp.window` | 1 | Steps before and after the current one that are accepted |
| `ui.layout` | `null` | The Blade layout for screens of a guard without its own |

## Send the mail yourself

The package sends a plain notification. To send a Mailable in your own design, register a callback in a service provider:

```php
use Darvis\Mfa\Facades\Mfa;
use Darvis\Mfa\Support\IssuedChallenge;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Mail;

Mfa::sendMailUsing(function (Authenticatable $user, IssuedChallenge $issued): void {
    Mail::to($user->email)->send(new LoginCodeMail(
        code: $issued->code,
        url: $issued->url(),
        minutes: $issued->minutes(),
    ));
});
```

`$issued->url()` is `null` when the channel carries no link.

## Land the link on your own route

```php
Mfa::linkUrlUsing(fn (string $token) => route('login.link', $token));
```

In that route call `Mfa::loginWithLink($token)`, and set `link.enabled` to false so the package route is not registered. `Mfa::linkIsUsable($token)` tells whether a link still works, without using it.

## Events

| Event | When | Carries |
| --- | --- | --- |
| `Darvis\Mfa\Events\LoginAttempted` | After every try, right or wrong | `guard`, `result`, `channel`, `identifier`, `user`, `method`, and `succeeded()` |
| `Darvis\Mfa\Events\ChallengeSent` | After a mail or text message went out | `challenge`, `user` |

An application with its own login log listens to `LoginAttempted` and sets `log.enabled` to false.

The values of `result`:

| Result | Meaning |
| --- | --- |
| `succeeded` | Signed in |
| `verified` | A first step was right, without signing in |
| `invalid` | Wrong code, secret or link |
| `expired` | The code or link ran out or was used before |
| `unknown_account` | No account for that address |
| `inactive` | The account is not active |
| `not_allowed` | The account switched the channel off |
| `throttled` | Too many tries |
| `blocked_ip`, `blocked_attempts`, `blocked_country` | The firewall refused the address |
| `emergency`, `emergency_link_issued` | A login or a link during an emergency |

## Translations and views

```bash
php artisan vendor:publish --tag=mfa-lang
php artisan vendor:publish --tag=mfa-views
```

English and Dutch ship with the package.
