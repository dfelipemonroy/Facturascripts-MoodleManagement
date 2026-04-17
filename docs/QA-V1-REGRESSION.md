# QA — v1.x → v2.0 upgrade regression test

`@since 2.0 — V2.0-ACTION-PLAN F12.2 · §F12.2`

Regression procedure run on a **real v1.x production backup**
before tagging `v2.0.0`. The goal is to prove the idempotent
migration set in `Update/v2_0.php` promotes a v1 database
cleanly and the upgraded plugin stays functional.

## Prerequisites

- [ ] A sanitised v1.2 production DB dump (≥ 3 instances, ≥ 100
      user maps, ≥ 1k enrolments, ≥ 10 certificates).
- [ ] A throwaway staging host matching the production stack
      (PHP / FS / DB versions).
- [ ] Two fresh branches:
    - `staging` with v1.2 plugin folder.
    - `release` with v2.0 plugin folder.
- [ ] Access to the original `FS_COOKIES_EXPIRE` secret (or
      reset it intentionally — see §3).

## 1. Baseline on v1.2

1. Restore the dump into staging.
2. Copy the v1.2 plugin into `Plugins/MoodleManagement`.
3. Activate the plugin from FS admin.
4. **Baseline snapshots**:
    - `SELECT COUNT(*) FROM moodle_user_map;` → record `$BASE_USERS`.
    - `SELECT COUNT(*) FROM moodle_enrolments;` → record `$BASE_ENROL`.
    - `SELECT COUNT(*) FROM moodle_certificates;` → record `$BASE_CERT`.
    - Export dashboard KPIs (Retention, Renewal, Expiring, Completion).
    - Download one certificate PDF. Keep its SHA-256 in a file
      (`sha256sum baseline-cert.pdf > baseline.sha256`).

## 2. Upgrade to v2.0

1. Enable FS maintenance mode.
2. Replace the plugin folder with v2.0.
3. From FS admin: **Plugins → Update**.
4. Watch logs. Expected sequence:
    - `moodle-view-create-failed` (only if the view already exists,
      benign — PostgreSQL) OR silent (MySQL CREATE OR REPLACE).
    - `token-cipher-migration-ok` with
      `encrypted = $BASE_INSTANCES`, `skipped = 0`.
    - No `moodle-migrations-failed` entries.
5. Disable maintenance mode.

## 3. Post-upgrade parity

Run the same queries and dashboard KPIs as §1.

| Check                                     | Expected |
|-------------------------------------------|----------|
| `SELECT COUNT(*) FROM moodle_user_map;`   | `$BASE_USERS`   |
| `SELECT COUNT(*) FROM moodle_enrolments;` | `$BASE_ENROL`   |
| `SELECT COUNT(*) FROM moodle_certificates;` | `$BASE_CERT` |
| Dashboard KPIs                            | Match §1 snapshot |
| Re-download same certificate + compare sha256 | Equal (PDF layout unchanged) |

**Acceptance**: every row count matches exactly; dashboard KPIs
within ±0 (v2.0 does not recompute any historic metric).

## 4. v2.0-only paths

Exercise the features that did not exist in v1:

- [ ] Instance edit → set `webhook_secret` + `username_strategy`.
- [ ] POST a valid webhook to `/ApiMoodleWebhook`.
- [ ] Force `deleted_at = now()` on one `user_map` row; open
      `/ListMoodleTrash` and restore via the button.
- [ ] Run `php index.php cron`; verify `moodle-progress-sync`
      populates `progress_percent` on at least one enrolment.
- [ ] Open `/ListMoodleAuditLog` — rows from the previous steps
      show up.

## 5. Idempotent re-upgrade

1. From FS admin: **Plugins → Update** **again** (without changing
   the plugin folder).
2. Log should show:
    - `token-cipher-migration-ok` with
      `encrypted = 0`, `skipped = $BASE_INSTANCES`.
    - Every migration key in `moodle_schema_version` stays at a
      single row each (no duplicates).

## 6. Rollback drill

Measured once per release. Should be rehearsed at least 24 h
before the production upgrade.

1. Snapshot the upgraded DB (`mysqldump` / `pg_dump`).
2. Restore the **pre-upgrade** dump.
3. Replace the plugin folder with v1.2.
4. From FS admin: **Plugins → Update**.
5. Expected: v1.2 site fully functional, no data loss, no
   foreign-key violations.

Time the whole drill; record **RTO** for the runbook.

## 7. Known-ok warnings (do not block)

- `mm-webhook-log-save-failed` during §2 before the table exists
  (first save runs immediately after the migration creates the
  table — race is cosmetic).
- PHP deprecation warnings from `Cezpdf` on PHP 8.2+ (vendored by
  FS core, will settle when FS upgrades the library).

## Sign-off

Tester: `__________________` · Date: `__________________`
Baseline sha256:
```
__________________________________________________________________
```
Post-upgrade sha256:
```
__________________________________________________________________
```
Rollback RTO: `______` min · Pass / Fail: `______`

Attach logs and snapshots to the release PR.
