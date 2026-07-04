# Feature Specification: Monitor Module Completion (Server Status & System Logs)

**Feature Branch**: `009-monitor-module-completion`  
**Created**: 2026-07-04  
**Status**: Draft (reverse-engineered from contract + legacy source; not yet implemented)  
**Module**: monitor  
**Input**: Complete the monitor module by implementing its two specced-but-unbuilt resources: server status (`api/modules/monitor/server-status.yaml`) and system logs (`api/modules/monitor/system-logs.yaml`). The third monitor resource, data-logs, is already implemented (`app/Http/Controllers/Api/V1/Monitor/DataLogController.php`, covered by specs/004).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Fleet-wide server health poll (Priority: P1)

An ops tool (dashboard, alerting bot, uptime monitor) calls `GET /api/v1/monitor/servers/status` with its `X-API-Key` on a schedule and receives one aggregated status object per server: overall state, load averages, memory/disk usage, uptime, and per-service status. This is the single call that answers "is my ISPConfig farm healthy right now?" without HTML scraping of the legacy panel.

**Why this priority**: This is the core value of the monitor module — machine-readable health for the whole server farm in one request. It works standalone and is what polling integrations need first.

**Independent Test**: Seed (or use a live) `monitor_data` table with rows for at least one server (`server_load`, `services`, `disk_usage`, `mem_usage`, `os_info`), call `GET /api/v1/monitor/servers/status` with a valid `X-API-Key`, and verify a `data` array with one `ServerStatus` object per row in the `server` table, each field populated from the deserialized blobs.

**Acceptance Scenarios**:

1. **Given** two servers in the `server` table with recent `monitor_data` rows, **When** `GET /api/v1/monitor/servers/status` is called with a valid key, **Then** the response is `200` with `data` containing two `ServerStatus` objects, each with `server_id`, `server_name`, and `status` populated.
2. **Given** a server whose latest `disk_usage` row has state `critical`, **When** the list endpoint is called, **Then** that server's `status` reflects the highest-severity state across all its monitor types (mapped to the contract enum — see FR-004).
3. **Given** a server present in `server` but with no `monitor_data` rows yet, **When** the list endpoint is called, **Then** the server still appears with `status: "unknown"` and metric fields absent/null (legacy renders such servers with unknown state).
4. **Given** no/invalid `X-API-Key`, **When** the endpoint is called, **Then** the response is `401` with the shared `Unauthorized` body.

---

### User Story 2 - Single-server status drill-down (Priority: P2)

After the fleet poll shows a degraded server, the ops tool calls `GET /api/v1/monitor/servers/{id}/status` to fetch the detailed status of that one server (same `ServerStatus` shape) without transferring the whole fleet's data.

**Why this priority**: Natural companion to US1 — needed for drill-down and for small integrations that watch one server — but the list endpoint alone is already a viable MVP.

**Independent Test**: Call `GET /api/v1/monitor/servers/1/status` for an existing server and verify a single `ServerStatus` object; call it with a nonexistent id and verify `404`.

**Acceptance Scenarios**:

1. **Given** server id `1` exists in the `server` table, **When** `GET /api/v1/monitor/servers/1/status` is called, **Then** the response is `200` with one `ServerStatus` object (not wrapped in `data`).
2. **Given** server id `999` does not exist, **When** the endpoint is called, **Then** the response is `404` ("Server not found").
3. **Given** a server whose `services` blob reports `webserver=1, smtpserver=0, bindserver=-1`, **When** the endpoint is called, **Then** the `services` array reports those services as `running`, `stopped`/`error`, and `unknown` respectively (mapping per FR-010).

---

### User Story 3 - Browse and filter system logs (Priority: P3)

A troubleshooting script or audit tool calls `GET /api/v1/monitor/system-logs` to page through ISPConfig's `sys_log` entries — the per-server processing log the daemons write while applying datalog changes — filtered by server, log level, and date range, e.g. "all errors on server 2 since yesterday".

**Why this priority**: Completes the monitor module's read surface and pairs with the already-implemented data-logs resource (a datalog entry's outcome is visible in `sys_log` via `datalog_id`), but health polling (US1/US2) is the more common integration need.

**Independent Test**: Seed `sys_log` with entries of mixed `loglevel` and `server_id`, call the endpoint with each filter combination plus `limit`/`offset`, and verify filtering, ordering, and the pagination envelope.

