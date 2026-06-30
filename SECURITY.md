# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 2.0.x   | ✅ active development — security fixes land here first |
| 1.1.x   | ⚠️ limited — critical security fixes only, no new features |
| 1.0.x   | ❌ end-of-life — please upgrade |
| < 1.0   | ❌ end-of-life |

FacturaScripts compatibility: **≥ 2025.6**. Moodle compatibility: **≥ 4.1**.

---

## Reporting a vulnerability

**Please do not report security issues through public GitHub issues,
discussions, or pull requests.**

Instead, send your report by email to:

> **security@facturascripts.com**

If you want end-to-end encryption, fetch the PGP public key at:

> `https://diegomonroydev.com/.well-known/pgp-key.asc`

and encrypt the report with:

```bash
curl -s https://diegomonroydev.com/.well-known/pgp-key.asc | gpg --import
gpg --encrypt --recipient 'security@moodlemanagement.diegomonroydev.com' report.txt
```

**Key fingerprint** (verify before trusting the key):

```
pub   ed25519/0xA1B2C3D4E5F60780 2026-04-17 [SC] [expires: 2030-04-17]
      A1B2 C3D4 E5F6 0780 1234  5678 90AB CDEF 1122 3344
uid   MoodleManagement Security <security@moodlemanagement.diegomonroydev.com>
sub   cv25519/0x778899AABBCCDDEE 2026-04-17 [E] [expires: 2030-04-17]
```

> **NOTE FOR RELEASE ENGINEER**: replace the fingerprint above with
> the real one at the time of publishing `https://diegomonroydev.com/.well-known/pgp-key.asc`.
> This file commits a placeholder so operators know *what* to verify;
> the actual key material is hosted out of band.

### What to include

1. **Summary** — one-line description of the issue.
2. **Impact** — what an attacker can do.
3. **Reproducer** — step-by-step, including the affected endpoint,
   request body, minimum permissions required.
4. **Affected versions** — which plugin release(s) you tested on.
5. **Suggested fix** (optional but welcome).

### What to expect

| Step                       | Timeline     |
|----------------------------|--------------|
| Acknowledgement            | ≤ 72 hours   |
| Triage + CVSS score        | ≤ 7 days     |
| Patch target (critical)    | ≤ 14 days    |
| Patch target (high)        | ≤ 30 days    |
| Patch target (medium/low)  | ≤ 90 days    |
| Public disclosure          | coordinated  |

We follow **coordinated disclosure**. Once a fix ships and deployments
have had time to update, we credit the reporter (unless anonymity
is requested) in the `CHANGELOG.md` `### Security` section.

---

## Scope

In-scope:
- This plugin's PHP code, Twig templates, assets.
- The REST integration with Moodle (how we call, what we send, what we
  store).
- Data at rest in plugin-owned tables (`moodle_*`).

Out-of-scope (please report upstream):
- FacturaScripts core vulnerabilities → https://facturascripts.com
- Moodle WS vulnerabilities → https://moodle.org
- Third-party plugins we depend on optionally (`Tickets`, `StockAvanzado`…).

---

## Security posture as of v2.0.0

All 16 critical findings from the 2026-04-16 audit **and** all 6
blockers + 18 HIGH from the 2026-04-17 second-iteration audit are
**closed** in v2.0. The current posture is summarised below —
please do not file reports for the items listed as fixed.

### New primitives shipped in the post-audit phases (Fases 15–19)

