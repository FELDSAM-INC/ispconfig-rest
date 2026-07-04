---

description: "As-built task record for the Monitor Data-Logs feature (brownfield migration)"
---

# Tasks: Monitor Data-Logs (read-only sys_datalog journal)

**Input**: Design documents from `/specs/004-monitor-data-logs/`
**Prerequisites**: plan.md, spec.md (both reverse-engineered — this file records what was ALREADY built on `main`, checked `[x]`, followed by a Gaps backlog of unchecked items)

**Tests**: None were written (optional per constitution; the original — undocumented — feature work did not request them). See Gaps.

**Organization**: Phases follow the project's per-resource flow (spec YAML → model → service → controller → routes → Swagger). Tasks map to the spec's user stories: US1 = verify async write processing (list), US2 = inspect single entry (show), US3 = audit history (filters/pagination — same list endpoint, so implemented together with US1).

## Format: `[ID] [P?] [Story] Description`

## Path Conventions (this project)

See `.specify/templates/tasks-template.md`; only rows this feature touches are used below. Notably there is **no model row** — the controller reads `sys_datalog` via the query builder directly (see Gaps).

---

## Phase 1: Setup (contract + legacy research) — DONE

- [x] T001 Endpoint spec authored in `api/modules/monitor/data-logs.yaml` (GET `/monitor/data-logs` with offset/limit/order/sort + server_id/dbtable/action/status/start_date/end_date/unprocessed_only params; GET `/monitor/data-logs/{datalog_id}`) and registered in `api/modules/monitor/_index.yaml`
- [x] T002 [P] Shared schema authored in `api/components/schemas/DataLog.yaml` (`x-db-table: sys_datalog`, per-field `x-db-field` mapping, action/status enums); list response reuses `api/components/schemas/Pagination.yaml`, shared parameters `api/components/parameters/{limit,offset,sort,order}.yaml`, and shared responses `api/components/responses/{BadRequest,Unauthorized,InternalServerError}.yaml`
- [x] T003 [P] Legacy behavior extracted from `source_code/interface/web/monitor/` (`datalog_list.php`, `list/datalog.list.php`, `dataloghistory_list.php`) and `source_code/interface/web/datalogstatus.php` → `db_mysql.inc.php::datalogStatus()` — filter set, `tstamp DESC` ordering, and the `datalog_id > server.updated` unprocessed watermark; captured in spec.md "ISPConfig Parity & Datalog Impact" (done retroactively during this migration; the watermark detail was evidently NOT applied by the original implementation — see Gaps)

---

## Phase 2: Foundational — DONE (reduced scope)

- [x] T004 Decision: no Eloquent model — read-only journal accessed via `DB::table('sys_datalog')`; `App\Services\DatalogService` (`app/Services/DatalogService.php`) already provides the write side (`log()`) used by `app/Models/BaseModel.php`, plus read helpers `getStatus()`/`getPendingEntries()`
- [x] T005 Datalog write/read round-trip confirmed: `DatalogService::log()` writes `dbidx = "<pk>:<value>"` and serialized `{"new","old"}` payloads that the controller's `unserialize()` step surfaces as structured JSON

---

## Phase 3: User Story 1 + 3 — List & filter journal entries (P1 list = P3 audit surface) — DONE

**Goal**: consumers confirm their async writes reached `sys_datalog` and page/filter the full journal

**Independent Test**: perform any API write, then `curl -H "X-API-Key: …" '/api/v1/monitor/data-logs?dbtable=<table>'` — newest entry matches; add filters and check `pagination.total`

- [x] T006 [US1] Implement `index` in `app/Http/Controllers/Api/V1/Monitor/DataLogController.php` — filters `server_id`/`dbtable`/`action` (lowercased)/`status`/`start_date`/`end_date`, `unprocessed_only` (via `server.updated` lookup), `sort` (default `datalog_id`)/`order` (default `desc`), `limit` (default 25)/`offset` (default 0), `count()` for total, `unserialize()` of `data` with fallback; response `{data, pagination:{total,limit,offset}}`
- [x] T007 [US1] Register `GET monitor/data-logs` in `routes/web.php` (line 111) inside the `api.auth` group — ordering verified: literal route precedes the parameterized one, `monitor/` prefix collides with nothing

## Phase 4: User Story 2 — Show single journal entry — DONE

**Goal**: fetch one entry by ID with the deserialized change payload for debugging/audit

**Independent Test**: `curl -H "X-API-Key: …" /api/v1/monitor/data-logs/<id>` → 200 with `data.new`/`data.old`; unknown id → 404

- [x] T008 [US2] Implement `show($datalog_id)` in `app/Http/Controllers/Api/V1/Monitor/DataLogController.php` — 404 `{"error": "Data log not found"}` when missing, `unserialize()` of `data` with fallback
- [x] T009 [US2] Register `GET monitor/data-logs/{datalog_id}` in `routes/web.php` (line 112)

