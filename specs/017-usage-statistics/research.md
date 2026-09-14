# Research: Usage Statistics (017)

Verified read-only against ISPConfig 3.3.1p1 on isp-test.feldhost.cz (source in `/usr/local/ispconfig`, live
`dbispconfig`) and against this repository on branch `017-usage-statistics`.

## R1 — Newest collector blob per server, read once per request

- **Decision**: Add `MonitorDataService::latestBlobs(array $types, array $serverIds): array` returning
  `[serverId][type] => ['data' => array|null, 'created' => int]`. One query:
  `SELECT server_id, type, created, data FROM monitor_data WHERE type IN (…) AND server_id IN (…) ORDER BY created
  DESC`, keeping the first row seen per `(server_id, type)`. Decoding reuses the existing hardened
  `unserialize(..., ['allowed_classes' => false])` (made reusable inside the service). Services call it once per
  request with the distinct `server_id`s of the rows being projected (FR-012).
- **Rationale**: Collectors `REPLACE INTO monitor_data` and immediately run `delOldRecords()`
  (`DELETE … WHERE type = ? AND created < UNIX_TIMESTAMP() - 240 AND server_id = ?`), so each server keeps one to
  a few rows per type; the newest row is the current value. Spec 009 already selects newest-per-type this way.
- **Alternatives considered**: merging blobs of all servers like legacy `quota_lib` (`array_merge_recursive`) —
  rejected by the spec (system users such as `web1` repeat across servers); per-row reads — violates FR-012.

## R2 — Staleness rule (FR-011)

- **Decision**: A blob is stale when `now - created` exceeds `config('api.usage.stale_after')` for its type:
  `harddisk_quota` 1800 s, `database_size` 1800 s, `email_quota` 3600 s (≈ 6× and 4× the collector intervals
  `*/5` and `*/15`). Stale, missing or undecodable blobs make the affected values `null` with `measured_at: null`
  (owner decision 2026-09-14).
- **Rationale**: Rows survive until the next collector run, so age tells whether the collector still runs; a
  multiple of the schedule tolerates a skipped run and cron jitter without showing hours-old figures as current.
- **Alternatives considered**: 240 s retention window from `delOldRecords` — too strict, the newest row is
  normally older than 240 s just before the next run; no staleness check — a dead collector would show frozen
  values forever.

## R3 — Website disk usage (FR-005)

- **Decision**: For `type = 'vhost'` rows take `blob['user'][system_user]` from the site's own server:
  `used_bytes = used × 1024`, `soft_limit_bytes = soft > 0 ? soft × 1024 : null`, `hard_limit_bytes` likewise,
  `files = (int) files`. `used_percent = round(used / soft × 100, 1)` when `soft > 0`; when the collector provides
  no soft limit (`du -s` fallback or quotas disabled) and `hd_quota > 0`, percent is computed against
  `hd_quota × 1024²`; otherwise `null`. `vhostsubdomain` / `vhostalias` rows return all disk fields `null` plus
  `parent_domain_id` (they share the parent's system user). Non-numeric legacy shapes (`[0 => x, 1 => y]` produced
  by recursive merges) are normalised by taking the larger numeric element, as `quota_lib::get_quota_data` does.
- **Rationale**: Matches `quota_lib::get_quota_data` (KiB → bytes, percent against soft) and the real blob on
  isp-test (`web1: used 868, soft 1048576, hard 1049600, files 43`).
- **Alternatives considered**: using the `group` section for per-site figures — it is per client group, not per
  site.

## R4 — Mailbox storage and database size (FR-007, FR-008)

- **Decision**: Mail: `blob[email]['used']` (bytes) from the mailbox's server; missing email → `null`
  (legacy shows 0). Quota: `mail_user.quota` bytes, `0` or `-1` → unlimited (`null`). Database: index the
  `database_size` list by `database_name` for the database's server; `size` bytes; quota
  `database_quota × 1024²`, `≤ 0` → unlimited.
- **Rationale**: Blob shapes verified on isp-test (`info@test.cz: used 1024`; `c1testcz: size 0`). The 1024²
  conversion is the spec's documented deviation from `quota_lib` (1000²) because the collector enforces 1024².
- **Alternatives considered**: `sys_groupid` from the database blob for matching — `database_name` is unique per
  server, the group id is redundant.

