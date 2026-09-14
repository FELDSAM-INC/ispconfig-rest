# Data Model: Usage Statistics (017)

Read-only projections. No table is created or written. All sizes in bytes (integers), percentages as numbers
with one decimal, timestamps ISO 8601 with offset in the API timezone. `null` means unknown or unlimited as
stated per field.

## Sources

| Source | Key used | Shape / unit | Freshness |
|--------|----------|--------------|-----------|
| `monitor_data` `harddisk_quota` | `(server_id)` newest row → `data['user'][web_domain.system_user]` | `used`, `soft`, `hard` KiB (strings), `files` | `*/5` collector; stale after 1800 s |
| `monitor_data` `email_quota` | `(server_id)` newest row → `data[mail_user.email]['used']` | bytes | `*/15`; stale after 3600 s |
| `monitor_data` `database_size` | `(server_id)` newest row → list item with `database_name` | `size` bytes | `*/5`; stale after 1800 s |
| `web_traffic` | `hostname = web_domain.domain`, `traffic_date` DATE | `traffic_bytes` | nightly, previous day |
| `mail_traffic` | `mailuser_id`, `month` `YYYY-MM` | `traffic` bytes | nightly |
| `client.limit_*` | target client | MB for quota limits, counts for others; `-1` unlimited, `0` disabled | live |
| `web_domain.hd_quota`, `traffic_quota`; `web_database.database_quota` | row | MB; `≤ 0` / `-1` unlimited | live |
| `mail_user.quota` | row | bytes; `0` / `-1` unlimited | live |

## UsageMetric (schema `UsageMetric.yaml`)

| Field | Type | Rule |
|-------|------|------|
| `used_bytes` | integer\|null | sum of known values; null when no contributing value is known |
| `allocated_bytes` | integer\|null | sum of assigned quotas (MB × 1024² or bytes); null for metrics without assignable quotas |
| `limit_bytes` | integer\|null | client limit × 1024²; null when unlimited (`-1`) |
| `used_percent` | number\|null | `used / limit × 100` when both known and limit > 0 |
| `measured_at` | string\|null | oldest `created` among contributing blobs; null for traffic and when unknown |

## UsageCount (schema `UsageCount.yaml`)

| Field | Type | Rule |
|-------|------|------|
| `used` | integer | spec 012 count for the limit column (`ClientLimitService::countUsage`) |
| `limit` | integer\|null | `null` unlimited (`-1`), `0` disabled, `n` booked |

Keys: `web_domains`, `web_subdomains`, `web_alias_domains`, `mail_domains`, `mailboxes`, `mail_aliases`,
`mail_forwards`, `databases`, `ftp_users`, `shell_users`, `cron_jobs`, `dns_zones` → limit columns
`limit_web_domain`, `limit_web_subdomain`, `limit_web_aliasdomain`, `limit_maildomain`, `limit_mailbox`,
`limit_mailalias`, `limit_mailforward`, `limit_database`, `limit_ftp_user`, `limit_shell_user`, `limit_cron`,
`limit_dns_zone`.

## UsageSummary (schema `UsageSummary.yaml`)

| Field | Type | Rule |
|-------|------|------|
| `client_id` | integer | resolved target client (R7) |
| `web_disk` | UsageMetric | vhost sites; allocated from `hd_quota`; limit `limit_web_quota` |
| `mail_storage` | UsageMetric | mailboxes; allocated from `mail_user.quota`; limit `limit_mailquota` |
| `database_size` | UsageMetric | databases; allocated from `database_quota`; limit `limit_database_quota` |
| `web_traffic_this_month` | UsageMetric | active vhost-type sites; allocated from `traffic_quota`; limit `limit_traffic_quota`; `measured_at` null |
| `counts` | map of UsageCount | keys above |
| `period` | object | `this_month_start` (date), `timezone` (string) |

## TrafficPeriods (schema `TrafficPeriods.yaml`)

`this_month`, `last_month`, `this_year`, `last_year` — integers (bytes), `0` when no rows. Calendar boundaries in
`config('app.timezone')`: this month `[first day, first day of next month)`, last month, this year
`[Jan 1, Jan 1 next year)`, last year.

## WebDomainUsage (schema `WebDomainUsage.yaml`)

| Field | Type | Rule |
|-------|------|------|
| `domain_id`, `domain`, `type`, `parent_domain_id`, `server_id` | from `web_domain` | types `vhost`, `vhostsubdomain`, `vhostalias` only |
| `disk.used_bytes` | integer\|null | vhost: KiB × 1024; child types: null |
| `disk.soft_limit_bytes`, `disk.hard_limit_bytes` | integer\|null | KiB × 1024 when > 0, else null |
| `disk.files` | integer\|null | vhost only |
| `disk.used_percent` | number\|null | against soft limit; fallback `hd_quota × 1024²` when soft unknown and hd_quota > 0 |
| `disk.measured_at` | string\|null | blob `created` |
| `hd_quota_bytes` | integer\|null | `hd_quota × 1024²`, null when ≤ 0 |
| `traffic` | TrafficPeriods | by `domain` hostname |
| `traffic_quota_bytes` | integer\|null | `traffic_quota × 1024²`, null when `-1` / ≤ 0 |

## MailUserUsage (schema `MailUserUsage.yaml`)

| Field | Type | Rule |
|-------|------|------|
| `mailuser_id`, `email`, `server_id` | from `mail_user` | |
| `used_bytes` | integer\|null | `email_quota` blob; null when email absent or blob stale |
| `quota_bytes` | integer\|null | null when `0` or `-1` |
| `used_percent` | number\|null | when both known and quota > 0 |
| `measured_at` | string\|null | blob `created` |
| `traffic` | TrafficPeriods | by `mailuser_id` |

## DatabaseUsage (schema `DatabaseUsage.yaml`)

| Field | Type | Rule |
|-------|------|------|
| `database_id`, `database_name`, `type`, `server_id`, `parent_domain_id` | from `web_database` | |
| `size_bytes` | integer\|null | `database_size` blob by name; null when absent or stale |
| `quota_bytes` | integer\|null | `database_quota × 1024²`, null when ≤ 0 |
| `used_percent` | number\|null | when both known and quota > 0 |
| `measured_at` | string\|null | blob `created` |

## TrafficHistory (schema `TrafficHistory.yaml`)

| Field | Type | Rule |
|-------|------|------|
| `granularity` | `month`\|`day` | `day` websites only |
| `period_start`, `period_end` | date | inclusive start, exclusive end |
| `timezone` | string | API timezone |
| `points[]` | `{period, bytes}` | `period` `YYYY-MM` or `YYYY-MM-DD`; oldest first; missing → 0 |

## Validation rules

- `months`: integer 1–36 (422); `granularity`: `month`|`day` (422; `day` on mail users → 422).
- `client_id` (summary, lists): positive integer; admin summary requires it (422); out of scope or unknown → 404;
  on lists only admin/reseller keys (400 for client keys).
- List parameters: shared `limit`/`offset`/`sort`/`order`; unknown parameters → 400.

## State transitions

None — read-only projections.
