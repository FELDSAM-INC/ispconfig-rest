# Tasks: Usage Collector Freshness

**Feature**: 043 | **Spec**: [spec.md](spec.md) | **Plan**: [plan.md](plan.md)

## Format: `[ID] [P?] [Story] Description`

- `[P]` = may run in parallel with the other `[P]` tasks of the same phase (different files, no shared state)
- `[Story]` = the user story the task serves (US1, US2) or `—` for shared work

## Path Conventions (this project)

Contract in `api/`, configuration in `config/`, code in `app/Services/`, tests in `tests/Feature/`. The
OpenAPI specification is the source of truth and lands before the code.

## Phase 1: Setup

- [x] T001 — Confirm the baseline suite is green (`php artisan test`, expect 1292 passing on `a19c1a7`).
- [x] T002 — Capture `/usage/summary` for client 19 on isp-test **before** deploying, so the post-deploy comparison can prove no existing field changed (quickstart §3 steps 1-3).

## Phase 2: Foundational (Blocking Prerequisites)

Contract and configuration first (constitution I); nothing in `app/` before these land.

- [x] T003 [P] — Create `api/components/schemas/UsageFreshness.yaml`: `interval_seconds`, `stale_after_seconds`, `measured_at` (nullable), `next_expected_at` (nullable), documenting that the intervals are installation facts present even without a measurement, that `measured_at` is reported even when the value is stale, and that both timestamps are null together.
- [x] T004 [P] — Add `usage.interval` to `config/api.php` (`harddisk_quota` 300, `database_size` 300, `email_quota` 900) with a comment naming the ISPConfig cron classes the values come from.
- [x] T005 — Add `freshness` to `api/components/schemas/UsageSummary.yaml` in both `properties` and `required` (the schema is `additionalProperties: false`), referencing the new schema per metric.
- [x] T006 — Extend the `api/modules/usage/summary.yaml` description: what the block means, how a consumer decides a value is outdated, why `web_traffic_this_month` has no entry, and that an unknown value is shown as "not measured yet", never `0`.
- [x] T007 — Commit the contract phase (`Add the usage freshness contract`) and push.

## Phase 3: User Story 1 - Explain a usage figure truthfully (Priority: P1) 🎯 MVP

### Tests for User Story 1 (REQUIRED) ⚠️

- [x] T008 [US1] — Create `tests/Feature/UsageFreshnessApiTest.php` on `UsageApiTestCase`: the summary carries `freshness` with exactly the three collector-backed metrics; each entry has the four fields; `web_traffic_this_month` has no entry.
- [x] T009 [P] [US1] — Add: with blobs of known age, `measured_at` equals the blob time and `next_expected_at` is exactly `interval_seconds` later (frozen clock, exact ISO strings); disk and databases report 300/1800, mail 900/3600.
- [x] T010 [P] [US1] — Add: two servers contributing to one metric → `measured_at` is the **oldest** of the two, not the newest.
- [x] T011 [US1] — Run the class; confirm it fails only because the block is missing.

### Implementation for User Story 1

- [x] T012 [US1] — Add `freshness()` to `app/Services/UsageService.php`, folded from the `$blobs` array `summary()` already loads: per metric, the oldest `created` among the contributing servers, plus the two configured values; no new query.
- [x] T013 [US1] — Return the block from `summary()` and run the class until the US1 tests pass.

## Phase 4: User Story 2 - Tell "never measured" apart from "stale" (Priority: P1)

### Tests for User Story 2 (REQUIRED) ⚠️

- [x] T014 [US2] — Add: a metric with no collector row → `measured_at: null` and `next_expected_at: null`, with both interval fields still present.
- [x] T015 [P] [US2] — Add: a blob older than `stale_after_seconds` → the metric stays `null` (unchanged behaviour) while the freshness entry reports the real timestamp and `next_expected_at`.
- [x] T016 [P] [US2] — Add: a corrupt blob behaves like stale data (metric null, freshness timestamp present); a client with no websites, mailboxes or databases reports all-null timestamps.
- [x] T017 [US2] — Run the class until green.

## Phase 5: Polish & Cross-Cutting Concerns

