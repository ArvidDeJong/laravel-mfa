---
title: "Installation"
nav_order: 2
description: "Install darvis/laravel-mfa with Composer, publish the config, run the migrations, list your guard and check in the terminal that login is set up."
---

# Installation

## Requirements

| What | Version |
| --- | --- |
| PHP | 8.2 or newer |
| Laravel | 11, 12 or 13 |
| Livewire | 3 or 4, optional. Only for the ready made screens |
| Flux | 2, optional. The ready made screens are built with it |
| A session guard | The package signs in on session guards, not on API tokens |

## Steps

1. Install the package.

   ```bash
   composer require darvis/laravel-mfa
   ```

2. Publish the config file. It lands in `config/mfa.php`.

   ```bash
   php artisan vendor:publish --tag=mfa-config
   ```

3. Run the migrations. They create the tables `mfa_challenges`, `mfa_factors`, `mfa_login_attempts` and `mfa_firewall_rules`.

   ```bash
   php artisan migrate
   ```

4. Tell the package which guards it serves, in `config/mfa.php`. The guard must exist in `config/auth.php`.

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

   | Key | Meaning |
   | --- | --- |
   | `channels` | The ways to sign in on this guard, see [Channels](channels.md) |
   | `home` | Where a mailed link lands after signing in |
   | `identifier` | The column a user signs in with, usually `email` |
   | `remember` | Whether a login is remembered after the browser closes |

5. Schedule the clean up, in `routes/console.php`.

   ```php
   Schedule::command('mfa:prune')->daily();
   ```

A published config file only needs what differs from the defaults. Each group is merged with the package defaults, so `'log' => ['enabled' => false]` keeps the other `log` settings. `guards` is the exception and is taken from your config as a whole.

## Check that it works

```bash
php artisan mfa:check
```

The command lists every guard with its channels and says whether login survives the loss of one outside service:

| Status | Meaning |
| --- | --- |
| `OK` | At least one channel works without mail and without SMS, or the channels lean on different services |
| `AT RISK` | Every channel depends on the same service. When it is down, nobody signs in on this guard |

A guard with only `mail` is at risk. Add `authenticator` and `recovery` and run the command again.

Then send yourself a login mail. On a development machine set `MAIL_MAILER=log` in `.env` first, so the mail is written to `storage/logs/laravel.log`:

```bash
php artisan tinker --execute="Darvis\Mfa\Facades\Mfa::request('staff', 'you@example.com');"
```

The log now holds a mail with a six digit code and a link. Nothing is written for an address that does not exist on that guard: the package answers the same either way.

## Next

[Your first login screen](first-login.md) shows the login in the browser.

## Laravel Boost

The package ships a Boost guideline and the `laravel-mfa-development` skill, so an AI coding agent knows how to use it. Boost only installs those of packages listed under `packages` in your `boost.json`:

```json
{
    "packages": [
        "darvis/laravel-mfa"
    ]
}
```

Then run `php artisan boost:update`. Boost reads only direct dependencies, so require the package in the application's own `composer.json`.
