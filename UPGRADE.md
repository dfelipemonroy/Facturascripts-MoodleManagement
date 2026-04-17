# Upgrade Guide

This document describes how to upgrade between major versions of the
MoodleManagement plugin. Everything below is **finalised** for the
v2.0 release (F11.8, 2026-04-17).

---

## General recommendations (all upgrades)

1. **Backup everything before upgrading**:
   ```bash
   # Application
   cp -r /path/to/facturascripts /backup/fs-$(date +%Y%m%d)
   # Database
   mysqldump --single-transaction --routines facturascripts \
     | gzip > /backup/fs-$(date +%Y%m%d).sql.gz
   ```
2. Confirm FacturaScripts version ≥ required `min_version` (see
   `facturascripts.ini`).
3. Put the site in **maintenance mode** during schema upgrades.
4. Check plugin `facturascripts.ini` compatibility before activating.
5. After upgrading, run the cron jobs **once manually** to surface any
   migration errors early.

---

## 1.x → 2.0

**Target release**: `v2.0.0` (tag applied at the end of Fase 12).
**Estimated downtime**: 2–5 minutes for DB migration + token re-cipher.

### 1.1 Breaking changes

Final list for v2.0. Every item is mitigated by an idempotent
migration in `Update/v2_0.php` unless noted otherwise.

| Area                              | Change                                                                                           | Mitigation                                             |
|-----------------------------------|--------------------------------------------------------------------------------------------------|--------------------------------------------------------|
| `MoodleClient` (Fase 8)           | God class split into `Lib/Moodle/*` + `Lib/Moodle/Api/*`. Facade retained for BC.                | No action for plugin users; new callers use facades.   |
| `moodle_instances.token`          | Widened to `VARCHAR(500)` and encrypted at rest (AES-256-GCM via `TokenCipher`).                 | `2.0.0-F5.11` + `2.0.0-F5.12-token-cipher` re-cipher.   |
| `moodle_instances.webhook_secret` | New `VARCHAR(500)` column for F10.1 HMAC key. NULL disables the endpoint.                        | `2.0.0-F10.1-webhook-secret`.                          |
| `moodle_instances.username_strategy` | New `VARCHAR(20)` defaulting `'name_based'`. Enables `random_alias` (F10.5).                   | `2.0.0-F10.5-username-strategy`.                       |
| `moodle_enrolments.idfactura`     | `ON DELETE CASCADE` → `ON DELETE SET NULL` + new `idfactura_archived`.                           | Fiscal trail preserved even if invoice is deleted.     |
| `moodle_enrolments` progress cols | New `progress_percent`, `completed_modules`, `total_modules`, `last_activity_at`, `progress_fetched_at`, `completion_date`, `final_grade`. | `2.0.0-F10.2-enrolment-progress`.                      |
| `moodle_enrolments` / `user_map` / `cohorts` | New `deleted_at` column for soft-delete + papelera.                                   | `2.0.0-F5.20-soft-delete`.                             |
| `moodle_user_map.badge_sync_needed` | New TINYINT flag. BadgeSyncWorker rebound from `Save` → `Insert` to cut the F6.1 cascade.       | `2.0.0-F6.1-badge-sync-needed`.                        |
| `contactos.mm_last_modified`      | New TIMESTAMP used by F7.4 conflict resolver.                                                   | `2.0.0-F7.4-contacto-mm-last-modified`.                |
| New tables                        | `moodle_audit_log` (F4.4), `moodle_webhook_log` (F10.1), `moodle_schema_version` (F5.1).         | Created by `Init::bootstrapSchema()` + FS XML install. |
| FK index catalogue                | 8 new secondary indexes on FK columns (user_map / enrolments / course_map / certificates).       | `2.0.0-F5.3-fk-indexes`.                               |
| `createViews()`                   | Now called from `init()` (fresh install) as well as `update()`.                                  | No action; idempotent.                                 |
| Cookies                           | `HttpOnly`, `Secure`, `SameSite=Lax` added to `mm_wizard_type` (F2.6).                           | Wizard state briefly resets after upgrade.             |
| Twig `|raw`                       | Removed from chat/notes/course content. User HTML sanitised via `Lib/Security/HtmlSanitizer`.   | Historic rows render unchanged (sanitiser is display-time). |
| Chart.js                          | Bumped 2.9 → 4.x (F3.1). Dashboard options schema rewritten.                                     | IE11 dropped; Safari ≥ 16 required.                    |
| MoodleClient::MIN_MOODLE_RELEASE | Bumped to `'4.1'` (F7.18). Older instances flip to `status=unsupported`.                         | Upgrade Moodle before plugin, or acknowledge the flag. |

