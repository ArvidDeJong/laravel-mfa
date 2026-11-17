---
title: "Home"
nav_order: 1
description: "darvis/laravel-mfa gives every Laravel guard two factor authentication and passwordless login with a fallback, plus a login firewall, so login survives an outage."
permalink: /
---

# Laravel MFA

`darvis/laravel-mfa` is a Laravel package for multi factor authentication (MFA, also called two factor authentication or 2FA) and passwordless login. It gives every Laravel guard more than one way to sign in: a magic link and a one time code by email, a code by text message, an authenticator app (TOTP, such as Google Authenticator, Microsoft Authenticator or 1Password), recovery codes, a password or a PIN at the office.

A built in login firewall adds an IP allow list, a deny list, a country filter and a block after too many failed logins.

What sets it apart: login that survives an outage. The package warns you when every way in leans on the same outside service, and gives you a controlled emergency mode for the day your mail or SMS provider goes down.

## Why it exists

On 12 and 13 November 2026 a worldwide Microsoft 365 outage stopped email delivery for many organisations. Every application that relied on a magic link or an emailed code had the same problem that day: the application itself was healthy, but nobody could log in. Login should never depend on one single service.

## In short

| Question | Answer |
| --- | --- |
| What is it for? | Signing people in to a Laravel website or CMS with a second factor, or without a password at all |
| Who is it for? | Any Laravel developer, from a first project to an agency with a `staff` guard next to normal users |
| What does it need? | PHP 8.2 or newer and Laravel 11, 12 or 13. Livewire and Flux are optional, for the ready made screens |
| What does it cost? | Nothing. MIT licence |
| Does it replace Laravel Fortify? | No. It works next to Fortify, for the guards Fortify does not serve |

## The words explained

| Word | What it means here |
| --- | --- |
| MFA, 2FA | Proving who you are with more than a password, for example a code from your phone |
| Guard | Laravel's name for a kind of login. `web` is the default for normal users; many sites add a second one, such as `staff` for the CMS |
| Channel | One way to sign in: `mail`, `sms`, `authenticator`, `recovery`, `password` or `pin`. You choose per guard which ones are allowed |
| Magic link | A link in an email that signs you in when you open it. No password needed |
| One time code (OTP) | A short code that works once and for a few minutes, sent by email or text message |
| Authenticator app (TOTP) | An app on your phone that shows a new code every 30 seconds. It works without internet and without email |
| Recovery codes | A list of spare codes to print or save, for when the phone is lost |
| Firewall | Rules about where a login may come from: which IP addresses and which countries |

## What it does not do

1. It does not serve a token API. It signs people in on session guards, the kind a website and a CMS use, not on Laravel Sanctum tokens.
2. It does not replace Laravel Fortify. Fortify keeps its own guard; list the other guards here.
3. It has no passkeys yet. They follow in a later release.
4. It does not register users or reset passwords. It signs in accounts that exist.

## Install

```bash
composer require darvis/laravel-mfa
php artisan vendor:publish --tag=mfa-config
php artisan migrate
```

[Installation](installation.md) has the full steps and a way to check that it works. [Your first login screen](first-login.md) gets you from there to a working login in one route.

## All pages

| Page | What you find there |
| --- | --- |
| [Installation](installation.md) | Requirements, the steps, and how to check that it works |
| [Your first login screen](first-login.md) | A working login with one route, or your own screen on the facade |
| [Channels](channels.md) | Mail, text message, authenticator app, recovery codes, password and PIN, and how users set them up |
| [Password plus a second step](two-step.md) | A login in two steps, and switching a channel off for one account |
| [Firewall](firewall.md) | Allow and deny list, the block after failed logins, the country filter, locations and PIN |
| [Screens for your admin](admin.md) | The sign-in overview, the firewall screen, the login log, gates and layouts |
| [When a provider is down](outage.md) | The resilience check, the emergency mode and a one time link |
| [Configuration](configuration.md) | Every setting, the hooks for your own mail and link route, and the events |
| [Commands](commands.md) | Every Artisan command with an example |
| [Testing](testing.md) | Test a login that uses the package in your own application |
| [Security](security.md) | What is stored, how tries are limited and what an attacker can learn |
| [Troubleshooting](troubleshooting.md) | No mail arrives, everybody is refused, and every error message with its cause |
| [Migrating](migrating.md) | Step by step guides for moving an existing login or firewall to the package |
| [FAQ](faq.md) | Short answers to common questions |
