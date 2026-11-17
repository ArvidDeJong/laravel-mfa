---
title: "Screens for your admin"
nav_order: 7
description: "Show in your Laravel admin whether the login firewall is on, which countries may sign in, who is blocked and which two factor methods each user has set up."
---

# Screens for your admin

Whoever manages an application should be able to see how signing in is set up, without reading the config. Give the admin one place that shows:

1. whether the firewall is on, and from which countries each guard may sign in;
2. who is blocked right now: the address, the accounts that were tried, until when, and a way to lift it;
3. which ways in each user has set up, and who has no fallback when mail or text messages fail.

## The screens

With Livewire and Flux installed the package brings three screens for the admin, next to `Login` and `Settings`.

| Screen | Class | What it shows |
| --- | --- | --- |
| Overview | `Darvis\Mfa\Livewire\Overview` | Firewall on or off, countries per guard, who is blocked now and before, and per user the methods set up and whether they have a fallback |
| Firewall | `Darvis\Mfa\Livewire\FirewallRules` | Add and remove rules: let in or keep out an address or range, for ever or until a date, mark a location, allow PIN, never block |
| Login log | `Darvis\Mfa\Livewire\LoginAttempts` | Who tried to sign in, how, from where and how it ended, with search |

Route them behind the login of the admin:

```php
use Darvis\Mfa\Livewire\FirewallRules;
use Darvis\Mfa\Livewire\LoginAttempts;
use Darvis\Mfa\Livewire\Overview;

Route::middleware(['auth:staff', 'mfa.firewall:staff'])->group(function () {
    Route::get('admin/sign-in', Overview::class);
    Route::get('admin/firewall', FirewallRules::class);
    Route::get('admin/login-attempts', LoginAttempts::class);
});
```

## Who may open them

The screens ask the `manageMfa` gate. Without that gate they only open in the local environment.

```php
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

Gate::define('manageMfa', fn ($user = null) => Auth::guard('staff')->user()?->is_admin === true);
```

A second, optional gate lets a wider group lift a block that followed failed logins, without managing the rules. Whoever passes only this gate sees the firewall screen with the blocks and nothing else:

```php
Gate::define('liftMfaBlocks', fn ($user = null) => Auth::guard('staff')->check());
```

That is for the colleague who recognises the address of the office and wants everybody back in.

## In a screen of your own

An application with its own admin reads the same answers from one class:

```php
use Darvis\Mfa\Support\Overview;

$overview = app(Overview::class);

$overview->firewall();          // enabled, and the fixed allow, deny and location lists
$overview->guards();            // per guard: channels, countries, resilient, skipped in an emergency
$overview->blocks();            // blocked now; each rule has range, identifiers and expires_at
$overview->pastBlocks();        // blocked before, with lifted_by
$overview->users('staff');      // paginated: identifier, channels set up, fallback
```

For one user:

```php
$overview->channelsOf('staff', $user);    // such as ['mail', 'authenticator']
$overview->hasFallback('staff', $user);   // true when they still get in when a service fails
```

Writing rules yourself:

```php
use Darvis\Mfa\Firewall\Firewall;
use Darvis\Mfa\Models\FirewallRule;

FirewallRule::query()->create([
    'action' => FirewallRule::ALLOW,
    'range' => '203.0.113.9',
    'guard' => 'staff',
    'note' => 'Timo, hotel Istanbul',
    'expires_at' => now()->addWeek(),
    'created_by' => $staff->email,
]);

app(Firewall::class)->refresh();
```

1. Call `Firewall::refresh()` after writing a rule directly. The firewall keeps the rules for the rest of the request, also in a test.
2. Lift a block with `app(Firewall::class)->lift($rule, $who)` and do not delete it. The row then keeps who lifted it.

## When to keep your own screen

Use the screens of the package, unless one of these holds:

1. the application keeps its own login log and switched the package log off, so the package log is empty;
2. the rights differ per action in a way two gates do not cover;
3. the texts of an existing screen are agreed with a client.

Then build a thin screen on the same table, as above.

## On a server

```bash
php artisan mfa:blocked     # who is blocked right now, and which accounts were tried
php artisan mfa:check       # per guard: channels, at risk or not, and how many users have a fallback
```
