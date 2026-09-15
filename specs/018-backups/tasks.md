---

description: "Task list for feature 018: Website & Database Backups"
---

# Tasks: Website & Database Backups

**Input**: Design documents from `/specs/018-backups/`
**Prerequisites**: plan.md, spec.md, research.md (R1–R14), data-model.md, contracts/, quickstart.md

**Tests**: Tests are REQUIRED (constitution v2) — every endpoint ships with feature tests covering happy path,
validation failures, auth failures, and write side effects (`sys_datalog` rows for settings, exact
`sys_remoteaction` rows for actions). Tests are written first and must fail before the implementation task.
Run with Docker `php:8.3-cli` (local PHP is too old, see quickstart.md).

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Re-baseline (2026-09-15, against `main` with 014, 015, 016, 017, 019 and the client company_name fix)

- **015 change status**: the backup settings `PUT` writes a `web_domain` datalog entry, so its 200 response documents
  `X-Change-Set-Id` (T001). The four remote-action operations (`POST …/backups`, `POST …/restore`, `POST …/download`,
  `DELETE …/backups/{backup_id}`) write no datalog and are added to `NON_JOURNALING_WRITES` in
  `tests/Unit/ChangeSetHeaderContractTest.php` (T006).
- **017 timezone alignment**: `created_at` and `available_until` (unix `tstamp` columns) serialize in the API timezone
  (`config('app.timezone')`, e.g. `+02:00` on isp-test) (T021, T043).
- **019 locked-client guard**: backup settings only touch `backup_*` columns, which are not lock-managed, and remote
  actions are not model saves — the guard does not apply to any backup endpoint. Locked clients keep backup access with
  non-admin keys (no new policy; recorded as an open point).
- **Test base**: backup tests share a new `tests/Support/WebBackupApiTestCase.php` (sites + tenant schema, real tenant
  keys, `limit_backup` column, two servers with and without `backup_dir`, `sys_remoteaction`/`monitor_data`) instead of
  extending `SitesApiTestCase`, because the gates need real client/reseller keys (T007, T008).
- **Contract lint**: `$ref` resolution script plus the contract tests replace `npx @redocly/cli lint` (T006, T050).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: Which user story this task belongs to (US1, US2, US3)
- Include exact file paths in descriptions

## Path Conventions (this project)

| Artifact | Path |
|----------|------|
| OpenAPI endpoint spec | `api/modules/sites/web-backups.yaml` (+ `api/modules/sites/_index.yaml`, path refs in `api/openapi.yaml`) |
| OpenAPI schemas | `api/components/schemas/WebBackup*.yaml` (+ `api/components/schemas/_index.yaml`) |
| Models | `app/Models/WebBackup.php` (`BaseModel`, read-only), `app/Models/RemoteAction.php` (Eloquent `Model`, Principle II exception) |
| Controllers | `app/Http/Controllers/Api/V1/WebBackup{,Job,Settings}Controller.php` |
| Services | `app/Services/WebBackupService.php`, `app/Services/RemoteActionService.php` |
| Middleware | `app/Http/Middleware/RequireBackupAccess.php`, alias `scope.backup` in `bootstrap/app.php` |
| Routes | `routes/api/sites.php` — above the SSL sub-resource block, specific-before-general |
| Test support | `tests/Support/SitesSchema.php`, `tests/Support/SitesApiTestCase.php`, `tests/Support/TenantFixtures.php` |
| Tests (REQUIRED) | `tests/Feature/WebBackup*ApiTest.php`, `BackupLimitGateTest.php`, `WebDomainBackupCopiesTest.php`, `ListQueryAliasTest.php` |

---

## Phase 1: Setup (Contract first)

**Purpose**: The OpenAPI contract is authored and valid before any PHP is written (Principle I)

