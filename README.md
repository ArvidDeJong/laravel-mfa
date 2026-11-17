# Laravel MFA: two factor authentication, magic link login and a login firewall for every guard

`darvis/laravel-mfa` is a Laravel package for multi factor authentication (MFA, also called two factor authentication or 2FA) and passwordless login. It gives every Laravel guard more than one way to sign in: a magic link and a one time code by email, a code by text message, an authenticator app (TOTP, such as Google Authenticator, Microsoft Authenticator or 1Password), recovery codes, a password or a PIN at the office. A built in login firewall adds an IP allow list, a deny list, a country filter and a block after too many failed logins.

What sets it apart: login that survives an outage. The package warns you when every way in leans on the same outside service, and gives you a controlled emergency mode for the day your mail or SMS provider goes down.

> **Status:** stable since 1.0.0. Passkeys and the documentation site follow in a later release.

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
3. [New to this? The words explained](#new-to-this-the-words-explained)
4. [Quick start](#quick-start)
5. [Screens](#screens)
6. [Firewall, locations and PIN](#firewall-locations-and-pin)
7. [Check your setup](#check-your-setup)
8. [When a provider is down](#when-a-provider-is-down)
9. [Questions and answers](#questions-and-answers)
10. [Laravel Boost](#laravel-boost)

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

It works per guard and next to Laravel Fortify. Fortify serves one guard; list the other guards (for example `staff`) here.

## New to this? The words explained

| Word | What it means here |
| --- | --- |
| MFA, 2FA | Multi factor or two factor authentication: proving who you are with more than a password, for example a code from your phone |
| Guard | Laravel's name for a kind of login. `web` is the default for normal users; many sites add a second one, such as `staff` for the CMS |
| Channel | One way to sign in: `mail`, `sms`, `authenticator`, `recovery`, `password` or `pin`. You choose per guard which ones are allowed |
| Magic link | A link in an email that signs you in when you open it. No password needed |
| One time code (OTP) | A short code that works once and for a few minutes, sent by email or text message |
| Authenticator app (TOTP) | An app on your phone that shows a new code every 30 seconds. It works without internet and without email |
| Recovery codes | A list of spare codes to print or save, for when the phone is lost |
| Firewall | Rules about where a login may come from: which IP addresses and which countries |

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

Open `/cms/login`, type an email address that exists for that guard, and the code and link arrive by mail. On a development machine set `MAIL_MAILER=log` and read the mail in `storage/logs/laravel.log`.

Building the screen yourself is just as possible. Use the facade from your own login screen:

```php
use Darvis\Mfa\Facades\Mfa;

// Send the mail. Says nothing about whether the address exists.
Mfa::request('staff', $email);

// Sign in with the code from the mail (or a text message).
$user = Mfa::loginWithCode('staff', $email, $code);

// Sign in with an authenticator code, a recovery code or a password.
$user = Mfa::loginWithSecret('staff', $email, 'authenticator', $code);
```

A login in two steps, such as a password followed by a mailed code, checks the first step without signing in:

```php
if (Mfa::checkSecret('staff', $email, 'password', $password) !== null) {
    Mfa::request('staff', $email, 'mail');
}

// next screen
$user = Mfa::loginWithCode('staff', $email, $code);
```

To switch a channel off for one account, implement `Darvis\Mfa\Contracts\RestrictsChannels` on the model. Nothing is sent and every code fails for that account, with the same screen as for an unknown address.

Each login method returns the user on success and `null` otherwise, with one answer for every way it can fail. `Darvis\Mfa\Exceptions\ThrottledException` is thrown after too many tries.

Tries are limited twice: per account and address together (`rate_limit.attempts`), and per account over every address (`rate_limit.account_attempts`), so a guess spread over many addresses stops as well. The second limit never holds back asking for a mail or opening its link, so the owner of an account under attack still gets in.

The mail or text message leaves after the response is sent (`challenge.send_after_response`). The screen answers just as fast for an address that exists as for one that does not, and a slow mail server does not hold up the login. It needs no queue worker; commands and tests send at once.

The link in the mail is handled by the package (`/mfa/link/{token}`) and lands on the guard's `home`. To land it on a route of your own instead, call `Mfa::linkUrlUsing(fn (string $token) => route('login.link', $token))`, call `Mfa::loginWithLink($token)` from that route and set `'link' => ['enabled' => false]`, so the package route is not registered at all.

A published `config/mfa.php` only needs what differs from the defaults: each group is merged with the package defaults, so `'log' => ['enabled' => false]` keeps the other `log` settings. `guards` is the exception and is taken from your config as a whole.

A code or link works once. After a login it stays behind as used, so opening the link again is logged for its owner, until `php artisan mfa:prune` (schedule it daily) removes it.

## Screens

With Livewire and Flux installed the package brings four screens. Route them yourself and name the guard as a route default:

```php
use Darvis\Mfa\Livewire\FirewallRules;
use Darvis\Mfa\Livewire\Login;
use Darvis\Mfa\Livewire\LoginAttempts;
use Darvis\Mfa\Livewire\Settings;

Route::get('admin/login', Login::class)->defaults('guard', 'staff')->name('cms.login');

Route::middleware('auth:staff')->group(function () {
    Route::get('admin/security', Settings::class)->defaults('guard', 'staff');
    Route::get('admin/firewall', FirewallRules::class);
    Route::get('admin/login-attempts', LoginAttempts::class);
});
```

Tell each guard which Blade layout its screens render in:

```php
'guards' => [
    'staff' => [
        'channels' => ['mail', 'authenticator', 'recovery', 'pin'],
        'home' => '/admin',
        'identifier' => 'email',
        'layout' => 'layouts::cms-auth',
        'remember' => true,
    ],
],

'ui' => ['layout' => 'layouts::cms'],
```

The firewall and login log screens ask the `manageMfa` gate. Without that gate they only open in the local environment:

```php
Gate::define('manageMfa', fn ($user = null) => Auth::guard('staff')->check());
```

A second, optional gate lets a wider group lift a block that followed failed logins, without managing the rules. Whoever passes only this gate sees the firewall screen with the blocks and nothing else:

```php
Gate::define('liftMfaBlocks', fn ($user = null) => Auth::guard('staff')->check());
```

Keeping a screen of your own, because the application has its own login log or its own rights? Write rules with `Darvis\Mfa\Models\FirewallRule`, call `Firewall::refresh()` after writing one directly, and lift a block with `Firewall::lift($rule, $who)` so the row keeps who did.

The views can be published with `php artisan vendor:publish --tag=mfa-views`.

## Firewall, locations and PIN

Off by default; switch it on with `MFA_FIREWALL=true`. Every login is judged in a fixed order: the deny list, the block for too many failed logins, the allow list and locations, the country filter.

```php
'firewall' => [
    'allow' => ['203.0.113.10'],          // always let in
    'countries' => ['NL', 'BE'],          // empty means no country filter
    'deny' => [],
    'enabled' => true,
    'locations' => ['198.51.100.0/24'],   // the office
],
```

Rules that come and go live in the database and can end on a date. Add them on the firewall screen or from the command line, for when you are the one locked out:

```bash
php artisan mfa:allow-ip 203.0.113.9 --until=2026-11-25 --note="Timo, hotel Istanbul"
php artisan mfa:allow-ip 198.51.100.7 --location --pin --never-block --note="Warehouse"
php artisan mfa:unblock-ip 203.0.113.9   # locked yourself out with wrong passwords
```

A location is a place where you are on site. Routes behind the `mfa.location` middleware only open there, and the `pin` channel only exists there:

```php
Route::middleware('mfa.location:staff')->group(function () {
    // only reachable from the office
});

Route::middleware(['auth:staff', 'mfa.firewall:staff'])->group(function () {
    // a session that began at the office does not keep working from abroad
});
```

Livewire sends its updates to its own endpoint, past the middleware of the page. Add the firewall there as well, or an open tab keeps working:

```php
Livewire::addPersistentMiddleware([\Darvis\Mfa\Http\Middleware\EnforceFirewall::class]);
```

Put `mfa.firewall` after `auth` in a group behind a login, so a guest still reaches the login screen instead of a 403.

The block for failed logins stops new logins only. Behind a login the middleware leaves it out, so one colleague with a wrong password does not sign out the whole office; the deny list, the allow list and the country filter still apply. Ask the same yourself with `Firewall::decide($ip, $guard, ignoreBlock: true)`.

An IPv6 address is counted and blocked as its whole /64 (`firewall.ipv6_prefix`), the size of one connection: a single IPv6 address would be swapped for the next one in a second. IPv4 addresses are blocked one by one.

A rule that ended, such as a lifted block, stays in the table for `log.retention_days` and is then removed by `mfa:prune`.

The country filter can be set per guard. This keeps the CMS to the Netherlands and leaves the visitors of the site alone:

```php
'firewall' => ['countries' => [], 'enabled' => true],

'guards' => [
    'staff' => ['channels' => ['mail'], 'countries' => ['NL'], /* ... */],
    'web' => ['channels' => ['mail'], /* ... */],
],
```

For the country filter with a local database: `composer require geoip2/geoip2`, then `php artisan mfa:geoip-update` once and monthly in the scheduler. The data is DB-IP's free IP to Country Lite, which asks for a link to db-ip.com. Behind Cloudflare, set `MFA_COUNTRY_DRIVER=header` instead.

Catch `Darvis\Mfa\Exceptions\BlockedException` next to `ThrottledException` in your own login screen; its message says why and reveals nothing about any account.

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

## Questions and answers

### How do I add two factor authentication to a Laravel application?

Install the package, list the guard in `config/mfa.php` with the channels you want, for example `['password', 'authenticator', 'recovery']`, and route the `Login` and `Settings` screens. Users set up their authenticator app and recovery codes on the settings screen. See [Quick start](#quick-start).

### How do I make a passwordless login with a magic link in Laravel?

Give the guard the `mail` channel. `Mfa::request($guard, $email)` sends one mail with a link and a code, and the link signs the user in. Add `authenticator` or `sms` next to it, so login does not depend on email alone.

### What happens to login when email is down?

Users with an authenticator app, recovery codes, a text message or a password keep signing in. `php artisan mfa:check` tells you beforehand which guards would be locked out. During an outage `php artisan mfa:emergency mail --until=18:00` skips the mail channels until that time. See [When a provider is down](#when-a-provider-is-down).

### Can I restrict my Laravel admin or CMS to one country or to the office?

Yes. Set `countries` on the guard of the CMS, such as `['NL']`, and the country filter applies to that guard only. Visitors of the site are left alone. An allow list lets a colleague abroad in until a date, and the `mfa.location` middleware opens routes at the office only. See [Firewall, locations and PIN](#firewall-locations-and-pin).

### Does it block brute force attacks on the login?

Yes, with the firewall on. After `firewall.block_after_attempts` wrong tries an IP address is blocked for `firewall.block_minutes`. For IPv6 that is the whole /64 of the connection. Separately, and also with the firewall off, wrong tries for one account are counted over every address together, so an attack spread over many addresses stops as well. Somebody who recognises the address can lift the block on the firewall screen, or with `php artisan mfa:unblock-ip`.

### Does it work with Laravel Fortify, Breeze or Jetstream?

It works next to them. They keep serving their own guard, usually `web`; list the other guards, such as `staff`, in `config/mfa.php`. Do not list a guard that Fortify already serves.

### Does it work with Livewire and Flux?

Yes. With both installed you get four ready made screens: login, security settings, firewall and login log. Without them the facade, the middleware and the commands work the same, and you build your own screens.

### Does it work for an API with Laravel Sanctum?

No. The package signs people in on session guards, the kind a website and a CMS use.

### Does it support passkeys?

Not yet. Passkeys are planned before version 1.0.

### Can an AI coding agent install or migrate it for me?

Yes. The package ships a Laravel Boost guideline and skill, and step by step [migration guides](docs/migrating/) written for AI agents: one for a hand written login by mail, one for an application with its own country filter or login block.

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

## Documentation

See [docs/](docs/), including the [migration guides](docs/migrating/) for moving an existing login to this package.

## Support the package

If darvis/laravel-mfa saves you time, buy me a beer 🍺: [sponsor me on GitHub](https://github.com/sponsors/ArvidDeJong). A star on [GitHub](https://github.com/ArvidDeJong/laravel-mfa) helps others find it.

## Maintainer

Built and maintained by [Arvid de Jong](https://github.com/ArvidDeJong) of [ARVID.NL](https://arvid.nl).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: see [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
