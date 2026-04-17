# Troubleshooting — MoodleManagement v2.0

`@since 2.0 — V2.0-ACTION-PLAN F11.2 · §9.2`

Symptom → cause → fix reference for the most common plugin
incidents. Entries are ordered by rough incidence rate.

## 1. Connectivity

### 1.1 Health check fails with `ssrf_rejected`

| Field | Value |
|-------|-------|
| Symptom | `Lib/Security/IpValidator::isValid` rejects the instance URL; `MoodleInstance::status = unreachable`. |
| Cause | The configured URL resolves to a private / loopback / link-local IP. The SSRF guard added in F7.1 treats those as untrusted. |
| Fix | Point the instance URL at a publicly resolvable hostname. In split-DNS environments, add the public CNAME to `/etc/hosts`. If intentional (on-prem lab), set `moodle_instances.status='development'` and document the deviation — the guard does not re-probe in that mode. |

### 1.2 `moodle-version-too-old`

| Field | Value |
|-------|-------|
| Symptom | Instance status flips to `unsupported`; dashboard banner persistent. |
| Cause | `applySiteInfo()` read `release` older than `MoodleClient::MIN_MOODLE_RELEASE` ("4.1"). |
| Fix | Upgrade the Moodle instance to 4.1 LTS or newer. Forcing `status=active` in DB will be reset on the next hourly health check. |

### 1.3 Token decrypt failures in the log (`token-cipher-decrypt-failed`)

| Field | Value |
|-------|-------|
| Symptom | Log entry `token-cipher-malformed` or `token-cipher-decrypt-failed`. |
| Cause | The `FS_COOKIES_EXPIRE` secret changed between Init runs, so the HKDF-derived key no longer matches. |
| Fix | Either restore the previous cookie secret, or re-enter every Moodle token through the UI — each save re-encrypts. The data migration `2.0.0-F5.12-token-cipher` is idempotent, so running `Init::update()` again is safe. |

## 2. Sync / WorkQueue

### 2.1 Enrolments stay `pending` after payment

| Field | Value |
|-------|-------|
| Symptom | Invoice is paid, but `moodle_enrolments.status` stays `pending`. |
| Cause | `EnrolmentWorker` didn't run — WorkQueue not drained (daemon off, crontab gap). |
| Fix | Run `php cron.php` manually, or verify `moodle-cron` OS timer is active. Check `Tools::log('moodle-cron')` for errors. |

### 2.2 Renewal estimate created but duplicate fires again next day

| Field | Value |
|-------|-------|
| Symptom | A fresh `PresupuestoCliente` shows up daily for the same expired enrolment. |
| Cause | The F6.2 skip-cache key (`mm:skip-preenrol:<id>`) expired or the cache backend is memory-only in a multi-node setup. |
| Fix | Configure a persistent cache backend (`FS_CACHE`), or extend the TTL in `Cron::EXPIRY_CHECK_JOB`. The 300s default covers single-node deployments. |

### 2.3 BadgeSyncWorker loops

| Field | Value |
|-------|-------|
| Symptom | Same user map triggers BadgeSyncWorker repeatedly. |
| Cause | Legacy code still binds the worker to `Model.MoodleUserMap.Save`. F6.1 rewired it to `.Insert`. |
| Fix | Apply `Init.php` from v2.0 cleanly (check the regression test `WorkerCascadeGuardTest`). The test asserts the wiring as source. |

## 3. Certificates

### 3.1 PDF download returns 404

| Field | Value |
|-------|-------|
| Symptom | `/MoodleCertificatePdf?code=<id>` → 404. |
| Cause | F4.1 IDOR fix: when ownership/signature/admin all fail, the response is a deliberate 404 (not 403) to prevent enumeration. |
| Fix | Log in as admin, or use a fresh signed URL (email-issued). Logs show the rejection reason under `certificate-pdf-denied`. |

### 3.2 PDF returns 500 "generation-failed"

| Field | Value |
|-------|-------|
| Symptom | PDF endpoint returns HTTP 500 with generic message. |
| Cause | `CertificatePdfGenerator` exception — typically a broken logo path blocked by F7.8 hardening, or Cezpdf not loaded. |
| Fix | Check `certificate-pdf-error` log (trace hash included). Verify logo path doesn't contain traversal, schemes, or unsupported extensions (SVG rejected). |

### 3.3 Rate limited

