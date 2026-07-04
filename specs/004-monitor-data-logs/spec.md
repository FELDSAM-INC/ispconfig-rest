# Feature Specification: Monitor Data-Logs (read-only sys_datalog journal)

**Feature Branch**: `004-monitor-data-logs` — no such branch exists; the feature was built directly on `main` before spec-kit adoption. This spec is a brownfield migration (reverse-engineered from the shipped code).
**Created**: 2026-07-04
**Status**: Migrated
**Module**: monitor
**Input**: Reverse-engineered from `app/Http/Controllers/Api/V1/Monitor/DataLogController.php`, `api/modules/monitor/data-logs.yaml`, `routes/web.php` (lines 110–112), and legacy `source_code/interface/web/monitor/`.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Verify that an asynchronous write was processed (Priority: P1)

Every write in this API (POST/PUT/DELETE on any resource) does not touch the target ISPConfig table state directly from the consumer's perspective: `BaseModel::save()`/`delete()` journals the change into `sys_datalog` via `DatalogService`, and the success response (201/200/204) only confirms the datalog entry — ISPConfig's server daemons apply it later, asynchronously (Constitution Principle II). An API consumer (provisioning script, control panel) that just created e.g. a DNS zone needs to find out whether ISPConfig has actually picked up and applied that change before proceeding (e.g., before pointing traffic at it). The consumer lists `GET /api/v1/monitor/data-logs` filtered by `dbtable` (and optionally `server_id`, `unprocessed_only=true`) and inspects the entries for its record (`dbidx` is `"<primary_key>:<value>"`, e.g. `"id:42"`, as written by `DatalogService::log()`).

**Why this priority**: This is the read-side companion of the datalog write pattern — without it, consumers have no API-visible way to reason about the async gap between "accepted" and "applied". It is the reason this endpoint exists.

**Independent Test**: Create any entity via the API (e.g., `POST /api/v1/mail/domains`), then `GET /api/v1/monitor/data-logs?dbtable=mail_domain&sort=datalog_id&order=desc` and verify the newest entry's `dbidx` matches the created record and `action` is `i`.

**Acceptance Scenarios**:

1. **Given** a write was accepted by any API endpoint, **When** the consumer calls `GET /monitor/data-logs?dbtable=<table>` with a valid `X-API-Key`, **Then** the response is 200 with the journal entry for that write in `data[]`, newest first (default `sort=datalog_id`, `order=desc`).
2. **Given** a populated journal, **When** the consumer passes `server_id`, `dbtable`, `action`, `status`, `start_date`, `end_date` filters, **Then** only matching rows are returned and `pagination.total` reflects the filtered count.
3. **Given** `unprocessed_only=true` and a `server_id`, **When** the consumer lists data-logs, **Then** only entries not yet processed by that server are returned (see Edge Cases — the shipped comparison deviates from legacy).

---

### User Story 2 - Inspect one change in detail (Priority: P2)

A consumer or an operator debugging a provisioning problem fetches a single journal entry by ID (`GET /api/v1/monitor/data-logs/{datalog_id}`) to see exactly what was recorded: which table and record (`dbtable`, `dbidx`), what action (`i`/`u`/`d`), when (`tstamp`), by whom (`user`, `session_id`), the entry `status`, and the full change payload — the controller `unserialize()`s the stored `data` blob so the response exposes the structured `{"new": {...}, "old": {...}}` diff that `BaseModel` recorded.

**Why this priority**: Detail lookup depends on already having a `datalog_id` (usually from the P1 list); it adds diagnostic depth but is not the entry point.

**Independent Test**: Pick any `datalog_id` from the list response, `GET /api/v1/monitor/data-logs/{datalog_id}`, verify 200 with the same row and a deserialized `data` object; request a nonexistent ID and verify 404.

**Acceptance Scenarios**:

