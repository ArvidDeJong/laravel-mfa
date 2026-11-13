# Security policy

This package decides who gets into your application, so security reports are taken seriously.

## Supported versions

Only the latest minor release receives security fixes. Upgrade before reporting.

## What counts as a vulnerability

For example:

1. a login that succeeds without a valid code, link, authenticator code, recovery code or password;
2. a code or link that works more than once, after it expired, or on another guard than it was issued for;
3. a way to tell from the outside whether an address has an account;
4. password only login being accepted while no emergency is active, or for a user who still has a working channel;
5. a plain code, link token, authenticator secret or phone number ending up in the database, logs or output.

## Reporting a vulnerability

Please do **not** open a public issue. Report it privately instead:

1. via [GitHub private vulnerability reporting](https://github.com/ArvidDeJong/laravel-mfa/security/advisories/new), or
2. by email to info@arvid.nl.

Include the package version, the Laravel version and the steps or request that reproduce it.

You will get a reply within a week. Once a fix is released, the advisory is published and you are credited, unless you prefer not to be.
