# QA — Smoke test checklist v2.0

`@since 2.0 — V2.0-ACTION-PLAN F12.1 · §F12.1`

Manual smoke-test script run by the release engineer **before**
tagging `v2.0.0`. Covers the eight screens that exercise every
audit-critical path. Execute on a dedicated staging host that
mirrors production config.

## Pre-flight

- [ ] Host matches production (PHP 8.0+ with cURL, MySQL 5.7+/PG 13+, HTTPS).
- [ ] `config.php` has a non-default `FS_COOKIES_EXPIRE`.
- [ ] At least one `MoodleInstance` row points at a reachable Moodle 4.1+.
- [ ] Dev cache flushed (`Tools::cache()->clear()` or `rm -rf MyFiles/Cache/*`).
- [ ] Test admin account + one non-admin operator account ready.
- [ ] `moodle_schema_version` row for `2.0.0-F10.5-username-strategy` exists
  (confirms every migration ran).

## 1. MoodleDashboard (`/MoodleDashboard`)

Focus: F10.9 cache + F3.1 Chart.js 4.

| Step | Expected |
|------|----------|
| 1.1 Load the page. | All 4 KPI cards render; 3 Chart.js canvases render. |
| 1.2 Reload immediately. | No 2nd SQL burst in slow-log (cache hit — F10.9). |
| 1.3 Append `?refresh=1` to the URL. | SQL burst reappears, counts refresh. |
| 1.4 Open DevTools console. | No `Chart is not a constructor` errors; no 2.x API warnings. |
| 1.5 Resize to < 768px. | Charts reflow responsively. |

## 2. EditMoodleInstance (`/EditMoodleInstance?code=<id>`)

Focus: F5.12 token cipher, F10.1 webhook_secret, F10.5 username_strategy.

| Step | Expected |
|------|----------|
| 2.1 Open an existing instance. | Token field shows password placeholder (no plaintext leak). |
| 2.2 Save with a new token. | `moodle_instances.token` rewrites with `mm2g:` prefix. |
| 2.3 Set `webhook_secret` + save. | Column rewrites with `mm2g:` prefix. |
| 2.4 Switch `username_strategy` → `random_alias` + save. | Value persists. |
| 2.5 Fill `url = http://127.0.0.1` + save. | Save is accepted — IpValidator runs on probe, not on save. |
| 2.6 Hit **Test connection**. | Logs show `ssrf_rejected`; instance flips to `unreachable`. |
| 2.7 Set URL to a real Moodle 4.1+ and **Test connection**. | Status = `active`, release version populated. |
| 2.8 Set URL to a Moodle 4.0. | Status = `unsupported`, `last_error = moodle-version-too-old`. |

## 3. ListMoodleUserMap + Import wizard

Focus: F3.13 session hardening, F2.6 cookie, F2.7 CSV export, F10.5 alias.

| Step | Expected |
|------|----------|
| 3.1 Open the list. Paginate. | Rows render; no N+1 warnings in slow log. |
| 3.2 Launch the Import wizard. | Step 1 form loads. Cookie `mm_wizard_type` is `HttpOnly; Secure; SameSite=Lax`. |
| 3.3 Step 2 — import a small CSV with a cell starting with `=SUM(A1)`. | Exported CSV has the cell prefixed with `'` (CsvEscaper). |
| 3.4 Step 4 — download result CSV. | Browser opens; BOM UTF-8 present; fields separated by `;`. |
| 3.5 Finish the wizard. Check `moodle_user_map` for a new row. | Row exists. Username matches the instance's `username_strategy`. |

## 4. EditMoodleUserMap (`/EditMoodleUserMap?code=<id>`)

Focus: F4.1 IDOR / F4.2 guard / F8.3 encapsulation / F10.5 alias.

| Step | Expected |
|------|----------|
| 4.1 Open as admin. | All fields visible. |
| 4.2 Log out, try to open the same URL. | Redirects to login (FS session guard). |
| 4.3 Log in as a non-admin operator without `codcliente` match. | Redirects to 404, audit row `forbidden`. |
| 4.4 Trigger **Sync to Moodle**. Instance uses `random_alias`. | Moodle user created with `mu_<12 hex>` username. |
| 4.5 Delete the contact. | `ContactDeleteWorker` suspends the Moodle user (not delete). |