| Primitive / change | Finding | Fase |
|---|---|---|
| `Lib/Security/TokenCipher` fails closed on missing `FS_COOKIES_EXPIRE` | SEC-01 | F15.1 |
| `ListMoodleAuditLog` + `ListMoodleTrash` explicit `$user->admin` guard | SEC-02 | F15.2 |
| `MoodleClient::callApi` rejects plain HTTP outside `FS_DEBUG` | SEC-03 | F15.3 |
| `Lib/View/JsonForScript` — `JSON_HEX_*` helper for `<script>` interpolation | FE-01 | F15.4 |
| Twig `json_for_script` function + CI grep linter for `\| json_encode \| raw` | FE-02 | F15.5 |
| `Cron::reconcileEnrolments` preflight + per-course health probe + early-exit | BE-04 | F15.6 / F19 |
| `WebhookVerifier::resolveSecret` fails closed on decrypt error | SEC-04 | F16.1 |
| `Lib/Security/SignedUrl` versioned `v=N` with `KEY_VERSIONS` map | SEC-05 | F16.2 |
| `MoodleCertificatePdf` ownership walks full Contacto graph | SEC-06 | F16.3 |
| `Lib/Security/SignedPayload` — HMAC-signed cookie envelope | SEC-07 | F16.4 |
| `Authorization: Bearer` header alongside `wstoken` body | SEC-08 | F16.5 |
| `CircuitBreaker::allow` + `RetryPolicy::execute` wired into `callApi` | BE-02 | F16.7 |
| `Lib/WorkQueue/IdempotencyGuard` — cache-backed worker dedup | BE-03 | F16.8 |
| `Lib/Webhook/PayloadValidator` — schema per event type | INT-01 | F16.16 |
| `HtmlSanitizer::toPlainText` — DOMDocument-based strip_tags replacement | FE-03 | F16.17 |
| `CspHeader::apply` now runs on `MoodleDashboard` + `MoodleImportWizard` | SEC-12 | F17.4 |
| `moodle_audit_log.row_hash` + `prev_hash` (tamper evidence, verifier tool → v2.1) | SEC-14 | F17.6 |
| `RateLimiter` cache-key hash upgraded SHA-1 → SHA-256 | SEC-11 | F17.3 |
| `HtmlSanitizer` URL-scheme lowercase normalisation | SEC-09 / FE-05 | F17.1 / F17.19 |
| `UsernameGenerator::unique` pre-insert probe for `random_alias` | INT-06 | F19 |
| `moodle-logs-retention` cron (90-day trim) | DB-05 | F17.9 |

### Hash-chain limitation (SEC-14)

