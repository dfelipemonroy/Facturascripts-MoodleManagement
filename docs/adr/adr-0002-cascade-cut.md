# ADR-0002 — WorkQueue cascade cut (F6.1)

`Status: accepted · Date: 2026-02 (Fase 6.1)`

## Context

`BadgeSyncWorker` was subscribed to `Model.MoodleUserMap.Save`. Inside
the worker body we called `$map->save()` to flip a flag, which
re-triggered the same event → infinite loop. Manifested as a runaway
WorkQueue that pinned a CPU at 100 % on every login.

## Decision

Rebind the subscription to `Model.MoodleUserMap.Insert`. Post-insert
badge sync happens on the first save only; subsequent re-syncs are
driven explicitly by setting `badge_sync_needed = 1` and enqueuing via
`WorkQueue::add('BadgeSyncWorker', $mapId)`.

Locked in `Test/Integration/WorkerCascadeGuardTest` with a source-level
regex asserting the binding cannot regress silently.

## Alternatives considered

- **Add a reentrancy guard inside the worker** — fragile; any other
  caller that triggers `.Save` still loops.
- **Subscribe to `Model.MoodleUserMap.Save` but skip when no fields
  changed** — FS model events fire before the diff is known.

## Consequences

- A post-onboarding re-sync is an explicit API, not a side effect of
  touching the row. Added a `badge_sync_needed` column in F6.1.
- Future features that need to re-fire the worker must call
  `WorkQueue::add('BadgeSyncWorker', …)` explicitly; the subscription
  list is a deliberately small surface.
- BE-03 (F16.8) layered `IdempotencyGuard::beginOnce` on top so
  even an explicit double-enqueue within 24 h no-ops the second run.
