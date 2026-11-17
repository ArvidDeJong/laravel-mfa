---
title: "Security"
nav_order: 12
description: "How darvis/laravel-mfa protects a Laravel login: what is stored hashed or encrypted, how tries are limited per address and per account, and what an attacker can and cannot learn."
---

# Security

What the package does to keep a login safe, and what it leaves to you.

## What is stored

| What | How |
| --- | --- |
| A mailed or texted code | Only a hash |
| The token of a mailed link | Only a hash |
| The key of an authenticator app | Encrypted with the app key |
| Recovery codes | Only hashes |
| A PIN | Only a hash |
| Passwords | Not stored by the package. The guard's own user provider checks them |

A code or link works once. After a login it stays behind as used, so opening the link again is logged for its owner, until `mfa:prune` removes it.

## One answer for every failure

A wrong code, an expired code, an unknown address, an inactive account and a channel that was switched off for one account all give the same result: `null` from the login methods, and nothing from `Mfa::request()`. The screen cannot be used to find out which addresses have an account.

The same holds for time. The mail or text message goes out after the response is sent (`challenge.send_after_response`), so a request for an address that exists answers as fast as one for an address that does not.

## Limits on trying

| Limit | Counts | Setting |
| --- | --- | --- |
| Per account and address | Every request and every try | `rate_limit.attempts` per `rate_limit.decay_seconds` |
| Per account, over every address | Wrong codes and secrets | `rate_limit.account_attempts` per `rate_limit.account_decay_seconds` |
| Per code | Wrong tries on one mailed code | `challenge.max_attempts` |
| Per address, with the firewall on | Wrong codes, passwords and PINs | `firewall.block_after_attempts` for `firewall.block_minutes` |
| Per PIN | Wrong tries | `pin.max_attempts` |

The limit per account stops a guess that is spread over many addresses. It never holds back asking for a mail or opening its link. Somebody whose account is under attack cannot type a code for a while, but still gets in through the link in the mail.

An IPv6 address is counted as its whole /64, the size of one connection.

## The link and mail scanners

Some mail systems open every link in a message before the person does. With `link.confirm` on, the link shows a button first and only signs in on the button, so a scanner does not use the link up.

## What an administrator sees

The login log and the blocks hold what was typed as the account. When somebody types a password in the address field by mistake, that text is visible to whoever may open the firewall screen or the log. Give the `manageMfa` gate to the people who should see it. Log lines and ended blocks are removed after `log.retention_days`.

## The emergency mode

An emergency never switches security off by itself. It skips the channels of the service that is down. A password alone is only accepted when you set `emergency.allow_password_only`, only during the emergency and only for users without any working channel. Every such login is logged.

## The real address behind a proxy

Everything about addresses reads `$request->ip()`. Behind a load balancer or Cloudflare, configure Laravel's trusted proxies, or every visitor has the address of the proxy and the firewall blocks them all together.

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trustProxies(at: ['10.0.0.0/8']);
})
```

Check it on the login log: the address there must be the visitor's.

## What is left to you

1. Use HTTPS. A code or session cookie sent over plain HTTP can be read.
2. Keep the `APP_KEY` secret. It encrypts the authenticator keys.
3. Decide who passes the `manageMfa` gate.
4. Ask users to set up a fallback. `php artisan mfa:check` tells you how many have one.

## Report a vulnerability

See [SECURITY.md](https://github.com/ArvidDeJong/laravel-mfa/blob/main/SECURITY.md) in the repository. Please do not open a public issue.
