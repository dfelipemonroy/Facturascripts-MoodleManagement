# ADR-0006 — BRAINSTORMING.md moved out of docs/

`Status: accepted · Date: 2026-04 (Fase 11.7)`

## Context

`docs/BRAINSTORMING.md` captured the pre-implementation design history
for each v2.0 feature. Useful for contributors, but:

1. Operators reading the release ZIP don't need the history.
2. The file grew to 20+ KB, adding weight to every `git archive`.
3. The stream-of-consciousness format sat awkwardly next to the
   release-grade docs in `docs/`.

## Decision

Move to `.docs-dev/BRAINSTORMING.md`. Keep it version-controlled in
the repo but exclude it from `git archive` via a `.gitattributes`
`export-ignore` rule.

Release artifacts that run `git archive --format=zip --prefix=…`
automatically drop the file. Contributors cloning the repo still see it.

## Alternatives considered

- **Leave it in `docs/`** — bloats the shipping ZIP, confuses
  operators who grep `docs/`.
- **Delete it** — loses the design history.
- **Move to a Wiki** — splits the source of truth and makes offline
  review harder.

## Consequences

- The `.gitattributes` `export-ignore` pattern is the primary gate
  for "ships in the ZIP vs stays in the repo". Same mechanism is used
  for `docs/V2.0-ACTION-PLAN.md`, `docs/V2.0-TASK-CHECKLIST.md`,
  `docs/V2.0-POST-AUDIT-PLAN.md`, `Test/`, and CI config.
- Contributors must know that `.docs-dev/` is the place for
  development-only documents. Stated in CONTRIBUTING.md.
