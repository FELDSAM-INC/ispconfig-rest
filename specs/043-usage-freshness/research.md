# Research: Usage Collector Freshness

Decisions for feature 043, grounded in ISPConfig 3.3.1p1 read on isp-test and in the shipped spec 017
implementation. Each item records the decision, why, and what was rejected.

## R1 — The block belongs on `/usage/summary`, not on a new endpoint

**Decision**: `GET /usage/summary` gains a required top-level `freshness` object. No new route.

**Why**: the consumer that needs it is already fetching the summary to render the figures, and
`UsageService::summary()` has already loaded the newest blob per (server, type) in one `latestBlobs()` call —
freshness is a fold of data the request holds. A separate endpoint would repeat the client resolution, the
resource scan and the blob read for information that is meaningless without the figures beside it.

**Rejected**: `GET /usage/freshness` (a second request per page for data the first already computed); putting
the fields inside `UsageMetric` (the intervals are installation-wide, so they would be repeated on every row
of `/usage/web-domains`, `/usage/mail-users` and `/usage/databases`); a response header (invisible to a JSON
consumer and awkward per metric).

## R2 — The cadence comes from ISPConfig's cron classes, exposed as configuration

**Finding** (read on isp-test):

| Monitor type | `$_schedule` | File |
|---|---|---|
| `harddisk_quota` | `*/5 * * * *` | `server/lib/classes/cron.d/100-monitor_hd_quota.inc.php:34` |
| `database_size` | `*/5 * * * *` | `server/lib/classes/cron.d/100-monitor_database_size.inc.php:36` |
| `email_quota` | `*/15 * * * *` | `server/lib/classes/cron.d/100-monitor_email_quota.inc.php:34` |

**Decision**: ship these as `config('api.usage.interval')` (300, 300, 900 seconds), next to the existing
`config('api.usage.stale_after')`, so an operator whose installation differs can correct them without a code
change.

**Why**: the schedule is compiled into ISPConfig's own source, identical on every server of one version, and
the API has no way to read a remote server's crontab (no shell, no root, and in a multi-server installation
the collectors run on other hosts entirely). Configuration is the honest representation: a documented default
that an operator can override.

**Rejected**: reading crontabs (impossible remotely, and would need privileges the API must not have);
hard-coding the numbers in PHP (an operator with a modified schedule could not correct them); **deriving the
cadence from the data** — impossible, because `monitor_data` keeps only the newest row per (server, type):
`monitor_tools::delOldRecords()` prunes the rest (documented in `MonitorDataService`), so there is no history
from which an interval could be measured.

## R3 — `measured_at` is reported regardless of staleness

**Decision**: the freshness entry reports the collection time even when the API considers the data too old,
while the metric itself keeps its current behaviour (value and `measured_at` become `null` when stale).

**Why**: this is the point of the feature. Today a stale metric and a never-collected one are byte-identical
in the response, so a panel cannot distinguish "not measured yet" from "measured three hours ago and now
considered too old". Reporting the raw timestamp in the freshness block resolves that without changing any
existing field (FR-009, SC-004).

**Rejected**: changing `UsageMetric.measured_at` to always report the raw time (would silently alter a
shipped field and make a stale value look current); adding a `stale` boolean (derivable from the two fields,
and a boolean cannot say *how* old).

## R4 — Several servers: the oldest contributing time wins

**Decision**: when a client's resources sit on several servers, `measured_at` is the **oldest** collection
time among the servers that have a row for that type.

**Why**: the metric is a sum across servers, so it is only as current as its least recently measured part.
This also matches the definition `UsageMetric.measured_at` already documents ("oldest collector timestamp
among the contributing values"), so the two agree whenever both are present.

**Rejected**: the newest time (would overstate freshness precisely when one server's collector has stopped);
per-server breakdown (server ids are not a customer's concern, and the summary deliberately exposes none).

## R5 — Missing data implies nothing

**Decision**: a metric with no collector row at all reports `measured_at: null` and `next_expected_at: null`,
while `interval_seconds` and `stale_after_seconds` are still present. The contract states that a consumer
renders "not measured yet" and never `0`.

**Why**: the intervals describe the installation and are true whether or not a measurement exists; a
timestamp would be an invention. Printing `0` for an unmeasured resource tells a customer their disk is empty
— the failure mode spec 017 already guards against, now stated where a consumer will read it.

**Rejected**: omitting the entry (a consumer would have to distinguish "absent key" from "absent value");
substituting `now` or the request time (fabricates a measurement).

## R6 — No additional query

**Decision**: the block is folded from the `$blobs` array `summary()` already holds; nothing new is read.

**Why**: FR-008/SC-003, and the lesson of spec 041 — a summary that grows a query per metric or per server
would degrade exactly for the accounts that need it most.

## R7 — Traffic has no freshness entry

**Decision**: `web_traffic_this_month` is excluded and the contract says why.

**Why**: it is summed from `web_traffic` daily counters, not produced by a monitor collector; it already
documents `measured_at` as always null. Inventing an interval for it would misrepresent how it is produced.

## R8 — Test strategy

**Decision**: extend the existing `UsageSummaryApiTest` fixtures (frozen clock, `blob($serverId, $type,
$data, $age)`) with a dedicated class covering: fresh data (exact `measured_at` and `next_expected_at`),
mail's different cadence, a stale blob (metric null, freshness timestamps present), a never-collected metric
(both null, intervals present), a corrupt blob (behaves like stale), two servers (oldest wins), a client with
no resources at all, and a query-count assertion proving the summary's query count is unchanged.

**Why**: constitution "Testing (REQUIRED)"; SC-002 and SC-003 are only meaningful if asserted, and the
frozen clock makes the timestamp arithmetic exact rather than approximate.
