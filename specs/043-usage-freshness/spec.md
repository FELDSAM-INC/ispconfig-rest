# Feature Specification: Usage Collector Freshness

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-16  
**Status**: Draft  
**Module**: usage  
**Input**: WHMCS module spec 007 (Usage & Backups) dependency 043: "`measured_at` is per value, but nothing tells the panel how often the collectors run, so it cannot say *updated every 30 minutes*."

## Context

Disk usage, mailbox storage and database sizes do not come from a live query: ISPConfig's server collectors
write them into `monitor_data` on a schedule, and the API reads the newest row per server and type. Every
usage figure therefore carries a `measured_at`, and the API already treats data older than a configured age
as unknown (`config('api.usage.stale_after')`: 30 minutes for disk and databases, 60 for mail storage).

A consumer sees the timestamp but not the rules around it, which leaves it unable to answer three ordinary
questions: how often is this refreshed, is this number still considered current, and when will it change
next. Worse, the current shape hides a distinction that matters: when collector data is stale the API
reports the value **and** its `measured_at` as `null`, so a panel cannot tell "never measured" from "measured
three hours ago and now considered too old". Both render as nothing.

The cadences themselves are fixed in ISPConfig's own cron classes, read on isp-test:

| Collector | Monitor type | Schedule (`$_schedule`) | Source |
|---|---|---|---|
| Disk quota | `harddisk_quota` | `*/5 * * * *` | `server/lib/classes/cron.d/100-monitor_hd_quota.inc.php:34` |
| Database size | `database_size` | `*/5 * * * *` | `server/lib/classes/cron.d/100-monitor_database_size.inc.php:36` |
| Mailbox quota | `email_quota` | `*/15 * * * *` | `server/lib/classes/cron.d/100-monitor_email_quota.inc.php:34` |

This feature exposes those rules alongside the figures, so a consumer can say "measured 4 minutes ago,
updated every 5 minutes" instead of a bare timestamp — and can tell an unmeasured resource from a stale one.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Explain a usage figure truthfully (Priority: P1)

A customer panel shows disk usage with "measured 4 minutes ago, updated about every 5 minutes" instead of an
unexplained number, and marks a figure as outdated when the collector has not run.

**Why this priority**: it is the whole feature; without the cadence a consumer can only print a timestamp and
hope the customer understands it.

**Independent Test**: seed a client with a website, a mailbox and a database on one server, write collector
blobs with known ages, and read `GET /usage/summary`: `freshness.web_disk.interval_seconds` is 300,
`stale_after_seconds` 1800, `measured_at` the blob's time and `next_expected_at` exactly 300 seconds later.

**Acceptance Scenarios**:

1. **Given** collector data for a client's servers, **When** the summary is read, **Then** it carries a
   top-level `freshness` object with one entry per collector-backed metric (`web_disk`, `mail_storage`,
   `database_size`), each with `interval_seconds`, `stale_after_seconds`, `measured_at` and
   `next_expected_at`.
2. **Given** a metric whose data was collected at time T, **When** the summary is read, **Then**
   `measured_at` is T and `next_expected_at` is T plus `interval_seconds`, both in the API timezone.
3. **Given** several servers contributing to one metric, **When** the summary is read, **Then**
   `measured_at` is the **oldest** contributing collection time, because the aggregate is only as current as
   its oldest part.
4. **Given** mailbox storage, **When** the summary is read, **Then** its interval is 900 and its stale age
   3600, while disk and databases report 300 and 1800.

---

### User Story 2 - Tell "never measured" apart from "stale" (Priority: P1)

A panel can say "not measured yet" for a resource the collector has never reported, and "last measured at
09:12, waiting for an update" for one whose data has aged out — today both look identical.

**Why this priority**: the coordinator's requirement, and the reason a consumer currently cannot render an
honest message. It also prevents the worst failure mode: printing `0` for an unmeasured value.

**Independent Test**: a client whose servers have no `harddisk_quota` blob at all versus one whose blob is
two hours old. The first reports `measured_at: null` and `next_expected_at: null`; the second reports the
real timestamp even though `web_disk.used_bytes` and `web_disk.measured_at` are `null` because the value is
stale.

**Acceptance Scenarios**:

1. **Given** a metric that has never been collected, **When** the summary is read, **Then** its freshness
   entry has `measured_at: null` and `next_expected_at: null`, while `interval_seconds` and
   `stale_after_seconds` are still present — they describe the installation, not a measurement.
2. **Given** a metric whose newest data is older than `stale_after_seconds`, **When** the summary is read,
   **Then** the metric itself stays `null` (unchanged behaviour) but the freshness entry reports the real
   `measured_at` and `next_expected_at`, so a consumer can explain the age.
