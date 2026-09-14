# Implementation Plan: Change Status for API Writes

**Branch**: `015-change-status` | **Date**: 2026-09-14 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/015-change-status/spec.md`

## Summary

Give every API key a truthful view of whether its writes have been applied by ISPConfig.
Every successful write that journals at least one `sys_datalog` entry returns `X-Change-Set-Id` (the
request's existing journal `session_id`). Two read-only endpoints report status:
- `GET /changes/{change_set_id}` — aggregate status over all entries of one change set, with its entries
  paginated (owner decision 2026-09-14).
- `GET /changes` — scoped, filterable list, including the customer's pending and failed changes and a
  record view for readable records.

Status comes from ISPConfig's own processing model: per-server `server.updated` watermarks (target server
plus active mirrors, or all active servers for `server_id = 0`) and `sys_datalog.error`. It adds `stalled`
when no active server is responsible. There are no schema changes, no ISPConfig writes, and `/monitor/*`
is unchanged. The header is emitted by one response middleware driven by a request-scoped journal counter
in `IspContext`, and it is documented on all 148 write operations of the contract.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12) — target platform; legacy Lumen 8 code is being ported (reboot Phase 2)  
**Primary Dependencies**: laravel/framework ^12, Eloquent ORM; dev: phpunit ^11, mockery, faker; symfony/yaml (already locked) for the contract lint test  
**Storage**: MySQL — ISPConfig's `dbispconfig` database (schema owned by ISPConfig; never migrated by this project; all writes via `sys_datalog`). This feature only reads `sys_datalog` and `server`  
**Testing**: PHPUnit (`vendor/bin/phpunit`), feature tests in `tests/Feature/` plus unit tests in `tests/Unit/` — REQUIRED per constitution v2  
**Target Platform**: Linux server alongside an ISPConfig installation  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: a status request answers within 1 s for change sets of any size, one page of entries per response (SC-005); one aggregate query, one page query and one `server` query per change set request; no per-entry queries  
**Constraints**: async write semantics via `sys_datalog` (201 create / 200 update / 204 delete); behavioral parity with legacy ISPConfig 3.3.1p1 datalog processing; no indexes may be added to `sys_datalog` (research R7); journal payloads and usernames never exposed  
**Scale/Scope**: 2 new endpoints; 1 new module (`changes`); 2 schemas + 1 header component; 148 write operations in 54 contract files gain a header reference; 1 middleware, 1 controller, 2 services; `IspContext` and `DatalogService` touched

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

Pre-research gate: **PASS**. Post-design re-check (after research.md, data-model.md, contracts/): **PASS**.

- [x] **Spec-first (I)**: `api/modules/changes/changes.yaml` + `_index.yaml`, `api/components/schemas/Change.yaml`, `ChangeSet.yaml` and `api/components/headers/ChangeSetId.yaml` are authored first (drafts in `contracts/`); the header reference is added to every write operation (`contracts/write-operations-header.md`) and enforced by a contract lint test. Bodies reference `api/components/schemas/`; list meta reuses `Meta.yaml`.
- [x] **Datalog-only writes (II)**: read-only feature. It writes no ISPConfig table and no API-owned table. `App\Models\DataLog` stays the documented read-only exception (save/delete throw). `DatalogService` only gains a counter increment after its existing insert; the byte format is unchanged.
- [x] **Legacy parity (III)**: `source_code/` is not vendored in this checkout, so the equivalent ISPConfig 3.3.1p1 source was reviewed on the test server:
  - `modules.inc.php::processDatalog()` — mirror and `server_id = 0` selection, watermark updates;
  - `server.php:76`;
  - `db_mysql.inc.php::datalogStatus()` (interface) — user scope, active filter;
  - `db_mysql.inc.php::datalogError()` (server);
  - `cron.d/200-logfiles.inc.php:300` — purge rules.
  Parity and intentional deviations are in the spec and research R2, R3, R5 and R10.
- [x] **Route discipline (IV)**: new `routes/api/changes.php` is required from `routes/api.php` inside the `api.key` group, outside every `scope.admin` group. `changes` is registered before `changes/{changeSetId}`. The flow is thin `ChangeController` → `ChangeStatusResolver` / `ChangeRecordResolver` services. (The template's `routes/web.php` / `api.auth` wording predates the Laravel 12 port; `routes/api.php` is the constitution v2 target.)
- [x] **HTTP contract (V)**:
  - The list returns `{data, meta:{total,limit,offset}}` through `HandlesListQuery`, and unknown parameters give 400.
  - The change set returns the `ChangeSet` resource with its entries paged by the shared `limit`/`offset` and a `meta` object next to `entries`; unknown parameters give 400 (owner decision 2026-09-14).
  - Errors are RFC 9457 problem+json: 400 for bad filters, 401 without a key, and 404 for unknown or invisible sets and for unreadable records.
  - Only 200 responses are added. Existing write status codes are unchanged; they only gain a response header.
- [x] **No schema changes**: no migrations and no indexes on ISPConfig tables. The index alternative is explicitly rejected in R7.
- [x] **Tests (REQUIRED)**: feature tests for every endpoint and for the header (success, 400, 401, 404, cross-tenant isolation, mirrors, `server_id = 0`, stalled, failed), plus unit tests for status derivation and for the contract header lint.

## Project Structure

### Documentation (this feature)

```text
specs/015-change-status/
├── plan.md              # This file
├── research.md          # Phase 0: decisions R1–R12
├── data-model.md        # Phase 1: derived entities, status rules, visibility
├── quickstart.md        # Phase 1: automated + manual verification
├── contracts/
│   ├── changes.yaml                  # draft of api/modules/changes/changes.yaml
│   ├── Change.schema.yaml            # draft of api/components/schemas/Change.yaml
│   ├── ChangeSet.schema.yaml         # draft of api/components/schemas/ChangeSet.yaml
│   ├── ChangeSetId.header.yaml       # draft of api/components/headers/ChangeSetId.yaml
│   └── write-operations-header.md    # header on all 148 write operations
├── checklists/requirements.md
└── tasks.md             # Phase 2 output (/speckit-tasks — not created here)
```

### Source Code (repository root)

```text
api/
├── openapi.yaml                          # add paths /changes, /changes/{change_set_id}; components.schemas Change, ChangeSet; new components.headers ChangeSetId
├── modules/changes/
│   ├── _index.yaml                       # NEW
│   └── changes.yaml                      # NEW — GET /changes, GET /changes/{change_set_id}
├── modules/*/*.yaml                      # 54 files: X-Change-Set-Id header on 149 inline 2xx write responses
└── components/
    ├── schemas/Change.yaml               # NEW
    ├── schemas/ChangeSet.yaml            # NEW
    └── headers/
        ├── _index.yaml                   # NEW
        └── ChangeSetId.yaml              # NEW

