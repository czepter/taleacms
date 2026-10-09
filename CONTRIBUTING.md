# Contributing to Kaleta

Thank you for helping. Kaleta is a small project, so a short, focused change with a test is the easiest to accept.

## Before you start

- **Bugs:** open an [issue](https://github.com/phprs-cms/kaletacms/issues) with the Kaleta version (Admin → Updates), what
  you did, what you expected and what happened. Screenshots and the PHP error log help.
- **Security problems:** do not open a public issue – follow [SECURITY.md](SECURITY.md).
- **New features:** open an issue first and describe the use case. Kaleta has no third-party plugins by design; features
  ship as built-in extensions that are tested together, so not every idea fits.

## Setting up

You need PHP 8.4+ and MySQL 8 or MariaDB 10.6+.

```bash
php -S localhost:8080 system/dev-router.php
```

Open `http://localhost:8080/install.php` and install into an empty database.

## Tests

Every pull request must pass:

```bash
composer install                                # PHPUnit and Phinx (vendor/)
vendor/bin/phpunit                              # all suites: unit, integration, legacy
vendor/bin/phpunit --testsuite unit             # no database, milliseconds
vendor/bin/phpunit --testsuite integration      # real MySQL 8: docker compose -f docker-compose-dev.yaml up -d db-test
vendor/bin/paratest --testsuite site -p 6      # whole installed sites over HTTP (admin, builder, MCP, forms, jobs …), one class per
                                                # site, in parallel; needs the db-test service
vendor/bin/phpunit tests/Site/EnglishInstall   # the English installer, site and admin must contain no Czech (site tests, tools/find-czech.php)
```

Write new tests with PHPUnit in `tests/Unit` (pure logic) or `tests/Integration` (extend `Kaleta\Tests\Support\DatabaseTestCase`: a
throw-away database built by the real migrations, every test in a rolled-back transaction; skipped when no MySQL is reachable). The older
`tools/unit-tests.php` (about 930 checks) runs as the `legacy` suite; move checks out of it when you touch the code they cover. Use a
test in `tests/Site` for anything that needs a running site (see `tests/Site/README.md`).

## Code

- PHP 8.4 with `declare(strict_types=1)`, namespace `Kaleta\`. Match the surrounding code: identifiers and comments are
  currently in Czech (moving to English is on the [roadmap](docs/ROADMAP.md)).
- No new runtime dependencies and no build step. CSS goes into the existing layers, JavaScript only where it is really
  needed.
- Anything shown to visitors or administrators goes through `t()` and needs an English translation; `tools/find-czech.php`
  checks that no Czech leaks into the English interface.
- Architecture notes for contributors (in Czech) are in [CLAUDE.md](CLAUDE.md).

## Translations

Visitor texts live in `system/jazyky/<code>.php` (for example `de.php`), the English admin in `system/jazyky/admin-en.php`.
A new language is one dictionary file keyed by the Czech source text; `tools/add-translations.py` adds entries. Languages without
a dictionary fall back to English with dates in their own format.

## Commits and pull requests

- Write commit messages, pull requests and issues in English.
- Keep one topic per pull request and describe what changed and how you tested it.
- By contributing you agree that your contribution is licensed under GPL-2.0-or-later, the licence of the project.