1. **Given** an existing entry, **When** `GET /monitor/data-logs/{datalog_id}`, **Then** 200 with the full row; `data` is the unserialized change payload (or the raw string if unserialization fails).
2. **Given** a nonexistent `datalog_id`, **When** the consumer requests it, **Then** 404 with body `{"error": "Data log not found"}` (note: deviates from the constitution's `{message, error}` shape — see Assumptions/Gaps).

---

### User Story 3 - Audit the change history (Priority: P3)

An auditor pages through the full change journal (the REST equivalent of legacy ISPConfig's Monitor → "Data Log History" list) using `limit`/`offset` with date-range (`start_date`/`end_date`, UNIX timestamps against `tstamp`) and `action`/`status` filters to review who changed what and when.

**Why this priority**: Valuable for compliance/forensics but not required for the core async-write workflow.

**Independent Test**: `GET /monitor/data-logs?start_date=<t1>&end_date=<t2>&limit=10&offset=10` returns page 2 of the window, `pagination.total` constant across pages.

**Acceptance Scenarios**:

1. **Given** entries across a time span, **When** filtering by `start_date`/`end_date`, **Then** only rows with `tstamp` inside the inclusive window are returned.
2. **Given** `total` > `limit`, **When** the consumer increments `offset`, **Then** successive non-overlapping pages are returned.

---

### Edge Cases

- **Missing/invalid `X-API-Key`**: routes sit inside the `api.auth` group → 401 (matches the YAML's 401 responses).
- **Nonexistent `datalog_id`**: 404 `{"error": "Data log not found"}`.
- **`unprocessed_only=true` without `server_id`**: the controller looks up `server.updated` for `server_id = 0`, finds no row, and **silently skips the filter** — the consumer gets all rows as if the flag were absent.
- **`unprocessed_only` comparison basis**: the controller filters `tstamp > server.updated`, but in ISPConfig `server.updated` stores the last **processed datalog_id**, not a timestamp (legacy: `sys_datalog.datalog_id > server.updated` in `datalog_list.php` and `db_mysql.inc.php::datalogStatus()`). Since UNIX timestamps (~1.7×10⁹) virtually always exceed a datalog counter, the shipped filter is effectively a no-op — a known defect, documented in tasks.md Gaps.
- **`action` case**: the DB stores lowercase `i`/`u`/`d`; the controller lowercases the query value, so `action=I` and `action=i` both work — but the YAML enum only admits uppercase `I`/`U`/`D`, and the `DataLog` schema declares uppercase values that the data never has.
- **Invalid `sort` column or `order` value**: no validation — an unknown column produces a SQL error and an invalid direction throws `InvalidArgumentException`; both surface as 500, never the 400 the YAML declares.
- **Corrupt/legacy `data` blob**: `unserialize()` failures are caught and the raw string is returned unchanged.
- **`limit` bounds**: shared parameter declares default 20 / max 100; the controller defaults to 25 and enforces no maximum.
- **Permissions**: no `sys_perm_*` / group scoping — any valid API key sees the entire journal (legacy scopes the UI to the `monitor` module permission and, for the status poll, to the logged-in user's own entries).

## API Contract *(mandatory)*

- **Spec file(s)**: `api/modules/monitor/data-logs.yaml` — existing; implemented (registered in `api/modules/monitor/_index.yaml`; root `api/openapi.yaml` registers only `/monitor/data-logs` — the `{datalog_id}` path ref is missing there, see Gaps)
- **Shared schemas**: `api/components/schemas/DataLog.yaml` (existing), `api/components/schemas/Pagination.yaml` (existing, referenced — but the implementation returns `{total, limit, offset}`, not the Laravel-paginator fields that schema requires)
- **Endpoints** (exactly as the YAML declares — this resource is deliberately read-only, no POST/PUT/DELETE):

| Method | Path | Purpose | Success code | Error codes (per YAML) |
|--------|------|---------|--------------|------------------------|
| GET | `/api/v1/monitor/data-logs` | List/filter journal entries; response `{data: DataLog[], pagination}` | 200 | 400, 401, 500 |
| GET | `/api/v1/monitor/data-logs/{datalog_id}` | Show one journal entry (deserialized `data`) | 200 | 401, 404, 500 |

- **Query parameters (index)**: shared `offset`, `limit`, `order`, `sort` plus feature-specific `server_id` (int), `dbtable` (string), `action` (enum I/U/D), `status` (enum pending/ok/warning/error), `start_date`/`end_date` (UNIX timestamps), `unprocessed_only` (boolean, default false).
- **Contract deviations found** (implementation ↔ YAML ↔ constitution): list envelope is `{data, pagination:{total,limit,offset}}` — the YAML says `data` + `Pagination.yaml`, the constitution says `{items,total,limit,offset}`, and the actual `pagination` object matches *neither* the referenced `Pagination.yaml` (which requires `per_page`, `current_page`, page URLs, …) nor the constitution shape. The YAML operations also declare `security: basicAuth`, a scheme not registered in `api/openapi.yaml` (only `apiKeyAuth`/X-API-Key exists; `api/components/securitySchemes.yaml` defines `basicAuth` but is wired nowhere). Recorded honestly in plan.md and tasks.md.

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference**: `source_code/interface/web/monitor/datalog_list.php` + `list/datalog.list.php` (pending "Jobqueue" list: `datalog_id > server.updated` per server, order `tstamp DESC, datalog_id DESC`, filters tstamp/server_id/action/dbtable), `dataloghistory_list.php` (full history list), `datalog_del.php` / `dataloghistory_undo.php` (legacy delete/undo — **not** exposed by this API), and `source_code/interface/web/datalogstatus.php` → `db_mysql.inc.php::datalogStatus()` (AJAX JSON poll: per-user pending counts grouped by table+action, active servers only, ignore-list `aps_instances`, `aps_instances_settings`, `mail_access`, `mail_content_filter`).
- **Legacy behaviors to mirror**: read/list semantics only. Mirrored: filterable columns (server_id, action, dbtable, date), newest-first ordering, per-server "unprocessed" concept. Not mirrored: the `datalog_id > server.updated` unprocessed comparison (shipped code compares `tstamp` — defect), the per-user scoping and table ignore-list of `datalogStatus()`, and the aggregated pending-count summary (no summary endpoint exists).
- **Tables written (via datalog only)**: **None — this feature is strictly read-only.** It reads `sys_datalog` (and `server.updated` for the unprocessed filter). No datalog entries are created; no ISPConfig table is modified. Not applicable per template guidance.
- **System fields handling**: not applicable — no records are created. (`sys_datalog` rows themselves carry no `sys_userid`/`sys_perm_*` fields.)
- **Intentional deviations from legacy**: (1) no delete/undo of journal entries (legacy `datalog_del.php`, `dataloghistory_undo.php`) — the API treats the journal as immutable; (2) `status` and `start_date`/`end_date`/`dbtable` filters and a single-entry detail view are additions beyond the legacy lists; (3) no per-user visibility scoping (API keys are admin-level in this project).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST list `sys_datalog` entries at `GET /monitor/data-logs` with `limit`/`offset` pagination and `sort`/`order` parameters (implementation defaults: `sort=datalog_id`, `order=desc`, `limit=25`, `offset=0`).
- **FR-002**: System MUST support exact-match filters `server_id`, `dbtable`, `action` (case-insensitive on input, stored lowercase `i`/`u`/`d`), and `status` (`pending`/`ok`/`warning`/`error`).
- **FR-003**: System MUST support an inclusive `tstamp` window via `start_date`/`end_date` (UNIX timestamps).
- **FR-004**: System MUST support `unprocessed_only=true` limiting results to entries not yet processed by the given `server_id`'s ISPConfig daemon (legacy semantics: `datalog_id > server.updated`; shipped code compares `tstamp` — see Gaps).
- **FR-005**: System MUST return a single entry at `GET /monitor/data-logs/{datalog_id}` and 404 when it does not exist.
- **FR-006**: System MUST expose the stored `data` payload in structured form (unserialized `{"new":…, "old":…}`), falling back to the raw string when unserialization fails.
- **FR-007**: API consumers MUST authenticate with `X-API-Key` (`api.auth` middleware); unauthenticated requests receive 401.
- **FR-008**: System MUST NOT expose any write operation on the journal — no POST/PUT/DELETE routes exist for this resource.
- **FR-009**: List responses MUST include the filtered total so consumers can page deterministically (`pagination.total`).

### Key Entities

- **DataLog**: one journaled change awaiting/after processing by ISPConfig's server daemons — table `sys_datalog` (pk `datalog_id`; `server_id`, `dbtable`, `dbidx` = `"<pk>:<value>"`, `action` char `i`/`u`/`d`, `tstamp`, `user`, `data` serialized text, `status` set default `ok`, `error`, `session_id`), schema `api/components/schemas/DataLog.yaml`, model: **none** — `app/Http/Controllers/Api/V1/Monitor/DataLogController.php` queries `DB::table('sys_datalog')` directly (no Eloquent model exists; acceptable for reads, flagged in Gaps).
- **Server** (read-only lookup): `server.updated` marks the last datalog entry the server processed — used only by the `unprocessed_only` filter.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Both endpoints declared in `api/modules/monitor/data-logs.yaml` respond over HTTP with the declared success codes (200) and auth behavior (401 without a key).
- **SC-002**: For any write performed through this API, the resulting journal entry is retrievable via the list endpoint filtered by its `dbtable` within one request — closing the async-write feedback loop.
- **SC-003**: Every documented filter (`server_id`, `dbtable`, `action`, `status`, `start_date`, `end_date`) demonstrably narrows results and `pagination.total` matches the filtered row count.
- **SC-004**: `GET /monitor/data-logs/{datalog_id}` returns 404 for unknown IDs and a deserialized `data` object for entries written by `BaseModel`/`DatalogService`.
- **SC-005**: Zero rows are inserted/updated/deleted in any ISPConfig table by exercising these endpoints (read-only guarantee).

## Assumptions

- **Scope boundary**: only the two data-logs endpoints are in scope. The monitor module's other specced resources — `api/modules/monitor/server-status.yaml` (`/monitor/servers/status`, `/monitor/servers/{id}/status`) and `api/modules/monitor/system-logs.yaml` (`/monitor/system-logs`) — are **specced but unbuilt** (no controllers, no routes) and are covered by a separate draft spec, not this one.
- **Status field semantics**: `sys_datalog.status` defaults to `ok` on insert (both legacy and `DatalogService::log()` write `ok`); "processed vs pending" is tracked by the `server.updated` pointer, not by `status`. Consumers should rely on `unprocessed_only`/`server.updated` rather than `status=pending` for processing checks.
- **Auth**: the YAML's `basicAuth` annotation is treated as a spec error; actual auth is the project-wide `X-API-Key` middleware, and no per-endpoint permission model exists beyond it.
- **Environment**: a populated `dbispconfig` database with at least one `server` row is available; legacy behavior verified against the `source_code/` ISPConfig version currently vendored.
- **Response envelope**: the `{data, pagination}` list shape predates the constitution's `{items,total,limit,offset}` rule (like the five client-era 202 controllers, this is a pre-spec-first artifact); aligning it is future work, tracked in tasks.md Gaps, not silently rewritten here.
