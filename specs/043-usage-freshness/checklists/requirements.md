# Requirements Checklist: Usage Collector Freshness

Quality gate for `spec.md` before planning. Each item is checked against the written specification.

## Grounding

- [x] The cadences are read from ISPConfig's own cron classes, with file and line for each of the three
      collectors, not assumed.
- [x] The staleness ages are the ones the API already applies (`config('api.usage.stale_after')`), not a new
      parallel notion.
- [x] The existing behaviour that motivated the feature is described precisely: a stale metric reports both
      its value and its `measured_at` as `null`, so "never measured" and "stale" are indistinguishable today.
- [x] The feature is stated as read-only and additive, with no change to existing fields (FR-009, SC-004).

## Requirement quality

- [x] Every functional requirement is testable without reading the code (FR-001 … FR-009).
- [x] The aggregation rule across several servers is specified (oldest contributing time, FR-005) rather than
      left to the implementation.
- [x] The relationship between the two timestamps is defined exactly (`next_expected_at = measured_at +
      interval_seconds`, FR-006).
- [x] Configurability is a requirement, with defaults named (FR-003), so an operator with a modified cron can
      correct it.
- [x] The no-extra-query constraint is a requirement with a matching success criterion (FR-008, SC-003).

## Honesty about missing data

- [x] A never-collected metric must not imply a measurement: both timestamps null, intervals still present
      (FR-007, US2 scenario 1).
- [x] A stale metric still reports its real collection time, so a consumer can explain the age (US2
      scenario 2).
- [x] The contract must tell consumers to render "not measured yet" rather than `0` (FR-007, US2 scenario 3).
- [x] Corrupt blobs are covered and behave like stale data (US2 scenario 4).
- [x] Clock skew is addressed rather than silently corrected (Edge Cases).

## Scope

- [x] No new endpoint; the block is added to the response a consumer already fetches.
- [x] `web_traffic_this_month` is explicitly excluded, with the reason (not collector-backed).
- [x] The API does not read remote crontabs; why that is sound is stated (schedule compiled into ISPConfig).

## Testability

- [x] Each user story has an independent test that seeds blobs with known ages.
- [x] Success criteria are measurable: renderable from one response (SC-001), the two null cases
      distinguishable (SC-002), query count unchanged (SC-003), existing fields untouched (SC-004).

**Result**: the specification is grounded, honest about missing data and ready for planning.
