# Upgrade Guide

This document describes how to upgrade between major versions of the
MoodleManagement plugin.

> **Note**: During v2.0 remediation (active), this is a **working
> skeleton**. Each phase of `docs/V2.0-ACTION-PLAN.md` will append its
> concrete migration steps and rollback instructions here.

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

**Target release**: `v2.0.0` (currently unreleased — see
`docs/V2.0-ACTION-PLAN.md`).
**Estimated downtime**: 2–5 minutes for DB migration + token re-cipher.

### 1.1 Breaking changes

_To be finalised per phase_. Expected areas of breakage:

| Area                       | Change                                                                  | Mitigation                                         |
|----------------------------|-------------------------------------------------------------------------|----------------------------------------------------|
| `MoodleClient` (Fase 8)    | God-class split into `Lib/Moodle/Api/*`. Facade retained for BC.        | No action required for plugin users.               |
| `moodle_instances.token`   | Column widened to `VARCHAR(500)` and encrypted at rest (AES-256-GCM).  | One-shot migration re-ciphers existing tokens.     |
| `moodle_enrolments.idfactura` | `ON DELETE CASCADE` → `ON DELETE SET NULL` + `idfactura_archived`.    | Fiscal trail preserved even if invoice is deleted. |
| `createViews()`            | Now called from `init()` (fresh install) as well as `update()`.          | No action. Existing installs re-run safely.        |
| Cookies                    | Gain `HttpOnly`, `Secure`, `SameSite=Lax`. JS can no longer read them.  | Wizard state briefly resets after upgrade.         |
| Twig `|raw`                | Removed from chat/notes/course content. User HTML now sanitised.         | Historic rows sanitised by one-shot migration.     |

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
   `Init::update()`, which runs `Update/v2.0.sql` idempotently:
   - Adds indexes (see §F5.3).
   - Widens `token` column.
   - Adds `mm_last_modified` on `contactos`.
   - Seeds default certificate template.
   - Creates `moodle_schema_version`, `moodle_audit_log`,
     `moodle_webhook_log`, `moodle_cron_state` tables.
   - Re-ciphers tokens via `TokenCipher::encryptAll()`.
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

_(Populated during Fase 12 QA.)_

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

*Last updated: 2026-04-17 · v2.0 kickoff (working skeleton)*
