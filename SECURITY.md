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

If you want end-to-end encryption, fetch the PGP public key at
`https://facturascripts.com/.well-known/security.pgp` (placeholder until
F11.9 lands) and encrypt the report.

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

## Known security issues under active remediation (v2.0)

The following critical findings are already tracked in
[`docs/V2.0-TASK-CHECKLIST.md`](docs/V2.0-TASK-CHECKLIST.md).
Please **do not file duplicate reports** for these:

| # | Issue                                                               | Task   |
|---|---------------------------------------------------------------------|--------|
| 1 | Stored XSS in `UserChat.html.twig` (Twig + JS)                      | F2.1–2 |
| 2 | Stored XSS in `UserNotes.html.twig`                                 | F2.3   |
| 3 | Stored XSS in `CourseContent.html.twig`                             | F2.4   |
| 4 | IDOR in `MoodleCertificatePdf.php`                                  | F4.1   |
| 5 | Exception message disclosure in `MoodleCertificatePdf.php`          | F2.5   |
| 6 | Cookies without `HttpOnly`/`Secure`/`SameSite`                      | F2.6   |
| 7 | WorkQueue cascade loops (`BadgeSyncWorker`, renewal estimate)       | F6.1–2 |
| 8 | Missing FK indexes (8 columns)                                      | F5.3   |
| 9 | `createViews()` not called on fresh install                         | F5.4   |
| 10 | SQL injection latent in `MoodleCourseCategory::codeModelAll`       | F5.6   |
| 11 | Plaintext Moodle token at rest                                     | F5.12  |
| 12 | Cascade delete on `moodle_enrolments.idfactura` loses fiscal trail | F5.5   |
| 13 | `EditProducto` extension encapsulation break                       | F8.3   |
| 14 | Missing v1 → v2 migration script + seed                            | F5.1–2 |

If you find a **different** critical issue, please report it.

---

## Best practices for administrators

Recommended PHP hardening (documented in detail in `README.md` after F10.7):

```ini
; php.ini
expose_php = Off
display_errors = Off
session.cookie_secure = 1
session.cookie_httponly = 1
session.cookie_samesite = "Lax"
```

Recommended deployment:
- HTTPS-only via reverse proxy / HSTS.
- Moodle webservice user with **least privileges** — only the WS
  functions this plugin actually uses.
- Rotate Moodle tokens quarterly (will be facilitated by Fase 5 cipher).

---

*Last updated: 2026-04-16*
