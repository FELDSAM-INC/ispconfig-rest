# Feature Specification: Usage Statistics

**Feature Branch**: `017-usage-statistics`  
**Created**: 2026-09-14  
**Status**: Draft  
**Module**: usage (new read-only module over sites / mail / client data)  
**Input**: User description: "Read-only usage statistics for a customer hosting panel: per website disk usage and web traffic, per mailbox storage and mail traffic, per database size, and client-level totals against client limits (limit_web_quota, limit_mailquota, limit_database_quota, limit_traffic_quota). Usable by client-scoped keys (own rows only, spec 011) and admin keys."

## Context

A customer-facing hosting panel (first consumer: a WHMCS module with a Plesk-like dashboard) must show
how much of a hosting plan is used: disk space, mail storage, database size, traffic, and resource
counts against the plan's limits. ISPConfig already collects all of this data, but the API exposes
none of it: the only usage-like data is `monitor`, which is admin-only (spec 011) and describes whole
servers, not customers.

ISPConfig collects the data as follows (verified on ISPConfig 3.3.1p1):

| Metric | Source | Collector | Refresh | Unit at source |
|--------|--------|-----------|---------|----------------|
| Website disk usage | `monitor_data` type `harddisk_quota`, key `user.<web_domain.system_user>` (`used`, `soft`, `hard`, `files`) and `group.<client group>` | `cron.d/100-monitor_hd_quota` (`repquota`, fallback `du -s`) | every 5 min | KiB |
| Mailbox storage | `monitor_data` type `email_quota`, key `<email>.used` | `cron.d/100-monitor_email_quota` (`doveadm quota get -A`, fallback `du -s`) | every 15 min | bytes |
| Database size | `monitor_data` type `database_size`, list of `database_name`, `size`, `sys_groupid` | `cron.d/100-monitor_database_size` | every 5 min | bytes |
| Web traffic | `web_traffic` (`hostname`, `traffic_date`, `traffic_bytes`) — one row per site per day | `cron.d/200-logfiles` | daily at 00:00 (previous day) | bytes |
| Mail traffic | `mail_traffic` (`mailuser_id`, `month` `YYYY-MM`, `traffic`) | `cron.d/100-mailbox_stats` | daily at 00:00 | bytes |

`monitor_data` rows are pruned by `monitor_tools::delOldRecords()` (~240 s), so only the newest blob per
server and type exists (same constraint as spec 009). Limits and quotas come from `client.limit_*`,
`web_domain.hd_quota` / `traffic_quota` (MB), `mail_user.quota` (bytes) and
`web_database.database_quota` (MB).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Plan usage summary for the dashboard (Priority: P1)

A customer opens the hosting dashboard. The panel calls `GET /usage/summary` with the customer's
client-scoped key and shows, in one view: disk space used by websites, mail storage used, database size,
web traffic this month, and counts of websites, subdomains, alias domains, mail domains, mailboxes,
databases, FTP users, cron jobs and DNS zones — each next to the plan limit, with a percentage and the
time the figure was measured.

**Why this priority**: This is the first screen of a Plesk-like panel and the main answer to "how much of
my plan do I use". A single request delivers it; per-resource detail (US2) is a drill-down.

**Independent Test**: Seed client A with `limit_web_quota = 1024`, `limit_mailquota = 2048`,
`limit_database_quota = -1`, `limit_traffic_quota = 10240`, `limit_web_domain = 5`; two vhost sites with
`harddisk_quota` blob entries (868 KiB and 1024 KiB used), one mailbox with `email_quota` used 1048576
bytes, one database with `database_size` 5242880 bytes, `web_traffic` rows for the current month, and
client B with its own rows. `GET /usage/summary` with A's key → 200 with A's totals only; database limit
reported as unlimited; website count `2` of `5`. With B's key → B's totals. With an admin key and
`?client_id=A` → A's totals; admin key without `client_id` → 422.

**Acceptance Scenarios**:

1. **Given** a client key, **When** it requests the summary, **Then** 200 returns totals for that client:
   `web_disk` (used bytes, allocated bytes from site quotas, limit bytes), `mail_storage`, `database_size`
   and `web_traffic_this_month` (used, limit), each with `used_percent` and `measured_at`.
2. **Given** a client limit of `-1`, **When** the summary is built, **Then** that metric's limit is reported
   as unlimited (`limit_bytes: null`, `used_percent: null`) and usage is still reported.
3. **Given** count limits (`limit_web_domain`, `limit_web_subdomain`, `limit_web_aliasdomain`,
   `limit_maildomain`, `limit_mailbox`, `limit_mailalias`, `limit_mailforward`, `limit_database`,
   `limit_ftp_user`, `limit_shell_user`, `limit_cron`, `limit_dns_zone`), **When** the summary is built,
   **Then** each count uses the same counting rules as spec 012 and is returned with its limit; limits of
   `0` (feature disabled) are returned as `0` so the panel can hide the feature.
4. **Given** no current collector data for a metric (collector not run, server down, corrupt blob),
   **When** the summary is built, **Then** that metric's `used` and `measured_at` are `null` and the request
   still succeeds.
5. **Given** an admin key, **When** it requests the summary with `client_id`, **Then** 200 with that client's
   summary; without `client_id` → 422; with an unknown `client_id` → 404.
6. **Given** a reseller key, **When** it requests the summary without `client_id`, **Then** its own client
   summary is returned; with the `client_id` of one of its clients → that client; any other client → 404.

---

### User Story 2 - Per-resource usage lists and details (Priority: P2)

The customer drills down: a websites table with disk used and quota, files count, traffic this month,
last month, this year and last year; a mailboxes table with storage used against mailbox quota and mail
traffic for the same periods; a databases table with size against database quota. Each row links to a
detail view.

**Why this priority**: Needed to find what consumes the plan, but the dashboard (US1) is usable without
it. It reuses the same data sources.

**Independent Test**: With client A's key, `GET /usage/web-domains` lists only A's vhost, vhost-subdomain
and vhost-alias sites with `{data, meta}`; the vhost row carries disk figures from `harddisk_quota`, the
other rows carry `disk: null` and their parent site id. `GET /usage/mail-users?mail_domain=example.com`
lists only mailboxes of that domain. `GET /usage/databases/{id}` of a client B database → 404.

**Acceptance Scenarios**:

1. **Given** websites owned by the key's scope, **When** `GET /usage/web-domains` is called, **Then** each
   row has `domain_id`, `domain`, `type`, `parent_domain_id`, disk `used_bytes`, `soft_limit_bytes`,
   `hard_limit_bytes`, `files`, `used_percent` (against the soft limit, as legacy) and web traffic
   `this_month`, `last_month`, `this_year`, `last_year` in bytes plus `traffic_quota_bytes`.