3. **Given** either case, **When** a consumer renders the figure, **Then** the contract states it must show
   "not measured yet" rather than `0` — a missing measurement is not a measurement of zero.
4. **Given** a corrupt collector blob, **When** the summary is read, **Then** it behaves like stale data: the
   metric is `null` and the freshness entry reports the timestamp the row carries.

---

### Edge Cases

- A client with no websites, mailboxes or databases at all: every freshness entry reports null timestamps
  with the intervals still present.
- Servers whose clocks differ from the API host: timestamps are reported as stored; the API does not correct
  them, and `next_expected_at` can therefore be in the past, which correctly signals an overdue collector.
- An operator who changes a collector's cron schedule: the interval is configuration, not a hard-coded
  constant, so it can be corrected without a code change.
- `web_traffic_this_month` has no collector (it is summed from daily traffic counters), so it has no
  freshness entry; the contract says so explicitly.

## API Contract *(mandatory)*

No new endpoint. `GET /usage/summary` gains a required top-level `freshness` object:

```json
"freshness": {
  "web_disk":      { "interval_seconds": 300, "stale_after_seconds": 1800, "measured_at": "2026-09-16T04:10:00+02:00", "next_expected_at": "2026-09-16T04:15:00+02:00" },
  "mail_storage":  { "interval_seconds": 900, "stale_after_seconds": 3600, "measured_at": "2026-09-16T04:00:00+02:00", "next_expected_at": "2026-09-16T04:15:00+02:00" },
  "database_size": { "interval_seconds": 300, "stale_after_seconds": 1800, "measured_at": null, "next_expected_at": null }
}
```

How a consumer decides a value is outdated: `measured_at` is null → never measured, show "not measured yet";
otherwise the value is current while `now − measured_at ≤ stale_after_seconds`, and outdated beyond it — the
same rule the API itself applies when it decides to report the metric as `null`.

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Reads only.** No write of any kind; no `sys_datalog`, no `sys_remoteaction`.
- **No extra query.** `UsageService::summary()` already loads the newest blob per (server, type) in one
  `latestBlobs()` call; freshness is folded from that same result.
- **Cadences come from ISPConfig's own cron classes** (table above) and are exposed as configuration so an
  operator whose schedule differs can correct them without patching code. The API does not read remote
  crontabs — the schedule is compiled into ISPConfig and identical across servers of one version.
- **No legacy equivalent**: ISPConfig's own interface shows collector values without explaining their age;
  this adds information, changes no behaviour, and removes nothing.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `GET /usage/summary` MUST return a required top-level `freshness` object with entries for
  `web_disk`, `mail_storage` and `database_size`.
- **FR-002**: Each entry MUST carry `interval_seconds`, `stale_after_seconds`, `measured_at` and
  `next_expected_at`.
- **FR-003**: `interval_seconds` MUST default to 300 for `web_disk` and `database_size` and 900 for
  `mail_storage`, and MUST be configurable.
- **FR-004**: `stale_after_seconds` MUST report the value the API actually applies when deciding that data is
  too old.
- **FR-005**: `measured_at` MUST be the oldest contributing collection time across the account's servers for
  that metric, reported **regardless** of staleness, and `null` only when no collector row exists.
- **FR-006**: `next_expected_at` MUST be `measured_at + interval_seconds`, and `null` whenever `measured_at`
  is null.
- **FR-007**: A metric that has never been collected MUST NOT imply a measurement: no invented timestamp, and
  the contract MUST state that a consumer shows "not measured yet" rather than `0`.
- **FR-008**: The feature MUST NOT add a database query to the summary.
- **FR-009**: Existing fields of the summary MUST keep their current meaning and values.

### Key Entities

- **Freshness entry**: the collection rules and last collection time of one collector-backed metric.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A consumer can render "measured X ago, updated about every Y" for disk, mail and database
  usage from the summary alone, without a second request.
- **SC-002**: A never-collected metric and a stale one are distinguishable in the response (verified by a
  test asserting both shapes).
- **SC-003**: The summary's query count is unchanged by this feature (asserted).
- **SC-004**: Every other field of `/usage/summary` is byte-identical to before (asserted by the existing
  summary tests staying green).

## Assumptions

- The collector cadence is a property of the installation, not of a client or a server row; one configured
  value per monitor type is therefore enough.
- Clock skew between servers is not corrected; an overdue `next_expected_at` is a useful signal, not an error.
- `web_traffic_this_month` stays outside the freshness object because it is not collector-backed.
