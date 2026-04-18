# Changelog

All notable changes to the MoodleManagement plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.0.0] — Unreleased

Comprehensive audit-remediation release. **16/16 CRITICAL findings
from the first-iteration audit closed**, 155/155 tracked tasks green.
See [`docs/V2.0-ACTION-PLAN.md`](docs/V2.0-ACTION-PLAN.md) for the
original scope and
[`docs/V2.0-TASK-CHECKLIST.md`](docs/V2.0-TASK-CHECKLIST.md) for
per-finding commits.

The release was held back after a second-iteration audit (2026-04-17)
surfaced 91 further findings. See
[`docs/V2.0-POST-AUDIT-PLAN.md`](docs/V2.0-POST-AUDIT-PLAN.md) for
the remediation plan split across Fases 15 / 16 / 17 / 18 — all of
which must close before `v2.0.0` is tagged.

### Added
- **Webhook receiver** `/ApiMoodleWebhook` with HMAC-SHA256 + timestamp
  window + nonce replay guard (F10.1). 4 handlers: enrolment_created,
  enrolment_deleted, course_completed, user_updated.
- **Progress sync cron** `moodle-progress-sync` every 6 h with a 1 h
  freshness skip (F10.2). Populates `progress_percent`,
  `completed_modules`, `total_modules`, `last_activity_at`,
  `progress_fetched_at`, `completion_date`, `final_grade`.
- **Audit log viewer** `/ListMoodleAuditLog` (F10.3) — read-only, two
  tabs covering `moodle_audit_log` + `moodle_webhook_log`.
- **Papelera** `/ListMoodleTrash` (F10.4) — admin-only restore/purge
  UI over `deleted_at IS NOT NULL`.
- **Random alias username** strategy (F10.5) — per-instance opt-in via
  `moodle_instances.username_strategy`.
