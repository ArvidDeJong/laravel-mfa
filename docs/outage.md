---
title: "When a provider is down"
nav_order: 8
description: "Keep a Laravel login working during a mail or SMS outage with darvis/laravel-mfa: the resilience check, a timed emergency mode and a one time login link."
---

# When a provider is down

A login that depends on one outside service stops when that service does. The package helps before, during and after.

## Before: know what you depend on

```bash
php artisan mfa:check
```

The command shows per guard whether login survives the loss of one service, and how many users have set up a fallback.

| Status | Meaning | What to do |
| --- | --- | --- |
| `OK` | Login survives the loss of one service | Nothing. Read how many users have a fallback |
| `AT RISK` | Every channel depends on the same service | Add `authenticator`, `recovery` or `password` to the guard |

A guard can be `OK` while most users have only a mail address. The second number tells you: "3 of 40 users have a fallback" means 37 people are locked out during a mail outage. Ask them to set up an authenticator app or recovery codes.

Add `--strict` in your deployment or CI to fail when a guard is at risk:

```bash
php artisan mfa:check --strict
```

## During: the emergency mode

Tell the package which service is down and until when. Its channels are skipped until that time.

```bash
php artisan mfa:emergency mail --until=18:00    # skip mail channels until 18:00
php artisan mfa:emergency                       # show what is active
php artisan mfa:emergency mail --off            # end it now
```

What happens then:

1. Users with another working channel keep using it. Nothing changes for them.
2. The login screen no longer offers the channel that is down.
3. An emergency ends by itself at the time you gave, and lasts at most `emergency.max_hours` (24).
4. Every login during an emergency is written to the login log as such.

The same can be set in `.env`, for a server where you cannot run a command:

```dotenv
MFA_MAIL_DOWN_UNTIL="2026-11-13 18:00"
```

### Users without any other channel

By default they wait. Two ways to let them in:

One person at a time, with a link you pass on by phone or chat:

```bash
php artisan mfa:link staff jan@example.com --minutes=15
```

Or, as a deliberate decision, let a password alone in for those users while the emergency lasts:

```dotenv
MFA_EMERGENCY_PASSWORD_ONLY=true
```

This only applies during an emergency, and only for users who have no working channel left. It lowers security for that time, so it is off by default.

A session that began on a password alone can be ended when the emergency is over. Put the `mfa.emergency` middleware on the routes behind the login.

## After

1. End the emergency, or let it run out.
2. Read the login log for the logins made during it.
3. Run `php artisan mfa:check` and see who still has no fallback.

## Related

1. [Channels](channels.md) shows which channel depends on which service.
2. [Screens for your admin](admin.md) shows per user whether they have a fallback.
