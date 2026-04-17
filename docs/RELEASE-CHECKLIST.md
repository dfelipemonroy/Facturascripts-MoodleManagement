# Release checklist — MoodleManagement v2.0.0

`@since 2.0 — V2.0-ACTION-PLAN F12.7 · §F12.7`

Step-by-step gate for tagging `v2.0.0`. Every checkbox must be
ticked by the release engineer and attached to the release PR.

## 0. Decision points

- Release owner: `__________________`
- Target tag date: `__________________`
- Staging host URL: `__________________`
- Rollback owner (if different): `__________________`

## 1. Code / tests / docs green

- [ ] `git log --oneline v2.0-dev` shows every phase-closing commit
      (F0–F12).
- [ ] `docs/V2.0-TASK-CHECKLIST.md` shows 155/155 green
      (166 logical tasks, 155 trackable rows after cross-cutting
      fold-ins).
- [ ] `docs/V2.0-ACTION-PLAN.md` §5.2 shows all 12 phases
      "✅ Completada".
- [ ] `grep -R "TODO:\|FIXME:\|XXX:" Controller Lib Worker Extension Widget Cron.php Init.php` returns only entries justified in a tracked issue.
- [ ] `CHANGELOG.md` `## [2.0.0]` block carries a concrete date
      (no "Unreleased").

## 2. Tooling clean (F12.5 + F12.6)

On a clean checkout of the commit to be tagged:

```bash
git clean -fdx
composer install --dev
vendor/bin/php-cs-fixer fix --dry-run --diff   # must show no changes
vendor/bin/phpcs                                # must exit 0
vendor/bin/phpstan analyse --no-progress        # must exit 0
vendor/bin/phpunit                              # must exit 0
```

- [ ] All four commands exited 0.
- [ ] `phpstan-baseline.neon` is empty (`ignoreErrors: []`).
- [ ] CI pipeline green on the exact commit
      (`.github/workflows/ci.yml` — F9.9).

## 3. QA runs (F12.1–F12.4)

- [ ] Smoke test (`docs/QA-SMOKE-TEST.md`) — all 8 screens pass.
      Tester signature + date attached.
- [ ] v1 regression (`docs/QA-V1-REGRESSION.md`) — parity
      confirmed on a real sanitised v1.x dump. Rollback drill
      RTO recorded.
- [ ] Performance benchmark (`docs/QA-PERFORMANCE.md`) — all
      4 scenarios within target. Harness output attached.
- [ ] OWASP ZAP scan (`docs/QA-OWASP-ZAP.md`) — 0 CRITICAL, 0
      HIGH. HTML report attached.

## 4. Security review

- [ ] `SECURITY.md` final PGP fingerprint replaced with the real
      key hash (release engineer's responsibility, see F11.9
      NOTE).
- [ ] Operator-facing audit log queries produce zero
      `forbidden` / `bad_signature` rows on staging after smoke
      test (expected — none of the steps are unauthorised).

## 5. Manifest + versioning

- [ ] `facturascripts.ini::version = 2.0` confirmed (F10.8).
- [ ] `facturascripts.ini::min_version = 2025.6` unchanged.
- [ ] `facturascripts.ini::min_php = 8.0` set.
- [ ] README title reads `MoodleManagement v2.0` (both ES + EN
      halves, F11.1).

## 6. Tag + push

Run from plugin root, on the commit that passes §1–§5.

```bash
# Fast-forward v2.0-dev one last time if needed, then tag.
git update-ref refs/heads/v2.0-dev refs/heads/v2.0/fase-12-qa-release
git checkout v2.0-dev

# Annotated, signed tag.
git tag -a -s v2.0.0 -m "MoodleManagement v2.0.0

Comprehensive audit remediation release.
See CHANGELOG.md and docs/V2.0-ACTION-PLAN.md.
"

# Push branch + tag.
git push origin v2.0-dev
git push origin v2.0.0
```

- [ ] Tag applied.
- [ ] Tag pushed to origin.
- [ ] `git tag -v v2.0.0` verifies the signature.

## 7. Build the distribution ZIP

```bash
git archive --format=zip --prefix=MoodleManagement/ v2.0.0 \
    -o ../MoodleManagement-v2.0.0.zip
```

`.gitattributes` (F11.7) automatically strips:

- `.docs-dev/` (internal brainstorm doc)
- `docs/V2.0-ACTION-PLAN.md`, `docs/V2.0-TASK-CHECKLIST.md`,
  `docs/DEAD-CODE-AUDIT.md` (internal)
- `Test/`, `.github/`, `.editorconfig`, `.gitignore`,
  `.gitattributes`, `.php-cs-fixer.dist.php`,
  `phpcs.xml.dist`, `phpstan.neon.dist`, `phpstan-baseline.neon`,
  `phpunit.xml.dist`

Verify the ZIP:

```bash
unzip -l ../MoodleManagement-v2.0.0.zip | grep -E 'Test/|\.docs-dev/|V2.0-ACTION-PLAN'
# Expected: no output. The three internal docs must not appear.
```

- [ ] ZIP built.
- [ ] Smoke-verify: unzip into a scratch FS install, activate
      plugin, run Plugins → Update, confirm Dashboard renders.
- [ ] SHA-256 of the ZIP recorded in the release notes.

## 8. Publish

- [ ] Release notes published (GitHub Releases or equivalent)
      with the ZIP attached + SHA-256 + link to the Mermaid
      diagram in README.
- [ ] `CHANGELOG.md` link reference at the bottom updated to
      point `2.0.0` at the freshly created tag URL.
- [ ] SECURITY.md PGP key uploaded to
      `https://diegomonroydev.com/.well-known/pgp-key.asc`.
- [ ] Announcement prepared for the FacturaScripts community
      forum (optional but recommended — mentions required Moodle
      4.1 floor and the HTTPS requirement for webhooks).

## 9. Post-release

- [ ] `main` branch merged from `v2.0-dev`.
- [ ] `v2.1-dev` branch created for the next cycle with the
      deferred items from the Known Limitations table
      (automatic soft-delete, tomasvotruba/unused-public,
      wkhtmltopdf renderer, rename wizard, etc.).
- [ ] Monitor the staging host for 48 h. Any outage or
      regression opens a hotfix branch off `v2.0.0` tagged
      `v2.0.1`.

## Sign-off

Release engineer: `__________________`
Date: `__________________`
All boxes ticked: `Yes / No`

Attach this file (with ticks) to the release PR before merge.
