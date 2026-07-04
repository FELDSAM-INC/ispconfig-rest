# Implementation Plan: Monitor Data-Logs (read-only sys_datalog journal)

**Branch**: `004-monitor-data-logs` (no real branch — feature shipped on `main`; brownfield migration) | **Date**: 2026-07-04 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/004-monitor-data-logs/spec.md`

**Note**: Reverse-engineered plan documenting the implementation as built, including honest constitution-gate results.

## Summary

Expose ISPConfig's `sys_datalog` change journal over two read-only REST endpoints (`GET /monitor/data-logs`, `GET /monitor/data-logs/{datalog_id}`) so API consumers can verify that their asynchronously-processed writes (Constitution Principle II: every write is journaled, applied later by ISPConfig daemons) were actually picked up. Implementation: a single thin controller in the project's only submodule namespace (`App\Http\Controllers\Api\V1\Monitor`) querying `sys_datalog` via the query builder with filter/sort/pagination parameters, plus `unserialize()` of the stored change payload. No model, no writes, no service logic of its own (the shared `DatalogService` is the *write-side* counterpart used by `BaseModel`; this controller injects it but does not actually call it).

## Technical Context

**Language/Version**: PHP ^7.3|^8.0 (Laravel Lumen 8.3)
**Primary Dependencies**: laravel/lumen-framework, Eloquent ORM (query builder only here — `Illuminate\Support\Facades\DB`); dev: phpunit ^9.5, mockery, fakerphp
**Storage**: MySQL — ISPConfig's `dbispconfig` database; this feature **reads** `sys_datalog` and `server.updated` only; schema owned by ISPConfig, never migrated
**Testing**: PHPUnit (`vendor/bin/phpunit`) — **no tests exist for this feature** (optional per constitution; none were requested)
**Target Platform**: Linux server alongside an ISPConfig installation
**Project Type**: Contract-first REST API (monolith)
**Performance Goals**: N/A — simple filtered/paginated selects; `count()` + page query per list request
**Constraints**: read-only surface (GET only); must make the async write semantics observable (`sys_datalog` + `server.updated` pointer); consumer-facing payloads must expose the `{"new","old"}` diff that `BaseModel`/`DatalogService::log()` serialize
**Scale/Scope**: 2 endpoints, 1 controller (2 actions), 1 OpenAPI resource file + 1 schema, 0 models, 0 migrations

## Constitution Check

*Gates evaluated against the code as shipped (brownfield — recorded honestly, not aspirationally).*

- [x] **Spec-first (I)** — **PARTIAL**: `api/modules/monitor/data-logs.yaml` exists and the implemented paths/methods/parameters mirror it. Violations: (a) the list response's `pagination` object `{total, limit, offset}` does not match the referenced `api/components/schemas/Pagination.yaml` (a Laravel-paginator shape requiring `per_page`, `current_page`, `last_page`, `from`, `to`, page URLs); (b) the YAML declares `security: basicAuth`, which is not registered in `api/openapi.yaml` (only `apiKeyAuth` is; `api/components/securitySchemes.yaml` defines `basicAuth` but nothing references it); (c) root `api/openapi.yaml` registers only `/monitor/data-logs` — the `/monitor/data-logs/{datalog_id}` path ref is missing, so Swagger UI does not render the show endpoint; (d) the YAML's declared 400 response is unreachable (no request validation exists); (e) implementation defaults diverge from the shared parameters (`limit` 25 vs declared default 20/max 100; `order` desc vs declared default asc).
- [x] **Datalog-only writes (II)** — **N/A / PASS**: read-only feature; no model, no writes to any ISPConfig table. No `BaseModel` needed. (Constitution-consistent: the prohibition targets writes. Note: `DatalogService` is constructor-injected into the controller but never used — dead dependency.)
- [x] **Legacy parity (III)** — **PARTIAL**: legacy consulted (see Legacy Research below); list filters/ordering mirror `datalog_list.php`/`dataloghistory_list.php`. Deviation-as-defect: `unprocessed_only` compares `tstamp > server.updated`, but legacy compares `datalog_id > server.updated` (`server.updated` is a datalog-ID watermark, not a timestamp) — the shipped filter is effectively always-true. Intentional deviations (no journal delete/undo, no per-user scoping, added filters) documented in the spec's Parity section.
- [x] **Route discipline (IV)** — **PASS**: both routes registered in `routes/web.php` (lines 110–112) inside the `api.auth` group under `API_PREFIX`; literal `monitor/data-logs` precedes `monitor/data-logs/{datalog_id}`; no shadowing of or by other routes.
- [x] **HTTP contract (V)** — **FAIL (documented pre-constitution deviation)**: list envelope is `{data: [...], pagination: {total, limit, offset}}` instead of `{items, total, limit, offset}`; the 404 body is `{"error": "Data log not found"}` instead of `{message, error}`; unexpected input (bad `sort`/`order`) yields 500 instead of 400/422. Status codes otherwise conform (200 reads, 401 auth, 404 missing, 500 unexpected). Like the five client-era 202 controllers, this predates the spec-first/contract rules — alignment is tracked in tasks.md Gaps; do not copy this envelope into new code.
- [x] **No schema changes** — **PASS**: no migrations; `database/` untouched by this feature.

## Project Structure

### Documentation (this feature)

```text
specs/004-monitor-data-logs/
├── spec.md              # Reverse-engineered feature spec
├── plan.md              # This file
└── tasks.md             # As-built task record + Gaps backlog
```

(No research.md / data-model.md / contracts/ — brownfield migration; their content is folded into these three files.)

### Source Code (repository root) — files this feature actually comprises

```text
api/
├── openapi.yaml                          # registers /monitor/data-logs (…/{datalog_id} ref MISSING — gap)
├── modules/monitor/
│   ├── _index.yaml                       # references data-logs.yaml (also system-logs/server-status — out of scope)
│   └── data-logs.yaml                    # both endpoint definitions (this feature's contract)
└── components/
    ├── schemas/DataLog.yaml              # sys_datalog row schema (x-db-table/x-db-field annotated)
    ├── schemas/Pagination.yaml           # referenced by the list response (shape mismatch — see gate I)
    ├── parameters/{limit,offset,sort,order}.yaml   # reused shared params
    └── responses/{BadRequest,Unauthorized,InternalServerError}.yaml  # reused shared responses

