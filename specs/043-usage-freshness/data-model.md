# Data Model: Usage Collector Freshness

Read-only, additive. No table is created, altered or written.

## Source data

| Source | Used for |
|---|---|
| `monitor_data` (`server_id`, `type`, `created`) via the `latestBlobs()` call `summary()` already makes | the collection time per (server, type) |
| `config('api.usage.interval')` | `interval_seconds` per monitor type |
| `config('api.usage.stale_after')` | `stale_after_seconds` per monitor type |
| the client's resource rows (`web_domain`, `mail_user`, `web_database`) already loaded by `summary()` | which servers contribute to which metric |

## Metric → monitor type

| Summary metric | Monitor type | Contributing servers |
|---|---|---|
| `web_disk` | `harddisk_quota` | servers of the client's `vhost` websites |
| `mail_storage` | `email_quota` | servers of the client's mailboxes |
| `database_size` | `database_size` | servers of the client's databases |
| `web_traffic_this_month` | — | none; summed from `web_traffic`, so it has **no** freshness entry |

## `freshness.<metric>` object

| Field | Type | Derivation |
|---|---|---|
| `interval_seconds` | integer | `config('api.usage.interval.<type>')`; defaults 300 / 900 / 300 |
| `stale_after_seconds` | integer | `config('api.usage.stale_after.<type>')`; defaults 1800 / 3600 / 1800 |
| `measured_at` | string (date-time) \| null | **oldest** `created` among the contributing servers that have a row for the type, in the API timezone; reported even when the data is stale or the blob is corrupt; null when no row exists |
| `next_expected_at` | string (date-time) \| null | `measured_at + interval_seconds`; null whenever `measured_at` is null |

Both timestamps are `null` together; the two interval fields are always present, because they describe the
installation rather than a measurement.

## The three states a consumer must distinguish

| Situation | metric (`web_disk` …) | `freshness` entry |
|---|---|---|
| Collected recently | values present, `measured_at` set | `measured_at` set, `next_expected_at` = +interval |
| Collected, but older than `stale_after_seconds` (or the blob is corrupt) | `used_bytes: null`, `measured_at: null` | `measured_at` **set** (the real time), `next_expected_at` set — this is how "stale" becomes visible |
| Never collected | `used_bytes: null`, `measured_at: null` | `measured_at: null`, `next_expected_at: null` |

**Rendering rule stated in the contract**: when a value is unknown, a consumer shows "not measured yet" (or,
with a `freshness.measured_at`, "last measured at …"), and never `0` — a missing measurement is not a
measurement of zero.

**Staleness rule for a consumer**: `measured_at === null` → never measured; otherwise the value is current
while `now − measured_at ≤ stale_after_seconds` and outdated beyond it — the same threshold the API applies
when it decides to report the metric as `null`.

## Query plan

Unchanged. `summary()` already issues one `latestBlobs()` query for the three monitor types across the
client's servers; the freshness block is folded from that array in PHP. No query is added (FR-008, SC-003).
