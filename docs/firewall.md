---
title: "Firewall"
nav_order: 6
description: "The login firewall of darvis/laravel-mfa: IP allow and deny list, a block after failed logins, a country filter per guard, on site locations with PIN login, and the middleware."
---

# Firewall

The firewall decides where a login may come from. It is off by default.

```dotenv
MFA_FIREWALL=true
```

## The order

Every login is judged in a fixed order. The first rule that matches decides.

| Step | Rule | Outcome |
| --- | --- | --- |
| 1 | The address is on the deny list | Refused |
| 2 | The address is blocked for too many failed logins | Refused |
| 3 | The address is on the allow list or is a location | Let in |
| 4 | The country of the address is not allowed for this guard | Refused |
| 5 | Nothing matched | Let in |

A login the firewall refuses throws `Darvis\Mfa\Exceptions\BlockedException`. Its message says why and reveals nothing about any account, so you may show it as is.

## Fixed lists

Addresses that always hold go in the config. Each entry is an IP address or a CIDR range.

```php
'firewall' => [
    'allow' => ['203.0.113.10'],          // always let in
    'deny' => ['198.51.100.66'],          // never let in
    'enabled' => true,
    'locations' => ['198.51.100.0/24'],   // the office
],
```

## Rules that come and go

Rules in the database can end on a date and can apply to one guard. Add them on the [firewall screen](admin.md) or from the command line, for when you are the one locked out:

```bash
php artisan mfa:allow-ip 203.0.113.9 --until=2026-11-25 --note="Timo, hotel Istanbul"
php artisan mfa:allow-ip 198.51.100.7 --location --pin --never-block --note="Warehouse"
```

| Option | Meaning |
| --- | --- |
| `--until=` | The last day the rule holds. For ever when left out |
| `--guard=` | Only for this guard |
| `--note=` | Who it is for and why |
| `--location` | The address is a location, a place where you are on site |
| `--pin` | PIN login is allowed from this location |
| `--never-block` | Never block this address for failed logins |

## The block after failed logins

After `firewall.block_after_attempts` (5) wrong tries from one address, the address is blocked for `firewall.block_minutes` (15). Only wrong codes, passwords and PINs count. Asking for a mail does not.

1. An IPv4 address is blocked by itself.
2. An IPv6 address is counted and blocked as its whole /64 (`firewall.ipv6_prefix`), the size of one connection. A single IPv6 address would be swapped for the next one in a second. Set 128 to go back to single addresses.
3. Addresses in `allow` and `locations`, and rules marked never block, are not blocked. One typo cannot lock out a whole office.
4. The block remembers which accounts were tried, so it says more than an address.
5. A block stops new logins. It does not end a session that is already signed in from the same address.

See who is blocked and lift a block:

```bash
php artisan mfa:blocked
php artisan mfa:unblock-ip 203.0.113.9
```

A rule that ended, such as a lifted block, stays in the table for `log.retention_days` with who lifted it. `php artisan mfa:prune` removes it after that.

## The country filter

Set the countries per guard. This keeps the CMS to the Netherlands and leaves the visitors of the site alone:

```php
'firewall' => ['countries' => [], 'enabled' => true],

'guards' => [
    'staff' => ['channels' => ['mail', 'authenticator'], 'countries' => ['NL'], 'home' => '/cms', 'identifier' => 'email', 'remember' => true],
    'web' => ['channels' => ['mail', 'authenticator'], 'home' => '/', 'identifier' => 'email', 'remember' => false],
],
```

| Value of `guards.<guard>.countries` | Meaning |
| --- | --- |
| `['NL', 'BE']` | Only these countries, as ISO codes |
| `[]` | No country filter for this guard |
| `null` or left out | Follow `firewall.countries` |

Where the country comes from is set with `MFA_COUNTRY_DRIVER`:

| Driver | How |
| --- | --- |
| `database` | A local database file. No request leaves your server |
| `header` | A header a proxy adds, `CF-IPCountry` behind Cloudflare. Change it with `firewall.country_header` |
| `none` | No lookup |

For the `database` driver:

```bash
composer require geoip2/geoip2
php artisan mfa:geoip-update
```

Schedule the update monthly:

```php
Schedule::command('mfa:geoip-update')->monthlyOn(3);
```

The data is DB-IP's free IP to Country Lite database, which asks for a link to [db-ip.com](https://db-ip.com) on your site.

An address whose country is unknown is refused, unless you set `firewall.allow_unknown_country`. When the database file cannot be read the filter refuses as well: it fails closed.

## Locations and PIN

A location is a place where you are on site: the office, a warehouse. Two things only work there.

Routes behind the `mfa.location` middleware only open at a location:

```php
Route::middleware('mfa.location:staff')->group(function () {
    // only reachable from the office
});
```

The `pin` channel only exists at a location that allows it. Everywhere else the channel is simply not offered. Addresses in `firewall.locations` allow PIN login; for a rule in the database tick "PIN login is allowed here" or pass `--pin`.

## Behind the login

A session that began at the office should not keep working from somewhere it may not. Put the `mfa.firewall` middleware on the routes behind the login:

```php
Route::middleware(['auth:staff', 'mfa.firewall:staff'])->group(function () {
    // the CMS
});
```

It refuses the request and signs out that guard only. A login on another guard in the same browser stays.

1. Put `mfa.firewall` after `auth`, so a guest still reaches the login screen instead of a 403.
2. Livewire sends its updates to its own endpoint, past the middleware of the page. Add the firewall there as well, or an open tab keeps working:

```php
use Darvis\Mfa\Http\Middleware\EnforceFirewall;
use Livewire\Livewire;

Livewire::addPersistentMiddleware([EnforceFirewall::class]);
```

## Ask the firewall yourself

```php
use Darvis\Mfa\Firewall\Firewall;

$verdict = app(Firewall::class)->decide($request->ip(), 'staff');

$verdict->allowed;     // bool
$verdict->reason;      // why not
$verdict->until;       // when a block ends, or null
```

Pass `ignoreBlock: true` to leave the block for failed logins out, as the middleware does.

An application that counts failed logins itself reports them, with what was typed as the account:

```php
app(Firewall::class)->registerFailure($request->ip(), $email);
```

Moving an application that already has its own country filter or login block? Follow [From an own country filter, allow list or login block](migrating/own-firewall.md).