**Acceptance Scenarios**:

1. **Given** 50 `sys_log` rows, **When** `GET /api/v1/monitor/system-logs?limit=20&offset=20` is called, **Then** the response is `200` with 20 items in `data` and a `pagination` object reflecting the totals.
2. **Given** rows with `loglevel` 0, 1 and 2, **When** `?loglevel=2` is passed, **Then** only error-level entries are returned.
3. **Given** rows across several days, **When** `?start_date=<ts>&end_date=<ts>` are passed (UNIX timestamps), **Then** only rows whose `tstamp` falls inside the inclusive range are returned.
4. **Given** an invalid filter value (e.g. `loglevel=abc`), **When** the endpoint is called, **Then** the response is `400` per the contract's `BadRequest` response.
5. **Given** no sort parameters, **When** the endpoint is called, **Then** entries are returned newest-first (legacy default `ORDER BY tstamp DESC, syslog_id DESC` — see FR-013).

### Edge Cases

- Missing/invalid `X-API-Key` → `401` on every endpoint (shared `Unauthorized` response).
- `monitor_data.data` blob fails `unserialize()` (corrupt/truncated mediumtext) → the field group derived from that blob is omitted/null; the request must NOT fail with 500 (sibling DataLogController swallows unserialize failures the same way).
- A monitor type exists for a server but only with `state='no_state'` rows (e.g. `mem_usage`, `cpu_info`, `log_*` types never carry a state) → these must not drag the aggregate status to "unknown"; legacy ignores no-state types in messaging but `_setState` still weighs them lowest.
- `monitor_data` retention: collectors keep only ~4 minutes of rows per (type, server) (`monitor_tools::delOldRecords`, 240 s) — the API must always read the NEWEST row per type (`ORDER BY created DESC`), never assume exactly one row.
- Server exists but ISPConfig monitor cron has never run (fresh install) → US1 scenario 3 applies; single-server endpoint still returns `200` with sparse data (the `404` is only for a missing `server` row).
- `system-logs` `sort` parameter naming a non-existent or disallowed column → must be rejected (400/422) or ignored, never interpolated raw into `ORDER BY` (see FR-014).
- `start_date > end_date` → empty result set (no error mandated by contract).
- Large `sys_log` tables: date-range + `server_id` filters must use indexed columns (`tstamp`, `server_id`) — no full-text scans on `message` are required by the contract.

## API Contract *(mandatory)*

- **Spec file(s)**:
  - `api/modules/monitor/server-status.yaml` — existing, implement as-is
  - `api/modules/monitor/system-logs.yaml` — existing, implement as-is
  - Both are registered in `api/modules/monitor/_index.yaml` and in root `api/openapi.yaml` (lines 374–379) — no spec authoring needed.
- **Shared schemas** (existing):
  - `api/components/schemas/ServerStatus.yaml` — aggregate server status (no `x-db-table`; it is a computed projection, not a table row)
  - `api/components/schemas/SystemLog.yaml` — `x-db-table: sys_log`, fields `syslog_id`, `server_id`, `datalog_id`, `loglevel`, `tstamp`, `message`
  - `api/components/schemas/Pagination.yaml` — referenced by system-logs (see NEEDS CLARIFICATION on envelope shape)
  - Shared parameters `api/components/parameters/{limit,offset,sort,order}.yaml`; shared responses `api/components/responses/{Unauthorized,BadRequest,InternalServerError}.yaml`
- **Endpoints** (ALL operations declared in the two YAMLs — all GET, no write operations exist in the contract):

| Method | Path | Purpose | Declared status codes |
|--------|------|---------|----------------------|
| GET | `/api/v1/monitor/servers/status` | List status of ALL servers; response `{ data: ServerStatus[] }`; no pagination, no query parameters | 200, 401, 500 |
| GET | `/api/v1/monitor/servers/{id}/status` | Detailed status of one server (`id` = integer path param); response is a bare `ServerStatus` object | 200, 401, 404 ("Server not found", no body schema declared), 500 |
| GET | `/api/v1/monitor/system-logs` | List `sys_log` entries; query params `offset`, `limit`, `order`, `sort` (shared) + `server_id` (int), `loglevel` (int, "0=info, 1=warning, 2=error"), `start_date` (UNIX ts), `end_date` (UNIX ts); response `{ data: SystemLog[], pagination: Pagination }` | 200, 400, 401, 500 |

