# QA — Performance benchmark

`@since 2.0 — V2.0-ACTION-PLAN F12.3 · §F12.3`

Executable benchmark harness and acceptance targets for v2.0.

## Harness

`scripts/benchmark.php` seeds 10 000 contacts + 30 000 enrolments
+ 500 audit rows and measures the four hot paths below.

```bash
cd Plugins/MoodleManagement
php scripts/benchmark.php seed
php scripts/benchmark.php run
php scripts/benchmark.php clean
```

Safety rails:

- The script aborts unless `FS_ROUTE` points at `localhost` /
  `127.0.0.1` **or** the DB name is prefixed `bench_`. This
  prevents anyone from running it against a production DSN by
  accident.
- Every seeded row carries a `bench_` marker so `clean` drops
  only what `seed` inserted.
- Seeding runs inside a single transaction.

## Acceptance targets

Hard targets enforced by `scripts/benchmark.php run` (exits with
code 5 if any scenario regresses past the limit).

| Scenario                                | Dataset                        | Target   | Why                                          |
|-----------------------------------------|--------------------------------|----------|----------------------------------------------|
| `dashboard`                             | 7 aggregate queries            | ≤ 150 ms | Within a single HTTP response budget (500 ms end-to-end). |
| `user_map_page`                         | 500-row paginated SELECT        | ≤  50 ms | List controllers expected to be indexed (FK indexes post-F5.3). |
| `progress_sync_batch`                   | 20 × 500 offset scans          | ≤ 500 ms | Cron window is 6 h — this is about avoiding runaway memory and lock holding. |
| `audit_log_page`                        | 100-row filter by outcome      | ≤  50 ms | Admin triage UI must stay snappy. |

Measured on:

- x86_64, 4 vCPU, 8 GB RAM
- MySQL 8.0 (`innodb_buffer_pool_size = 2G`)
- PHP 8.2 with OPcache enabled
- Local socket (no network)

On lower-spec hardware, scale the targets proportionally but
never relax them without a recorded decision in `CHANGELOG.md`.

## Interpreting failures

| Scenario fails     | Likely cause                                                                                      |
|--------------------|---------------------------------------------------------------------------------------------------|
| `dashboard`        | Missing index on `moodle_enrolments.status` or join blow-up. Re-apply `2.0.0-F5.3-fk-indexes`.     |
| `user_map_page`    | `idcontacto` / `idinstance` indexes missing.                                                      |
| `progress_sync_batch` | Pagination spilling into table scan. Check `EXPLAIN` for `Using filesort` and add ORDER BY covering index. |
| `audit_log_page`   | Outcome column not indexed. Add `CREATE INDEX idx_mm_audit_outcome ON moodle_audit_log(outcome)`. |

## CI integration (post-v2.0)

The benchmark is **not** in the CI matrix today — it needs a DB
fixture and takes ~2 minutes. Tracked for v2.1:

- GitHub Actions job `benchmark` on manual dispatch.
- Results stored as an artefact for trend charts.
- Soft-fail on a 20 % regression, hard-fail on a 50 % one.

## Recording a run

Append to `docs/QA-PERFORMANCE-LOG.md` (created after the first
run) with: date, hardware spec, plugin SHA, scenario timings.
Use the run log to justify target adjustments across releases.