app/
├── Http/Controllers/Api/V1/Monitor/DataLogController.php  # index (filters/sort/pagination/unserialize) + show
└── Services/DatalogService.php           # shared write-side infrastructure (log/getStatus/getPendingEntries);
                                          # injected but unused by this controller — its read helpers
                                          # (getStatus, getPendingEntries) exist but have no callers here

routes/web.php                            # lines 110–112: the two GET routes inside the api.auth group

app/Models/                               # (intentionally) no DataLog model — direct DB::table('sys_datalog') reads
tests/                                    # no test file for this feature
```

**Structure Decision**: `Monitor/` is the project's only controller submodule namespace (`App\Http\Controllers\Api\V1\Monitor`) — explicitly allowed by the constitution's Code Boundaries and the natural home for the unbuilt server-status/system-logs resources later. Routes slot after the mail/domains block; ordering is trivially safe (unique `monitor/` prefix, literal-before-parameterized).

## Legacy Research (Phase 0 focus)

How legacy ISPConfig exposes datalog status (all paths under read-only `source_code/`):

- **`interface/web/monitor/datalog_list.php` + `list/datalog.list.php`** ("Jobqueue" — pending changes): builds a per-server WHERE of `(sys_datalog.datalog_id > server.updated AND sys_datalog.server_id = X) OR …` across all servers; orders `tstamp DESC, datalog_id DESC`; searchable columns `tstamp`, `server_id` (SQL datasource dropdown), `action` (select i/u/d), `dbtable`; 15 records/page; guarded by `check_module_permissions('monitor')`. → Informs the REST index's filter set, default ordering, and the *correct* unprocessed semantics (`datalog_id > server.updated`).
- **`interface/web/monitor/dataloghistory_list.php`** (full journal): same list without the unprocessed WHERE — the REST index without `unprocessed_only` is its equivalent.
- **`interface/web/datalogstatus.php`** → **`interface/lib/classes/db_mysql.inc.php::datalogStatus($login)`**: the AJAX endpoint behind the UI's red "changes pending" banner. Returns JSON `{count, entries[]}` of pending changes **for the current user** (`sys_datalog.user = ?`), active servers only (`server.active = 1`), `datalog_id > server.updated`, grouped by `dbtable`+`action`, ignoring tables `aps_instances`, `aps_instances_settings`, `mail_access`, `mail_content_filter`. → This is the closest legacy analogue of the P1 use case (did my change get processed?); the REST API covers it via `unprocessed_only` filtering rather than a per-user aggregate (no summary endpoint was built).
- **`interface/web/monitor/datalog_del.php`, `dataloghistory_undo.php`, `dataloghistory_view.php`**: legacy can delete journal entries and undo/inspect historical changes — deliberately **not** carried into the REST surface (journal is immutable via this API).
- **Write side for context**: `DatalogService::log()` (used by `BaseModel::save()/delete()`) writes `dbidx = "<pk>:<value>"`, `status = 'ok'`, serialized `{"new":…, "old":…}` payload — matching the DB default (`status` set defaults to `ok` per `ISPConfig-DB-Structure.txt`, `sys_datalog`, lines 381–386). Hence "pending" is signaled by the `server.updated` watermark, not by `status`, which is why the `unprocessed_only` parameter exists at all.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Direct `DB::table('sys_datalog')` / `DB::table('server')` reads in the controller (no Eloquent model, business query logic in the controller) | `sys_datalog` is a system journal, not a provisioned entity; it must never be written by a model, and only two read actions exist | A `DataLog` model extending `BaseModel` would wrongly advertise `save()`/`delete()` on the journal itself; a read-only model or moving query logic into `DatalogService` remains the cleaner target — recorded as a gap, not blocking |
| List envelope `{data, pagination}` + 404 body `{error}` diverge from Principle V | Shipped before the constitution; consumers may already depend on the shape | Silent envelope change would break existing consumers; alignment needs a deliberate, spec-first change (tasks.md Gaps) |
