# Contributing

Contributions are welcome: bug reports, fixes, documentation and ideas.

## Before you start

1. **Bugs:** open an [issue](https://github.com/ArvidDeJong/laravel-mfa/issues/new/choose) with the steps to reproduce.
2. **Features:** open an issue first, so we can agree it fits before you build it.
3. **Security issues:** don't open an issue; see [SECURITY.md](SECURITY.md).

## Development

```bash
git clone https://github.com/ArvidDeJong/laravel-mfa.git
cd laravel-mfa
composer install

composer test      # Pest
composer lint      # Pint, check only (composer format fixes)
composer analyse   # Larastan, level 8
```

CI runs the tests on PHP 8.2 to 8.4 with Laravel 11, 12 and 13, on the lowest and the latest dependencies.

## Pull requests

1. Add or update tests for every change in behaviour.
2. Keep the public API compatible within 1.x. Deprecate first and remove in 2.0.
3. Read config only through `Darvis\Mfa\Support\MfaConfig`, and keep the keys in `config/mfa.php` in alphabetical order.
4. A new migration must also be added to the list in `tests/TestCase.php`, otherwise it is never tested.
5. No Doctrine DBAL and no driver specific SQL: host apps run their tests on SQLite.
6. Write code, comments and messages in English. Add every new translation key to both `en` and `nl`.
7. Update `docs/`, `CHANGELOG.md` (under `Unreleased`) and `resources/boost/` when users will notice the change.

## Code of conduct

This project follows the [Contributor Covenant](https://github.com/ArvidDeJong/.github/blob/main/CODE_OF_CONDUCT.md).
