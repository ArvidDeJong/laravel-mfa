---
title: "Troubleshooting"
nav_order: 13
description: "Fix common problems with darvis/laravel-mfa: no login mail arrives, a code is refused, everybody is blocked, the country filter refuses, and every error message with its cause."
---

# Troubleshooting

Start with these two commands. They answer most questions.

```bash
php artisan mfa:check       # how every guard is set up
php artisan mfa:blocked     # who is blocked right now
```

After a change in `.env` or the config, run `php artisan config:clear`, or `php artisan config:cache` again on a server.

## No mail arrives {#no-mail-arrives}

| Check | How |
| --- | --- |
| Does the address exist on that guard? | Nothing is sent to an unknown address, on purpose. Look in the table of that guard |
| Is the account active? | An account that is not active gets nothing |
| Is the guard listed, with the `mail` channel? | `php artisan mfa:check` |
| Is an emergency skipping mail? | `php artisan mfa:emergency` |
| Does mail work at all? | Set `MAIL_MAILER=log` and look in `storage/logs/laravel.log` |
| Did the delivery fail? | A failed delivery is reported to your error log, and the screen still answers as usual |
| Too many requests? | After `rate_limit.attempts` requests the next ones are held back for a minute |

The login log shows what happened to each request: `unknown_account`, `inactive`, `not_allowed`, `throttled`.

## A code is refused {#a-code-is-refused}

| Cause | What to do |
| --- | --- |
| The code ran out | A code works for `challenge.lifetime_minutes` (15). Ask for a new one |
| A newer mail was sent | Only the last code works |
| Too many wrong tries | After `challenge.max_attempts` (5) the code is burned. Ask for a new one |
| The code was meant for another guard | A code for `staff` does not work on `web` |
| An authenticator code is refused | Check the clock of the phone and of the server. Raise `totp.window` when clocks drift |
| An authenticator code was used before | Each code works once. Wait for the next one |

## Everybody is refused {#everybody-is-refused}

When every visitor gets "Too many failed logins" at the same moment, the application probably sees one address for all of them: the address of a proxy or load balancer. Configure Laravel's trusted proxies, see [Security](security.md#the-real-address-behind-a-proxy).

Lift the block that is there now:

```bash
php artisan mfa:blocked
php artisan mfa:unblock-ip 203.0.113.9
```

## I locked myself out {#i-locked-myself-out}

| Situation | Command |
| --- | --- |
| Too many wrong passwords | `php artisan mfa:unblock-ip <your address>` |
| The country filter refuses you | `php artisan mfa:allow-ip <your address> --until=<date> --note="..."` |
| Mail is down and you have no other channel | `php artisan mfa:link <guard> <your email>` |

An allow rule lets you past the country filter. It does not lift a block for wrong passwords, unless the rule is marked never block.

## The country filter refuses everybody {#the-country-filter-refuses-everybody}

The filter fails closed: when the country cannot be told, the login is refused.

1. With the `database` driver, run `php artisan mfa:geoip-update` once. The file lands in `storage/app/mfa/country.mmdb`.
2. Install the reader: `composer require geoip2/geoip2`.
3. On a development machine the address is `127.0.0.1`, which has no country. Leave the country filter off locally, or add `127.0.0.1` to `firewall.allow`.
4. Behind Cloudflare, set `MFA_COUNTRY_DRIVER=header`.

## An open tab keeps working after the address changed {#an-open-tab-keeps-working}

Livewire sends its updates to its own endpoint, past the middleware of the page. Add the firewall to Livewire's persistent middleware, see [Firewall](firewall.md#behind-the-login).

## The screens show an error about a layout {#the-screens-show-an-error-about-a-layout}

Set `guards.<guard>.layout` or `ui.layout` to a Blade layout that exists, see [Your first login screen](first-login.md#the-layout).

## The firewall or log screen answers 403 {#the-firewall-or-log-screen-answers-403}

The screens ask the `manageMfa` gate. Without that gate they only open in the local environment. Define it, see [Screens for your admin](admin.md#who-may-open-them).

## Error messages

### Messages a visitor can see

| Message | Cause |
| --- | --- |
| Too many failed logins from this address. Try again later. | The address is blocked after wrong tries. It ends by itself, or lift it with `mfa:unblock-ip` |
| Signing in is not possible from this country. | The country filter of that guard refuses the country of the address |
| Signing in is not possible from this address. | The address is on the deny list, or there is no address at all |

### Messages a developer can see

| Message | Cause and fix |
| --- | --- |
| The MFA link route is switched off (mfa.link.enabled). Call Mfa::linkUrlUsing() to point the mailed link at a route of the application. | `link.enabled` is false and no route of your own is registered. Call `Mfa::linkUrlUsing()` in a service provider, or set `link.enabled` back to true |
| The "database" country driver needs the geoip2/geoip2 package: composer require geoip2/geoip2. | The country filter uses the `database` driver without the reader installed |
| The country database cannot be read. Run php artisan mfa:geoip-update. | The database file is missing or broken. The message names the path. Until it is fixed the country filter refuses every login |

## Still stuck

Open an issue on [GitHub](https://github.com/ArvidDeJong/laravel-mfa/issues) with the output of `php artisan mfa:check` and the Laravel and PHP version. Leave out addresses and names of real people.