- [x] T001 Author `api/modules/sites/web-backups.yaml` from `specs/018-backups/contracts/web-backups.yaml` (10 operations, FR-013 destructive-restore and manual-job caveats, FR-014 download object, `X-Change-Set-Id` on the backup-settings `PUT` 200 response) and register it in `api/modules/sites/_index.yaml`
- [x] T002 [P] Author `api/components/schemas/WebBackup.yaml`, `WebBackupJob.yaml`, `WebBackupCreate.yaml`, `WebBackupSettings.yaml` and `WebBackupSettingsUpdate.yaml` from `specs/018-backups/contracts/schemas.yaml` and register them in `api/components/schemas/_index.yaml`
- [x] T003 [P] Change `backup_copies` in `api/components/schemas/WebDomain.yaml` from `minimum: 1`/`maximum: 30` to `enum: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 15, 20, 30]` (FR-016, owner decision 2026-09-14)
- [x] T004 [P] Document the 403 for `backup_*` fields when the client has `limit_backup = 'n'` in `api/modules/sites/web-domains.yaml` and `api/modules/sites/databases.yaml` (FR-009)
- [x] T005 Add the seven new path references to `api/openapi.yaml` directly after `/sites/web-domains/{id}/ssl/renew` (depends on T001)
- [x] T006 Add the four remote-action operations to `NON_JOURNALING_WRITES` in `tests/Unit/ChangeSetHeaderContractTest.php`, resolve every `$ref` of `api/openapi.yaml`, and run `ChangeSetHeaderContractTest` and `tests/Feature/SwaggerSpecServerTest.php` in Docker `php:8.3-cli` (depends on T001–T005)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Test fixtures, list-query extension, models, gate and remote-action queueing used by every story

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [x] T007 [P] Extend `tests/Support/SitesSchema.php` with the `sys_remoteaction` table (live columns per research R14: `action_id` PK, `server_id`, `tstamp`, `action_type`, `action_param` nullable text, `action_state` default `pending`, `response` nullable text) and a `monitor_data` table when absent
- [x] T008 [P] Add fixture helpers to `tests/Support/WebBackupApiTestCase.php` (re-baseline: new base class): `server.config` INI with `[server] backup_dir=/var/backup` and a variant without it, `web_backup` rows (web, mysql, borg, `manual-` filename, empty legacy `backup_format`), a `monitor_data` `backup_utils` row, and an assertion helper for exact `sys_remoteaction` rows
- [x] T009 [P] Write `tests/Feature/ListQueryAliasTest.php` for the new `listQuery()` arguments: `defaultOrder: 'desc'` honoured, `sortAliases` map public sort names to columns, unknown sort still 400, existing callers still default to ascending (must fail before T010)
- [x] T010 Add optional, backward-compatible named arguments `defaultOrder` (default `'asc'`) and `sortAliases` (default `[]`) to `listQuery()` in `app/Http/Concerns/HandlesListQuery.php` (research R6; depends on T009)
- [x] T011 [P] Create read-only `app/Models/WebBackup.php` extending `BaseModel` (`$table = 'web_backup'`, `$primaryKey = 'backup_id'`, integer casts; docblock: the API never saves or deletes these rows, servers own them)
- [x] T012 [P] Create `app/Models/RemoteAction.php` extending `Illuminate\Database\Eloquent\Model` (`$table = 'sys_remoteaction'`, `$primaryKey = 'action_id'`, `$timestamps = false`) with a docblock citing the Principle II exception (plan.md Complexity Tracking, legacy `plugin_backuplist.inc.php`)
- [x] T013 Create `app/Services/WebBackupService.php` foundation: `BACKUP_COPIES` constant `[1..10, 15, 20, 30]`, `backupAllowed(AuthScope)` gate (admin exempt; client row missing or `limit_backup != 'y'` → false, research R8 step 3) and `backupsAvailable(int $serverId)` reading `[server] backup_dir` through `app/Services/ServerConfigService.php` (research R9)
- [x] T014 Create `app/Http/Middleware/RequireBackupAccess.php`: website `type != 'vhost'` → 404 problem+json, `WebBackupService::backupAllowed()` false → 403 problem+json; it relies on route-model binding having resolved `{webDomain}` through the read predicate first (research R8 steps 1–3; depends on T013)
- [x] T015 Register the alias `'scope.backup' => RequireBackupAccess::class` in `bootstrap/app.php` next to `scope.admin`/`scope.limit` (depends on T014)
- [x] T016 Create `app/Services/RemoteActionService.php`: `DB::transaction` with `lockForUpdate()` on the website row, pending count by (`action_type`, `action_param`) → 409, `backup_dir` check for every target server → 409, legacy-identical inserts (`server_id`, `tstamp = time()`, `action_type`, `action_param` as string, `action_state = 'pending'`, `response = ''`), returning the created `RemoteAction` rows (research R1–R3, R9; depends on T012, T013)