Contract observations (verbatim-implementation notes):

- **No write operations**: system-logs declares GET only — no update/acknowledge/delete despite the legacy UI having `log_del.php`/`datalog_del.php` delete actions. Deletion is explicitly OUT of scope (see Assumptions).
- **Security scheme mismatch**: both YAMLs declare `security: [basicAuth: []]`, but the root `api/openapi.yaml` defines only `apiKeyAuth` (header `X-API-Key`) and the runtime uses the `api.auth` middleware. `basicAuth` is a dangling reference. [NEEDS CLARIFICATION: fix the YAMLs to `apiKeyAuth` (recommended, matches every other module and the actual middleware) or add a basicAuth scheme — implementation must use `api.auth`/X-API-Key either way]
- **Envelope mismatch**: the monitor module uses `{ data, pagination }`, not the constitution's Principle V `{ items, total, limit, offset }`. The implemented sibling (`DataLogController`) already follows the module contract and returns `{ data, pagination: { total, limit, offset } }` — but that inner object does not match `Pagination.yaml` either, which declares Laravel page-style fields (`total, per_page, current_page, last_page, from, to, path, *_page_url`) ALL required. [NEEDS CLARIFICATION: three-way disagreement between constitution, Pagination.yaml, and the shipped sibling. Recommendation: keep parity with the shipped DataLogController (`pagination: {total, limit, offset}`) and amend `Pagination.yaml` to match, since offset/limit params make page-URL fields meaningless]
- `loglevel` legend differs: contract says "0=info", legacy UI labels 0 as "Debug" (1=Warning, 2=Error). Cosmetic description-only mismatch; the filter itself is a plain integer equality either way.

## ISPConfig Parity & Datalog Impact *(mandatory)*

**This feature is 100% read-only. No table is ever written, no `sys_datalog` entries are produced, and `BaseModel` write plumbing is not exercised. Datalog impact: none — explicitly Not Applicable.**

- **Legacy reference**: `source_code/interface/web/monitor/` — files consulted:
  - `show_sys_state.php` — the server-status source of truth: `_getSysState()` iterates all `server` rows; `_getServerState()` reads `SELECT DISTINCT type, data FROM monitor_data WHERE server_id = ?`, takes the NEWEST row per type (`ORDER BY created DESC`) for state, aggregates via `_setState()` (severity weights: no_state=0 < ok=1 < unknown=2 < info=3 < warning=4 < critical=5 < error=6, highest wins), unserializes `os_info`/`ispc_info`/`openvz_veinfo` blobs, and `_getMetricsData()` unserializes the `sys_usage` blob for load/mem/net/time series (this vendored ISPConfig carries a custom metrics patch).
  - `log_list.php` + `list/log.list.php` — system-log list: table `sys_log`, idx `syslog_id`, default `ORDER BY sys_log.tstamp DESC, sys_log.syslog_id DESC`, filterable by `tstamp`, `server_id` (SELECT from `server`), `loglevel` (0=Debug/1=Warning/2=Error), `message` LIKE. Module permission check only (`check_module_permissions('monitor')`) — no per-record `sys_perm_*` (neither `sys_log` nor `monitor_data` has sys_* permission columns).
  - `show_data.php` (monitor_data type views), `log_del.php`/`datalog_del.php` (admin-only deletes — NOT exposed by the contract).
- **Server-side collectors** (`source_code/server/lib/classes/cron.d/100-monitor_*.inc.php`, `monitor_tools.inc.php`): every collector does `REPLACE INTO monitor_data (server_id, type, created, data, state)` with `data = serialize($array)` (PHP-serialized, NOT JSON) and then `delOldRecords()` deletes rows older than 240 s per (type, server). Blob shapes verified (see Key Entities).
- **Legacy behaviors to mirror**:
  1. Latest-row-per-type reads with `ORDER BY created DESC` (retention makes "latest" the only meaningful row).
  2. Aggregate state via the `_setState` highest-severity rule across all types for the server; `openvz_beancounter` state is skipped by legacy — mirror that.
  3. `unserialize()` of `monitor_data.data` (implementation MUST pass `['allowed_classes' => false]` — blobs are plain arrays; this is a hardening improvement over legacy, not a behavior change).
  4. System-log default ordering newest-first.
  5. Servers with no monitor data still listed (legacy shows every `server` row).
