# QA — OWASP ZAP security scan

`@since 2.0 — V2.0-ACTION-PLAN F12.4 · §F12.4`

Runbook for the OWASP ZAP baseline scan applied to every v2.0
release candidate. The scan targets the 7 plugin-owned screens
that handle untrusted input.

## Scope

In-scope endpoints (spidered + actively scanned):

- `/MoodleDashboard`
- `/ListMoodleUserMap`
- `/EditMoodleInstance`
- `/MoodleCertificatePdf`
- `/ApiMoodleWebhook`
- `/ListMoodleAuditLog`
- `/ListMoodleTrash`

Explicitly **out of scope** (FS core or unrelated):

- `/AdminPlugins`, `/User`, `/EditUser`, `/Logout`
- `/ListFacturaCliente`, `/ListCliente`

## Prerequisites

- Docker host with at least 4 GB RAM.
- The plugin running on a staging host behind HTTPS.
  (HTTPS is mandatory — some ZAP rules behave differently on
  plain HTTP and false-positive rates balloon.)
- Two environment variables exported:
  - `MM_BASE_URL` — e.g. `https://staging.example.test`
  - `MM_USER` / `MM_PASSWORD` — admin credentials for the context.

## Running the scan

```bash
export MM_BASE_URL="https://staging.example.test"
export MM_USER="zap-admin"
export MM_PASSWORD="<read from vault>"

docker run --rm \
  -e MM_BASE_URL -e MM_USER -e MM_PASSWORD \
  -v "$PWD:/zap/wrk" \
  -t ghcr.io/zaproxy/zaproxy:stable \
  zap.sh -cmd -autorun /zap/wrk/qa/zap-baseline.yaml
```

The YAML plan (`qa/zap-baseline.yaml`):

1. Installs the `pscanrules`, `ascanrules`, and `reports` add-ons.
2. Runs a traditional spider (5 min, depth 5) as authenticated admin.
3. Runs the AJAX spider (5 min).
4. Waits for passive scan to finish (≤ 5 min).
5. Runs the active scan using the default policy, capped at
   30 min total and 2 min per rule.
6. Emits `qa/reports/v2.0-<timestamp>.html`.
7. Applies two alert filters for documented false positives
   (see rationale inline in the YAML).

## Acceptance criteria

| Severity  | v2.0 gate                                                      |
|-----------|----------------------------------------------------------------|
| CRITICAL  | 0 alerts. Any finding blocks the release.                      |
| HIGH      | 0 alerts. Any finding blocks the release.                      |
| MEDIUM    | Reviewed and either fixed, suppressed with written rationale, or tracked for v2.1. |
| LOW       | Reviewed; may ship if justified.                               |
| INFO      | Report-only.                                                   |

The YAML sets `failOnError: true`, which causes the run to exit
non-zero on HIGH/CRITICAL. CI consumers can rely on that directly.

## Suppression policy

Every `alertFilter` in the YAML must carry an inline comment
explaining:

- Why the alert is a false positive or out-of-scope.
- Which component is responsible (FS core, upstream proxy, etc.).
- The upstream tracking issue if any.

Unjustified suppressions block the release.

## Reporting

After a clean run:

1. Attach `qa/reports/v2.0-<timestamp>.html` to the release PR.
2. Record the following in `docs/QA-OWASP-ZAP-LOG.md`:
   - Date, scanner version, plugin SHA.
   - Counts per severity.
   - Links to suppressions added since the previous release.

## Regression tracking

When a future release adds a MEDIUM, log it in the file above
with the mitigation plan. Two consecutive releases without
addressing a MEDIUM downgrade to HIGH.

## Known-limited coverage

- ZAP cannot exercise the Moodle-side round-trip — our webhook
  integration tests (`Test/Integration/MoodleClientRejectsSsrfTest`)
  cover the SSRF guard independently.
- ZAP cannot fully assess CSP strength. `MoodleCertificatePdf`
  sets `'none'` on every directive (F2.11); other endpoints
  inherit FS core's policy. This is documented in the YAML's
  alert filter block.