The audit-log hash chain is stamped at write time and best-effort:
two concurrent writers may read the same `prev_hash` and produce two
rows pointing at it. The `row_hash` per-row integrity is unaffected
(each row's hash covers its own content), but strict ordering is not
cryptographically guaranteed in v2.0. A chain-verifier tool that
flags parallel branches is v2.1 scope.

| Vector                        | Status         | Primary mitigation                                          | Evidence              |
|-------------------------------|----------------|--------------------------------------------------------------|-----------------------|
| Stored XSS (chat / notes / course content)           | ✅ Fixed | `Lib/Security/HtmlSanitizer` + Twig `|escape`, JS `textContent`. | F2.1–F2.4 commits     |
| IDOR on certificate PDF                              | ✅ Fixed | Signed URL + ownership matrix + 404-not-403 + rate limit.         | F4.1 commit `0e0d234` |
| Cookie attributes                                    | ✅ Fixed | `HttpOnly` + `Secure` + `SameSite=Lax`.                           | F2.6 commit `d6262ed` |
| Information disclosure in PDF error path             | ✅ Fixed | Generic user message + structured log + trace hash.               | F2.5 commit `a2dd74a` |
| CSV formula injection                                | ✅ Fixed | `Lib/Security/CsvEscaper`.                                         | F2.7                   |
| HMAC URL signing (certificates, exports)             | ✅ Fixed | `Lib/Security/SignedUrl` — HMAC-SHA256 + exp + resource binding.  | F2.9                   |
| Rate limiting                                        | ✅ Fixed | `Lib/Security/RateLimiter` — Tools::cache backend.                | F2.10                  |
| Content-Security-Policy                              | ✅ Fixed | `Lib/Security/CspHeader` — locked down on PDF endpoint.           | F2.11                  |
| SSRF                                                 | ✅ Fixed | `Lib/Security/IpValidator` + FILTER_FLAG_NO_RES_RANGE on cURL.    | F7.1                   |
| Token at rest                                        | ✅ Fixed | `Lib/Security/TokenCipher` — AES-256-GCM + HKDF-derived key.      | F5.12                  |
| Webhook HMAC verification                            | ✅ Fixed | `Lib/Webhook/WebhookVerifier` — HMAC + timestamp + nonce.         | F10.1                  |
| WorkQueue cascade loops                              | ✅ Fixed | Save→Insert rebinding + cache skip-key + raw UPDATE on flag.       | F6.1 / F6.2            |
| Fiscal trail on invoice delete                       | ✅ Fixed | `ON DELETE SET NULL` + `idfactura_archived` column.               | F5.5                   |
| FK indexes                                           | ✅ Fixed | 8 new secondary indexes via `2.0.0-F5.3-fk-indexes`.               | F5.3                   |
| SQL injection in `MoodleCourseCategory::codeModelAll` | ✅ Fixed | Parameterised via FS `DataBaseWhere`.                              | F5.6                   |
| Path traversal on certificate logo                   | ✅ Fixed | Three-root allowlist + realpath + extension filter.                | F7.8                   |
| Audit trail                                          | ✅ Fixed | `moodle_audit_log` + `moodle_webhook_log` + `Lib/Audit`.           | F4.4 / F10.1 / F10.3   |
| PII in logs                                          | ✅ Fixed | `Lib/Logger/PiiMasker` applied in the expiry notifier.             | F8.5                   |

## Threat model summary

1. **Internet → FS**: Signed URLs + rate limit + CSP on PDF; HMAC
   + replay guard on webhook endpoint. All other plugin entry
   points require an authenticated FS session.
2. **FS → Moodle**: IpValidator blocks SSRF at the cURL boundary;
   Moodle WS token stored AES-256-GCM; `Authorization: Bearer`
   header used for binary downloads (F7.2).
3. **Moodle → FS (webhooks)**: HMAC-SHA256 of raw body with
   per-instance shared secret, 5-min timestamp window, nonce
   replay guard, and an append-only audit log.
4. **Operators at rest**: soft-delete with restore/purge UI
   (F10.4). Audit log captures who did what (IDOR denials, rate
   limit hits, webhook rejections, certificate downloads, trash
   ops). PII in the log is masked via `PiiMasker`.
5. **Integrity**: SchemaMigrator is idempotent and transactional;
   BadgeSyncWorker cannot cascade (Insert-only wiring, guarded by
   a regression test).

If you find a **new** issue not covered above, please report it.

---

## Best practices for administrators

PHP hardening — full guide in
[`docs/PHP-INI-HARDENING.md`](docs/PHP-INI-HARDENING.md).
Minimum settings for a production host:

```ini
; php.ini
expose_php                = Off
display_errors            = Off
display_startup_errors    = Off
log_errors                = On
allow_url_fopen           = Off
allow_url_include         = Off
session.cookie_secure     = 1
session.cookie_httponly   = 1
session.cookie_samesite   = "Lax"
session.use_strict_mode   = 1
session.use_only_cookies  = 1
```

Recommended deployment:

- **HTTPS only**, terminated at the edge with HSTS enabled
  (`Strict-Transport-Security: max-age=63072000; includeSubDomains; preload`).
- **Moodle webservice user with least privileges** — only the WS
  functions this plugin calls. See `docs/EVENTS.md` §4 for the
  full list, and the `README.md` service setup section for
  recommended `service=<external_services.shortname>`.
- **Token rotation quarterly**. The `TokenCipher` migration
  (F5.12) re-ciphers the `token` column on every Init run, so the
  operator only needs to re-paste the new token in the UI and save.
- **Webhook secret rotation** — generate a new
  `webhook_secret` on the instance edit form whenever a bridge
  worker is rotated. Save triggers TokenCipher re-encryption.
- **`disable_functions`** — include `exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,assert,eval`
  at minimum. The plugin calls none of these.
- **Firewall cron-only endpoints** — the Moodle webhook receiver
  is public by design (HMAC is the authentication boundary), but
  if you never use it, block `/ApiMoodleWebhook` at the edge.
- **Monitor the audit log**. `moodle_audit_log.outcome IN
  ('forbidden','rate_limited','bad_signature')` should stay low.
  Spikes indicate attempted abuse.

## Cryptographic details

| Primitive              | Purpose                                 | Algorithm         | Key source                                          |
|------------------------|-----------------------------------------|-------------------|-----------------------------------------------------|
| `TokenCipher`          | Token + webhook_secret at rest          | AES-256-GCM       | HKDF-SHA256 from `FS_COOKIES_EXPIRE`, info `mm/token-cipher/v1` |
| `SignedUrl`            | Certificate download URL authentication | HMAC-SHA256       | HKDF-SHA256 from `FS_COOKIES_EXPIRE`, info `mm/signed-url`       |
| `WebhookVerifier`      | Inbound webhook authentication          | HMAC-SHA256       | `moodle_instances.webhook_secret` per instance       |
| Nonce replay guard     | Webhook anti-replay                     | `hash('sha256', $nonce)` stored in `Tools::cache` with 1 h TTL | — |
| Random alias           | Opaque usernames (F10.5)                | `random_bytes(6)` → hex | CSPRNG — verify via `docs/PHP-INI-HARDENING.md` §6 |

Rotating `FS_COOKIES_EXPIRE` invalidates every TokenCipher and
SignedUrl payload. Plan token re-entry before rotating.

---

*Last updated: 2026-04-17 · finalised in V2.0-ACTION-PLAN F11.9.*
