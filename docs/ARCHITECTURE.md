# MoodleManagement — architecture overview

`@since 2.0 — DOC-04 (2026-04-17)`

High-level reference for operators and contributors. The authoritative
source during v2.0 development was `CLAUDE.md` §3; this file lifts the
portions that matter for day-to-day ops out into a shippable document.

---

## Layers

```
┌─────────────────────────────────────────────────────┐
│  HTTP boundary                                       │
│    · Controller/Edit*.php, List*.php                 │
│    · Controller/ApiMoodleWebhook.php (public, HMAC)  │
│    · Controller/MoodleCertificatePdf.php (signed URL)│
├─────────────────────────────────────────────────────┤
│  Security primitives (Lib/Security/*)                │
│    HtmlSanitizer · CsvEscaper · SignedUrl            │
│    RateLimiter · CspHeader · TokenCipher · IpValidator│
│    SignedPayload (SEC-07)                            │
├─────────────────────────────────────────────────────┤
│  Domain                                              │
│    · Lib/Moodle/ (HttpClient, BoundClient, Api/*,    │
│      Contract/*, RetryPolicy, CircuitBreaker)        │
│    · Lib/Webhook/ (Verifier, Dispatcher, Handler/*,  │
│      PayloadValidator)                               │
│    · Lib/Matching/UserMatcher                        │
│    · Lib/Cron/Lock                                   │
│    · Lib/Migration/SchemaMigrator                    │
│    · Lib/Logger/{BufferedLogger,PiiMasker}           │
│    · Lib/Model/SoftDeleteTrait                       │
│    · Lib/Enum/* (5 status enums)                     │
│    · Lib/Exception/* (7 typed exceptions)            │
│    · Lib/WorkQueue/IdempotencyGuard (BE-03)          │
│    · Lib/Contact/ContactTimestampUpdater (BE-07)     │
│    · Lib/View/JsonForScript (FE-01)                  │
├─────────────────────────────────────────────────────┤
│  Legacy (migration pending in v2.1)                  │
│    · Lib/MoodleClient.php — god class, 100+ static   │
│      methods. Wired through CircuitBreaker + Retry.  │
├─────────────────────────────────────────────────────┤
│  Persistence                                         │
│    · Model/* (ActiveRecord vía FS ModelClass)        │
│    · Table/* (XML schema)                            │
│    · Update/v2_0.php (idempotent migrations)         │
├─────────────────────────────────────────────────────┤
│  Async                                               │
│    · Worker/* (6 workers · WorkQueue)                │
│    · Cron.php (8 jobs, cooperative locking)          │
└─────────────────────────────────────────────────────┘
```

## Cron jobs

| Job | Cadence | Purpose |
|-----|--------|---------|
| `moodle-health-check` | hourly | `core_webservice_get_site_info` probe per instance. Feeds `health_fail_count` streak (BE-06). |
| `moodle-user-sync` | 6 h | Re-validate FS ↔ Moodle user mappings. |
| `moodle-course-sync` | 6 h | Pull course catalogue from Moodle. |
| `moodle-reconciliation` | 1 d | Compare local enrolments with Moodle; mark orphans `unenrolled`. Preflight + per-course health probe (BE-04). |
| `moodle-cleanup` | 1 d | Bulk-delete orphan rows (BE-08). |
| `moodle-expiry-check` | 6 h | Warn on soon-to-expire enrolments. |
| `moodle-progress-sync` | 6 h | Fetch completion + grade into `moodle_enrolments`. |
| `moodle-logs-retention` | 1 d | Trim audit + webhook logs past `LOGS_RETENTION_DAYS` (DB-05). |

## Event flow (paid invoice → enrolment)

1. `FacturaCliente.Update` (pagada=1) fires.
2. `EnrolmentWorker::run` — dedupe via `IdempotencyGuard` (BE-03).
3. Resolve contact → `MoodleUserMap` → `MoodleCourseMap`.
4. `MoodleClient::callApi('enrol_manual_enrol_users')` — wrapped in
   `CircuitBreaker::allow` + `RetryPolicy::execute` (BE-02).
5. Persist `MoodleEnrolment` with `status = enrolled`.

## Data retention

| Table                  | Retention | Driver                               |
|------------------------|-----------|--------------------------------------|
| `moodle_audit_log`     | 90 d       | `moodle-logs-retention` cron (DB-05) |
| `moodle_webhook_log`   | 90 d       | same                                  |
| `moodle_enrolments`    | Indefinite | soft-delete via `deleted_at`         |
| `moodle_user_map`      | Indefinite | soft-delete via `deleted_at`         |
| `moodle_cohorts`       | Indefinite | soft-delete via `deleted_at`         |

## Threat model

See `SECURITY.md` § Threat model for the 5-zone breakdown. High-level
summary: the HTTP boundary, the background workers, the WS transport
to Moodle, the file cache, and the DB state-at-rest each have their
own trust boundary and their own controls.