## R5 — Traffic periods and timezone (FR-006, FR-015)

- **Decision**: `TrafficPeriodService` builds boundaries with `CarbonImmutable::now(config('app.timezone'))`:
  this month `[Y-m-01, next month)`, last month, this year `[Y-01-01, next year)`, last year. Web: one query per
  request for all hostnames of the rows —
  `SELECT hostname, SUM(CASE WHEN traffic_date >= :tm THEN traffic_bytes ELSE 0 END) this_month, SUM(CASE WHEN
  traffic_date >= :lm AND traffic_date < :tm …) last_month, … FROM web_traffic WHERE hostname IN (…) AND
  traffic_date >= :last_year_start GROUP BY hostname`. Mail: same pattern on `mail_traffic.month` strings
  (`'YYYY-MM'` compares lexicographically). Missing rows → `0` for periods (traffic is known to be zero once a
  site exists), not `null`.
- **Rationale**: `traffic_date` is a DATE and `month` a string, both written in the server's local time by
  `200-logfiles` / `100-mailbox_stats`; boundaries must be computed in the same timezone. Legacy uses PHP
  `date()` in the server timezone. Grouped `CASE` sums work on MySQL and on the sqlite test database.
- **Alternatives considered**: MySQL `YEAR()`/`MONTH()` like legacy — not sqlite-compatible and cannot use the
  `hostname` index range as well; per-period queries — four scans instead of one.

## R6 — Why FR-015 needs a code change, not only installer work

- **Decision**: Change `config/app.php` to `'timezone' => env('APP_TIMEZONE', 'UTC')`. Installer: new
  `--timezone TZ` flag / `ISPC_REST_TIMEZONE`; when absent, detect the system timezone in this order —
  `timedatectl show -p Timezone --value`, `/etc/timezone`, `readlink -f /etc/localtime` minus
  `/usr/share/zoneinfo/` — validate it with PHP `timezone_identifiers_list()`, fall back to `UTC` with a warning,
  write `APP_TIMEZONE` to `.env`, and record `TIMEZONE_MODE="auto"|"explicit"` plus `TIMEZONE` in
  `/etc/ispconfig-rest/install.conf`. `ispconfig-rest update`: when `TIMEZONE_MODE` is `auto` or missing
  (installations older than this feature), re-detect and rewrite only the `APP_TIMEZONE=` line of `.env` before
  `config:cache`; `explicit` is left alone. `ispconfig-rest status` prints the API and system timezone.
- **Rationale**: isp-test today: system `Europe/Prague`, `.env` `APP_TIMEZONE=UTC` written by the installer, and
  `config/app.php` hard-codes `'UTC'`, so the env value is ignored altogether. Installs predating this feature
  never chose a timezone, so treating a missing mode as `auto` satisfies "unless set explicitly".
- **Alternatives considered**: per-request timezone parameter — consumers would have to know the server
  timezone; storing periods in UTC — legacy dates are local, so month boundaries would shift by the offset.

## R7 — Summary target client and access (FR-004)

