# Architecture Decision Records (ADR)

`@since 2.0 — DOC-05 (2026-04-17)`

Each file in this directory records one architectural decision taken
during v2.0 development. The decision itself is documented; the
context and the alternatives considered are written alongside so the
reasoning survives the original contributors.

## Index

- [ADR-0001 — AES-256-GCM + HKDF for TokenCipher](adr-0001-tokencipher.md)
- [ADR-0002 — WorkQueue cascade cut (F6.1)](adr-0002-cascade-cut.md)
- [ADR-0003 — Migration framework propietario vs doctrine/migrations](adr-0003-migration-framework.md)
- [ADR-0004 — SoftDeleteTrait without model events](adr-0004-soft-delete-trait.md)
- [ADR-0005 — Webhook envelope (HMAC + nonce + timestamp)](adr-0005-webhook-envelope.md)
- [ADR-0006 — BRAINSTORMING.md moved out of docs/](adr-0006-brainstorming-location.md)

## Format

Each record follows the same rough structure:

1. **Status** (proposed / accepted / superseded)
2. **Context** — what problem are we solving?
3. **Decision** — what did we pick?
4. **Alternatives considered** — and why we rejected them.
5. **Consequences** — the known trade-offs.

Historical source for each: `CLAUDE.md` §6 (in the repo root during
v2.0 development).
