# ADR-0005 — Webhook envelope (HMAC + nonce + timestamp)

`Status: accepted · Date: 2026-03 (Fase 10.1) · Hardened F16.1 (SEC-04)`

## Context

F10.1 added an inbound webhook receiver (`/ApiMoodleWebhook`) so Moodle
can push enrolment / course-completion / user-update events into FS.
The endpoint is public (no FS session) and must reject every request
that does not come from a legitimate Moodle-side bridge.

## Decision

Three independent gates, all on the same HTTP request:

1. **HMAC-SHA256 signature** over the raw body, keyed with the
   per-instance `webhook_secret` (stored encrypted via
   `TokenCipher`). Header: `X-MM-Signature: sha256=<hex>`.
2. **Timestamp freshness** via `X-MM-Timestamp`. Reject delivery
   > 5 min old. Limits the replay window.
3. **Nonce uniqueness** via `X-MM-Nonce` (≤128 chars, opaque). Stored
   for 1 h in `Tools::cache()`; second use within the window is
   rejected. Closes the residual replay window.

Each rejection is independently recorded in `moodle_webhook_log` so
operators can diagnose misconfigurations (typical: clock skew > 5 min).

F16.1 (SEC-04) closed a bypass: `WebhookVerifier::resolveSecret` used
to return the raw stored value when decrypt failed, which leaked the
ciphertext as HMAC key material. Now fail-closed.

## Alternatives considered

- **OAuth 2 / JWT** — heavier, needs a token dance, overkill for a
  pre-shared secret scenario.
- **Asymmetric keypair per instance** — better for public-facing
  webhook providers; for FS ↔ Moodle an internal shared secret is
  simpler to provision.
- **IP allowlist** — brittle if Moodle sits behind a load balancer with
  rotating egress IPs.

## Consequences

- Secret rotation is a one-line change on both sides (Moodle-side
  bridge config + `moodle_instances.webhook_secret` via the UI).
- INT-01 (F16.16) added a per-event payload validator on top so
  malformed bodies surface as `STATUS_ERROR` rather than hitting the
  handlers with zero-coerced garbage.
- Retention: `moodle_webhook_log` rows are trimmed to 90 days by the
  `moodle-logs-retention` cron (DB-05 / F17.9).
