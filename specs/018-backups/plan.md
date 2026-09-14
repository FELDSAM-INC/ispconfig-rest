# Implementation Plan: Website & Database Backups

**Branch**: `018-backups` | **Date**: 2026-09-14 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/018-backups/spec.md`

## Summary

Expose ISPConfig website backups as sub-resources of web domains: list and show backups, queue restore,
download, delete and on-demand backups as ISPConfig remote actions, poll backup jobs, and read/update the
website's backup settings. Actions are inserted into `sys_remoteaction` exactly like the legacy backup
list plugin (documented Principle II exception); settings are written through the datalog. Access follows
legacy: vhost websites only, `limit_backup = 'y'` for non-admin keys, read permission for list/show/
download, update permission for restore/delete/backup/settings. Download delivery stays the legacy folder
copy (owner decision 2026-09-14). No new tables and no ISPConfig schema changes.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12) — target platform; legacy Lumen 8 code is being ported (reboot Phase 2)  
**Primary Dependencies**: laravel/framework ^12, Eloquent ORM; dev: phpunit ^11, mockery, faker  
**Storage**: MySQL — ISPConfig's `dbispconfig` database (schema owned by ISPConfig; never migrated by this project; all writes via `sys_datalog`, except the documented `sys_remoteaction` inserts)  
**Testing**: PHPUnit (`vendor/bin/phpunit`), feature tests in `tests/Feature/` — REQUIRED per constitution v2 for every endpoint (happy path, validation, auth, datalog/remote-action assertion)  
**Target Platform**: Linux server alongside an ISPConfig installation  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: list endpoints use one query per list plus one per referenced lookup table (no per-row queries); action endpoints do one locked read and at most one insert per target server  
**Constraints**: async processing by ISPConfig `server.sh` (about one minute); behavioral parity with legacy ISPConfig 3.3.1p1 (verified on isp-test, research.md); local PHP 8.1 cannot run the suite — use Docker `php:8.3-cli` (quickstart.md)  
**Scale/Scope**: 10 operations on 3 sub-resources; 3 controllers, 2 models, 2 services, 1 middleware, 1 request concern, 1 contract file + 5 schemas; small backward-compatible extension of `HandlesListQuery`

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: `api/modules/sites/web-backups.yaml` and five schemas are authored first from
  `contracts/`; controllers implement them verbatim; `web-domains.yaml` and `databases.yaml` document the
  new 403 for backup fields.
- [x] **Datalog-only writes (II)** — with one documented exception: settings updates go through
  `WebDomain` (`BaseModel`) → `sys_datalog`. Remote actions are direct inserts into `sys_remoteaction`,
  exactly as legacy does; justified in Complexity Tracking. `web_backup` is never written.
- [x] **Legacy parity (III)**: `plugin_backuplist.inc.php`, `web_vhost_domain.tform.php`,
  `backup_plugin.inc.php`, `backup.inc.php` and `modules.inc.php::processActions()` reviewed (research
  R1–R13); intentional deviations are those listed in the spec plus the no-client-row gate note (R8).
- [x] **Route discipline (IV)**: routes go in `routes/api/sites.php` (the project's real location, inside
  the `api.key` group — the template's `routes/web.php`/`api.auth` wording predates the port), registered
  above the bare `sites/web-domains/{webDomain}` routes next to the SSL sub-resource, most specific first.
- [x] **HTTP contract (V)**: lists `{data, meta}` via `HandlesListQuery`; problem+json errors; 200 read and
  settings update, 201 for queued jobs, 204 for delete, 400/401/403/404/409/422 as specified.
- [x] **No schema changes**: no migrations; no API-owned table either (research R5).

**Post-design re-check (after Phase 1)**: all gates still pass; the only exception remains the
`sys_remoteaction` insert. No new violations were introduced by the data model or contracts.

## Project Structure

### Documentation (this feature)

```text
specs/018-backups/
├── plan.md              # This file
├── research.md          # Phase 0: R1–R14 decisions with legacy citations
├── data-model.md        # Phase 1: web_backup, sys_remoteaction, web_domain backup columns
├── quickstart.md        # Phase 1: Docker test commands, manual check on isp-test
├── contracts/
│   ├── web-backups.yaml # Draft of api/modules/sites/web-backups.yaml
│   └── schemas.yaml     # Drafts of WebBackup, WebBackupJob, WebBackupCreate, WebBackupSettings(Update)
├── checklists/requirements.md
└── tasks.md             # Phase 2 (/speckit-tasks)
```

### Source Code (repository root)

```text
api/
├── openapi.yaml                                  # + 7 path refs after /sites/web-domains/{id}/ssl/renew
├── modules/sites/
│   ├── _index.yaml                               # + web-backups entry
│   ├── web-backups.yaml                          # NEW (from contracts/web-backups.yaml)
│   ├── web-domains.yaml                          # document 403 for backup_* fields (limit_backup)
│   └── databases.yaml                            # document 403 for backup_* fields (limit_backup)
└── components/schemas/
    ├── _index.yaml                               # + 5 entries
    ├── WebBackup.yaml                            # NEW
    ├── WebBackupJob.yaml                         # NEW
    ├── WebBackupCreate.yaml                      # NEW
    ├── WebBackupSettings.yaml                    # NEW
    └── WebBackupSettingsUpdate.yaml              # NEW