### 1.2 Pre-flight checklist

- [ ] Backup DB and plugin folder (see general section above).
- [ ] Record current `moodle_instances.token` values in a sealed envelope
      (safety net in case decipher fails post-upgrade).
- [ ] Verify **Moodle version ≥ 4.1** on every configured instance.
- [ ] Close any in-flight WorkQueue items (let workers drain).

### 1.3 Steps

1. Activate maintenance mode in FacturaScripts admin.
2. Replace the `Plugins/MoodleManagement` folder contents with the v2.0
   release ZIP.
3. From FS admin: **Plugins → Update**. This triggers
   `Init::update()`, which runs `Update/v2_0.php` idempotently in
   a single pass. Each migration is keyed by a version tag stored
   in `moodle_schema_version`. The full order is:
   - `2.0.0-F5.3-fk-indexes` — 8 FK secondary indexes.
   - `2.0.0-F5.5-idfactura-archived` — add preserved column.
   - `2.0.0-F5.8-instance-name-unique` — UNIQUE on instance name.
   - `2.0.0-F5.11-token-varchar-500` — widen token column.
   - `2.0.0-F5.12-token-cipher` — re-cipher existing tokens.
   - `2.0.0-F5.14-enrolment-status-check` — CHECK constraint.
   - `2.0.0-F5.15-created-updated-by` — audit columns.
   - `2.0.0-F5.18-cert-unique-hash` — UNIQUE on certificate hash.
   - `2.0.0-F5.20-soft-delete` — `deleted_at` on 3 tables.
   - `2.0.0-F5.10-collation-utf8mb4` — collation unification (MySQL).
   - `2.0.0-F5.7-cohorts-codgrupo-fk` — FK to `gruposclientes`.
   - `2.0.0-F5.22-role-map-timestamps` — created_at/updated_at.
   - `2.0.0-F6.1-badge-sync-needed` — explicit resync flag.
   - `2.0.0-F7.4-contacto-mm-last-modified` — contact sync marker.
   - `2.0.0-F10.1-webhook-secret` — webhook HMAC key column.
   - `2.0.0-F10.2-enrolment-progress` — progress columns.
   - `2.0.0-F10.5-username-strategy` — name_based / random_alias.
   Plus `Init::bootstrapSchema()`:
   - `createViews()` — `moodle_course_categories_view`.
   - `seedDefaultCertificateTemplate()` — one row if missing.
4. Run: **Tools → Cron** once. Verify each cron logs `ok`.
5. Smoke test:
   - Dashboard renders without console errors.
   - Import wizard launches.
   - Existing `UserMap` and `Enrolment` lists populated and paginated.
6. Disable maintenance mode.

### 1.4 Rollback

If anything goes wrong before step 6:

1. Enable maintenance mode.
2. Restore DB from pre-upgrade backup:
   ```bash
   gunzip -c /backup/fs-YYYYMMDD.sql.gz | mysql facturascripts
   ```
3. Restore plugin folder from backup.
4. Disable maintenance mode.

If the upgrade completed but a regression appears post-hoc, see
**Section 3 — Rollback from 2.0 to 1.1** below.

