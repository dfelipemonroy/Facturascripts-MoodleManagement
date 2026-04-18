# ADR-0001 — AES-256-GCM + HKDF for TokenCipher

`Status: accepted · Date: 2026-02 (landed F5.12) · Tightened F15.1 (fail-closed)`

## Context

Audit §4.6 (first iteration, CRÍTICO): the Moodle WS token was stored as
plaintext inside `moodle_instances.token`. Any DB dump or read-only role
had full control of every linked Moodle instance. We needed at-rest
encryption without introducing a runtime key-management dependency.

## Decision

`Lib/Security/TokenCipher` wraps AES-256-GCM with a key derived via
HKDF-SHA256 from `FS_COOKIES_EXPIRE` (the FS core cookie secret), info
label `mm/token-cipher/v1`. Wire format:

```
mm2g:<base64url(iv(12) | tag(16) | ciphertext)>
```

`isEncrypted()` detects the `mm2g:` prefix so legacy plaintext
coexists during the one-shot migration (F5.12).

F15.1 tightened the derivation: if `FS_COOKIES_EXPIRE` is missing, the
helper throws `RuntimeException` instead of falling back to a hardcoded
string.

## Alternatives considered

- **libsodium** — worse compat with PHP 8.0 on hosts that lack the
  extension. FS core does not require it.
- **Independent key in config** — another secret to rotate, more
  configuration surface for operators to get wrong.
- **DB-level transparent encryption** — vendor-specific (MySQL
  enterprise only); would not port to MariaDB/PostgreSQL installs.

## Consequences

- If `FS_COOKIES_EXPIRE` is rotated, **every** stored token is
  invalidated simultaneously. SEC-05 now documents this for
  `SignedUrl`; for tokens the operator must re-enter each one. A
  future v2.1 task could decouple via a dedicated token key.
- HKDF info label `mm/token-cipher/v1` is a version tag — bumping it
  rotates the signing key without touching the FS secret.
- Reuses: `Lib/Security/SignedUrl` (same HKDF pattern), `Lib/Security/SignedPayload`
  (same fail-closed posture).
