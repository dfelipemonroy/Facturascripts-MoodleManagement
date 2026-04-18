# Webhook runbook

Operational guide for the F10.1 inbound webhook receiver. Pair this
with `scripts/webhook-bridge-example.php` for the Moodle-side
reference bridge.

`@since 2.0 — DOC-03 (2026-04-17)`

---

## 1. Endpoint

```
POST /ApiMoodleWebhook
```

- Public path: no FS session required.
- Three HTTP headers expected on every request:
  - `X-MM-Event`     — event name (see §2).
  - `X-MM-Signature` — `sha256=<hex-hmac-over-raw-body>`.
  - `X-MM-Timestamp` — Unix epoch when the bridge computed the sig.
  - `X-MM-Nonce`     — opaque unique id (≤128 chars) to close the
    replay window.

Rate limit: 60/min per client IP.

## 2. Supported events

| Event name           | Payload shape (validated by `PayloadValidator`)            |
|----------------------|------------------------------------------------------------|
| `enrolment_created`  | `userid:int, courseid:int, timestart?:int, timeend?:int`   |
| `enrolment_deleted`  | `userid:int, courseid:int`                                 |
| `course_completed`   | `userid:int, courseid:int, completiondate?:int, grade?:number` |
| `user_updated`       | `userid:int, username?, email?, firstname?, lastname?`     |

Unknown events return `status = ignored` with HTTP 200 so Moodle-side
operators can subscribe new observers without FS breakage.

## 3. Rotating the HMAC secret

1. Generate a 32-byte random secret (`openssl rand -hex 32`).
2. Update `moodle_instances.webhook_secret` via the UI; the value is
   automatically encrypted through `TokenCipher` (F5.12 migration).
3. Publish the new secret to the Moodle-side bridge config.
4. Both sides should roll forward within 5 minutes (the timestamp
   window) — older-secret requests will start failing
   `verifySignature`. Log signal:
   `mm-webhook-bad-signature` (rate-limited via the replay guard).

## 4. Troubleshooting

Symptom | Likely cause | Where to look
---|---|---
`invalid payload shape` (`STATUS_ERROR`) | Moodle-side bridge emitted a string where int expected | `moodle_webhook_log.payload_hash` + `mm-webhook-invalid-payload` log line
Every request rejected | Clock skew > 5 min | `X-MM-Timestamp` vs `now`; ensure NTP healthy
Same nonce replay rejection | Bridge not regenerating nonce | Check bridge code; each POST must mint a fresh nonce
Handler throws | Downstream model/FS state inconsistent | `mm-webhook-handler-failed` with exception class

## 5. Retention

`moodle_webhook_log` rows older than `LOGS_RETENTION_DAYS` (90) are
purged by the `moodle-logs-retention` cron (DB-05).
