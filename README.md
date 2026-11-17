# Laravel MFA

Login that survives an outage. Give every Laravel guard more than one way in: a mailed link and code, a text message, an authenticator app, recovery codes or a password. The package warns you when all of them lean on the same outside service, and gives you a controlled emergency mode for the day a provider goes down.

> **Status:** in development, not released yet. Passkeys and the documentation site follow before 1.0.

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

If this package helps you, a star on [GitHub](https://github.com/ArvidDeJong/laravel-mfa) helps others find it.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: see [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
