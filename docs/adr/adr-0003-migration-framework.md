# ADR-0003 — Migration framework propietario vs doctrine/migrations

`Status: accepted · Date: 2026-02 (Fase 5.1)`

## Context

v2.0 added 17 idempotent schema migrations (indexes, widen-and-encrypt
on the token column, new audit/webhook/schema-version tables, …). FS
core ships its own ad-hoc DbUpdater which reads `Table/*.xml` but
doesn't cover data migrations or cross-table conditional DDL. We
needed a migration runner that is portable across MySQL and PostgreSQL
and that can co-exist with FS core's DbUpdater.

## Decision

Bespoke `Lib/Migration/SchemaMigrator` (~200 LOC). Each migration is a
closure keyed by `<major>.<minor>.<patch>-F<phase>.<subphase>-<slug>`
in `Update/v2_0.php`. The runner tracks applied versions in a
`moodle_schema_version` ledger table and wraps each migration in a
transaction when the backend supports one.

Introspection via `information_schema` for both MySQL and PostgreSQL:
`tableExists`, `columnExists`, `indexExists`, `constraintExists`,
`columnCharLength` (DB-03).

## Alternatives considered

- **doctrine/migrations** — adds a composer dependency FS core does not
  declare, brings its own ORM concepts, and requires a separate
  migration generator workflow.
- **Phinx** — same weight problem.
- **Raw SQL files per migration** — MySQL < 8.0 lacks
  `CREATE INDEX IF NOT EXISTS`; branching logic in SQL is clumsy.

## Consequences

- ~200 LOC of runner to maintain, but every step is PHP + portable.
- Migrations are pure functions of `SchemaMigrator`; trivially mockable.
- Idempotent by design: `columnExists` / `indexExists` checks before
  every DDL statement.
- DB-03 (F16.15) uses `columnCharLength` to enforce a runtime
  precondition across migration files, something a raw-SQL approach
  could not express portably.
