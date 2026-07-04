# Tasks: Monitor Module Completion (Server Status & System Logs)

**Input**: Design documents from `/specs/009-monitor-module-completion/`
**Prerequisites**: plan.md (required), spec.md (required for user stories)

**Status**: Draft — feature is NOT yet implemented; every task below is unchecked and describes FUTURE work. All file paths are exact.

**Tests**: Tests are OPTIONAL in this project (see constitution) — the spec does not request them, so no test tasks are included.

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1, US2, US3)

## Path Conventions (this project)

| Artifact | Path |
|----------|------|
| OpenAPI endpoint specs (existing) | `api/modules/monitor/server-status.yaml`, `api/modules/monitor/system-logs.yaml` (registered in `api/modules/monitor/_index.yaml` and root `api/openapi.yaml`) |
| OpenAPI schemas (existing) | `api/components/schemas/ServerStatus.yaml`, `api/components/schemas/SystemLog.yaml`, `api/components/schemas/Pagination.yaml` |
| Model | `app/Models/SystemLog.php` — extends `App\Models\BaseModel` (read-only usage; no model for composite-PK `monitor_data`) |
| Controllers | `app/Http/Controllers/Api/V1/Monitor/ServerStatusController.php`, `app/Http/Controllers/Api/V1/Monitor/SystemLogController.php` |
| Service | `app/Services/MonitorDataService.php` |
| Routes | `routes/web.php` — inside the `api.auth` group, appended to the existing Monitor block (after the `monitor/data-logs/{datalog_id}` line); literal `monitor/servers/status` BEFORE `monitor/servers/{id}/status` |
| Pattern reference (do not modify) | `app/Http/Controllers/Api/V1/Monitor/DataLogController.php` |

**The per-resource implementation flow is always**: spec YAML (already authored) → model → (service if needed) → controller → routes → Swagger verification.

---

## Phase 1: Setup

**Purpose**: Contract verified and open contract/legacy disagreements resolved before any PHP is written

- [ ] T001 Verify the existing contract renders: `api/modules/monitor/server-status.yaml` and `api/modules/monitor/system-logs.yaml` resolve through `api/modules/monitor/_index.yaml` and root `api/openapi.yaml` in Swagger UI (`/api/documentation`) with `ServerStatus`/`SystemLog`/`Pagination` schema refs intact — no YAML authoring expected; fix nothing silently, log any parse issue
- [ ] T002 Resolve the NEEDS CLARIFICATION items in `specs/009-monitor-module-completion/spec.md` that gate mapping code and record decisions in the spec: (a) FR-004 7→4 status enum mapping; (b) FR-007 memory source (`sys_usage` vs `mem_usage`); (c) FR-008 `cpu_usage` source-or-null; (d) FR-009 `disk_usage` single-float rule (root mount vs max); (e) FR-010 service naming, unmonitored-service handling, per-service `uptime`; (f) pagination inner shape (`{total,limit,offset}` sibling style vs `Pagination.yaml`); (g) FR-013 system-logs default sort/order; (h) FR-014 400-vs-422 for invalid query values; (i) FR-002 404 body `{message,error}` vs sibling `{error}`
- [ ] T003 [P] Apply the agreed contract corrections (ONLY if T002 sign-off says so — these change spec files, which is a Principle I ownership decision): replace dangling `basicAuth` with `apiKeyAuth` in `api/modules/monitor/server-status.yaml` and `api/modules/monitor/system-logs.yaml`; align `api/components/schemas/Pagination.yaml` with the agreed pagination shape (check impact on `api/modules/monitor/data-logs.yaml`, which also references it)
- [ ] T004 [P] Re-verify legacy parity notes in the spec against `source_code/interface/web/monitor/show_sys_state.php` (state ladder, `openvz_beancounter` skip), `source_code/interface/web/monitor/list/log.list.php` (default order `tstamp DESC, syslog_id DESC`; filters), and blob shapes in `source_code/server/lib/classes/cron.d/100-monitor_server.inc.php`, `100-monitor_services.inc.php`, `100-monitor_disk_usage.inc.php`, `100-monitor_mem_usage.inc.php`, `100-monitor_sys_usage.inc.php` (read-only reference — never modify `source_code/`)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Shared pieces that MUST exist before ANY user story can be implemented

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [ ] T005 Create `app/Services/MonitorDataService.php` with: (a) `latestPerType(int $serverId): array` — newest `monitor_data` row per `type` for a server via query builder (`ORDER BY created DESC`, group per type; retention is ~240 s so newest-row logic is mandatory); (b) safe blob decoding `unserialize($data, ['allowed_classes' => false])` with try/fallback-to-null (FR-003, never 500 on corrupt blobs); (c) legacy state aggregation — `_setState` severity ladder `no_state<ok<unknown<info<warning<critical<error`, skipping `openvz_beancounter`, then the T002-agreed mapping onto `["ok","warning","error","unknown"]` (FR-004); (d) `buildServerStatus(object $server): array` assembling the `ServerStatus` projection per FR-005…FR-011 (load_average from `server_load.load_{1,5,15}`; uptime seconds from `up_days/up_hours/up_minutes`; memory/cpu/disk per T002 decisions; services flag mapping 1/0/-1; `server_name` from `server` row; `last_updated` ISO 8601 from max `created`, null when no data); all fields null/omitted when their source type is absent (FR-017). No HTTP response construction in the service (constitution, Code Boundaries)
- [ ] T006 [P] Create `app/Models/SystemLog.php` extending `App\Models\BaseModel`: `protected $table = 'sys_log'; protected $primaryKey = 'syslog_id';` no timestamps (inherited), no `$fillable` needed (read-only usage), casts for integer fields if BaseModel conventions require them — its `save()`/`delete()` are never called by this feature (FR-016)
- [ ] T007 Confirm read-only guarantee: grep the new code paths for any `save|delete|insert|update|DB::table(...)->(insert|update|delete)` against ISPConfig tables — none may exist; note in the PR that `sys_datalog` count is unchanged after exercising endpoints (SC-005)

