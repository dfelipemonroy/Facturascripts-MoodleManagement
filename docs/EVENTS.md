# Events — MoodleManagement v2.0

`@since 2.0 — V2.0-ACTION-PLAN F11.5 · §9.5`

Catalogue of every event the plugin consumes (inbound) or emits
(outbound). Use this as the contract reference when writing a
custom worker, debugging a WorkQueue stall, or building a
webhook bridge on the Moodle side.

## Legend

| Symbol | Meaning |
|--------|---------|
| ↘      | The plugin listens / consumes. |
| ↗      | The plugin emits. |
| 🔁     | Cron job (periodic, not event-driven). |
| 🌐     | External (HTTP) entry point. |

## 1. FacturaScripts core events the plugin listens on

Declared in `Init.php`. Each row is a call to
`WorkQueue::addWorker(<WorkerName>, <EventTag>)`.

| ↘ | Event tag                                        | Worker              | What triggers it                                                    | Side effects                                                                 |
|---|--------------------------------------------------|---------------------|---------------------------------------------------------------------|------------------------------------------------------------------------------|
| ↘ | `Model.FacturaCliente.Update`                    | EnrolmentWorker     | FS invoice saved (e.g. paid flag flipped).                           | Create/advance `moodle_enrolments` rows for Moodle-flagged products.        |
| ↘ | `Model.PresupuestoCliente.Update`                | PreEnrolmentWorker  | Estimate saved.                                                     | Stage pre-enrolment placeholder rows.                                       |
| ↘ | `Model.PedidoCliente.Update`                     | PreEnrolmentWorker  | Order saved.                                                        | Same as estimate, different source.                                         |
| ↘ | `Model.LineaPresupuestoCliente.Delete`           | PreEnrolmentWorker  | Estimate line deleted (F6.10).                                      | Rollback staged enrolments.                                                 |
| ↘ | `Model.LineaPedidoCliente.Delete`                | PreEnrolmentWorker  | Order line deleted (F6.10).                                         | Rollback staged enrolments.                                                 |
| ↘ | `Model.Contacto.Update`                          | ContactSyncWorker   | FS contact edited.                                                  | Push delta to Moodle via `core_user_update_users` (30 s debounce, F6.8).    |
| ↘ | `Model.Contacto.Delete`                          | ContactDeleteWorker | FS contact removed.                                                 | Suspend-only Moodle user (F7.15); never physical delete.                    |
| ↘ | `Model.MoodleUserMap.Insert`                     | BadgeSyncWorker     | Fresh user mapped (F6.1 — rebound from Save to Insert).              | Initial badge sync via `core_badges_get_user_badges`.                       |
| ↘ | `Model.MoodleUserMap.Insert`                     | OnboardingWorker    | Fresh user mapped.                                                  | Enrol into onboarding course/cohort + welcome message.                      |

**Cascade guard**: the regression test
`Test/Integration/WorkerCascadeGuardTest` asserts the
Insert-only wiring; CI blocks a rebinding to `.Save` which would
reintroduce the F6.1 loop.

### Worker execution order (DOC-02)

FS WorkQueue does not define a strict priority between workers
subscribed to the same event. When two workers react to
`Model.MoodleUserMap.Insert`, the order is the order they were
registered in `Init::init`. Current order:

1. `BadgeSyncWorker` — runs first; fetches initial badges before the
   onboarding pipeline publishes any profile note that would need
   the badge count.
2. `OnboardingWorker` — runs second; enrols in the welcome course,
   adds to the cohort, sends the welcome message, creates the note.

Both workers are **idempotent as of F16.8** via
`IdempotencyGuard::beginOnce`. A redelivered event is a no-op. Key:

- BadgeSync: the guard is enforced inside
  `BadgeSyncWorker::run` (ran again for v2.0 via `badge_sync_needed`
  flag + explicit enqueue); actual de-dup happens Moodle-side since
  the WS call is idempotent.
- Onboarding: `onboard:usermap=<id>` with TTL 24 h. Explicitly
  forget-by-operator via `IdempotencyGuard::clear` from a support
  script.

Workers wired to `Model.FacturaCliente.Update` (only `EnrolmentWorker`)
fire once per `save()` call. Dedup key includes `pagada` so a paid
→ unpaid flip re-fires.

Workers wired to `Model.Contacto.Update` (only `ContactSyncWorker`)
debounce 30 s (F6.8) via a cache-based lock before reaching Moodle.

## 2. Plugin-emitted model events

FS automatically emits `Model.<Class>.<Insert|Update|Delete>` on
every save/delete. Third-party plugins can subscribe to any of
these via `WorkQueue::addWorker`.

| ↗ | Event tag                          | Source                    | Payload |
|---|------------------------------------|---------------------------|---------|
| ↗ | `Model.MoodleInstance.Insert`      | First-time instance save. | `{id, name, url, status}` |
| ↗ | `Model.MoodleInstance.Update`      | Instance edited.          | Full model columns.      |
| ↗ | `Model.MoodleCourseMap.Insert`     | Course mapped.            | `{id, idinstance, moodle_courseid, idproducto}` |
| ↗ | `Model.MoodleCourseMap.Update`     | Course metadata refresh.  | Full model columns.      |
| ↗ | `Model.MoodleEnrolment.Insert`     | New enrolment staged.     | `{id, idcontacto, idcourse_map, status}` |
| ↗ | `Model.MoodleEnrolment.Update`     | Status / completion move. | Full model columns.      |
| ↗ | `Model.MoodleCertificate.Insert`   | Badge sync landed.        | `{id, idcontacto, unique_hash}` |

Useful subscribers live in user code — e.g. a custom plugin that
listens on `Model.MoodleCertificate.Insert` to push a copy to a
DMS.

