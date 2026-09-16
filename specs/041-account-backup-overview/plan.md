# Implementation Plan: Account-Wide Backup Overview

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-16 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/041-account-backup-overview/spec.md`

## Summary

- A new read-only endpoint `GET /me/backups` returns, for each `vhost` website of the calling account, the
  newest backup per type plus `backups_available` and a total (research R1, R7, R8).
- Visibility, representation and the plan gate are delegated to the shipped spec 018 code
  (`WebBackupService`), so the overview can never disclose more than the per-website endpoints (R2, R5, R9).
- A new `AccountBackupService` folds one page of websites, one grouped database-server query, one
  `web_backup` query and one server query into the response — a query count independent of the number of
  websites (R3, R4, R6).

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)
**Primary Dependencies**: laravel/framework ^12; dev: phpunit ^11
**Storage**: MySQL `dbispconfig` — read-only (`web_domain`, `web_backup`, `web_database`, `server`, `client`)
**Testing**: PHPUnit feature tests on sqlite in-memory, Docker `php:8.3-cli` (baseline 1258 on `53bc008`)
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)
**Project Type**: Contract-first REST API (monolith)
**Performance Goals**: constant number of queries per request, independent of the account's website count
**Constraints**: no new visibility rule; no write of any kind; no file system access
**Scale/Scope**: 1 route, 1 controller, 1 service, 1 contract file, 2 schemas, 1 test class

## Constitution Check

- [x] **Spec-first (I)**: `api/modules/me/backups.yaml` plus its schemas land before the implementation, and
      the module index and `openapi.yaml` are updated in the same commit.
- [x] **Datalog-only writes (II)**: no writes at all — the feature is read-only (spec "ISPConfig Parity &
      Datalog Impact").
- [x] **Legacy parity (III)**: the plan gate and the vhost-only rule are the ported legacy conditions
      (`web_vhost_domain.tform.php:85-97` → `RequireBackupAccess`), and every backup field comes from the
      existing `plugin_backuplist.inc.php` port (R2, R5, R9).
- [x] **Route discipline (IV)**: one route in `routes/api/me.php` inside the existing `api.key` group; the
      controller validates and delegates, the service holds the logic.
- [x] **HTTP contract (V)**: `{data, meta}` list, problem+json refusals with the spec 023 types, 400 for
      unknown parameters, 422/404 for the `client_id` rules — identical to the other account endpoints.
- [x] **Tests required**: one feature test class covering both user stories, isolation, the gate, paging,
      a query-count assertion and field equality with the per-website list (R10).
- [x] **No schema changes**: no migrations; only existing ISPConfig tables are read.

## Project Structure

### Documentation (this feature)

```
specs/041-account-backup-overview/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── checklists/requirements.md
└── contracts/README.md
```

### Source Code (repository root)

```
api/modules/me/backups.yaml                     # new contract (paths + operation)
api/modules/me/_index.yaml                      # + backups entry
api/openapi.yaml                                # + /me/backups path
api/components/schemas/AccountBackupOverview.yaml   # new: one website entry; latest[] -> WebBackup.yaml
app/Http/Controllers/Api/V1/MeBackupsController.php # new: validation + delegation
app/Services/AccountBackupService.php               # new: the page fold
app/Services/WebBackupService.php                   # + page-level server-id resolution (R4)
routes/api/me.php                                   # + GET me/backups
tests/Feature/MeBackupsApiTest.php                  # new
README.md                                           # Modules: the new endpoint
```

## Legacy Research (Phase 0 focus)

Completed in [research.md](research.md). The decisive legacy facts, all read on isp-test:

- The Backup tab is shown only for `vhost` websites and only when the client's `limit_backup` is `y`
  (`/usr/local/ispconfig/interface/web/sites/form/web_vhost_domain.tform.php`), already ported in
  `app/Http/Middleware/RequireBackupAccess.php`.
- A backup belongs to a website through `web_backup.parent_domain_id`; ISPConfig's own list
  (`interface/lib/classes/plugin_backuplist.inc.php`) shows the rows of the website's server and of its
  database servers, and marks `download_available` false when the backup's server differs from the
  website's (line 234-235) — both already ported in `WebBackupService`.
- Whether backups exist at all on a server is decided by the server's `backup_dir`
  (`plugins-available/backup_plugin.inc.php:78-80`), ported as `WebBackupService::backupsAvailable()`.

No new legacy behaviour is introduced; the feature only changes how many HTTP requests a consumer needs.

## Complexity Tracking

| Item | Why it is justified | Simpler alternative rejected because |
|---|---|---|
| New service instead of a controller method | the fold (websites → servers → backups → newest per type) is the feature | a controller holding it would breach route discipline (IV) |
| Page-level server-id resolution added to `WebBackupService` | keeps one definition of "which servers' backups count" (R4) | a loop over the per-website method reintroduces per-row queries (FR-008) |
