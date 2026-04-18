# ADR-0004 — SoftDeleteTrait without model events

`Status: accepted · Date: 2026-03 (Fase 10.4) · Hardened F17.7 (DB-02)`

## Context

F10.4 introduced a papelera (`ListMoodleTrash`) over three tables
(`moodle_user_map`, `moodle_enrolments`, `moodle_cohorts`). We needed
a soft-delete primitive the models could share. FS `ModelClass::delete()`
fires `Model.<X>.Delete` events; some of our subscribers (workers,
handlers) would misinterpret a soft-delete as a real one and act on it.

## Decision

`Lib/Model/SoftDeleteTrait` overrides `delete()` to issue a raw
`UPDATE <table> SET deleted_at = NOW() WHERE id = ?`. The update does
NOT go through the FS model layer, so the `Model.<X>.Update` event
never fires. `forcePhysicalDelete()` is the escape hatch for purge
flows that DO want the event (papelera purge, admin cleanup).

F17.7 (DB-02) added a runtime guard: if the target model declares a
composite primary key, the trait throws `UnsupportedSchemaException`
rather than silently targeting the wrong row.

## Alternatives considered

- **FS core soft-delete plugin** — none ships with FS; would need
  community approval to upstream.
- **Event suppression via global flag** — thread-unsafe; other handlers
  could race against the flag.
- **Add a `deleted_at` filter in every query** — opt-in everywhere,
  prone to regressions.

## Consequences

- Trait-using models must declare `public $deleted_at` and a scalar PK.
- `restore()` convenience method clears `deleted_at` in place, also via
  raw UPDATE.
- `isTrashed()` helper lets callers filter in PHP.
- Audit trail for restore/purge goes through `Audit::record`; the
  raw UPDATE sidesteps events but the controller wraps it.
