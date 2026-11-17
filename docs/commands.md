---
title: "Commands"
nav_order: 10
description: "Every Artisan command of darvis/laravel-mfa with an example: check the setup, the emergency mode, a one time link, the firewall, the country database and the clean up."
---

# Commands

| Command | What it does |
| --- | --- |
| `mfa:check` | Check that no guard depends on one outside service |
| `mfa:emergency` | Skip the channels of a service that is down, until a set time |
| `mfa:link` | Create a one time login link for one user |
| `mfa:allow-ip` | Let an address in, from the command line |
| `mfa:blocked` | Show who is blocked for failed logins right now |
| `mfa:unblock-ip` | Lift the block for failed logins from an address |
| `mfa:geoip-update` | Download the country database the firewall reads |
| `mfa:prune` | Remove used and expired codes, and old log lines and firewall rules |

## mfa:check

```bash
php artisan mfa:check
php artisan mfa:check --strict
```

Lists every guard with its channels, whether login survives the loss of one service, and how many users have a fallback. With `--strict` the command fails when a guard is at risk, for use in CI. See [When a provider is down](outage.md).

## mfa:emergency

```bash
php artisan mfa:emergency mail --until=18:00
php artisan mfa:emergency mail --until="2026-11-13 18:00"
php artisan mfa:emergency
php artisan mfa:emergency mail --off
```

The first argument is the service that is down: `mail` or `sms`. Without arguments the command shows what is active.

## mfa:link

```bash
php artisan mfa:link staff jan@example.com
php artisan mfa:link staff jan@example.com --minutes=30
```

Prints a one time login link for a user who cannot be reached by mail. Pass it on yourself. The link is logged.

## mfa:allow-ip

```bash
php artisan mfa:allow-ip 203.0.113.9 --until=2026-11-25 --note="Timo, hotel Istanbul"
php artisan mfa:allow-ip 198.51.100.0/24 --guard=staff --location --pin --never-block --note="Office"
```

For when the person who would add the rule on screen is the one locked out by the country filter. See [Firewall](firewall.md#rules-that-come-and-go) for the options.

## mfa:blocked

```bash
php artisan mfa:blocked
```

Lists the addresses that are blocked right now, with the country, the accounts that were tried and until when.

## mfa:unblock-ip

```bash
php artisan mfa:unblock-ip 203.0.113.9
```

Lifts the block that followed failed logins, for whoever locked themselves out and is signed in nowhere. A rule somebody put on the deny list by hand stays. An allow rule does not lift a block; this command does.

## mfa:geoip-update

```bash
php artisan mfa:geoip-update
```

Downloads this month's DB-IP IP to Country Lite database, checks it and moves it over the old file in one step. Needs `composer require geoip2/geoip2`. Schedule it monthly.

## mfa:prune

```bash
php artisan mfa:prune
```

Removes used and expired codes and links, login log lines older than `log.retention_days`, and firewall rules that ended longer ago than that. Schedule it daily.

## A schedule to copy

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('mfa:prune')->daily();
Schedule::command('mfa:geoip-update')->monthlyOn(3, '04:00');
```