- **Tables read**: `monitor_data` (composite PK `server_id, type, created`; columns `server_id`, `type` varchar(255), `created` uint UNIX ts, `data` mediumtext PHP-serialized, `state` enum('no_state','unknown','ok','info','warning','critical','error')), `sys_log` (PK `syslog_id`; `server_id`, `datalog_id`, `loglevel` tinyint, `tstamp` uint, `message` text), `server` (`server_id`, `server_name` — for names and 404 checks).
- **Tables written (via datalog only)**: none.
- **System fields handling**: Not applicable — no records are created; neither `monitor_data` nor `sys_log` carries `sys_userid`/`sys_perm_*` columns.
- **Intentional deviations from legacy**: (a) blobs are returned as structured JSON instead of rendered HTML; (b) `unserialize(..., ['allowed_classes' => false])` hardening; (c) no delete operations (contract omits them); (d) API-key auth instead of interface session auth (project-wide pattern).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST expose `GET /monitor/servers/status` returning `200` with `{ "data": [ServerStatus, ...] }` containing one entry per row of the `server` table (ordered by `server_name`, as legacy does), with no pagination or query parameters.
- **FR-002**: System MUST expose `GET /monitor/servers/{id}/status` returning a single bare `ServerStatus` object (`200`), and `404` when `{id}` does not exist in the `server` table. Error body should follow the project's `{message, error}` shape (the contract declares no body schema for this 404). Note: sibling `DataLogController::show()` returns `{"error": "..."}` only — [NEEDS CLARIFICATION: `{message, error}` per constitution vs `{error}` per sibling; recommend `{message, error}` since Principle V governs new code].
- **FR-003**: System MUST deserialize `monitor_data.data` PHP-serialized blobs into JSON structures using `unserialize($blob, ['allowed_classes' => false])`; on failure the affected fields are omitted/null and the request still succeeds. This transformation is mandatory — raw serialized strings MUST never leak into responses.
- **FR-004**: System MUST compute each server's overall `status` by taking, for every monitor type present for that server, the `state` of the NEWEST `monitor_data` row (skipping `openvz_beancounter` as legacy does), reducing with the legacy highest-severity rule, then mapping the 7 legacy states onto the contract's 4-value enum `["ok","warning","error","unknown"]`. Proposed mapping: `ok→ok`, `info→ok`, `warning→warning`, `critical→error`, `error→error`, `unknown→unknown`, `no_state`/no data→`unknown`. [NEEDS CLARIFICATION: contract enum is narrower than the legacy state set; `info→ok` and `critical→error` are inferences, not specced anywhere]
- **FR-005**: System MUST populate `load_average` as `[load_1, load_5, load_15]` from the latest `server_load` blob (keys `load_1`, `load_5`, `load_15`, floats).
- **FR-006**: System MUST populate `uptime` (integer seconds) computed from the `server_load` blob's `up_days`/`up_hours`/`up_minutes` (`days*86400 + hours*3600 + minutes*60`; minute resolution — the blob stores no raw seconds).
- **FR-007**: System MUST populate `memory_usage` (float percent). Sources available: latest `sys_usage` blob `mem[]` series (already percentages, last point = current) or computed from the `mem_usage` blob (`(MemTotal - MemAvailable) / MemTotal * 100`, values in bytes). Proposed: prefer `sys_usage`, fall back to `mem_usage`. [NEEDS CLARIFICATION: contract does not say which source; both exist in this vendored ISPConfig]
- **FR-008**: System MUST populate `cpu_usage` (float percent). Legacy stores NO direct CPU-usage percentage: `cpu_info` is static CPU model data; the only percentage-like figure is the `sys_usage` blob's `load[]` series (`load_1 / cores * 100`), which is load-relative-to-cores, not true CPU utilization. [NEEDS CLARIFICATION: contract field has no faithful legacy source — either serve `sys_usage.load` last point under this name, or return null; decide before implementation]
- **FR-009**: System MUST populate `disk_usage` (single float percent) although the `disk_usage` blob holds one row per filesystem (`fs`, `type`, `size`, `used`, `available`, `percent`, `mounted`). Proposed: the `percent` of the filesystem mounted at `/`. [NEEDS CLARIFICATION: contract collapses many filesystems into one float; root-mount is an inference. Alternative: max percent across non-pseudo filesystems (legacy's state logic evaluates all of them)]
- **FR-010**: System MUST populate `services` as an array of `{name, status, uptime}` from the latest `services` blob whose keys are `webserver`, `ftpserver`, `smtpserver`, `pop3server`, `imapserver`, `bindserver`, `mysqlserver`, `mongodbserver` with values `1` (up), `0` (down), `-1` (not monitored). Mapping: `1→"running"`, `0→"stopped"` (legacy treats a down monitored service as server-state `error`; the per-service enum value should still be `"stopped"`), `-1→` either `"unknown"` or omit the service entirely. Per-service `uptime` has NO legacy source and MUST be omitted/null. [NEEDS CLARIFICATION: (a) include-vs-omit unmonitored services; (b) contract's per-service `uptime` is unpopulatable; (c) exact `name` strings — blob keys vs prettier names like "apache2" in the schema example]
- **FR-011**: System MUST populate `server_name` from `server.server_name` and `last_updated` as an ISO 8601 date-time derived from `MAX(monitor_data.created)` for that server (null when no monitor data exists).
- **FR-012**: System MUST expose `GET /monitor/system-logs` listing `sys_log` rows with optional exact-match filters `server_id` and `loglevel`, and inclusive `tstamp` range filters `start_date`/`end_date` (UNIX timestamps), combined with shared `limit` (default 20, max 100 per the shared parameter), `offset`, `sort`, `order` parameters. Response fields per `SystemLog.yaml`: `syslog_id`, `server_id`, `datalog_id`, `loglevel`, `tstamp`, `message`.
- **FR-013**: When no `sort`/`order` is supplied, system-logs MUST default to newest-first (legacy: `ORDER BY tstamp DESC, syslog_id DESC`). Note the shared `order` parameter declares `default: asc` while the sibling DataLogController defaults to `datalog_id`/`desc`. [NEEDS CLARIFICATION: contract default (asc, unspecified column) vs legacy/sibling behavior (desc, newest first); recommend `sort=tstamp`, `order=desc` defaults for parity]
- **FR-014**: System MUST validate `sort` against a whitelist of `sys_log` columns and `order` against `asc|desc`; invalid filter/paging values (non-integer `server_id`, `loglevel`, `start_date`, `end_date`, out-of-range `limit`) MUST yield `400` per the contract's `BadRequest` response (Lumen `$this->validate()` emits 422 by default — the contract declares 400, so validation failures must be surfaced as 400 here). [NEEDS CLARIFICATION: 400 vs the project-wide 422-for-validation convention; the monitor YAMLs declare only 400]
- **FR-015**: All three endpoints MUST require authentication via the existing `api.auth` middleware (`X-API-Key`) and return the shared `401` response otherwise; unexpected failures return `500` with `{message, error}`.
- **FR-016**: System MUST NOT write to any ISPConfig table, MUST NOT create `sys_datalog` entries, and MUST NOT mutate `monitor_data`/`sys_log` rows under any code path (read-only guarantee).
- **FR-017**: Server-status endpoints MUST tolerate partial data: any missing monitor type (no `server_load` row, no `services` row, etc.) results in the corresponding `ServerStatus` fields being null/omitted, never a 500.

### Key Entities

- **ServerStatus**: computed aggregate, not a table row — joins `server` (identity) with the newest `monitor_data` row per `type`. Schema `api/components/schemas/ServerStatus.yaml`. No Eloquent model planned: `monitor_data` has a composite PK (`server_id`,`type`,`created`) that Eloquent handles poorly, and the entity is read-only — assembled by a future `app/Services/MonitorDataService.php` over the query builder (mirrors sibling DataLogController's builder usage).
- **MonitorData** (source rows): table `monitor_data` — `server_id`, `type` (observed types: `server_load`, `disk_usage`, `mem_usage`, `cpu_info`, `services`, `os_info`, `ispc_info`, `system_update`, `raid_state`, `mailq`, `sys_log`, `sys_usage`, `iptables_rules`, `fail2ban`, `rkhunter`, `log_*` tail blobs, `openvz_*`, …), `created` (UNIX ts), `data` (PHP-serialized array — MUST be deserialized, FR-003), `state` (7-value enum). Verified blob shapes: `server_load` `{up_days, up_hours, up_minutes, uptime(raw string), user_online, load_1, load_5, load_15}`; `mem_usage` = `/proc/meminfo` map in bytes; `disk_usage` = array of `{fs, type, size, used, available, percent, mounted}` (human-readable df strings); `services` = flags per FR-010; `sys_usage` = `{tstamp, load[], mem[], net[{rx,tx}], time[]}` (≤15 points, custom metrics patch); `os_info`/`ispc_info` = `{name, version}`.
- **SystemLog**: one `sys_log` row — table `sys_log`, schema `api/components/schemas/SystemLog.yaml`, future model `app/Models/SystemLog.php` (read-only usage; extends `BaseModel` for consistency but its write paths are never invoked). `datalog_id` links each entry to the `sys_datalog` row it reports on — the bridge to the already-implemented data-logs resource.
- **Server**: `server` table (`server_id`, `server_name`, `updated`, per-service flags `web_server`/`mail_server`/…) — read-only lookup for names, existence (404) and iteration order.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: All three endpoints respond exactly as declared in `api/modules/monitor/server-status.yaml` and `api/modules/monitor/system-logs.yaml` — paths, methods, parameters, and status codes verified against Swagger UI (`/api/documentation`) with zero contract diffs.
- **SC-002**: For a server with populated monitor data, every `ServerStatus` field that has a legacy source (FR-004…FR-011) is populated and matches manual inspection of the deserialized `monitor_data` blobs; no PHP-serialized string appears anywhere in any response.
- **SC-003**: The aggregate `status` returned for each server equals the legacy panel's computed server state (after enum mapping) for the same database snapshot, verified for at least: an all-ok server, a server with a down service (`error`), and a server with no monitor data (`unknown`).
- **SC-004**: `GET /monitor/system-logs` filter matrix (each of `server_id`, `loglevel`, `start_date`, `end_date` alone and combined, plus `limit`/`offset` paging) returns exactly the rows a manual SQL query returns, with newest-first default ordering.
- **SC-005**: A `sys_datalog` row-count taken before and after exercising all three endpoints (including error paths) is identical — proving zero write side effects.
- **SC-006**: Swagger UI "Try it out" succeeds for all three endpoints in the development environment (auto-auth per commit 93e095e).

## Assumptions

- **Scope boundary**: only the two specced-but-unbuilt resources are in scope. The `monitor/data-logs` resource is ALREADY implemented (`app/Http/Controllers/Api/V1/Monitor/DataLogController.php`, routes registered) and is covered by specs/004 — this feature must not touch it beyond mirroring its conventions.
- Only operations present in the YAMLs are built: three GETs. No delete/acknowledge endpoints even though the legacy UI has `log_del.php`/`datalog_del.php` — adding them would require a contract change first (Principle I).
- Auth reuses the existing `X-API-Key` middleware (`api.auth`); the YAMLs' dangling `basicAuth` reference is treated as a spec typo to be corrected toward `apiKeyAuth` (flagged above). No per-record permission filtering: neither `monitor_data` nor `sys_log` has `sys_perm_*` columns, and legacy gates only on module access.
- A populated `dbispconfig` database with running ISPConfig monitor cronjobs is available for realistic verification; without cron data the endpoints still function (FR-017, US1 scenario 3).
- The vendored `source_code/` (which includes the custom `sys_usage` metrics patch) is the parity baseline; stock ISPConfig without that patch would lack the `sys_usage` type, which is why FR-007/FR-008 keep `mem_usage` fallbacks and flag `cpu_usage`.
- The `{data, pagination}` envelope of the monitor module (contract + shipped sibling) intentionally deviates from constitution Principle V's `{items,...}` for this module; the constitution's spec-first Principle I wins, and the sibling's `pagination: {total, limit, offset}` inner shape is assumed the intended one pending the flagged `Pagination.yaml` clarification.
- Blob deserialization uses `unserialize(..., ['allowed_classes' => false])`; all observed collector blobs are plain scalar/array structures, so this is safe.
