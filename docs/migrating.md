---
title: "Migrating"
nav_order: 15
description: "Step by step guides, written so an AI coding agent can follow them, for moving an existing Laravel login or firewall to darvis/laravel-mfa without changing what users see."
has_children: true
---

# Migrating

Guides for moving something that already works to the package. Each one is written so that an AI coding agent can carry it out step by step, and so that a person can read along and check.

| Guide | For an application that has |
| --- | --- |
| [From a hand written staff login by mail](migrating/fortify-web-plus-staff.md) | A second guard, such as `staff`, that signs in with a mailed code or link, next to Laravel Fortify |
| [From an own country filter, allow list or login block](migrating/own-firewall.md) | Its own geo filter, list of allowed addresses or block after failed logins |

## How to use a guide

1. Install [Laravel Boost](installation.md#laravel-boost), so the agent knows the package.
2. Give the agent the guide and ask for a plan first: what stays, what becomes a thin layer on the package and what goes, and which tests would change.
3. Approve the plan before anything is changed.
4. Let it build, then run the whole test suite of the application.

The tests of the application are the contract. A test may change in how it is written. What it expects may not change without your approval.

## Replace the engine first

Both guides keep the behaviour the same in the first pass: the screens, the messages and the names in `.env` stay. Only what decides underneath is replaced. That makes the change easy to check and easy to roll back. Moving the screens and the texts comes later, when you want to.