## Phase 5: Polish — PARTIALLY DONE

- [x] T010 Swagger verification (list endpoint): `/monitor/data-logs` referenced from root `api/openapi.yaml` (line 380) and renders under the Monitor tag
- [ ] T011 Swagger verification (show endpoint): **NOT done** — see Gaps G1

---

## Gaps

Unchecked items = discrepancies found during this brownfield migration. None were fixed as part of the migration (rule: no source changes); each needs a deliberate, spec-first follow-up.

- [ ] **G1 — Show endpoint missing from root spec**: `api/openapi.yaml` registers only `/monitor/data-logs`; add the `/monitor/data-logs/{datalog_id}` ref (`./modules/monitor/data-logs.yaml#/~1monitor~1data-logs~1{datalog_id}`) so Swagger UI renders the show operation (Quality Gate 1 currently unmet for it)
- [ ] **G2 — `unprocessed_only` defect (legacy parity)**: `DataLogController::index()` filters `tstamp > server.updated`, but `server.updated` is ISPConfig's last-processed **datalog_id** watermark (legacy: `sys_datalog.datalog_id > server.updated` in `datalog_list.php` and `datalogStatus()`); since timestamps dwarf the counter the filter is effectively always-true. Fix to compare `datalog_id`, and decide behavior when `server_id` is omitted (currently the filter is silently skipped)
- [ ] **G3 — No Eloquent model / query logic in controller**: direct `DB::table('sys_datalog')` reads are constitution-tolerable (reads only; Principle II bars unlogged *writes*), but this is the only resource with no model and with query building in the controller; the injected `DatalogService` is never used (dead constructor dependency). Either add a read-only `app/Models/DataLog.php` (must NOT extend the write-through `BaseModel` semantics — needs a documented exception) or move the queries into `DatalogService` and drop the direct facade calls
- [ ] **G4 — List envelope violates Principle V and its own schema ref**: response is `{data, pagination:{total,limit,offset}}`; constitution requires `{items,total,limit,offset}` and the YAML's `Pagination.yaml` ref describes a Laravel paginator (`per_page`, `current_page`, page URLs — all required). Response currently matches neither. Align spec + implementation together (breaking change for consumers — version or coordinate)
- [ ] **G5 — Security scheme mismatch**: `data-logs.yaml` declares `security: basicAuth`, unregistered in `api/openapi.yaml` (only `apiKeyAuth`/X-API-Key exists; `api/components/securitySchemes.yaml` defines `basicAuth` but is referenced nowhere). Change to `apiKeyAuth` to match the actual `api.auth` middleware
- [ ] **G6 — No request validation; declared 400 unreachable**: no `$this->validate()` in the controller — invalid `sort` column → SQL error 500; invalid `order` → `InvalidArgumentException` 500; `limit` unclamped (shared param declares default 20/max 100, controller uses default 25/no max); `order` default `desc` vs declared `asc`. Add validation (whitelist sort columns, enum order, bound limit) returning 422/400 per constitution, and reconcile defaults with the shared parameter files
- [ ] **G7 — Action/data typing mismatches in `DataLog.yaml`**: `action` enum is uppercase `I/U/D` but stored values are lowercase `i/u/d` (controller lowercases input, so `action=i` works despite the spec); `data` is declared `type: string` but responses return the unserialized object. Fix the schema (lowercase enum or documented case-insensitivity; `data` as object/oneOf)
- [ ] **G8 — 404 body shape**: `{"error": "Data log not found"}` lacks the constitution's `message` field (`{message, error}`); also the YAML's 404 declares no content schema — align both
- [ ] **G9 — No tests**: no `tests/DataLogApiTest.php`; if desired, follow `tests/ClientApiTest.php` pattern covering filters, pagination totals, 404, and 401
- [ ] **G10 — `unserialize()` hardening**: calls in `index`/`show` lack `['allowed_classes' => false]`; journal payloads are plain arrays, so restricting classes closes a PHP object-injection vector at zero cost
- [ ] **G11 — Minor spec registry**: `DataLog` is not listed under `components.schemas` in `api/openapi.yaml` (relative `$ref`s still resolve; add for consistency with other schemas)

---

## Dependencies & Execution Order (as built)

- Phase 1 (contract) preceded implementation — Principle I was followed for paths/methods/params
- No Phase-2 model — T004 was a scope decision, not an omission by accident (but see G3)
- US1/US3 and US2 share the controller file; routes edits were sequential in `routes/web.php`
- Gaps ordering suggestion: G1 + G5 + G11 (pure spec fixes, no behavior change) → G2 (bug fix) → G6/G7/G8 (contract alignment, potentially breaking) → G3 (refactor) → G9/G10 (tests + hardening)