**Checkpoint**: Foundation ready - user story implementation can now begin

---

## Phase 3: User Story 1 - Fleet-wide server health poll (Priority: P1) 🎯 MVP

**Goal**: `GET /api/v1/monitor/servers/status` returns `{ data: ServerStatus[] }` for every row of the `server` table

**Independent Test**: With monitor cron data present, `curl -H "X-API-Key: ..." /api/v1/monitor/servers/status` returns 200 and one fully-mapped `ServerStatus` per server; a server without `monitor_data` rows appears with `status: "unknown"`; no serialized-PHP strings anywhere in the payload

### Implementation for User Story 1

- [ ] T008 [US1] Create `app/Http/Controllers/Api/V1/Monitor/ServerStatusController.php` with `__construct(MonitorDataService $service)` and `index()`: iterate `server` rows ordered by `server_name` (legacy order), map each through `MonitorDataService::buildServerStatus()`, return `response()->json(['data' => $items])` — NO pagination object and NO query parameters (the contract declares none); 500s surface as `{message, error}` (mirror sibling `DataLogController` conventions: query builder reads, thin controller)
- [ ] T009 [US1] Register the route in `routes/web.php` inside the `api.auth` group, appended to the Monitor block after `monitor/data-logs/{datalog_id}`: `$router->get('monitor/servers/status', 'Api\V1\Monitor\ServerStatusController@index');` — verify no shadowing against existing routes (none expected; path is fully literal)
- [ ] T010 [US1] Verify against Swagger UI (`/api/documentation`): `GET /monitor/servers/status` renders from `api/modules/monitor/server-status.yaml`, "Try it out" returns 200 with the `data`-array envelope and 401 without a key — response fields match `api/components/schemas/ServerStatus.yaml` exactly

**Checkpoint**: At this point, User Story 1 should be fully functional and testable independently

---

## Phase 4: User Story 2 - Single-server status drill-down (Priority: P2)

**Goal**: `GET /api/v1/monitor/servers/{id}/status` returns one bare `ServerStatus` object, 404 for unknown servers

**Independent Test**: `curl -H "X-API-Key: ..." /api/v1/monitor/servers/1/status` returns 200 with a single object (not wrapped in `data`); `/monitor/servers/999999/status` returns 404 "Server not found"

### Implementation for User Story 2

- [ ] T011 [US2] Add `show($id)` to `app/Http/Controllers/Api/V1/Monitor/ServerStatusController.php`: look up the `server` row by `server_id`; return 404 with the T002-agreed error body ("Server not found") when absent; otherwise return `MonitorDataService::buildServerStatus()` output as a bare JSON object (contract wraps nothing on the single resource)
- [ ] T012 [US2] Register the route in `routes/web.php` immediately AFTER the `monitor/servers/status` line: `$router->get('monitor/servers/{id}/status', 'Api\V1\Monitor\ServerStatusController@show');` — ordering matters: the literal route from T009 must stay first so `{id}` can never capture the string `status`
- [ ] T013 [US2] Verify against Swagger UI: `GET /monitor/servers/{id}/status` renders, 200/401/404 behave as declared in `api/modules/monitor/server-status.yaml`; confirm the US1 endpoint still resolves (no route shadowing regression)

**Checkpoint**: At this point, User Stories 1 AND 2 should both work independently

---

## Phase 5: User Story 3 - Browse and filter system logs (Priority: P3)

**Goal**: `GET /api/v1/monitor/system-logs` lists `sys_log` rows with `server_id`/`loglevel`/`start_date`/`end_date` filters, `limit`/`offset`/`sort`/`order` paging, `{ data, pagination }` envelope

**Independent Test**: With seeded `sys_log` rows, exercise each filter alone and combined plus paging; results must equal manual SQL, default order newest-first; invalid values (e.g. `loglevel=abc`) return 400