| Field | Value |
|-------|-------|
| Symptom | `HTTP 429 too-many-requests` with `Retry-After: 60`. |
| Cause | More than 30 downloads / minute from the same actor (user or IP). |
| Fix | Wait 60 seconds. If legitimate, raise `MoodleCertificatePdf::RATE_LIMIT` and redeploy. |

## 4. Webhooks

### 4.1 All webhooks rejected with `bad-signature`

| Field | Value |
|-------|-------|
| Symptom | `moodle_webhook_log.signature_ok = false` for every row. |
| Cause | Either the bridge uses a different secret than `moodle_instances.webhook_secret`, or TokenCipher couldn't decrypt the stored secret. |
| Fix | Re-save the webhook secret from the instance edit form (it will be re-encrypted). Test with `hash_hmac('sha256', $rawBody, $secret)` against the header. |

### 4.2 `stale-timestamp` (HTTP 409)

| Field | Value |
|-------|-------|
| Symptom | `X-MM-Timestamp` older than 5 minutes. |
| Cause | Clock skew between Moodle and FS hosts. |
| Fix | Configure NTP on both sides. The 5-minute window (`WebhookVerifier::TIMESTAMP_WINDOW`) is intentionally tight to limit replay. |

### 4.3 `replayed-nonce`

| Field | Value |
|-------|-------|
| Symptom | HTTP 409 with `message: "replayed nonce"`. |
| Cause | The bridge sent the same nonce twice within the last hour. |
| Fix | Make sure the bridge generates a fresh nonce per request (`bin2hex(random_bytes(16))`). |

## 5. Dashboard & UI

### 5.1 Dashboard counts don't update after a bulk sync

| Field | Value |
|-------|-------|
| Symptom | Refreshing the page shows the same numbers. |
| Cause | F10.9 cache TTL (60 s). |
| Fix | Append `?refresh=1` or wait 60 seconds. |

### 5.2 Charts render empty on old browsers

| Field | Value |
|-------|-------|
| Symptom | Canvas stays blank, console shows "Chart is not defined". |
| Cause | Browser predates Chart.js 4.x requirements (Safari < 16, older Edge). |
| Fix | Upgrade the browser. v2.0 does not ship a fallback. |

## 6. Migrations

### 6.1 `migration already applied` during upgrade

| Field | Value |
|-------|-------|
| Symptom | `moodle-migrations-failed` in the log with "duplicate key" or "already exists". |
| Cause | A previous partial run left the migration row absent but the DDL applied (usually DB crashed mid-ALTER). |
| Fix | Insert the version tag manually into `moodle_schema_version` and re-run `Init::update()`. `SchemaMigrator::ensureVersionTable()` documents the contract. |

### 6.2 `webhook_secret column missing` after upgrade

| Field | Value |
|-------|-------|
| Symptom | Save on MoodleInstance throws "Unknown column 'webhook_secret'". |
| Cause | Partial v2.0 upgrade — F10.1 migration didn't run. |
| Fix | `php index.php install` (FS global install path re-runs plugin Init). Or manually add `ALTER TABLE moodle_instances ADD COLUMN webhook_secret VARCHAR(500) NULL`. |

## 7. Quick diagnostic commands

```bash
# 1. Dump the last 100 plugin log lines
tail -n 100 MyFiles/Logs/moodle-cron.log

# 2. Count WorkQueue depth per event
mysql -e "SELECT event, COUNT(*) FROM workqueue GROUP BY event"

# 3. Check migration state
mysql -e "SELECT * FROM moodle_schema_version ORDER BY applied_at DESC"

# 4. Verify cooperative lock is not held
mysql -e "SELECT IS_FREE_LOCK('mm.moodle-user-sync')"

# 5. Smoke-test the webhook signature
php -r '
$body = "{}";
$secret = "SECRET_FROM_DB";
echo hash_hmac("sha256", $body, $secret) . "\n";'
```

## 8. Escalation path

1. Reproduce with `display_errors = On` in staging.
2. Capture `Tools::log()` output for the failing request.
3. Check the matching entry in `moodle_audit_log` or `moodle_webhook_log`.
4. Open an issue at [github.com/dfmonroy/moodlemanagement](https://github.com/dfmonroy/moodlemanagement) including:
   - FS version (`grep version facturascripts.ini`)
   - Plugin version (`2.0.x`)
   - PHP + DB versions (`php -v`, `SELECT VERSION()`)
   - Log excerpt with PII masked via `Lib/Logger/PiiMasker`.
