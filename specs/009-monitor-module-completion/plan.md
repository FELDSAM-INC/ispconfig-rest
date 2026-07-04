# Implementation Plan: Monitor Module Completion (Server Status & System Logs)

**Branch**: `009-monitor-module-completion` | **Date**: 2026-07-04 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/009-monitor-module-completion/spec.md`

**Status**: Draft — reverse-engineered from the existing OpenAPI contract and legacy ISPConfig source; nothing is implemented yet. This plan describes FUTURE files only.

## Summary

Implement the two remaining monitor-module resources already declared in the contract: aggregated server status (`GET /monitor/servers/status`, `GET /monitor/servers/{id}/status`) and system logs (`GET /monitor/system-logs`). All three endpoints are strictly read-only. The core technical work is (a) a deserialization/aggregation service that turns ISPConfig's PHP-serialized `monitor_data` blobs (one row per monitoring type per server, ~4-minute retention, composite PK) into the contract's `ServerStatus` projection using the legacy highest-severity state rule, and (b) a filtered, paginated query over `sys_log` mirroring the legacy list defaults. Conventions mirror the shipped sibling `Monitor/DataLogController` (submodule namespace, query-builder reads, `{data, pagination}` envelope, unserialize-with-fallback).

## Technical Context

**Language/Version**: PHP ^7.3|^8.0 (Laravel Lumen 8.3)  
**Primary Dependencies**: laravel/lumen-framework, Eloquent ORM; dev: phpunit ^9.5, mockery, fakerphp  
**Storage**: MySQL — ISPConfig's `dbispconfig` database (schema owned by ISPConfig; never migrated by this project; all writes via `sys_datalog` — this feature performs NO writes). Tables read: `monitor_data`, `sys_log`, `server`.  
**Testing**: PHPUnit (`vendor/bin/phpunit`), tests in `tests/*Test.php` — optional per constitution; the spec does not request tests, so none are planned  
**Target Platform**: Linux server alongside an ISPConfig installation  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: N/A beyond sane queries — `monitor_data` is tiny (240 s retention per type/server); `sys_log` filters use indexed/primary columns (`syslog_id`, plus `server_id`/`tstamp` equality/range)  
**Constraints**: READ-ONLY feature — async `sys_datalog` write semantics do not apply; behavioral parity with `source_code/interface/web/monitor/show_sys_state.php` (state aggregation) and `list/log.list.php` (log list defaults); `monitor_data.data` is PHP-serialized (never JSON) and MUST be `unserialize(..., ['allowed_classes' => false])`-ed server-side  
**Scale/Scope**: 3 endpoints, 1 new service, 1 new read-only model, 2 new controllers, 3 route registrations, 0 spec-file changes (contract already authored; two flagged spec corrections pending clarification)

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: PASS — both YAMLs exist (`api/modules/monitor/server-status.yaml`, `api/modules/monitor/system-logs.yaml`), are registered in `_index.yaml` and root `openapi.yaml`, and reference shared schemas (`ServerStatus.yaml`, `SystemLog.yaml`, `Pagination.yaml`) and shared parameters/responses. Implementation mirrors them verbatim. **Honest notes**: (a) both YAMLs reference an undefined `basicAuth` security scheme — the root spec defines only `apiKeyAuth`; treated as a spec typo, implementation uses `api.auth` (X-API-Key). (b) `Pagination.yaml` declares Laravel page-style required fields that neither the shipped sibling `DataLogController` nor offset/limit semantics can honor — flagged in spec.md; recommended resolution is amending `Pagination.yaml`, which is a contract fix outside this feature's code and needs sign-off.
- [x] **Datalog-only writes (II)**: N/A / PASS — zero write endpoints in the contract, zero writes in the design. The one new model (`SystemLog`) extends `BaseModel` for convention but only its read path is used. `monitor_data` gets no model at all (composite PK `server_id,type,created`; read via query builder inside a service, exactly like the sibling reads `sys_datalog`).
- [x] **Legacy parity (III)**: PASS — `source_code/interface/web/monitor/` reviewed (`show_sys_state.php`, `log_list.php`, `list/log.list.php`, `show_data.php`) plus the server-side collectors in `source_code/server/lib/classes/cron.d/100-monitor_*.inc.php` and `monitor_tools.inc.php`; state-aggregation rule, blob shapes, retention, and list defaults captured in spec.md's Parity section. **Honest note**: the contract's `ServerStatus` demands fields legacy never stored (per-service `uptime`, true `cpu_usage`, single-float `disk_usage`) — parity gaps are flagged as NEEDS CLARIFICATION in FR-008/FR-009/FR-010 and must be resolved before coding those mappings.
- [x] **Route discipline (IV)**: PASS — three new GET routes inside the existing `api.auth` group in `routes/web.php`, grouped with the existing Monitor block. Ordering: `monitor/servers/status` (fully literal) must be registered before `monitor/servers/{id}/status`; neither collides with `monitor/data-logs*`. No existing route shadows or is shadowed.
- [x] **HTTP contract (V)**: PASS with documented deviation — status codes per spec (200/400/401/404/500; no writes so 201/204 N/A); errors `{message, error}`. **Honest note**: the monitor module's list envelope is `{data, pagination}` per its YAMLs (Principle I wins over Principle V's `{items,total,limit,offset}` here, and matches the shipped sibling); also the contract declares 400 (not 422) for bad query values on system-logs — implementation will return 400, diverging from the project's usual 422-on-validation, per the YAML.
- [x] **No schema changes**: PASS — no migrations, no DDL; `database/` untouched.

## Project Structure

### Documentation (this feature)

```text
specs/009-monitor-module-completion/
├── spec.md              # Feature spec (done — this feature)
├── plan.md              # This file
└── tasks.md             # Task list (/speckit-tasks output)
```

(No separate research.md/data-model.md — legacy research and entity mapping are consolidated in spec.md and the Legacy Research section below; the contracts already live in `api/`.)

### Source Code (repository root)

```text
api/                                                   # NO changes required to implement
├── modules/monitor/
│   ├── _index.yaml                                    # existing — already lists server-status + system-logs
│   ├── server-status.yaml                             # existing contract — implement as-is
│   └── system-logs.yaml                               # existing contract — implement as-is
└── components/
    ├── schemas/ServerStatus.yaml                      # existing
    ├── schemas/SystemLog.yaml                         # existing
    └── schemas/Pagination.yaml                        # existing — pending flagged clarification (possible amendment, needs sign-off)

app/
├── Http/Controllers/Api/V1/Monitor/
│   ├── DataLogController.php                          # existing sibling — pattern reference, DO NOT modify
│   ├── ServerStatusController.php                     # FUTURE — index() (all servers), show($id) (one server, 404 via `server` table)
│   └── SystemLogController.php                        # FUTURE — index() only (filters + pagination over sys_log)
├── Models/
│   └── SystemLog.php                                  # FUTURE — extends BaseModel; $table='sys_log', $primaryKey='syslog_id'; read-only usage
└── Services/
    └── MonitorDataService.php                         # FUTURE — latest-row-per-type query, unserialize(['allowed_classes'=>false]),
                                                       #          legacy _setState aggregation + 7→4 enum mapping, blob→ServerStatus mapping

routes/web.php                                         # FUTURE edits — 3 GETs appended to the existing "Monitor" block:
                                                       #   GET monitor/servers/status        → Monitor\ServerStatusController@index
                                                       #   GET monitor/servers/{id}/status   → Monitor\ServerStatusController@show
                                                       #   GET monitor/system-logs           → Monitor\SystemLogController@index
                                                       # literal 'servers/status' registered BEFORE 'servers/{id}/status'
```

**Structure Decision**: Mirror the sibling submodule namespace `App\Http\Controllers\Api\V1\Monitor\` (constitution explicitly allows submodule dirs; DataLogController establishes the pattern). One controller per contract resource file. The blob deserialization + state aggregation is genuinely reusable, non-HTTP business logic → it goes in `app/Services/MonitorDataService.php` per Principle IV rather than fattening the controller (the constitution's "blob deserialization may need a service" concern lands here). `monitor_data` deliberately gets no Eloquent model (composite PK, read-only, service-owned queries — the sibling already reads `sys_datalog` via `DB::table()`); `sys_log` has a clean single-column PK so a thin `SystemLog` model keeps the controller idiomatic. Routes slot into the existing Monitor block at the end of the `api.auth` group in `routes/web.php` (after line ~112, `monitor/data-logs/{datalog_id}`); no reordering of existing routes is needed.

## Legacy Research (Phase 0 focus)

Findings from `source_code/` (read-only reference), consolidated:

- **No tform/list form for server status** — it is not a CRUD entity. `interface/web/monitor/show_sys_state.php` is the reference: iterate `server` rows (ordered by `server_name`); per server read `monitor_data` newest-first per type; aggregate `state` with `_setState()` severity ladder `no_state(0) < ok(1) < unknown(2) < info(3) < warning(4) < critical(5) < error(6)` (skip `openvz_beancounter`); unserialize `os_info`/`ispc_info` for name/version display and (custom patch) `sys_usage` for load/mem/net/time series.
- **Collector blob shapes** (all `serialize()`-ed arrays; `REPLACE INTO monitor_data`; `delOldRecords()` prunes rows older than 240 s per type/server — always query newest row): `server_load` `{up_days, up_hours, up_minutes, uptime<raw string>, user_online, load_1, load_5, load_15}`; `mem_usage` = `/proc/meminfo` map (bytes); `disk_usage` = per-filesystem `{fs,type,size,used,available,percent,mounted}` (human-readable df strings — `percent` needs `floatval`); `services` = `{webserver,ftpserver,smtpserver,pop3server,imapserver,bindserver,mysqlserver,mongodbserver}` ∈ {1,0,-1}; `sys_usage` `{tstamp, load[], mem[], net[{rx,tx}], time[]}` ≤15 points.
- **System logs**: `list/log.list.php` — table `sys_log`, idx `syslog_id`, default order `tstamp DESC, syslog_id DESC`, filters `server_id`(=), `loglevel`(=; 0=Debug/1=Warning/2=Error), `tstamp`(like), `message`(like). The contract exposes `server_id`, `loglevel`, and a `start_date`/`end_date` range instead of tstamp-like; no message search (not in YAML → not built).
- **Permissions**: module-level check only (`check_module_permissions('monitor')`); neither table has `sys_perm_*` columns → API-key auth alone is faithful.
- **Deletes exist in legacy** (`log_del.php`, `datalog_del.php`, admin-only) but are absent from the contract → out of scope.
- **Contract-vs-legacy gaps requiring sign-off before the mapping code is written** (full detail in spec.md FRs): status enum 7→4 mapping; `cpu_usage` has no faithful source; `disk_usage` single-float reduction rule; per-service `uptime` unpopulatable; service name strings; pagination envelope inner shape; system-logs default sort and 400-vs-422 validation responses; `basicAuth` spec typo.

## Complexity Tracking

> Fill ONLY if Constitution Check has violations that must be justified

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Query-builder reads of `monitor_data`/`server` inside a service instead of Eloquent models | `monitor_data` has a composite PK (`server_id`,`type`,`created`) and is a read-only, REPLACE-managed scratch table; `server` is only touched for name/existence | An Eloquent model would need fake single-key semantics it can never satisfy and would imply write support that must not exist; the shipped sibling (`DataLogController` on `sys_datalog`) already established builder reads for monitor-module system tables |
| `{data, pagination}` envelope instead of Principle V's `{items,total,limit,offset}` | The monitor YAMLs (Principle I, source of truth) and the shipped sibling both use it | Returning `{items,...}` would violate the contract and split the module into two envelope styles |