app/
├── Http/Controllers/Api/V1/
│   ├── WebBackupController.php                   # NEW index/show/store/destroy + restore/download
│   ├── WebBackupJobController.php                # NEW index/show
│   └── WebBackupSettingsController.php           # NEW show/update
├── Http/Middleware/RequireBackupAccess.php       # NEW alias scope.backup: vhost-only 404, limit_backup 403
├── Http/Requests/
│   ├── StoreWebBackupRequest.php                 # NEW type web|mysql
│   ├── UpdateWebBackupSettingsRequest.php        # NEW legacy validation (R11)
│   ├── Concerns/EnforcesBackupLimit.php          # NEW 403 on backup_* fields (R12)
│   ├── WebDomainRequest.php                      # use EnforcesBackupLimit
│   ├── StoreWebDatabaseRequest.php               # use EnforcesBackupLimit
│   └── UpdateWebDatabaseRequest.php              # use EnforcesBackupLimit
├── Http/Concerns/HandlesListQuery.php            # + optional defaultOrder, sortAliases (R6)
├── Models/
│   ├── WebBackup.php                             # NEW extends BaseModel, read-only
│   └── RemoteAction.php                          # NEW extends Eloquent Model (exception, R1)
└── Services/
    ├── WebBackupService.php                      # NEW visibility, derived fields, availability, settings
    └── RemoteActionService.php                   # NEW locked de-dup + legacy-identical inserts

bootstrap/app.php                                 # + alias 'scope.backup'
routes/api/sites.php                              # + backup routes above web-domains/{webDomain}

tests/
├── Support/SitesSchema.php                       # + sys_remoteaction, monitor_data; server.config backup_dir seeds
└── Feature/
    ├── WebBackupApiTest.php                      # list/show/filters/sort/derived fields/scoping
    ├── WebBackupActionApiTest.php                # store/restore/download/delete: exact rows, 409, 422, 403, 404
    ├── WebBackupJobApiTest.php                   # attribution, state mapping ('' → error), download object
    ├── WebBackupSettingsApiTest.php              # validation matrix, datalog u row, password write-only
    ├── BackupLimitGateTest.php                   # limit_backup n/y/no client row, admin exempt, existing endpoints
    └── ListQueryAliasTest.php                    # defaultOrder/sortAliases, existing callers unchanged
```

**Structure Decision**: Backup routes are registered in `routes/api/sites.php` directly above the existing
SSL sub-resource block, in this order: `…/backups/{backup}/restore`, `…/backups/{backup}/download`,
`…/backups/{backup}` (GET, DELETE), `…/backups` (GET, POST), `…/backup-jobs/{job}`, `…/backup-jobs`,
`…/backup-settings` (GET, PUT), all `whereNumber` and grouped under the `scope.backup` middleware. Route
model binding keeps resolving `{webDomain}` through the read predicate (404), then the middleware applies the
vhost and `limit_backup` gates (research R8). Controllers stay thin; `WebBackupService` owns queries and
derivations, `RemoteActionService` owns the transaction, lock, de-duplication and inserts.

## Legacy Research (Phase 0 focus)

Completed in [research.md](./research.md):

- Form definition: `web_vhost_domain.tform.php` Backup tab fields, values and regex (R11); availability gates
  (R8); missing compression tools (R10).
- Actions: `plugin_backuplist.inc.php` queueing, permission checks, pending checks, list query and derived
  fields (R1–R3, R6, R7).
- Server side: `backup_plugin.inc.php` action handlers and return values, `modules.inc.php::processActions()`
  state handling, `backup.inc.php` download location and 3-day cleanup (R4, R9, R13).
- Live schema: `DESCRIBE web_backup`, `DESCRIBE sys_remoteaction`, `sql_mode` (R4, R14).

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Direct insert into `sys_remoteaction` (Principle II) | ISPConfig servers execute backup restore/download/delete and manual backups only from `sys_remoteaction` rows: `modules.inc.php::processActions()` selects this table and raises `backup_plugin` actions. Legacy `plugin_backuplist.inc.php` (`makeBackup()`, `onShow()` action branches) inserts these rows with plain SQL, never through the datalog. | A datalog entry on `web_domain` triggers no backup action on the server, so the feature could not work. |
| `RemoteAction` model does not extend `BaseModel` (Principle II model rule) | `BaseModel::save()` always writes a `sys_datalog` entry; remote actions must not be journaled (legacy parity, spec FR-015 exact rows). Inserts go only through `RemoteActionService`. | Extending `BaseModel` and suppressing the datalog would add a special case to the shared write chokepoint used by every other model. |
