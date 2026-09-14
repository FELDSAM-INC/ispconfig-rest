# Implementation Plan: Client Lock and Cancel Side Effects

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-14 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/019-client-lock-cancel/spec.md`

## Summary

Port legacy `func_client_lock` / `func_client_cancel` (ISPConfig 3.3.1p1) to the client and reseller
endpoints, plus the owner-decided write guard for locked clients (FR-013):

- A new `ClientLockService` performs lock (disable every record of the lock table list owned by the
  client's group through `DatalogService::updateRecord`, store the legacy `tmp_data` snapshot), unlock
  (restore from the snapshot, drop `prev_active`) and the login toggle (`sys_user.active`).
- `ClientService::updateClient()` re-reads the stored `locked`/`canceled` values under a row lock inside the
  controller transaction and calls the service only when a value changes; `createClient()` creates the
  control-panel user inactive when `canceled` is true.
- `BaseModel::save()` gains a locked-client guard next to the spec 012 limit checks: non-admin scopes get
  403 (no datalog) when they re-enable a lock-managed column of a locked client's record or create a
  lock-list record owned by a locked client.

No new endpoints, no schema changes; contract descriptions and README document the behavior.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)  
**Primary Dependencies**: laravel/framework ^12, Eloquent ORM; dev: phpunit ^11, mockery, faker  
**Storage**: MySQL `dbispconfig` (ISPConfig-owned schema; datalog writes for record toggles; documented direct writes for `client.tmp_data` and `sys_user.active`)  
**Testing**: PHPUnit feature tests in `tests/Feature/` on sqlite in-memory, run in Docker `php:8.3-cli`  
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: lock/unlock of a client with ~500 records completes inside one request (one select + update + re-select + datalog insert per changed record, as legacy)  
**Constraints**: byte-compatible `tmp_data` snapshot and datalog payloads with legacy; side effects atomic with the client update; no change for requests that do not change `locked`/`canceled`  
**Scale/Scope**: 4 existing endpoints change behavior (POST/PUT `/clients`, `/resellers`); write guard applies to all write endpoints of 12 lock-list tables for non-admin keys

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: no new paths or schemas; `api/components/schemas/Client.yaml` (`locked`, `canceled`) and
  `api/modules/client/clients.yaml` / `resellers.yaml` create/update descriptions are updated first
  (contracts/client-contract-changes.md). The 403 used by FR-013 is already declared on every write
  operation of the lock-list modules (verified: `api/modules/sites/*.yaml`, `api/modules/mail/*.yaml`).
- [x] **Datalog-only writes (II)**: record toggles go through `DatalogService::updateRecord()` (legacy
  `datalogUpdate` parity). Two direct writes are documented exceptions matching legacy plain queries
  (Complexity Tracking): `client.tmp_data` and `sys_user.active`.
- [x] **Legacy parity (III)**: `functions.inc.php:567-705`, `client_edit.php:488-497`,
  `reseller_edit.php:434-548`, `db_mysql.inc.php:811-841` read on isp-test; captured in research R1–R6.
  Deviations are owner-approved and listed in the spec (cancel on create, change-only, FR-013 guard).
- [x] **Route discipline (IV)**: no route changes.
- [x] **HTTP contract (V)**: existing status codes; FR-013 uses 403 problem+json via `AuthorizationException`
  (same path as the spec 011 write gate).
- [x] **No schema changes**: no migrations; test schemas gain missing columns only.

**Post-design re-check (after Phase 1)**: all gates still pass; the two Principle II exceptions remain the only
deviations and are justified below.

## Project Structure

### Documentation (this feature)

```text
specs/019-client-lock-cancel/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── client-contract-changes.md
│   └── lock-snapshot.md
└── tasks.md             # /speckit-tasks
```

### Source Code (repository root)

```text
api/
├── components/schemas/Client.yaml            # locked/canceled descriptions (side effects, FR-013)
└── modules/client/
    ├── clients.yaml                          # POST/PUT descriptions
    └── resellers.yaml                        # POST/PUT descriptions

app/
├── Services/ClientLockService.php            # NEW: lock/unlock/snapshot/login toggle, lock table list
├── Services/LockedClientGuard.php            # NEW: FR-013 check used by BaseModel::save()
├── Services/ClientService.php                # change detection (row lock), cancel on create, docblock
└── Models/BaseModel.php                      # invoke LockedClientGuard for non-admin create/update

tests/
├── Support/TenantSchema.php                  # ensure client.locked/canceled/tmp_data columns
├── Feature/ClientLockApiTest.php             # NEW: US1 lock/unlock matrix, snapshot bytes, legacy snapshot, no-op
├── Feature/ClientCancelApiTest.php           # NEW: US2 cancel toggle, cancel on create, keys keep working
├── Feature/ResellerLockApiTest.php           # NEW: US3 reseller scope + sys_userid attribution
└── Feature/LockedClientWriteGuardTest.php    # NEW: FR-013 client/reseller refusals, admin unaffected

README.md                                     # document lock/cancel behavior and deviations
```

**Structure Decision**: Business logic in two services (`ClientLockService` for side effects,
`LockedClientGuard` for the write guard) so `ClientService` stays the lifecycle orchestrator and `BaseModel`
only adds one call at the existing create/update chokepoint. No controller or route changes.

## Legacy Research (Phase 0 focus)

See [research.md](research.md): exact lock/unlock algorithm and table list (R1), snapshot format and the
never-restored owner key (R2), `sys_userid` attribution per endpoint (R3), change detection and concurrency
(R4), cancel and cancel on create (R5), guard design and bypass paths (R6–R7), testing strategy (R8).

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Direct `UPDATE client SET tmp_data` (no datalog) | Legacy `func_client_lock` writes the snapshot with a plain query (`functions.inc.php`, `reseller_edit.php`); the snapshot is panel bookkeeping servers never read | Datalogging it would emit `client` updates legacy never produces and bump the client's datalog history |
| Direct `UPDATE sys_user SET active` (no datalog) | Legacy `func_client_cancel` uses a plain query; `sys_user` is never datalogged (already an exception in `ClientService`) | There is no datalog consumer for `sys_user`; a datalog row would be ignored by servers and diverge from legacy |