### 1.5 Known issues during upgrade

Compiled from the v2.0 remediation plan and ongoing staging runs.

| Symptom                                                               | Likely cause                                                                       | Action                                                                                                     |
|-----------------------------------------------------------------------|------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------|
| `token-cipher-decrypt-failed` after upgrade                           | `FS_COOKIES_EXPIRE` rotated between backup and upgrade.                             | Restore tokens from the sealed envelope (step 1.2) and re-save via the instance Edit form.                |
| `moodle-migrations-failed` with "Duplicate key"                       | Partial previous run; DDL applied but version tag wasn't stored.                    | Insert the missing version tag into `moodle_schema_version` and re-run Plugins → Update.                   |
| Instance flips to `status=unsupported` post-upgrade                   | Remote Moodle < 4.1 (F7.18).                                                       | Upgrade the Moodle site to 4.1 LTS or acknowledge and keep the downgraded status.                          |
| Dashboard counts look stale for ~1 minute                             | F10.9 60 s cache; benign.                                                          | Append `?refresh=1` or wait.                                                                               |
| Wizard shows empty step 1 after login                                 | F2.6 moved the `mm_wizard_type` cookie to HttpOnly/Secure/SameSite.                 | Re-submit step 1; the wizard re-populates its state.                                                       |
| `ssrf_rejected` on a private Moodle                                   | F7.1 IP validator rejects RFC1918 / loopback / link-local.                          | Route the plugin through a hostname that resolves to a public IP (split-DNS or a TLS-terminating proxy).   |
| `progress_fetched_at` never populates                                 | Cron not draining.                                                                 | Ensure `moodle-progress-sync` job is scheduled (see `docs/EVENTS.md`).                                     |

### 1.6 Post-upgrade verification

Run through this checklist before re-opening the site:

- [ ] `SELECT version FROM moodle_schema_version ORDER BY applied_at DESC LIMIT 1;` returns the latest tag.
- [ ] `SELECT COUNT(*) FROM moodle_instances WHERE token NOT LIKE 'mm2g:%'` returns 0 (all tokens re-ciphered).
- [ ] Dashboard renders with Chart.js 4 (no console warnings about 2.x APIs).
- [ ] Health check cron runs at least once and every instance lists `status=active`.
- [ ] Audit log viewer loads (`/ListMoodleAuditLog` — admin only).
- [ ] Trash viewer loads (`/ListMoodleTrash`).
- [ ] A manual webhook test returns HTTP 200 when sent with a valid HMAC — see `docs/EVENTS.md` §5.

---

## 2. Minor-version upgrades (2.x → 2.x+1)

Minor releases ship schema changes that are **additive only** and
**fully idempotent**. The standard path is:

1. Backup.
2. Replace files.
3. FS admin → Plugins → Update.
4. Run cron once.

---

## 3. Rollback from 2.0 to 1.1

> **Not recommended in production.** Only use to recover from a failed
> 2.0 deployment.

Token re-ciphering is the main reason rollback is delicate: once tokens
are encrypted by v2.0, rolling back to 1.1 means restoring the pre-cipher
DB snapshot. There is no script to decrypt-in-place without the
derivation key present in the running FS instance.

1. Maintenance mode on.
2. Restore DB from the pre-upgrade dump.
3. Replace plugin folder with the v1.1 ZIP.
4. FS admin → Plugins → Update (runs v1.1 idempotent init).
5. Maintenance mode off.

---

## 4. FAQs

**Q. The upgrade failed halfway. What now?**
Restore from backup (§1.4). File an issue with the SQL errors you saw
and the plugin version you came from.

**Q. Can I skip from 1.0 to 2.0 directly?**
Yes — the v2.0 migration supersedes all prior ones. The idempotent
`Update/v2.0.sql` script detects and upgrades from any 1.x state.

