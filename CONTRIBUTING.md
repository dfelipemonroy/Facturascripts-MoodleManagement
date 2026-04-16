# Contributing to MoodleManagement

Thanks for your interest in contributing! This plugin powers production
ERP/LMS integrations, so contributions are held to a high bar for quality,
security and backwards compatibility.

> During **v2.0 remediation** (active), the canonical backlog lives in
> [`docs/V2.0-ACTION-PLAN.md`](docs/V2.0-ACTION-PLAN.md) and
> [`docs/V2.0-TASK-CHECKLIST.md`](docs/V2.0-TASK-CHECKLIST.md). All work
> must reference the corresponding audit finding (`[#AUDIT-FX.Y]`).

---

## 1. Ground rules

1. **Every change ships tested.** New code adds tests. Fixing a bug adds a
   regression test. Target coverage: ≥ 40% global, ≥ 70% in `Lib/`.
2. **Security first.** User input is untrusted. Twig auto-escape is on.
   `|raw` needs a justification comment. SQL always parameterised.
3. **Backwards compatible.** Public API of `MoodleClient` is frozen. If a
   method must change shape, add a new one and deprecate the old.
4. **Readability over cleverness.** PSR-12, descriptive names, no dead code.
5. **Small commits.** One logical change per commit. Easier to bisect.

---

## 2. Development setup

```bash
# Clone the FS core (required dev dep)
git clone https://github.com/NeoRazorX/facturascripts.git fs-dev
cd fs-dev
composer install

# Clone this plugin
cd Plugins
git clone https://github.com/your-org/MoodleManagement.git
cd MoodleManagement

# Install dev tools (phpcs, phpstan, php-cs-fixer, phpunit)
composer require --dev \
    "squizlabs/php_codesniffer:^3.7" \
    "phpstan/phpstan:^1.10" \
    "friendsofphp/php-cs-fixer:^3.40" \
    "phpunit/phpunit:^9.6"
```

---

## 3. Branching model

- **`main`** — current stable release. Only hotfixes land here.
- **`v2.0-dev`** — integration branch for v2.0 remediation.
- **`v2.0/fase-NN-slug`** — per-phase branches (e.g. `v2.0/fase-02-seguridad-basica`).
- **`feature/<slug>`** — isolated features.
- **`fix/<slug>`** — isolated fixes.

Merge via PR into the parent branch (fase → v2.0-dev → main once released).

---

## 4. Commit conventions

Follow **Conventional Commits** plus audit reference:

```
<type>(<scope>): <subject> [#AUDIT-FX.Y]

<body — why, not what>

Refs: V2.0-ACTION-PLAN §Fase N · Task FX.Y
```

### Allowed types
| Type       | Use for                                             |
|------------|-----------------------------------------------------|
| `feat`     | New user-facing feature                             |
| `fix`      | Bug fix                                             |
| `refactor` | Code change without behaviour change                |
| `security` | Security-relevant fix (XSS, CSRF, IDOR, SSRF, etc.) |
| `perf`     | Performance improvement                             |
| `docs`     | Documentation only                                  |
| `test`     | Test-only changes                                   |
| `chore`    | Tooling, build, deps, config                        |
| `ci`       | CI pipeline                                         |
| `style`    | Formatting (no logic change)                        |

### Subject line
- Imperative mood (“add X”, not “added X”).
- Max 72 chars.
- Lowercase except proper nouns.

---

## 5. Pull requests

1. Run locally **before opening**:
   ```bash
   vendor/bin/php-cs-fixer fix
   vendor/bin/phpcs
   vendor/bin/phpstan analyse
   vendor/bin/phpunit
   ```
2. Describe **the why** in the PR body.
3. Reference the audit finding and the corresponding checklist item.
4. Include screenshots for UI changes.
5. Keep PRs scoped to a single phase / topic when possible.

A PR is mergeable when:
- CI is green.
- Coverage does not drop.
- At least one review approved (self-review is ok during solo-dev of
  v2.0 remediation if checklist is updated in the same PR).

---

## 6. Code style

- PSR-12 baseline (see `phpcs.xml.dist`).
- `php-cs-fixer fix` must be a no-op on submitted PRs.
- Arrays: short syntax `[]`.
- Strings: single quotes unless interpolation.
- Comments in **English** for source files (`README.md` stays bilingual).
- Add `@since 2.0` to classes and methods introduced in the remediation.

---

## 7. Testing

- **Unit tests** in `Test/Unit/*` — no DB, no HTTP, mocks only.
- **Integration tests** in `Test/Integration/*` — in-memory SQLite and
  mocked `HttpClient`.
- **Fixtures** in `Test/Fixtures/*`.

Run:
```bash
vendor/bin/phpunit --testsuite MoodleManagement
```

---

## 8. Reporting vulnerabilities

Do **not** open a public issue. See [`SECURITY.md`](SECURITY.md).

---

## 9. License

By contributing, you agree your contribution is licensed under the
same terms as this plugin (see `LICENSE`).

---

*Last updated: 2026-04-16 · v2.0 kickoff*