### Implementation for User Story 3

- [ ] T014 [US3] Create `app/Http/Controllers/Api/V1/Monitor/SystemLogController.php` with `index(Request $request)`: query `SystemLog` (or builder on `sys_log`); exact-match filters `server_id`, `loglevel`; inclusive `tstamp >= start_date` / `tstamp <= end_date` range; `sort` validated against a whitelist of `sys_log` columns (`syslog_id`, `server_id`, `datalog_id`, `loglevel`, `tstamp`) and `order` against `asc|desc` (FR-014 — never interpolate raw input into `ORDER BY`); defaults per T002 decision (legacy parity: newest-first `tstamp DESC, syslog_id DESC`); paging via `limit` (default 20, max 100 per shared parameter) and `offset`; invalid query values → 400 `BadRequest` per the contract; respond `{ data: [...], pagination: {...} }` with the T002-agreed pagination shape; fields per `api/components/schemas/SystemLog.yaml` (`syslog_id`, `server_id`, `datalog_id`, `loglevel`, `tstamp`, `message`)
- [ ] T015 [US3] Register the route in `routes/web.php` in the Monitor block: `$router->get('monitor/system-logs', 'Api\V1\Monitor\SystemLogController@index');` — literal path, no `{id}` route exists for this resource (contract declares list only); re-check block ordering
- [ ] T016 [US3] Verify against Swagger UI: `GET /monitor/system-logs` renders from `api/modules/monitor/system-logs.yaml`; exercise every declared parameter via "Try it out"; confirm 200/400/401 statuses and the envelope match the YAML exactly

**Checkpoint**: All user stories should now be independently functional

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Improvements that affect multiple user stories

- [ ] T017 [P] Update `README.md` endpoint list with the three new monitor endpoints (public surface changed)
- [ ] T018 [P] Code cleanup pass: controllers thin (HTTP + validation only), all blob/state logic in `app/Services/MonitorDataService.php`; no ad-hoc duplication of the unserialize fallback between controllers
- [ ] T019 Re-verify legacy parity for the documented cases (SC-003, SC-004): aggregate status vs the legacy panel for an all-ok server, a down-service server, and a data-less server; system-logs filter matrix vs manual SQL; confirm `sys_datalog` row count unchanged after exercising all endpoints incl. error paths (SC-005)
- [ ] T020 Run `vendor/bin/phpunit` (full existing suite must pass — no new tests required by this spec)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — contract verification and clarification sign-off first, always. T002 is the hard gate: T005's mapping code cannot be written correctly before its decisions land
- **Foundational (Phase 2)**: Depends on Setup — BLOCKS all user stories (`MonitorDataService` feeds US1+US2; `SystemLog` model feeds US3)
- **User Stories (Phase 3–5)**: All depend on Phase 2. Proceed in priority order P1 → P2 → P3; US2 additionally depends on US1's T009 route line existing first (ordering: literal before `{id}`)
- **Polish (Phase 6)**: Depends on all desired user stories being complete

### Within Each User Story

- Spec YAML precedes controller work (Principle I — already satisfied; YAMLs exist)
- Service/model before controller; controller before routes
- Swagger verification is the story's last task

### Parallel Opportunities

- T003 and T004 can run in parallel with each other (different files) once T002 decisions exist
- T005 and T006 are parallel [P] (different files)
- T008 (US1 controller) and T014 (US3 controller) touch different files and could proceed in parallel after Phase 2 — but T009/T012/T015 all edit `routes/web.php` and MUST be sequential (single shared file, ordering matters; never mark routes edits [P])
- T017 and T018 are parallel [P]

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup (contract verification + clarification sign-off — T002 is non-skippable)
2. Complete Phase 2: Foundational (`MonitorDataService` correct on real cron data)
3. Complete Phase 3: User Story 1
4. **STOP and VALIDATE**: Swagger UI "Try it out" against a populated `dbispconfig` (dev key auto-auth in local env); confirm no serialized strings in output and `sys_datalog` untouched
5. Deploy/demo if ready

### Incremental Delivery

1. Each story adds endpoints without breaking previously delivered ones
2. `routes/web.php` ordering re-checked at every story boundary (T009 → T012 → T015)

---

## Notes

- READ-ONLY feature: no `sys_datalog` writes anywhere; T007 enforces this. A task that writes to the DB in any way violates the constitution AND this contract
- `monitor_data.data` and the sibling's `sys_datalog.data` are PHP-serialized — always `unserialize(..., ['allowed_classes' => false])` with graceful fallback
- Never modify `source_code/` (read-only legacy reference) or the already-shipped `app/Http/Controllers/Api/V1/Monitor/DataLogController.php` (owned by specs/004)
- [P] tasks = different files, no dependencies — never mark two edits to `routes/web.php` as [P]
- Commit after each task or logical group
- Avoid: endpoints or parameters not present in the YAMLs (e.g., no message-search filter, no delete operations, no `{id}` show for system-logs)