## 5. MoodleCertificatePdf (`/MoodleCertificatePdf?code=<id>`)

Focus: F4.1 IDOR, F2.9 signed URL, F2.11 CSP, F7.8 logo hardening.

| Step | Expected |
|------|----------|
| 5.1 Download as admin. | PDF renders. Content-Type `application/pdf`. |
| 5.2 Anonymous GET without signature. | HTTP 401. |
| 5.3 Anonymous GET with signed URL from email. | HTTP 200. |
| 5.4 31 requests in 60 s from same IP. | HTTP 429 with `Retry-After: 60`. Audit row `rate_limited`. |
| 5.5 Response headers. | `Content-Security-Policy` present, every directive `'none'`. |
| 5.6 Edit a certificate template with a logo outside allowed roots (e.g. `/etc/passwd`). | PDF renders with no logo (resolveLogoPath returned null, no error leaked). |

## 6. ApiMoodleWebhook (`POST /ApiMoodleWebhook?instance=<id>`)

Focus: F10.1 full path.

| Step | Expected |
|------|----------|
| 6.1 POST with no headers. | HTTP 400 `missing required headers`. |
| 6.2 POST with a wrong HMAC. | HTTP 401, `moodle_webhook_log.signature_ok = false`, audit row `bad_signature`. |
| 6.3 POST with a good HMAC but `X-MM-Timestamp` 10 minutes old. | HTTP 409 `stale timestamp`. |
| 6.4 POST same nonce twice within a minute. | 2nd request HTTP 409 `replayed nonce`. |
| 6.5 POST with event `enrolment_created`. | HTTP 200, new row in `moodle_enrolments`, audit row `ok`. |
| 6.6 POST with event `unknown_type`. | HTTP 202 `status: ignored`. |
| 6.7 130 requests in 60 s from same IP. | HTTP 429. |

## 7. ListMoodleAuditLog + ListMoodleTrash

Focus: F10.3 audit UI, F10.4 papelera.

| Step | Expected |
|------|----------|
| 7.1 Open `/ListMoodleAuditLog` as admin. | Both tabs render; rows from the tests above visible. |
| 7.2 `btnNew` / `btnDelete` buttons absent. | Confirmed (append-only). |
| 7.3 Open `/ListMoodleTrash`. Set `deleted_at = now()` on one `moodle_user_map` via SQL. Refresh. | Row appears under User maps tab. |
| 7.4 Click **Restore**. | `deleted_at = NULL`, row disappears, audit row `trash.restore ok`. |
| 7.5 Manually set `deleted_at` again. Click **Purge**. | Row physically deleted, audit row `trash.purge ok`. |
| 7.6 Try to **Purge** a non-soft-deleted row (manual URL tamper). | Operation refused; audit row not recorded. |

## 8. MoodleDashboard → Cron → Progress

Focus: F10.2 progress sync, F6.4 cron locks, F10.10 buffered logger.

| Step | Expected |
|------|----------|
| 8.1 Seed a fresh `moodle_enrolment`. Run `php index.php cron`. | `progressSync` job runs; log shows `mm-batched-events` entries. |
| 8.2 Run cron twice in quick succession. | Second run logs `cron-skip-locked`, job body skipped. |
| 8.3 `SELECT progress_fetched_at FROM moodle_enrolments WHERE id=<seeded>`. | Timestamp present. |
| 8.4 Re-run cron within 1 hour. | No new WS call (PROGRESS_REFRESH_SECONDS skip). |
| 8.5 Wait >1 hour, re-run cron. | WS call issued, progress updated. |

## Release-blocker criteria

Any of the following halts the release:

- Chart.js console errors on dashboard.
- PDF endpoint returning anything other than 200 / 401 / 404 / 429 / 500 in §5.
- Webhook accepting a wrong-HMAC request.
- Worker cascade (BadgeSyncWorker firing on `.Save`).
- Migration rerun ends with `moodle-migrations-failed`.
- Trash purge removing a non-soft-deleted row.

## Sign-off

Tester: `__________________` · Date: `__________________`
Pass / Fail: `______`

Attach tester notes and browser console dumps to the release PR.