## 3. Cron jobs (not event-driven)

| 🔁 | Job name                      | Class method                 | Schedule  | What it does |
|----|-------------------------------|------------------------------|-----------|--------------|
| 🔁 | `moodle-health-check`         | `Cron::healthCheck`          | 1 h       | Probe every active instance; 60 s per-instance cache (F6.11). |
| 🔁 | `moodle-user-sync`            | `Cron::userSync`             | 6 h       | Paginated pull of Moodle user profiles (F6.3). |
| 🔁 | `moodle-course-sync`          | `Cron::courseSync`           | 6 h       | Paginated pull of course metadata. |
| 🔁 | `moodle-reconciliation`       | `Cron::reconciliation`       | 1 d       | Re-query Moodle enrolment state; fix drift. |
| 🔁 | `moodle-cleanup`              | `Cron::cleanup`              | 1 d       | Prune stale cache entries + summary log line (F6.9). |
| 🔁 | `moodle-expiry-check`         | `Cron::expiryCheck`          | 6 h       | Generate renewal estimates + email notifications. |
| 🔁 | `moodle-progress-sync`        | `Cron::progressSync`         | 6 h       | Refresh completion + grade per enrolment (F10.2). |

All jobs are wrapped in `Lib/Cron/Lock` (F6.4) so concurrent triggers
skip silently and only one process per instance progresses.

## 4. Inbound webhook events

Handled by `Controller/ApiMoodleWebhook` (F10.1). Routed to
`Lib/Webhook/Handler/*` via `WebhookDispatcher`.

Required request shape:

| Header          | Value                                         |
|-----------------|-----------------------------------------------|
| `X-MM-Signature`| `sha256=<hex>` — HMAC-SHA256 over raw body     |
| `X-MM-Timestamp`| Unix epoch, must be within ±5 minutes         |
| `X-MM-Nonce`    | Opaque ≤128 chars, unique within 1 hour       |
| `X-MM-Event`    | One of the event types below                  |

Query: `?instance=<MoodleInstance.id>`. Body: JSON.

| 🌐 | Event type             | Handler                        | Payload contract                                       |
|----|------------------------|--------------------------------|--------------------------------------------------------|
| 🌐 | `enrolment_created`    | `EnrolmentCreatedHandler`      | `{userid, courseid, timestart?, timeend?}`            |
| 🌐 | `enrolment_deleted`    | `EnrolmentDeletedHandler`      | `{userid, courseid}`                                  |
| 🌐 | `course_completed`     | `CourseCompletedHandler`       | `{userid, courseid, timecompleted, grade?}`          |
| 🌐 | `user_updated`         | `UserUpdatedHandler`           | `{userid, username?, email?, firstname?, lastname?}` |

Unknown event types respond `202 ignored` rather than 404 so new
Moodle-side observers can be added incrementally.

## 5. HMAC signing reference

Both directions use the same HMAC construction so a bridge can
share a single helper.

```php
$secret  = /* moodle_instances.webhook_secret, decrypted */;
$rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
$sig     = hash_hmac('sha256', $rawBody, $secret);
// Header: X-MM-Signature: sha256=<sig>
```

The receiver uses `hash_equals` internally — constant-time compare
— so timing attacks against the signature are not feasible.

## 6. Observability surfaces

- `moodle_audit_log` — security events, IDOR denials, rate limits,
  bad signatures. Written by `Lib/Audit::record()`.
- `moodle_webhook_log` — every inbound webhook call, signature
  outcome, bytes, payload_hash. Written by
  `Controller/ApiMoodleWebhook`.
- `Tools::log()` — FS structured log. Channels used by the plugin:
  `moodle-cron`, `moodle-user-sync`, `moodle-progress-sync`,
  `moodle-webhook`, `certificate-pdf-error`, …

## 6.b Reference bridge (Moodle-side)

Since Moodle core does not emit HTTP webhooks natively, the
operator must add a thin bridge on the Moodle host. The
plugin ships a reference CLI script at
[`scripts/webhook-bridge-example.php`](../scripts/webhook-bridge-example.php)
that can be wired into a Moodle Event Observer.

Typical Moodle-side wiring (simplified):

```php
// In local_yourplugin/classes/observer.php
class observer {
    public static function enrol_user_created(\core\event\user_enrolment_created $e): void {
        $payload = json_encode([
            'userid'    => $e->relateduserid,
            'courseid'  => $e->courseid,
            'timestart' => (int) $e->timecreated,
        ], JSON_UNESCAPED_SLASHES);
        exec('php /opt/moodle-webhook-bridge.php enrolment_created ' . escapeshellarg($payload));
    }
}
```

And the matching `db/events.php`:

```php
$observers = [
    [
        'eventname' => '\core\event\user_enrolment_created',
        'callback'  => '\local_yourplugin\observer::enrol_user_created',
    ],
];
```

The bridge script deliberately has **no Moodle dependency** —
it only needs ext-curl and ext-openssl. Copy it anywhere on
the Moodle host, edit `$FS_BASE_URL`, `$INSTANCE_ID`, and
`$SECRET`, and drive it from any shell-exec friendly path.

> For Moodle 4.3+ hosts with Messaging API / webhooks
> extensions available, prefer a pure-PHP in-process
> delivery that reuses this script's signing logic and
> avoids a shell exec. The signing / envelope code is
> already identical to what the receiver verifies.

## 7. Stability contract

Event names in this file are part of the v2.0 public contract.
Changes require:

1. New migration + changelog entry.
2. Two-version deprecation window (emit both old + new name).
3. Update to the Mermaid diagram in `README.md`.

Adding new events without removing old ones is non-breaking.