- [x] T018 — Add a query-count assertion proving `/usage/summary` issues the same number of queries as before the feature (FR-008, SC-003).
- [x] T019 — Full suite on `php:8.3-cli` (expect 1292 + the new tests, no regressions, and the shipped summary tests still green — SC-004) and Pint on every changed file.
- [x] T020 — README: mention freshness in the `usage` module row.
- [x] T021 — Commit the implementation phase and push.
- [x] T022 — Deploy to isp-test and confirm the deployed commit is on `origin/main`.
- [x] T023 — Run quickstart §3 live: compare the captured pre-deploy summary with the new one (only `freshness` added), check the interval/stale values and the `next_expected_at` arithmetic, cross-check one `measured_at` against `monitor_data`, and read a temporary resource-less client for the never-measured case.
- [x] T024 — Cleanup per quickstart §4 and verify isp-test is back to baseline.
- [x] T025 — Record the live results in this file and commit.

## Dependencies & Execution Order

### Phase Dependencies

Phase 1 → Phase 2 (contract + config) → Phase 3 (US1) → Phase 4 (US2) → Phase 5. T002 must happen before the
deploy in T022, which is why it sits in Phase 1.

### Within Each User Story

Tests before implementation, always. In Phase 2, T003 and T004 are independent; T005 needs T003, T006 needs
T005.

### Parallel Opportunities

T003/T004; T009/T010; T015/T016.

## Implementation Strategy

**MVP** is User Story 1 — the cadence beside the figure. User Story 2 is what makes the block honest about
missing data, and both ship together: a freshness block that cannot distinguish "stale" from "never measured"
would leave the original problem unsolved.

## Notes

- No migrations, no writes, no new endpoint, no change to any existing field.
- The cadence cannot be derived from stored data (`monitor_data` keeps only the newest row per server and
  type), which is why it is configuration — see research R2.

## Live verification on isp-test (T023, 2026-09-16)

Deployed commit `c00e3f7` (identical to local HEAD). The pre-deploy capture from T002 made the strongest
check possible: a field-by-field comparison against **real** data.

| Check | Expected | Observed |
|---|---|---|
| Added keys | only `freshness` | only `freshness`; nothing removed |
| Existing fields | unchanged | all identical except `web_disk.measured_at`, explained below |
| Cadence / stale age | 300/1800, 900/3600, 300/1800 | exactly that |
| `next_expected_at − measured_at` | equals `interval_seconds` | disk 300 s, mail 900 s — exact |
| Cross-check with `monitor_data` | API time = newest row | `harddisk_quota` 04:10:01 and `email_quota` 04:00:01 match |
| Never measured (temporary client with no resources) | all timestamps null, intervals present | all three metrics null/null, intervals 300/900/300 present |
| Writes | none by this feature | `sys_remoteaction` unchanged (15 → 15); the only journal rows were the temporary client's create/delete |

### The one changed field is a collector run, not a regression

`web_disk.measured_at` moved from `04:05:01` (baseline, captured 04:05:09) to `04:10:01`. The disk collector
runs every 5 minutes and `monitor_data`'s newest `harddisk_quota` row is exactly `04:10:01`; `used_bytes`,
`allocated_bytes`, `limit_bytes` and `used_percent` are byte-identical, and `mail_storage` (15-minute cadence)
did not move at all. The metric and the new `freshness` block agree on the new collection time. So the
additive promise holds: this feature changed no existing value.

### The distinction the feature adds, seen on real data

Client 19 has websites and mailboxes but **no databases**, so `database_size` reports `used_bytes: null` and
`measured_at: null` — while a `database_size` blob does exist on server 1 (04:10:01). The freshness block
reports `measured_at: null` for it, because no server *contributes* to that metric for this account. A
consumer therefore renders "not measured yet" rather than `0`, and can still explain disk and mail with their
real collection times.

## Cleanup (T024, 2026-09-16)

Temporary client 54 deleted through the API, journal drained (`server.updated` = last `sys_datalog` id, no
pending remote action), `qa043*` keys removed by name, and both capture files deleted from the server. Final
state matches the pre-run baseline: keys `1, 2, 20, 27, 50`; clients `1, 2, 19`; 6 websites; 0 backup rows;
no `qa043` client or system user; client directories `client0, client1, client19`.