**Q. My Moodle tokens disappeared.**
Check `moodle_instances.token` length. If rows show the old 40-char
value, the cipher step didn't run. Re-run: *Plugins → Update*. If rows
show a ~200-char base64 but connectivity fails, the derivation key
changed (FS_COOKIE_EXPIRE rotated). Restore tokens manually from the
sealed envelope (§1.2) and re-save them via the Edit screen — they
will be re-ciphered with the current key.

---

## 5. Partitioning strategy (large deployments)

For deployments with > 500k rows in `moodle_enrolments` or
`moodle_audit_log`, consider range partitioning by
`YEAR(created_at)`. This is **opt-in** and not applied by the automatic
migration. Example:

```sql
ALTER TABLE moodle_enrolments
  PARTITION BY RANGE (YEAR(created_at)) (
    PARTITION p2023 VALUES LESS THAN (2024),
    PARTITION p2024 VALUES LESS THAN (2025),
    PARTITION p2025 VALUES LESS THAN (2026),
    PARTITION p2026 VALUES LESS THAN (2027),
    PARTITION pFuture VALUES LESS THAN MAXVALUE
  );
```

See [`docs/V2.0-ACTION-PLAN.md`](docs/V2.0-ACTION-PLAN.md) §F5.23.

### 5.1 Schema type conventions (v2.0)

The v2.0 migrations (`Update/v2_0.php`) observe the following
conventions so operators auditing the schema can predict column
semantics at a glance (F5.16, F5.17, F5.19):

| Category                                           | Choice              | Why                                                       |
|----------------------------------------------------|---------------------|-----------------------------------------------------------|
| FS-native timestamps (rows tracked by the plugin) | `TIMESTAMP`         | Integrates with FS `DateTime` widgets and SQL functions.  |
| Moodle epoch columns (timestart, timeend, …)      | `INTEGER`           | Matches the Moodle WS payload; rendered via `WidgetMoodleTimestamp`. |
| Long free-form prose (notes, last_error, custom_fields_map) | `TEXT`       | Unbounded operator input; MySQL stores InnoDB off-page with negligible overhead. |
| Free-form short labels / identifiers              | `VARCHAR(<=200)`    | Keeps in-row, supports equality indexes.                 |
| Monetary values                                    | N/A in plugin tables | All money lives in FS core (`facturascli.total`).         |

**F5.19 (DECIMAL review)**: the plugin does not own any
monetary column. Money propagates through `facturascli`, which
is governed by FS core conventions.

### 5.2 Idempotent seeds

Every seed executed by `Init::bootstrapSchema()` (F5.2 default
certificate template) is guarded by an existence check before
insert, so re-running the plugin installer does not duplicate
rows. F5.21 therefore applies to any future seed: use either
`INSERT IGNORE` or an up-front `SELECT` as
`seedDefaultCertificateTemplate()` does.

---

## 6. Performance — webserver configuration

v2.0 ships new static assets under `Plugins/MoodleManagement/Assets/`.
FacturaScripts itself does not emit long-lived `Cache-Control`
headers for plugin assets, so configure your webserver to cache
them aggressively — filenames are versioned with the release tag,
so `max-age=604800` (7 days) is safe.

### Apache

```apache
<LocationMatch "^/Plugins/MoodleManagement/Assets/">
    Header set Cache-Control "public, max-age=604800, immutable"
</LocationMatch>
```

### Nginx

```nginx
location ~* ^/Plugins/MoodleManagement/Assets/ {
    add_header Cache-Control "public, max-age=604800, immutable" always;
}
```

The `immutable` directive is optional but recommended: once the
admin deploys a new release, asset filenames change (or an entry
in `MyFiles/routes.json` is refreshed), so the browser is never
asked to revalidate mid-session.

_See V2.0-ACTION-PLAN §F3.14._

---

*Last updated: 2026-04-17 · finalised in V2.0-ACTION-PLAN F11.8.*
