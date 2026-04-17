# QA — Lint and static analysis gate

`@since 2.0 — V2.0-ACTION-PLAN F12.5 + F12.6 · §F12.5 + §F12.6`

v2.0 release gate for static-analysis + code-style cleanliness.
Both tools run in CI on every push and PR
(`.github/workflows/ci.yml`); this document is the runbook for
running the same commands locally before submitting.

## 1. PHPStan level 5 — must be 0 errors

```bash
cd Plugins/MoodleManagement
composer require --dev "phpstan/phpstan:^1.10"
vendor/bin/phpstan analyse --no-progress
```

Configuration: `phpstan.neon.dist` (level 5, parallel=4,
excludes `Dinamic/*`, `vendor/*`, `docs/*`, `.docs-dev/*`).

A baseline file (`phpstan-baseline.neon`) is included but **empty
at v2.0 release**. CI runs the analysis with baseline active;
adding an entry requires:

1. A matching TODO in `docs/V2.0-TASK-CHECKLIST.md` or a
   follow-up issue.
2. Reviewer approval on the release PR.

Each release is expected to leave the baseline **shorter** than
it found it (strict monotonicity rule).

### Typical fixes

- Missing return type on a new public method → add it, PHP 8.0+
  supports every type the plugin uses.
- "Call to an undefined method …" on a FS Dinamic class → guard
  with `method_exists` or annotate via
  `/** @var \FacturaScripts\Dinamic\Model\Foo $model */`.
- "Unsafe usage of new static()" → switch to `new self()` in
  final classes.

## 2. PHPCS PSR-12 — must be 0 errors

```bash
cd Plugins/MoodleManagement
composer require --dev "squizlabs/php_codesniffer:^3.7"
vendor/bin/phpcs --standard=phpcs.xml.dist
```

Configuration: `phpcs.xml.dist` (PSR-12 ruleset, excludes
`Dinamic/*`, `vendor/*`, `Assets/JS/vendor/*`).

CI outputs a GitHub checkstyle annotation per violation so PRs
show inline comments automatically.

### Typical fixes

- Line length >120 → split.
- Missing space after comma → fix by hand or run `php-cs-fixer`.
- File header doc block missing → add the LGPL block every
  plugin file carries.

## 3. PHP-CS-Fixer — style drift

```bash
composer require --dev "friendsofphp/php-cs-fixer:^3.40"
vendor/bin/php-cs-fixer fix --dry-run --diff
```

Configuration: `.php-cs-fixer.dist.php` (PSR-12 + array short,
single quotes, trailing comma in multiline).

Run with `--dry-run` to see the diff; drop `--dry-run` to apply.

## 4. Combined gate

Before opening a PR:

```bash
cd Plugins/MoodleManagement
vendor/bin/php-cs-fixer fix --dry-run --diff
vendor/bin/phpcs
vendor/bin/phpstan analyse
vendor/bin/phpunit
```

All four must exit 0. CI mirrors this four-command sequence.

## 5. Verifying a release candidate

On the tagged commit, run:

```bash
# 1. Clean state
git clean -fdx
composer install --dev

# 2. Tooling
vendor/bin/php-cs-fixer fix --dry-run --diff
vendor/bin/phpcs
vendor/bin/phpstan analyse
vendor/bin/phpunit

# 3. Confirm baseline is empty
test ! -s phpstan-baseline.neon || grep -q 'ignoreErrors: \[\]' phpstan-baseline.neon || { echo "FAIL: phpstan baseline not empty"; exit 1; }
```

If all three tools report 0 errors and the baseline check passes,
the release is cleared for tagging (F12.7).
