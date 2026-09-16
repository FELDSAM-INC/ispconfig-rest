# Contracts: Usage Collector Freshness

The OpenAPI specification is the source of truth (constitution I). This feature's contract lives in the
repository's `api/` tree, not in this folder:

| File | Content |
|---|---|
| `api/components/schemas/UsageFreshness.yaml` | new: one metric's `interval_seconds`, `stale_after_seconds`, `measured_at`, `next_expected_at`, with the null rules spelled out |
| `api/components/schemas/UsageSummary.yaml` | `freshness` added to `properties` **and** `required` — the schema is `additionalProperties: false`, so it must be declared |
| `api/modules/usage/summary.yaml` | operation description: what the block means, how a consumer decides a value is outdated, and that an unknown value is rendered "not measured yet", never `0` |
| `config/api.php` | `usage.interval` (300 / 300 / 900) beside the existing `usage.stale_after` |

Contract rules specific to this feature:

- **The intervals are installation facts, not measurements.** They are present even when nothing has ever
  been collected, and they are configuration so an operator with a modified cron schedule can correct them.
- **`measured_at` is reported even when the value is stale.** The metric itself keeps reporting `null` in
  that case; the freshness block is what makes "measured three hours ago" distinguishable from "never
  measured". The contract says this explicitly, because it is the reason the block exists.
- **Both timestamps are null together.** No timestamp is ever invented for a metric that has no collector
  row.
- **`web_traffic_this_month` has no entry**, and the contract states why: it is summed from daily traffic
  counters rather than produced by a monitor collector.
- **Nothing else in the response changes.** The feature is additive; every existing field keeps its value and
  meaning, which the shipped summary tests continue to assert.
