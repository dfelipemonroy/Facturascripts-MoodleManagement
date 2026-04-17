# Supported Versions — MoodleManagement v2.0

`@since 2.0 — V2.0-ACTION-PLAN F10.8 · §6.21`

## FacturaScripts

| FacturaScripts | Status            |
| -------------- | ----------------- |
| 2025.81 +      | Fully supported   |
| 2025.6 – 2025.8 | Supported (v2.0 minimum is 2025.6) |
| < 2025.6       | **Not supported** — `facturascripts.ini::min_version` will block install |

## PHP

| PHP | Status                |
| --- | --------------------- |
| 8.0 | Minimum — CI matrix ✅ |
| 8.1 | Supported — CI matrix ✅ |
| 8.2 | Supported — CI matrix ✅ |
| 8.3 | Best effort — not in CI matrix yet |
| 7.x | **Not supported** — strict_types + enum-style constants require 8.0 |

CI runs on 8.0 / 8.1 / 8.2 via `.github/workflows/ci.yml` (F9.9).

## Moodle

| Moodle         | Status                                                                         |
| -------------- | ------------------------------------------------------------------------------ |
| 4.1 LTS +      | Fully supported — minimum declared via `MoodleClient::MIN_MOODLE_RELEASE`      |
| 4.0            | Best effort — most WS calls work but F6.10 pre-enrolment depends on 4.1 events |
| 3.11           | **Not supported** — `core_course_get_categories` shape differs                  |
| 3.9            | **Not supported** — same                                                        |

When FS probes a Moodle instance (`applySiteInfo`, F7.18), a release
older than 4.1 switches the instance to `status = unsupported` and
logs `moodle-version-too-old`. Admins can acknowledge and continue
using the plugin, but the dashboard will surface a persistent
warning and workers silently skip that instance.

## Database

| Database         | Status                                                                     |
| ---------------- | -------------------------------------------------------------------------- |
| MySQL 5.7+       | Supported — CHECK constraints are parsed but not enforced (F5.14 expects this). |
| MySQL 8.0+       | Fully supported                                                            |
| MariaDB 10.4+    | Supported                                                                  |
| PostgreSQL 13+   | Supported — `SchemaMigrator::isPostgres()` branches for DDL dialect        |
| SQLite           | Test-only (phpunit in-memory DB in F9 tests)                               |

## Browsers

Matches the FacturaScripts 2025 baseline:

- Chrome / Edge latest 2
- Firefox latest 2
- Safari 16+

The Chart.js 4 upgrade in F3.1 drops IE11 support; the plugin
already required evergreen browsers for its modern twig+ES6
JavaScript, so this only formalises the existing constraint.

## cURL

- Min version: 7.50 (for modern TLS + HTTP/2 support).
- The `IpValidator` SSRF guard relies on `filter_var(…, FILTER_FLAG_NO_RES_RANGE)` which is present on all supported systems.

## Tooling (dev)

- Composer 2.x
- Node.js — **not required**. The plugin ships no npm toolchain;
  JS assets are served as-is.
- PHPStan ^1.10, PHPUnit ^9.6, PHP-CS-Fixer ^3.40, php_codesniffer ^3.7.