- **Decision**: `UsageService::resolveTargetClient(AuthScope $scope, ?int $clientId)`:
  admin — `client_id` required (422 via `UnprocessableEntityHttpException`), unknown → 404; client key — own
  `clientId`, a different `client_id` → 404; reseller key — own client when omitted, otherwise the client's
  `sys_group.groupid` must be in `$scope->groupIds` (reseller's `sys_user.groups` CSV) else 404. The summary is then
  computed with `AuthScope::forClient($clientId)`: the client's control-panel identity resolved like `ApiKeyAuth`
  (`sys_group.client_id` → `sys_user.default_group`, groups CSV), so counts and sums match what that client itself
  would see.
- **Rationale**: Mirrors `dashlets/limits.php`, which counts with `getAuthSQL('r', …, clientid_to_groups_list(
  $client_id))` for the selected client, and reuses the spec 011 identity resolution. 404 rather than 403 avoids
  confirming the existence of other clients.
- **Alternatives considered**: computing with the acting scope — an admin scope would count every tenant's rows.

## R8 — Resource counts and allocated quotas (FR-003)

- **Decision**: Add read-only public methods to `ClientLimitService`: `countUsage(AuthScope $scope, string
  $limitColumn): int` and `allocatedQuota(AuthScope $scope, string $limitColumn): int` (MB), resolving the same
  `LimitSpec` entries used by `checkCreate()` / `checkQuotaSum()` (`webDomainCountSpecs`,
  `mailForwardingCountSpecs`, `databaseCountSpecs`, quota specs) through a new `limitSpecFor(string $limitColumn)`
  map. Counts in the summary: `limit_web_domain`, `limit_web_subdomain`, `limit_web_aliasdomain`,
  `limit_maildomain`, `limit_mailbox`, `limit_mailalias`, `limit_mailforward`, `limit_database`,
  `limit_ftp_user`, `limit_shell_user`, `limit_cron`, `limit_dns_zone`. Limit `-1` → `null` (unlimited), `0` →
  `0` (disabled, US1 scenario 3).
- **Rationale**: FR-003 requires spec 012 counting rules; keeping one map avoids divergence between enforcement and
  display.
- **Alternatives considered**: re-implementing counts in `UsageService` — two sources of truth for type filters and
  predicates.

## R9 — Summary metrics composition (FR-002)

- **Decision**:
  - `web_disk`: used = sum of `used_bytes` over the client's `vhost` sites with known data; allocated =
    `allocatedQuota('limit_web_quota')` × 1024²; limit = `limit_web_quota` × 1024² (`-1` → null);
    `measured_at` = oldest `created` of the contributing blobs; `used` null when no vhost has data.
  - `mail_storage`: same pattern with mailbox usage, allocated from `limit_mailquota` quota sum.
  - `database_size`: same pattern, allocated from `limit_database_quota`.
  - `web_traffic_this_month`: used = this-month sum over the client's `vhost`, `vhostsubdomain`, `vhostalias`
    sites with `active = 'y'` (legacy `get_trafficquota_data`; owner decision 2026-09-14), allocated from `limit_traffic_quota` quota sum,
    limit = `limit_traffic_quota` × 1024²; `measured_at` = null (daily table, no collector timestamp).
  - `used_percent` = used / limit × 100 (1 decimal) when both known and limit > 0, else null.
- **Rationale**: Owner decision — client disk total is the sum of sites, not the group quota. Summing known values
  keeps the dashboard useful when a brand-new site has no collector data yet.
- **Alternatives considered**: null the total when any site lacks data — one new site would blank the dashboard.

## R10 — List filters and sorting (FR-009)

- **Decision**: `HandlesListQuery` with filters `domain` / `email` / `database_name` as the project's `wildcard`
  type (`*` → SQL `LIKE`), `mail_domain` (mail users, matched on the email's domain part), `parent_domain_id`
  (web domains), and `client_id` as the existing `owning_client` filter. `client_id` is accepted only for admin
  and reseller keys; a client key sending it gets 400 (owner decision 2026-09-14). Sorting whitelists: `domain`; `email`; `database_name`.
  Web domain lists include only `vhost`, `vhostsubdomain`, `vhostalias` rows.
- **Rationale**: Reuses the shared list machinery (strict parameters, read predicate before counting). The project
  convention for name filters is `*` wildcards; the spec was aligned to it (owner decision 2026-09-14).
- **Alternatives considered**: bespoke substring matching — diverges from every other list endpoint.

## R11 — Traffic history (FR-010)

- **Decision**: `months` 1–36 (default 12), `granularity` `month` (default) or `day`; `day` only for websites
  (mail → 422) and covers the current month from day 1 to yesterday. Monthly points come from one grouped query
  (web: `SUBSTR(traffic_date, 1, 7)`; mail: `month`), zero-filled in PHP, oldest first.
- **Rationale**: `mail_traffic` stores months only; today's web traffic is written after midnight.

## R12 — Tests without MySQL

- **Decision**: New `tests/Support/UsageSchema.php` creates `web_traffic` and `mail_traffic`;
  `MonitorCompletionSchema` provides `monitor_data`; `SitesSchema`, `MailCompletionSchema`, `TenantSchema` +
  `TenantFixtures` provide resources, clients and the four-key matrix. Blobs are seeded with `serialize()` like
  `ServerStatusApiTest`. Period tests freeze time with `Carbon::setTestNow()` and set `config(['app.timezone' =>
  'Europe/Prague'])`. Installer/manager changes are verified manually on isp-test (no shell test harness exists).
- **Rationale**: Matches existing test infrastructure; local development uses docker `php:8.3-cli`.