**Checkpoint**: Foundation ready — user story implementation can now begin

---

## Phase 3: User Story 1 - List backups and restore one (Priority: P1) 🎯 MVP

**Goal**: A client-scoped key lists a website's backups, restores one, and polls the restore job.

**Independent Test**: Seed website W of client A (`limit_backup = 'y'`) with a web and a mysql backup and website V of
client B; A's key lists only W's backups (V → 404), restore → 201 `pending` job with exactly one `backup_restore`
`sys_remoteaction` row and no datalog row, second restore → 409, row set to `ok` → job reports `ok`.

### Tests for User Story 1 (REQUIRED) ⚠️

- [x] T017 [P] [US1] Write `tests/Feature/WebBackupApiTest.php`: list/show `{data, meta}` newest first; filters `type`/`job`; sort `created_at`/`id`; derived fields (R7: format fallbacks, borg extension, `encrypted`, `job`, `download_available`, `database_name`, integer or null `filesize`, `filesize_approximate`); visibility by website and database servers (R6); backup password never returned; client A cannot read client B's website (404); non-vhost website 404; unknown query parameters 400
- [x] T018 [P] [US1] Write restore cases in `tests/Feature/WebBackupActionApiTest.php`: 201 job in state `pending`; exactly one `sys_remoteaction` row (`backup_restore`, `action_param` = backup id as string, `server_id` = backup's server or the website's when 0, `response = ''`); no `sys_datalog` row; duplicate pending → 409; key with read but not update permission → 403; backup of another website → 404; server without `backup_dir` → 409
- [x] T019 [P] [US1] Write `tests/Feature/WebBackupJobApiTest.php`: job attribution (R5) for `backup_web_files`/`backup_database` by website id and `backup_restore`/`backup_download`/`backup_delete` by the website's backup ids; state mapping `pending`/`ok`/`warning`/`error` and stored `''` → `error` (R4); filters `state`/`action`; jobs of other websites → 404
- [x] T020 [P] [US1] Write backup-endpoint cases in `tests/Feature/BackupLimitGateTest.php`: `limit_backup = 'y'` allowed, `'n'` → 403, missing client row → 403, admin key exempt, unreadable website → 404 before the gate (same pattern as `tests/Feature/AuthBeforeBindingTest.php`), non-vhost website → 404

### Implementation for User Story 1

- [x] T021 [US1] Add to `app/Services/WebBackupService.php`: visible backups query (R6), backup representation with derived fields (R7), job attribution query (R5) and job representation with state mapping (R4)
- [x] T022 [P] [US1] Create `app/Http/Controllers/Api/V1/WebBackupController.php` with `index` (`listQuery()` with `defaultOrder: 'desc'` and `sortAliases`), `show` and `restore` (requires `u` via `AuthScope::allows()` → 403, delegates to `RemoteActionService`, returns 201 job) (depends on T010, T016, T021)
- [x] T023 [P] [US1] Create `app/Http/Controllers/Api/V1/WebBackupJobController.php` with `index` and `show` (depends on T021)
- [x] T024 [US1] Register in `routes/api/sites.php` above the SSL block, inside a `scope.backup` middleware group with `whereNumber`: `POST sites/web-domains/{webDomain}/backups/{backup}/restore`, `GET …/backups/{backup}`, `GET …/backups`, `GET …/backup-jobs/{job}`, `GET …/backup-jobs` — specific routes first, no shadowing of `sites/web-domains/{webDomain}` (depends on T015, T022, T023)
- [x] T025 [US1] Run T017–T020 in Docker (`vendor/bin/phpunit --filter 'WebBackup|BackupLimitGate'`) and verify list, show, restore and jobs in Swagger UI against `api/modules/sites/web-backups.yaml`