app/
├── Http/Controllers/Api/V1/ChangeController.php      # NEW — index (list), show (change set)
├── Http/Middleware/AttachChangeSetId.php             # NEW — 2xx + journalEntryCount() > 0 → header
├── Services/ChangeStatusResolver.php                 # NEW — responsible servers, thresholds, per-entry/set status, SQL status predicates
├── Services/ChangeRecordResolver.php                 # NEW — table→primary key map, readable-record check via AuthScope
├── Services/DatalogService.php                       # CHANGED — IspContext::recordJournalEntry() after insert
└── Support/IspContext.php                            # CHANGED — journal counter

bootstrap/app.php                          # alias 'change.set'; priority after ApiKeyAuth
routes/api.php                             # group middleware ['api.key', 'change.set']; require routes/api/changes.php outside scope.admin
routes/api/changes.php                     # NEW — changes (list) before changes/{changeSetId} (where: [A-Za-z0-9,-]{1,64})

tests/Feature/ChangeStatusApiTest.php      # NEW — change set show: pending/applied/failed/stalled, mirrors, server_id 0, entry paging with status over all entries, 400/401/404, isolation
tests/Feature/ChangeListApiTest.php        # NEW — visibility own writes vs admin, filters, since, record view (readable/unreadable/deleted/400), unknown params
tests/Feature/ChangeSetHeaderTest.php      # NEW — header on create/update/cascading delete matches session rows; absent on no-change update and 422
tests/Unit/ChangeStatusResolverTest.php    # NEW — derivation matrix from server rows (active/inactive/mirror/deleted server, server_id 0)
tests/Unit/ChangeSetHeaderContractTest.php # NEW — every write 2xx in api/modules references the header
```

**Structure Decision**:
- Read endpoints live in a new top-level `changes` module, because the view is cross-module and available
  to every key. It is not placed under `monitor`, which is admin-only (spec 011).
- `routes/api/changes.php` is required in `routes/api.php` next to the other non-gated modules (client,
  dns, mail, sites); inside the file the static `changes` route precedes `changes/{changeSetId}`.
- Header emission is a group middleware so no write controller changes.
- Status logic sits in services shared by both endpoints and unit-testable without HTTP.
- Tests reuse `MonitorSchema` (journal and `server` with `updated`/`mirror_server_id`/`active`),
  `TenantSchema`/`TenantFixtures` (admin, clientA, clientB and reseller identities with minted keys), and one
  module schema (for example `MailSchema`) for real writes in `ChangeSetHeaderTest` and the record view.

## Legacy Research (Phase 0 focus)

Done — see research.md.

- **Processing model**:
  - `processDatalog()` selects entries above the server's last id for its own id, its mirror master and
    `server_id = 0`, then advances `server.updated` per processed entry;
  - `server.php` starts from the maximum of master and local watermarks.
- **Status shown to users**: `datalogStatus()` counts the logged-in user's entries above the watermark of
  active servers, and `datalogstatus.php` polls it for every user type.
- **Errors**:
  - server plugins call `datalogError()`, which fills `sys_datalog.error`;
  - `status` stays `ok`;
  - edit forms (`web_vhost_domain_edit.php`, `dns_soa_edit.php`) show the record's latest error.
- **Cleanup**: `200-logfiles.inc.php` purges processed, old entries per server and keeps `server_id = 0`
  entries. A purged set therefore returns 404.
- **Permission**: the journal has no row permissions. Visibility is the writer username, plus the record
  view gated by the record's own `getAuthSQL('r')`, which is `AuthScope::applyReadPredicate` here.

## Implementation Notes

1. **Contract first**:
   - Author the `changes` module, schemas and header component.
   - Register them in `openapi.yaml`.
   - Add the header reference to all write 2xx responses by text edit.
   - Add `ChangeSetHeaderContractTest`.
2. **Journal counter**: `IspContext::recordJournalEntry()` and `journalEntryCount()`, called only from
   `DatalogService::log()` after `insertGetId`. Suppressed no-change writes do not count.
3. **Middleware**:
   - `AttachChangeSetId` reads `IspContext` after `$next($request)` and sets `X-Change-Set-Id` to
     `sessionId()` when `$response->isSuccessful()` and the count is above 0.
   - Add it to the `api.key` group and to the priority list after `ApiKeyAuth`.
4. **`ChangeStatusResolver`**:
   - Load `server` rows once and build `R(s)`/`T(s)` for every server id plus 0.
   - `statusOf(object $row)` follows the data-model rules; `aggregate(array $statuses)` follows FR-005.
   - `applyStatusFilter(Builder $q, string $status)` implements the R6 predicates.
5. **`ChangeRecordResolver`**:
   - Hold the table → primary key map from data-model.md (sys-field flag per table).
   - `assertReadable(string $table, int $id)` loads the row with `applyReadPredicate('r')` for non-admin
     scopes and throws a not-found problem otherwise.
   - `dbidx(string $table, int $id)` returns `"<pk>:<id>"`.
6. **`ChangeController@index`**:
   - Reject `sort` (400) and validate `status`, `since` and `record_id`/`table` (400 on invalid values).
   - Apply visibility: admin none; non-admin `user = username` unless the record view applies, which
     instead constrains `dbtable`/`dbidx` after `assertReadable`.
   - Apply the filters, then `listQuery(DataLog::query(), sortable: ['datalog_id'], defaultSort:
     'datalog_id', filters: ['change_set_id' => ...])` with `order` defaulting to `desc`.
   - Map rows to the `Change` shape (no `data`/`user`/`server_id`).
7. **`ChangeController@show`** (owner decision 2026-09-14: status over all entries, entries paginated):
   - Visibility constraint: `session_id = id` (plus `user = username` for non-admin).
   - Aggregate query: `COUNT(*)`, conditional sums of the `ChangeStatusResolver` status predicates and
     `MIN(tstamp)`; `total = 0` → 404; set `status` from the counts (FR-005).
   - Page query: `ORDER BY datalog_id ASC` with the shared `limit`/`offset` (unknown parameters 400), mapped
     to `Change`; respond with `id`, `status`, `entry_counts`, `created_at`, `entries` and `meta` (research R12).
8. **Isolation tests**:
   - Client B never sees A's sets or entries.
   - A reseller sees only entries written by its own username, not its clients' (legacy parity, owner
     decision 2026-09-14; the record view covers readable client records).
   - The admin sees all.
9. **Deferred**: CORS exposure of `X-Change-Set-Id` for browser consumers is not part of this feature (owner
   decision 2026-09-14); WHMCS calls the API server-side.

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

No violations. `sys_datalog` scans without new indexes are a deliberate consequence of the no-schema-change rule (research R7), not a constitution violation.