2. **Given** a vhost-subdomain or vhost-alias site, **When** it is listed, **Then** its disk figures are
   `null` (disk is accounted to the parent vhost's system user) and its traffic is reported for its own
   hostname.
3. **Given** mailboxes, **When** `GET /usage/mail-users` is called, **Then** each row has `mailuser_id`,
   `email`, `used_bytes`, `quota_bytes` (`null` when unlimited), `used_percent`, mail traffic for the four
   periods, and `measured_at`.
4. **Given** databases, **When** `GET /usage/databases` is called, **Then** each row has `database_id`,
   `database_name`, `type`, `size_bytes`, `quota_bytes` (`null` when unlimited), `used_percent` and
   `measured_at`.
5. **Given** list query parameters, **Then** lists support `limit`/`offset`/`sort`/`order` (sortable by the
   identifying columns: `domain`, `email`, `database_name`) and filters `client_id` (admin and reseller
   keys only), `domain` / `email` / `database_name` (substring), `mail_domain` (mail users) and
   `parent_domain_id` (web domains); unknown parameters → 400.
6. **Given** a resource id outside the key's read scope, **When** its detail is requested, **Then** 404.

---

### User Story 3 - Traffic history (Priority: P3)

The customer opens a website or mailbox and sees a traffic chart: monthly totals for the last 12 months,
and for websites daily totals of the current month.

**Why this priority**: Nice-to-have visualisation; current and previous period figures (US1/US2) cover
the plan-usage need.

**Independent Test**: Seed `web_traffic` rows across 14 months for A's site; `GET
/usage/web-domains/{id}/traffic?months=12` returns 12 monthly points, oldest first, months without rows as
`0`; `?granularity=day` returns one point per day of the current month up to yesterday.

**Acceptance Scenarios**:

1. **Given** a website, **When** its traffic history is requested, **Then** 200 returns `granularity`,
   `period_start`, `period_end` and `points` (`period`, `bytes`); default 12 months, `months` 1–36.
2. **Given** a mailbox, **When** its traffic history is requested, **Then** monthly points are returned
   (`granularity=day` → 422, mail traffic is only stored per month).
3. **Given** a resource outside the key's scope, **Then** 404.

### Edge Cases

- Missing or invalid `X-API-Key` → 401; the usage module is available to admin, reseller and client keys
  (unlike `monitor`).
- Collector blob missing, older than the retention window, or not decodable → the affected values are
  `null` with `measured_at: null`; never 500 (same defensive decoding as spec 009).
- A mailbox with no entry in the `email_quota` blob → `used_bytes: null` (legacy shows `0`).
- Several servers: blobs exist per server; a resource is matched only against the blob of its own
  `server_id` (system user names such as `web1` can repeat on different servers).
- Unlimited markers differ per column: `hd_quota` ≤ 0, `mail_user.quota` = 0 or -1, `database_quota` ≤ 0,
  `traffic_quota` = -1, `client.limit_*` = -1 → reported as unlimited (`null` limit, `null` percent).
- A website renamed after traffic was recorded: traffic is keyed by hostname, so history recorded under
  the old hostname is not attributed (legacy parity).
- Today's web traffic is not available until the nightly collector runs; `this_month` covers days up to
  the last collector run.
- Filesystem quotas disabled on the web server: `du` fallback provides `used` without soft/hard limits →
  limits `null`, percent against `hd_quota` instead.
- `client_id` sent by a client key: allowed only when it equals the key's own client (otherwise 404);
  unknown query parameters → 400.

## API Contract *(mandatory)*

- **Spec file(s)**: new module `api/modules/usage/` — `summary.yaml`, `web-domains.yaml`,
  `mail-users.yaml`, `databases.yaml`, `_index.yaml` (all new, to be authored first).
- **Shared schemas**: `api/components/schemas/UsageSummary.yaml`, `UsageMetric.yaml`,
  `UsageCount.yaml`, `WebDomainUsage.yaml`, `MailUserUsage.yaml`, `DatabaseUsage.yaml`,
  `TrafficPeriods.yaml`, `TrafficHistory.yaml` (all new). Shared list parameters and problem responses
  reused.
- **Endpoints**:

| Method | Path | Purpose | Success code |
|--------|------|---------|--------------|
| GET | `/api/v1/usage/summary` | Client usage totals and resource counts against limits | 200 |
| GET | `/api/v1/usage/web-domains` | List website disk usage and traffic periods (`{data, meta}`) | 200 |
| GET | `/api/v1/usage/web-domains/{id}` | Website usage detail | 200 |
| GET | `/api/v1/usage/web-domains/{id}/traffic` | Website traffic history (monthly or daily) | 200 |
| GET | `/api/v1/usage/mail-users` | List mailbox storage and mail traffic periods (`{data, meta}`) | 200 |
| GET | `/api/v1/usage/mail-users/{id}` | Mailbox usage detail | 200 |
| GET | `/api/v1/usage/mail-users/{id}/traffic` | Mailbox traffic history (monthly) | 200 |
| GET | `/api/v1/usage/databases` | List database sizes (`{data, meta}`) | 200 |
| GET | `/api/v1/usage/databases/{id}` | Database usage detail | 200 |

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference** (ISPConfig 3.3.1p1): `interface/web/sites/user_quota_stats.php`,
  `sites/web_sites_stats.php`, `sites/database_quota_stats.php`, `mail/user_quota_stats.php`,
  `mail/mail_user_stats.php`, `interface/lib/classes/quota_lib.inc.php`
  (`get_quota_data`, `get_trafficquota_data`, `get_mailquota_data`, `get_databasequota_data`),
  `dashboard/dashlets/limits.php`, `quota.php`, `mailquota.php`, `databasequota.php`; collectors in
  `server/lib/classes/cron.d/` (`100-monitor_hd_quota`, `100-monitor_email_quota`,
  `100-monitor_database_size`, `200-logfiles`, `100-mailbox_stats`) and `monitor_tools::delOldRecords()`.
- **Legacy behaviors to mirror**:
  - Website disk usage per vhost from `harddisk_quota` `user.<system_user>` (KiB → bytes), percentage
    against the soft limit (`quota_lib::get_quota_data`).
  - Web traffic periods this month / last month / this year / last year by calendar boundaries summed from
    `web_traffic` for vhost, vhostsubdomain and vhostalias sites (`get_trafficquota_data`).
  - Mail storage from `email_quota` by email; mailbox quota `0` treated as unlimited (`mailquota.php`).
  - Mail traffic periods from `mail_traffic` by month (`mail_user_stats.php`).
  - Database size from `database_size` by database name; unlimited when `database_quota` ≤ 0.
  - Counts and allocated quota sums as in `dashlets/limits.php`, with the counting predicates already
    implemented for spec 012; row visibility via the legacy read predicate (spec 011).
- **Tables written (via datalog only)**: none — read-only feature. Reads `monitor_data`, `web_traffic`,
  `mail_traffic`, `web_domain`, `mail_user`, `web_database`, `client`, `sys_group`, and the tables counted
  for limits.
- **System fields handling**: not applicable (no writes).
- **Intentional deviations from legacy**:
  - Unknown values are `null` instead of `0` / `n/a`, so consumers can tell "no data" from "empty".
  - Monitor blobs are matched to a resource by its `server_id`; legacy merges blobs from all servers and can
    mismatch repeated system user names.
  - All sizes are returned in bytes; MB columns are converted with 1024², including database quotas
    (legacy `quota_lib` uses 1000² for database quota but the collector enforces 1024²).
  - The summary reports actual usage in addition to the allocated quota sums that the legacy limits dashlet
    shows.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST expose a read-only `usage` module accessible to admin, reseller and client keys;
  every row and total MUST be restricted by the key's read scope (spec 011).
- **FR-002**: `GET /usage/summary` MUST return, for one client, `web_disk`, `mail_storage`,
  `database_size` and `web_traffic_this_month` metrics with used bytes, allocated bytes (where quotas are
  assignable), limit bytes or unlimited, used percent and `measured_at`.
- **FR-003**: The summary MUST include the resource counts listed in US1 scenario 3 with their limits,
  computed with the spec 012 counting rules.
- **FR-004**: Client keys MUST receive their own client's summary; reseller keys their own or one of their
  clients' by `client_id`; admin keys MUST pass `client_id` (422 when missing, 404 when unknown or out of
  scope).
- **FR-005**: Website usage MUST report disk usage for vhost sites from the newest `harddisk_quota` blob of
  the site's server, and `null` disk figures for vhost-subdomain and vhost-alias sites together with
  `parent_domain_id`.
- **FR-006**: Website and mailbox usage MUST report traffic for this month, last month, this year and last
  year in bytes using calendar periods in the API's configured timezone.
- **FR-007**: Mailbox usage MUST report storage from the newest `email_quota` blob of the mailbox's server
  and the mailbox quota (unlimited when `0` or `-1`).
- **FR-008**: Database usage MUST report size from the newest `database_size` blob of the database's server
  and the database quota (unlimited when ≤ 0).
- **FR-009**: Lists MUST use the shared `{data, meta}` envelope and list parameters with the filters in US2
  scenario 5; unknown parameters MUST return 400.
- **FR-010**: Traffic history MUST return monthly points (default 12, allowed 1–36, oldest first, missing
  months as 0) for websites and mailboxes, and daily points of the current month for websites.
- **FR-011**: Missing, stale or corrupt collector data MUST yield `null` values and `measured_at: null`,
  never an error response.
- **FR-012**: A list request MUST read each collector blob and traffic table at most once per request
  (no per-row reads), so large clients remain fast.
- **FR-013**: The feature MUST NOT write to any table and MUST NOT trigger collectors.
- **FR-014**: Every endpoint MUST be defined in the OpenAPI contract first and covered by feature tests for
  success, scoping (two clients), unlimited limits, missing collector data, 400/401/404/422 cases.
- **FR-015**: The installer MUST set the API's timezone to the ISPConfig server's system timezone so calendar
  traffic periods match the dates ISPConfig writes; existing installations get it on the next
  `ispconfig-rest update` unless the timezone was set explicitly (owner decision 2026-09-14).

### Key Entities

- **Usage Summary**: per-client totals and counts against limits — computed from `client`, `web_domain`,
  `mail_user`, `web_database`, `monitor_data`, `web_traffic`; schema `api/components/schemas/UsageSummary.yaml`
  (metrics `UsageMetric.yaml`, counts `UsageCount.yaml`); no model.
- **Website Usage**: disk and traffic of one site — `web_domain` + `monitor_data` (`harddisk_quota`) +
  `web_traffic`; schema `WebDomainUsage.yaml`, periods `TrafficPeriods.yaml`; base model
  `app/Models/WebDomain.php`.
- **Mailbox Usage**: storage and traffic of one mailbox — `mail_user` + `monitor_data` (`email_quota`) +
  `mail_traffic`; schema `MailUserUsage.yaml`; base model `app/Models/MailUser.php`.
- **Database Usage**: size of one database — `web_database` + `monitor_data` (`database_size`); schema
  `DatabaseUsage.yaml`; base model `app/Models/WebDatabase.php`.
- **Traffic History**: time series for one site or mailbox — `web_traffic` / `mail_traffic`; schema
  `TrafficHistory.yaml`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A customer dashboard shows all plan usage figures (disk, mail, databases, traffic, resource
  counts) from a single request.
- **SC-002**: In tests with two clients, a client key never receives another client's usage, totals or
  counts (zero leaks across all endpoints).
- **SC-003**: For the same seeded data, disk, mail, database and traffic figures equal the values shown by
  the legacy ISPConfig statistics pages (after unit conversion to bytes).
- **SC-004**: When collector data is absent or corrupt, every endpoint still responds successfully and marks
  the affected figures as unknown.
- **SC-005**: A client with 200 websites, 500 mailboxes and 50 databases gets each usage list page within
  2 seconds.
- **SC-006**: All endpoints render in Swagger UI and behave as documented, including 400/401/404/422 cases.

## Assumptions

- The ISPConfig servers of one installation share one timezone; the installer aligns the API with it (FR-015),
  so calendar periods line up with the dates written to `web_traffic` and `mail_traffic`.
- Data freshness is whatever the ISPConfig collectors provide (5 min disk and databases, 15 min mail
  storage, daily traffic); the API does not trigger or cache collection.
- FTP traffic (`ftp_traffic`), backup storage statistics, OpenVZ traffic and web statistics pages
  (AWStats/GoAccess) are out of scope; backups are covered by a separate backups feature.
- Reseller-wide aggregates across all their clients are out of scope; resellers query one client at a
  time.
- Client-level web disk usage is the sum of the client's vhost site usages (always available), not the
  filesystem group quota value, which exists only when group quotas are enabled (owner decision 2026-09-14).
- Sorting by computed usage values is not required; consumers sort client-side within a page.