**Checkpoint**: User Story 1 is fully functional and testable independently (MVP)

---

## Phase 4: User Story 2 - Back up now, delete backups, change the schedule (Priority: P2)

**Goal**: On-demand web/database backups, backup deletion, backup settings, `limit_backup` on existing endpoints, and
the FR-016 `backup_copies` rule.

**Independent Test**: A's key: `POST …/backups {"type":"web"}` → 201 with one `backup_web_files` row; `mysql` with
databases on two servers → two `backup_database` rows; repeat → 409; `DELETE …/backups/{id}` → 204 with a
`backup_delete` row; `PUT …/backup-settings` valid → 200 with one `web_domain` `u` datalog row, `copies: 11` → 422;
`limit_backup = 'n'` → 403.

### Tests for User Story 2 (REQUIRED) ⚠️

- [x] T026 [US2] Add store and delete cases to `tests/Feature/WebBackupActionApiTest.php`: `type: web` → one `backup_web_files` row (param = website id, website's server); `type: mysql` → one `backup_database` row per distinct database server, website without databases → 422; duplicate pending per type → 409; read-only permission → 403; any target server without `backup_dir` → 409; delete → 204 with one `backup_delete` row on the backup's server, duplicate → 409
- [x] T027 [P] [US2] Write `tests/Feature/WebBackupSettingsApiTest.php`: read shape including `backup_password_set`, `backups_available`, `missing_utils`; validation matrix (interval enum, copies set, excludes regex and max 255, both format enums, encryption without password → 422); one `web_domain` `u` datalog row on change and none when unchanged; `backup_password` write-only; read-only key → 403 on update; `limit_backup = 'n'` → 403
- [x] T028 [US2] Add existing-endpoint cases to `tests/Feature/BackupLimitGateTest.php`: `POST`/`PUT /sites/web-domains` and web database store/update sending `backup_*` fields with `limit_backup = 'n'` → 403; requests without backup fields unaffected; admin key exempt (depends on T020)
- [x] T029 [P] [US2] Write `tests/Feature/WebDomainBackupCopiesTest.php` (FR-016): `backup_copies` 11 and 25 → 422, 15 and 30 accepted, on create and update, with admin and client keys

### Implementation for User Story 2

- [x] T030 [P] [US2] Create `app/Http/Requests/StoreWebBackupRequest.php` (`type` required, one of `web`, `mysql`)
- [x] T031 [P] [US2] Create `app/Http/Requests/UpdateWebBackupSettingsRequest.php` with the research R11 rules (`backup_copies` uses `WebBackupService::BACKUP_COPIES`, excludes regex, format enums, write-only password)
- [x] T032 [P] [US2] Create `app/Http/Requests/Concerns/EnforcesBackupLimit.php`: throw `AuthorizationException` (403) when any `backup_*` key is present and `WebBackupService::backupAllowed()` fails; admin keys unaffected (research R12)
- [x] T033 [US2] Use `EnforcesBackupLimit` and replace `'min:1', 'max:30'` with `Rule::in(WebBackupService::BACKUP_COPIES)` for `backup_copies` in `app/Http/Requests/WebDomainRequest.php` (FR-016; depends on T032)
- [x] T034 [P] [US2] Use `EnforcesBackupLimit` in `app/Http/Requests/StoreWebDatabaseRequest.php` (depends on T032)
- [x] T035 [P] [US2] Use `EnforcesBackupLimit` in `app/Http/Requests/UpdateWebDatabaseRequest.php` (depends on T032)
- [x] T036 [US2] Add the settings representation and `missing_utils` (newest `monitor_data` row of type `backup_utils` for the website's server, research R10) to `app/Services/WebBackupService.php`
- [x] T037 [US2] Add `store` (web: one action on the website's server; mysql: one action per database server, 422 without databases) and `destroy` (queue `backup_delete`, return 204), both requiring `u`, to `app/Http/Controllers/Api/V1/WebBackupController.php` (depends on T030)
- [x] T038 [US2] Create `app/Http/Controllers/Api/V1/WebBackupSettingsController.php` with `show` and `update` (`WebDomain::forceFill()->save()` for the datalog `u` entry, `y`/`n` mapping for `backup_encrypt`, 422 when encryption has no stored or supplied password) (depends on T031, T036)
- [x] T039 [US2] Register in `routes/api/sites.php` inside the `scope.backup` group: `DELETE …/backups/{backup}`, `POST …/backups`, `GET …/backup-settings`, `PUT …/backup-settings` (depends on T024, T037, T038)
- [x] T040 [US2] Run T026–T029 in Docker and verify store, delete and settings, plus the 403 and `backup_copies` documentation of web domains and databases, in Swagger UI

**Checkpoint**: User Stories 1 and 2 both work independently

---

## Phase 5: User Story 3 - Download a backup (Priority: P3)

**Goal**: Queue download preparation and report where the file is placed (legacy folder delivery, FR-014).

**Independent Test**: A's key: `POST …/backups/{id}/download` → 201 job with one `backup_download` row; repeat → 409;
database backup stored on another server than the website → 422; an `ok` download job reports
`download.path = backup/<filename>` and `available_until`.

### Tests for User Story 3 (REQUIRED) ⚠️

- [x] T041 [US3] Add download cases to `tests/Feature/WebBackupActionApiTest.php`: 201 job with one `backup_download` row (param = backup id, backup's server); key with read permission only is allowed; duplicate pending → 409; backup on another server than the website → 422; server without `backup_dir` → 409
- [x] T042 [P] [US3] Add download-object cases to `tests/Feature/WebBackupJobApiTest.php`: `ok` download job whose backup exists returns `download {path: "backup/<filename>", filename, available_until = created_at + 3 days}` (research R13); pending, error, or removed backup → no download object

### Implementation for User Story 3

- [x] T043 [US3] Add the download object derivation (research R13) to `app/Services/WebBackupService.php`
- [x] T044 [US3] Add `download` (read permission only; 422 when `download_available` is false; delegates to `RemoteActionService`) to `app/Http/Controllers/Api/V1/WebBackupController.php`
- [x] T045 [US3] Register `POST sites/web-domains/{webDomain}/backups/{backup}/download` in `routes/api/sites.php` next to restore, above `…/backups/{backup}` (depends on T039, T044)
- [x] T046 [US3] Run T041–T042 in Docker (`vendor/bin/phpunit --filter 'WebBackupActionApi|WebBackupJobApi'`) and verify download in Swagger UI against `api/modules/sites/web-backups.yaml`

**Checkpoint**: All user stories are independently functional

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Documentation, boundary checks, full-suite verification, manual end-to-end check

- [ ] T047 [P] Add backups, backup jobs and backup settings to the `sites` row of the module table in `README.md`
- [ ] T048 [P] Confirm constitution boundaries by code search: nothing writes `web_backup`; `sys_remoteaction` inserts exist only in `app/Services/RemoteActionService.php`; settings writes go through `app/Models/WebDomain.php`
- [ ] T049 Re-verify legacy parity read-only on isp-test (`/usr/local/ispconfig/interface/lib/classes/plugin_backuplist.inc.php`, `interface/web/sites/form/web_vhost_domain.tform.php`, `server/plugins-available/backup_plugin.inc.php`) for the R2 action rows, R7 derived fields and R11 validation; record any difference in `specs/018-backups/research.md`
- [ ] T050 Run the full suite (`vendor/bin/phpunit`) in Docker `php:8.3-cli` and resolve every `$ref` of `api/openapi.yaml` as in `specs/018-backups/quickstart.md`
- [ ] T051 Manual end-to-end check on isp-test following the "Manual check on the test server" section of `specs/018-backups/quickstart.md` with a disposable vhost website of a temporary `limit_backup = 'y'` client: list, manual web backup → job `ok` and a new `manual` backup, duplicate → 409, restore, download → file in the website's `backup/` folder, delete, `limit_backup = 'n'` → 403, `backup_copies` 11 → 422 (owner workflow 2026-09-15: isp-test is a test server; clean up afterwards)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — contract first, always
- **Foundational (Phase 2)**: Depends on Setup — BLOCKS all user stories (fixtures, `HandlesListQuery`, models, gate middleware, `RemoteActionService`)
- **User Story 1 (Phase 3)**: Depends on Foundational
- **User Story 2 (Phase 4)**: Depends on Foundational; shares `WebBackupController.php`, `WebBackupService.php`, `WebBackupActionApiTest.php`, `BackupLimitGateTest.php` and `routes/api/sites.php` with US1, so it runs after US1
- **User Story 3 (Phase 5)**: Depends on Foundational; shares the same controller, service, test files and routes file, so it runs after US2
- **Polish (Phase 6)**: Depends on all delivered stories

### Within Each User Story

- Tests MUST be written and FAIL before implementation
- Contract YAML before controller work (Principle I)
- Service before controller; controller before routes
- `routes/api/sites.php` edits are sequential (T024 → T039 → T045) and keep specific routes above `sites/web-domains/{webDomain}`
- The `scope.backup` middleware must run after route-model binding so an unreadable website still returns 404 (T020 verifies)
- Swagger verification is the story's last task

### Parallel Opportunities

- Setup: T002, T003, T004 in parallel with T001
- Foundational: T007, T008, T009, T011, T012 in parallel; T013 → T014 → T015 and T013/T012 → T016 are sequential
- US1: tests T017–T020 in parallel; controllers T022 and T023 in parallel after T021
- US2: tests T027 and T029 in parallel (T026 and T028 extend US1 test files); requests T030, T031, T032 in parallel; T034 and T035 in parallel after T032
- US3: T042 in parallel with T041

---

## Parallel Example: User Story 1

```bash
# Tests for User Story 1 together:
Task: "Write tests/Feature/WebBackupApiTest.php (list/show/derived fields/scoping)"
Task: "Write restore cases in tests/Feature/WebBackupActionApiTest.php"
Task: "Write tests/Feature/WebBackupJobApiTest.php (attribution, state mapping)"
Task: "Write backup-endpoint cases in tests/Feature/BackupLimitGateTest.php"

# Controllers after WebBackupService (T021):
Task: "Create app/Http/Controllers/Api/V1/WebBackupController.php (index/show/restore)"
Task: "Create app/Http/Controllers/Api/V1/WebBackupJobController.php (index/show)"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup (contract valid)
2. Complete Phase 2: Foundational (fixtures, list-query extension, models, gate, remote-action service)
3. Complete Phase 3: User Story 1 (list + restore + jobs)
4. **STOP and VALIDATE**: Docker test run, Swagger UI "Try it out"; verify the exact `sys_remoteaction` row
5. Deploy/demo if ready

### Incremental Delivery

1. Setup + Foundational → foundation ready
2. US1 → list, restore, job polling (MVP for the WHMCS panel)
3. US2 → on-demand backup, delete, settings, `limit_backup` on existing endpoints, `backup_copies` tightening
4. US3 → download preparation
5. `routes/api/sites.php` ordering re-checked at every story boundary

---

## Cross-feature notes

- **Spec 015 (change status)**: when 015 is merged, `PUT /sites/web-domains/{id}/backup-settings` writes a datalog
  entry and must reference 015's `X-Change-Set-Id` response header in `api/modules/sites/web-backups.yaml`.
  The remote-action endpoints (`POST …/backups`, `POST …/restore`, `POST …/download`, `DELETE …/backups/{backup}`)
  write no datalog and return no header; 015's write-operation contract test must not require the header for them.
- **Spec 016 (server assignment)**: no overlap — backup actions target servers already recorded on the website,
  backups and databases.
- **Spec 014 (API keys)**: no overlap; backup endpoints use the existing `api.key` group.
- `CLAUDE.md` Spec Kit block conflicts between feature branches are expected and resolved at merge time.

---

## Notes

- [P] tasks = different files, no dependencies — never two edits to `routes/api/sites.php` in parallel
- [Story] label maps each task to its user story for traceability
- Settings writes go only through `WebDomain` (`BaseModel::save()`); remote actions only through `RemoteActionService` (documented Principle II exception); `web_backup` is never written
- Commit after each task or logical group
- Avoid: vague tasks, same-file conflicts, endpoints not present in the YAML spec
