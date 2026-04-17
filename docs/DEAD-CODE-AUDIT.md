# Dead Code Audit — MoodleManagement v2.0

`@since 2.0 — V2.0-ACTION-PLAN F10.6 · §6.19`

This file records the dead-code audit performed before the v2.0 tag
and the tooling used to keep regressions out.

## Goals

1. No unused private / protected method in `Lib/`, `Controller/`,
   `Worker/`, `Cron.php` or `Init.php`.
2. No unreachable branches (`if (false)`, `return` followed by code).
3. No unused imports (`use` lines without a referenced symbol).
4. No unused class-level constants or private properties.

## Tooling

| Layer         | Tool                              | Invocation                          |
| ------------- | --------------------------------- | ----------------------------------- |
| Static        | PHPStan level 5                   | `vendor/bin/phpstan analyse`        |
| Lint          | PHP_CodeSniffer PSR-12            | `vendor/bin/phpcs`                  |
| Style         | PHP-CS-Fixer                      | `vendor/bin/php-cs-fixer fix`       |
| Dead code     | PHPStan + manual grep pass        | see "Manual pass" below             |

PHPStan level 5 flags unused private methods by default. The rule
is enabled in `phpstan.neon.dist` along with
`reportStaticMethodSignatures: true` and
`checkDynamicProperties: true` (F10.6) so stale static helpers
surface quickly.

## Manual pass (2026-04-17)

Ran the following checklist against `Lib/`, `Controller/`, `Worker/`:

1. `grep -R "private function" Lib/` — inspected every result for a
   matching `self::` / `$this->` / `static::` caller in the same
   file. No orphans found: all private helpers have at least one
   internal caller.
2. `grep -R "protected function" Lib/ Controller/` — same check
   extended to subclass access. No orphans.
3. `grep -R "public const" Lib/ Controller/` — all constants are
   referenced either in tests, inside the same class, or from
   callers documented in the class phpdoc.
4. `grep -R "^use " --include "*.php"` — scanned for unreferenced
   imports. PHP-CS-Fixer's `no_unused_imports` rule handles the
   bulk of these; the dry-run job in CI catches the rest.

## Known false positives

- `Lib/Moodle/Api/*Api.php` facades delegate to
  `MoodleClient` via `__callStatic`-style thin wrappers. PHPStan
  won't flag the wrappers as unused because their signatures are
  referenced from MR-bound handlers and future external plugins.
  These are intentional scaffolding ahead of the Fase 8 god-class
  split described in §1.4.

## Follow-up (post v2.0)

- Evaluate `tomasvotruba/unused-public` once the plugin has a
  dedicated `composer.json`. At that point the manual pass can be
  retired in favour of a CI gate.
- Consider `psalm --find-unused-code` as a belt-and-braces layer
  alongside PHPStan.

## How to re-run the audit

```bash
cd Plugins/MoodleManagement
../../vendor/bin/phpstan analyse --no-progress
../../vendor/bin/phpcs --standard=PSR12 Controller Lib Worker Cron.php Init.php
../../vendor/bin/php-cs-fixer fix --dry-run --diff
```

A GitHub Actions workflow (`.github/workflows/ci.yml`, added in
F9.9) runs all three on every push / PR. Failures block the merge.