- **Lib/Security/** — HtmlSanitizer, CsvEscaper, SignedUrl, RateLimiter,
  CspHeader, TokenCipher, IpValidator (Fases 2 + 5 + 7).
- **Lib/Moodle/** namespaced facade layer — HttpClient, UsernameGenerator,
  ConflictResolver, BoundClient, RetryPolicy, CircuitBreaker + 7 Api
  classes (User / Course / Enrolment / Cohort / Badge / File / Completion)
  (Fases 8 + 10).
- **Lib/Webhook/** — WebhookVerifier + WebhookDispatcher + 4 handlers.
- **Lib/Matching/UserMatcher** + **Lib/Logger/PiiMasker** +
  **Lib/Logger/BufferedLogger** (F8.2, F8.5, F10.10).
- **Lib/Cron/Lock** cooperative GET_LOCK / pg_try_advisory_lock wrapper.
- **Lib/Migration/SchemaMigrator** — idempotent DDL runner with
  `moodle_schema_version` ledger.
- **Lib/Controller/ProductoMoodleDecorator** (F8.3 — closes the last
  CRITICAL around Extension encapsulation).
- **Lib/Enum/** — EnrolmentStatus, InstanceStatus, UserMapStatus,
  CertificateStatus (F1.10).
- **Lib/Exception/** hierarchy — 6 classes (F1.11).
- **Lib/Widget/WidgetMoodleTimestamp** for INT epoch columns (F3.7).
- 10 unit test classes + 4 integration tests + GitHub Actions CI
  matrix (PHP 8.0/8.1/8.2) (Fase 9).
- 8 release-facing docs:
  `docs/TROUBLESHOOTING.md`, `docs/EVENTS.md`,
  `docs/SUPPORTED-VERSIONS.md`, `docs/DEAD-CODE-AUDIT.md`,
  `docs/PHP-INI-HARDENING.md`, `docs/QA-SMOKE-TEST.md`,
  `docs/QA-V1-REGRESSION.md`, `docs/QA-PERFORMANCE.md`,
  `docs/QA-OWASP-ZAP.md`, `docs/QA-LINT-ANALYSE.md`,
  `docs/RELEASE-CHECKLIST.md`.

### Changed
- `Lib/MoodleClient.php` — legacy god class retained for BC; new
  callers route through `Lib/Moodle/Api/*` facades.
- `MoodleDashboard` payload cached for 60 s (F10.9) with `?refresh=1`
  bypass.
- `Cron.php` — every job wrapped in cooperative lock (F6.4),
  paginated scanners with `BATCH_SIZE = 500` (F6.3), `progressSync`
  wired through `BufferedLogger` (F10.10).
- `Init.php::bootstrapSchema()` now runs createViews + SchemaMigrator
  + seed on both `init()` and `update()` (F5.4).
- `BadgeSyncWorker` rebound from `Model.MoodleUserMap.Save` to
  `.Insert` to cut the cascade (F6.1) — guarded by
  `Test/Integration/WorkerCascadeGuardTest`.
- `PreEnrolmentWorker` subscribes to `LineaPresupuestoCliente.Delete`
  + `LineaPedidoCliente.Delete` (F6.10).
- Chart.js bumped 2.9 → 4.x with full options schema migration (F3.1).
- Inline `onclick=` → `data-mm-*` event delegation across Twig views
  (F3.2) via new `Assets/JS/mm-actions.js`.
- Polling loops gain exponential backoff (F3.3) and 300 ms debounce
  on conversation search (F3.4).
- `moodle_instances.token` widened to `VARCHAR(500)` and encrypted at
  rest (F5.11 + F5.12).
- 8 missing FK indexes added (F5.3).
- `moodle_enrolments.idfactura` cascade relaxed to `SET NULL` +
  `idfactura_archived` column to preserve fiscal trail (F5.5).
- `MoodleClient::MIN_MOODLE_RELEASE = '4.1'` — older instances
  flagged `status=unsupported` (F7.18).
- README, UPGRADE, SECURITY, CONTRIBUTING bumped from "working
  skeleton" to release-grade (Fase 11).

### Deprecated
- Direct static calls to `Lib/MoodleClient.php` — prefer
  `Lib/Moodle/Api/*` facades. Facade layer stable since F8.1; direct
  calls will keep working for the v2.x line but are no longer
  recommended in new code.

### Removed
- Twig `|raw` on user-generated content in UserChat, UserNotes and
  CourseContent (F2.1 through F2.4).
- Legacy emoji pictographs in source comments (F1.1).
- Spanish prose from code comments (F1.3).

### Fixed
- **Stored XSS** in UserChat, UserNotes, CourseContent (F2.1-F2.4).
- **IDOR** on `/MoodleCertificatePdf` — full owner/signed-URL/admin
  matrix + rate limit + CSP (F4.1).
- **Information disclosure** in PDF exception path — generic user
  message + structured log + trace hash (F2.5).
- **Cookies** gain HttpOnly/Secure/SameSite=Lax (F2.6).
- **WorkQueue cascade loop** — BadgeSyncWorker + generateRenewalEstimate
  (F6.1 + F6.2).
- **EditProducto encapsulation** — extension now calls FS public API
  only via `ProductoMoodleDecorator` (F8.3).
- **`createViews()` not running on fresh install** (F5.4).
- **SQL injection latent** in
  `MoodleCourseCategory::codeModelAll` (F5.6).
- **Path traversal** on certificate logo — three-root allowlist +
  realpath + extension filter (F7.8).
- **Cron stampede** — cooperative GET_LOCK across every job (F6.4).
- **Health-probe thundering herd** — 60 s per-instance cache (F6.11).
- **Cascade delete on `moodle_enrolments.idfactura`** losing fiscal
  trail (F5.5).
- **Username collisions** — `generateUniqueUsername` probes Moodle
  with up to 100 suffix attempts + 8-char hex fallback (F7.3).
- **Moodle version mismatch** — applySiteInfo flips status to
  `unsupported` on < 4.1 (F7.18).
- **Dashboard Chart.js 2.x warnings** after upgrade (F3.1).

### Security
- **All 16 CRITICAL findings from the first-iteration audit closed**
  — full list in `SECURITY.md` §Security posture.
- **Second-iteration audit Fase 17 (2026-04-17) MEDIUM findings worked** (38 items — 12 code-fixed, 15 verified/superseded, 11 deferred with rationale; see `docs/V2.0-POST-AUDIT-PLAN.md` §FASE 17 for per-item status):
  - **SEC-09 / FE-05** · `HtmlSanitizer` URL scheme + `isSafeImageSrc`
    lowercase-normalise the scheme before every match so `JaVaScRiPt:`
    variants cannot slip past a future case-sensitive edit.
  - **SEC-11** · `RateLimiter` cache-key hash upgraded SHA-1 → SHA-256.
  - **SEC-12** · `CspHeader::apply` now runs on `MoodleDashboard` and
    `MoodleImportWizard` in addition to the PDF endpoint.
  - **SEC-14** · `moodle_audit_log` gains `row_hash` + `prev_hash`
    columns (stamped at write-time). Verifier tooling is v2.1.
  - **BE-06** · `moodle_instances.health_fail_count` streak counter,
    threshold 3 → structured `health-check-quarantine` log.
  - **DB-02** · `SoftDeleteTrait` throws `UnsupportedSchemaException`
    on composite primary keys rather than silently targeting the
    wrong row.
  - **DB-05** · New `moodle-logs-retention` cron trims
    `moodle_audit_log` + `moodle_webhook_log` past
    `LOGS_RETENTION_DAYS = 90`.
  - **DB-07** · Indexes on `moodle_user_map.moodle_username` +
    `moodle_userid` so Moodle-side lookups stay O(log n).
  - **DB-08** · `TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP` on three
    plugin columns (MySQL only; Postgres already defaulted).
  - **DB-11** · `moodle_instances.url` UNIQUE (with pre-flight dedupe).
  - **DB-12** · `moodle_cohorts.codgrupo` FK → `ON DELETE SET NULL`.
  - **DB-13** · `utf8mb4_unicode_520_ci` normalise across 8 tables.
  - **F18.34 (migrated)** · `deleted_at` indexes on the three
    soft-delete tables.
  - **FE-06** · `UserChat` `.catch` handler surfaces 5xx via
    `console.warn` + aria-live status line instead of silent failure.
  - **FE-09** · `WidgetMoodleTimestamp` + `WidgetCourseimage` now
    pass `ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5` to
    `htmlspecialchars`.

- **Second-iteration audit Fase 16 (2026-04-17) HIGH findings closed** (18 items):
  - **SEC-04** · `WebhookVerifier::resolveSecret` fail-closed on decrypt
    failure so the raw ciphertext never leaks as HMAC key material.
  - **SEC-05** · `SignedUrl` gains a version-tagged HKDF info label
    (`mm/signed-url/v1`) and a `KEY_VERSIONS` map; rotating keys is
    a one-line change and in-flight URLs keep verifying until `exp`.
  - **SEC-06** · Certificate PDF ownership check walks the full
    Contacto graph (billing, shipping, every row with
    `codcliente`) rather than only `idcontactofact`.
  - **SEC-07** · Wizard prefs cookie sealed with HMAC via the new
    `Lib/Security/SignedPayload` helper. Context-bound info label
    prevents cross-feature replay.
  - **SEC-08** · `MoodleClient::callApi` advertises the token via
    `Authorization: Bearer` alongside the body `wstoken` so OAuth
    gateways and reverse proxies can authenticate without parsing
    the body.
  - **BE-01** · `declare(strict_types=1)` across every controller,
    worker, and extension (33 files).
  - **BE-02** · `MoodleClient::callApi` gated by
    `CircuitBreaker::allow` + wrapped in `RetryPolicy::execute`;
    the two helpers, shipped but orphaned in Fase 6.7, finally
    protect every WS dispatch.
  - **BE-03** · `EnrolmentWorker` + `OnboardingWorker` dedupe via
    the new `Lib/WorkQueue/IdempotencyGuard` so redelivered events
    cannot produce duplicate Moodle-side side effects.
  - **BE-05** · Partial-failure `warnings` arrays surfaced in the
    log and, optionally, as `exception = partial_failure` when the
    caller passes `fail_on_partial = true`.
  - **BE-07** · Raw `UPDATE contactos SET mm_last_modified` SQL
    extracted into `Lib/Contact/ContactTimestampUpdater` helper.
  - **BE-08** · Orphan cleanup cron uses a single-SQL bulk DELETE
    capped at 1000 rows per run, replacing the N+1 model loop.
  - **BE-09** · `BufferedLogger` gets `MAX_BATCH_SIZE = 500` and
    `HARD_BUFFER_CAP = 2000` with a loud overflow warning.
  - **BE-10** · `ListMoodleTrash::tableOf` throws
    `InvalidArgumentException` on unknown entities so audit logs
    carry the offending tag.
  - **DB-01** · `TokenCipher::encryptExistingRows` wrapped in a
    single transaction so a mid-batch failure rolls back cleanly.
  - **DB-03** · F5.12 asserts F5.11 precondition at runtime via
    `SchemaMigrator::columnCharLength`.
  - **INT-01** · `Lib/Webhook/PayloadValidator` normalises + validates
    every inbound webhook payload per event type.
  - **FE-03** · `HtmlSanitizer::toPlainText` replaces Twig `|
    striptags` across three templates; DOM-based, resistant to the
    `<script>body</script>` bypass.
  - **FE-04** · `Assets/JS/mm-dom-safe.js` exposes `mmSetText`,
    `mmClearAndAppend`, `mmCreate` so text-only is the obvious
    default for future UI work.

- **Second-iteration audit Fase 15 (2026-04-17) blockers closed**:
  - **SEC-01** · `TokenCipher::deriveKey` now fails closed when
    `FS_COOKIES_EXPIRE` is missing. Previously fell back to a
    hardcoded string that made stored tokens recoverable from
    source.
  - **SEC-02** · `ListMoodleAuditLog` and `ListMoodleTrash` enforce
    an explicit `$user->admin` check in `privateCore`. Non-admin
    operators with list-page permission can no longer enumerate
    logged IPs, user-agents, or soft-deleted rows.
  - **SEC-03** · `MoodleClient::callApi` rejects plain-HTTP Moodle
    endpoints unless `FS_DEBUG` is true. Previously the `wstoken`
    travelled in the POST body over HTTP, harvestable on the wire.
  - **FE-01** · `MoodleDashboard` pre-serialises chart payloads via
    the new `JsonForScript::encode` helper (`JSON_HEX_TAG | APOS |
    QUOT | AMP | THROW_ON_ERROR`). Twig `| json_encode | raw` is no
    longer used inside `<script>` blocks.
  - **FE-02** · `CourseContent` and `CourseGroups` templates route
    Moodle-supplied strings through the `json_for_script` Twig
    function registered by `Init::init`. CI job
    `Twig JSON safety linter` greps for regressions.
  - **BE-04** · `Cron::reconcileEnrolments` probes the instance with
    `testConnection` before paginating and re-probes before acting
    on an empty `getEnrolledUsers` response. Prevents bulk
    `unenrolled` flips triggered by silent WS failures.
- AES-256-GCM at rest for Moodle tokens + webhook secrets, derived
  from `FS_COOKIES_EXPIRE` via HKDF-SHA256 (F5.12).
- HMAC-SHA256 on signed certificate URLs (F2.9) + webhook payloads
  (F10.1), constant-time compare via `hash_equals`.
- SSRF guard via `Lib/Security/IpValidator` + cURL
  `FILTER_FLAG_NO_RES_RANGE` (F7.1).
- CSP `'none'` on PDF endpoint (F2.11); FS core retains its
  default policy elsewhere.
- Append-only audit log (`moodle_audit_log`, F4.4) + dedicated
  webhook log (`moodle_webhook_log`, F10.1).
- PII in logs masked via `Lib/Logger/PiiMasker` (F8.5).

---

## [1.1.0] — 2026-04-09

### Added
- 6 refinement features (v2.0-E through v2.0-J):
  - Configurable certificate templates.
  - Configurable expiry e-mail days.
  - Dashboard range selector.
  - Wizard memory cookie.
  - CSV export from lists.
  - Manual expiry e-mail resend.
- Certificate template model + controllers (`EditMoodleCertificateTemplate`,
  `ListMoodleCertificateTemplate`).
- `Lib/CertificatePdfGenerator.php`, `Lib/ExpiryNotifier.php`.
- `Controller/MoodleCertificatePdf.php`, `Controller/MoodleImportWizard.php`.
- `Assets/JS/CertificatePdf.js`.

### Changed
- `README.md` updated (bilingual ES/EN) with the 6 refinements.
- `.docs-dev/BRAINSTORMING.md` documents technical decisions for each refinement (moved out of `docs/` in F11.7 so the release ZIP stays slim; still tracked in the repo).

---

## [1.0.0] — 2025-03-05

### Added
- Initial public release.
- Bidirectional FS ↔ Moodle integration via Moodle REST API.
- 8 tables: `moodle_instances`, `moodle_user_map`, `moodle_course_map`,
  `moodle_enrolments`, `moodle_cohorts`, `moodle_role_map`,
  `moodle_course_categories`, `moodle_certificates`.
- 5 workers: `EnrolmentWorker`, `PreEnrolmentWorker`, `ContactSyncWorker`,
  `ContactDeleteWorker`, `BadgeSyncWorker`.
- 6 cron jobs: `healthCheck`, `userSync`, `courseSync`, `reconciliation`,
  `cleanup`, `expiryCheck`.
- 3 controller extensions: `EditContacto`, `EditCliente`, `EditProducto`.

[2.0.0]: https://github.com/your-org/MoodleManagement/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/your-org/MoodleManagement/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/your-org/MoodleManagement/releases/tag/v1.0.0
