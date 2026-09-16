# Implementation Plan: Usage Collector Freshness

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-16 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/043-usage-freshness/spec.md`

## Summary

- `GET /usage/summary` gains a required top-level `freshness` object with one entry per collector-backed
  metric (`web_disk`, `mail_storage`, `database_size`), each carrying `interval_seconds`,
  `stale_after_seconds`, `measured_at` and `next_expected_at` (research R1).
- The cadences come from ISPConfig's cron classes and ship as `config('api.usage.interval')` so an operator
  can correct them (R2).
- `measured_at` is the oldest contributing collection time and is reported **regardless** of staleness, which
  is what finally separates "never measured" from "stale"; the metric fields keep their current behaviour
  (R3, R4).
- Missing data implies nothing: both timestamps null, intervals still present, and the contract tells
  consumers to render "not measured yet" rather than `0` (R5).
- Folded from the blob array `summary()` already loads — no additional query (R6).

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)
**Primary Dependencies**: laravel/framework ^12; dev: phpunit ^11
**Storage**: MySQL `dbispconfig` — read-only, `monitor_data` via the existing `latestBlobs()` call
**Testing**: PHPUnit feature tests on sqlite in-memory with a frozen clock, Docker `php:8.3-cli`
(baseline 1292 on `a19c1a7`)
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)
**Project Type**: Contract-first REST API (monolith)
**Performance Goals**: query count of `/usage/summary` unchanged
**Constraints**: no new endpoint; no existing field may change value or meaning; no invented timestamps
**Scale/Scope**: 1 response object, 1 service method, 1 config block, 1 new schema, 1 test class

## Constitution Check

- [x] **Spec-first (I)**: `UsageFreshness.yaml`, the `UsageSummary.yaml` property and the `summary.yaml`
      description land before the service change.
- [x] **Datalog-only writes (II)**: read-only feature; nothing is written.
- [x] **Legacy parity (III)**: the cadences are ISPConfig's own `$_schedule` values with file and line (R2);
      no legacy behaviour is changed, and the API does not invent a measurement it cannot observe (R5).
- [x] **Route discipline (IV)**: no new route; the controller is untouched, the derivation lives in
      `UsageService`.
- [x] **HTTP contract (V)**: an additive, required object on an existing 200 response; no status code or
      refusal changes.
- [x] **Tests required**: fresh, stale, absent, corrupt, multi-server, no-resources, cadence per metric, and
      an unchanged-query-count assertion (R8).
- [x] **No schema changes**: no migrations; only `monitor_data` is read, as before.

## Project Structure

### Documentation (this feature)

```
specs/043-usage-freshness/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── checklists/requirements.md
└── contracts/README.md
```

### Source Code (repository root)

```
api/components/schemas/UsageFreshness.yaml   # new: one metric's collection rules and last collection time
api/components/schemas/UsageSummary.yaml     # + freshness (property and required; additionalProperties: false)
api/modules/usage/summary.yaml               # description: what freshness means and how to read staleness
config/api.php                               # + usage.interval (harddisk_quota 300, database_size 300, email_quota 900)
app/Services/UsageService.php                # + freshness(), folded from the blobs already loaded
tests/Feature/UsageFreshnessApiTest.php      # new
README.md                                    # Modules: the usage row mentions freshness
```

## Legacy Research (Phase 0 focus)

Completed in [research.md](research.md). The decisive facts, read on isp-test:

- Collector cadences are `$_schedule` constants in ISPConfig's cron classes: `*/5` for `harddisk_quota` and
  `database_size`, `*/15` for `email_quota`.
- `monitor_data` retains only the newest row per (server, type) — `monitor_tools::delOldRecords()` prunes the
  rest — so the cadence **cannot** be derived from stored data; it must be configuration.
- The API already decides staleness with `config('api.usage.stale_after')` (1800/1800/3600) in
  `UsageService::freshBlob()`, and reports a stale metric as `null` including its `measured_at` — the gap this
  feature closes.

## Complexity Tracking

| Item | Why it is justified | Simpler alternative rejected because |
|---|---|---|
| A separate `freshness` object rather than fields on each metric | the intervals are installation-wide | repeating them per row of three list endpoints is noise (R1) |
| Reporting `measured_at` even when stale | it is the only way to tell "stale" from "never measured" | changing `UsageMetric.measured_at` would alter a shipped field (R3) |
