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

- [ ] T008 [US1] — Create `tests/Feature/UsageFreshnessApiTest.php` on `UsageApiTestCase`: the summary carries `freshness` with exactly the three collector-backed metrics; each entry has the four fields; `web_traffic_this_month` has no entry.
- [ ] T009 [P] [US1] — Add: with blobs of known age, `measured_at` equals the blob time and `next_expected_at` is exactly `interval_seconds` later (frozen clock, exact ISO strings); disk and databases report 300/1800, mail 900/3600.
- [ ] T010 [P] [US1] — Add: two servers contributing to one metric → `measured_at` is the **oldest** of the two, not the newest.
- [ ] T011 [US1] — Run the class; confirm it fails only because the block is missing.

### Implementation for User Story 1

- [ ] T012 [US1] — Add `freshness()` to `app/Services/UsageService.php`, folded from the `$blobs` array `summary()` already loads: per metric, the oldest `created` among the contributing servers, plus the two configured values; no new query.
- [ ] T013 [US1] — Return the block from `summary()` and run the class until the US1 tests pass.

## Phase 4: User Story 2 - Tell "never measured" apart from "stale" (Priority: P1)

### Tests for User Story 2 (REQUIRED) ⚠️

- [ ] T014 [US2] — Add: a metric with no collector row → `measured_at: null` and `next_expected_at: null`, with both interval fields still present.
- [ ] T015 [P] [US2] — Add: a blob older than `stale_after_seconds` → the metric stays `null` (unchanged behaviour) while the freshness entry reports the real timestamp and `next_expected_at`.
- [ ] T016 [P] [US2] — Add: a corrupt blob behaves like stale data (metric null, freshness timestamp present); a client with no websites, mailboxes or databases reports all-null timestamps.
- [ ] T017 [US2] — Run the class until green.

## Phase 5: Polish & Cross-Cutting Concerns

- [ ] T018 — Add a query-count assertion proving `/usage/summary` issues the same number of queries as before the feature (FR-008, SC-003).
- [ ] T019 — Full suite on `php:8.3-cli` (expect 1292 + the new tests, no regressions, and the shipped summary tests still green — SC-004) and Pint on every changed file.
- [ ] T020 — README: mention freshness in the `usage` module row.
- [ ] T021 — Commit the implementation phase and push.
- [ ] T022 — Deploy to isp-test and confirm the deployed commit is on `origin/main`.
- [ ] T023 — Run quickstart §3 live: compare the captured pre-deploy summary with the new one (only `freshness` added), check the interval/stale values and the `next_expected_at` arithmetic, cross-check one `measured_at` against `monitor_data`, and read a temporary resource-less client for the never-measured case.
- [ ] T024 — Cleanup per quickstart §4 and verify isp-test is back to baseline.
- [ ] T025 — Record the live results in this file and commit.

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
