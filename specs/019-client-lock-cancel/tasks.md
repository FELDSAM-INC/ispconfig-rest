---

description: "Task list for spec 019 — client lock and cancel side effects"
---

# Tasks: Client Lock and Cancel Side Effects

**Input**: Design documents from `/specs/019-client-lock-cancel/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 761 passing).

**Organization**: grouped by user story. FR-013 (locked-client write guard, owner decision 24) belongs to US1
(suspension must hold) and has its own phase.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1, US2, US3 from spec.md

---

## Phase 1: Setup (contract first)

**Purpose**: document the behavior in the OpenAPI contract before any PHP (constitution Principle I)

- [x] T001 Update the `locked` and `canceled` descriptions in `api/components/schemas/Client.yaml` per `specs/019-client-lock-cancel/contracts/client-contract-changes.md`
- [x] T002 [P] Update the POST `/clients` and PUT `/clients/{id}` descriptions in `api/modules/client/clients.yaml`
- [x] T003 [P] Update the POST `/resellers` and PUT `/resellers/{id}` descriptions in `api/modules/client/resellers.yaml`
- [x] T004 Verify the contract still parses and is served by running `tests/Feature/SwaggerSpecServerTest.php`

---

## Phase 2: Foundational (blocking prerequisites)

**Purpose**: shared lock table definition, snapshot codec and test schema columns used by every story

- [x] T005 Ensure `client.locked`, `client.canceled`, `client.tmp_data` and `sys_user.active` exist in the composable test schema (`ensureColumns` / create branches) in `tests/Support/TenantSchema.php`
- [x] T006 Create `app/Services/ClientLockService.php` with the legacy lock table list (ordered, incl. the `mail_user_smtp` reversed pseudo entry), lock-column metadata accessor (table → columns → disabled/enabled values) and snapshot read/write helpers (`''`/`null`/non-array → `[]`, `unserialize` with `allowed_classes => false`, plain `UPDATE client SET tmp_data`) per `specs/019-client-lock-cancel/contracts/lock-snapshot.md`
- [x] T007 Add control-panel identity resolution (`sys_user.userid` and `sys_group.groupid` by `client_id`, first rows, nullable) to `app/Services/ClientLockService.php`

**Checkpoint**: full suite still 761 green

---

## Phase 3: User Story 1 — Suspend and resume a client with lock (Priority: P1) 🎯 MVP

**Goal**: `PUT /clients/{id}` with a changed `locked` disables / restores every lock-managed record through the datalog with a legacy-compatible snapshot

**Independent Test**: seeded client with the spec's record matrix; lock → exact datalog rows + snapshot bytes; unlock → previous states restored

### Tests for User Story 1 (write first, must fail)

- [x] T008 [US1] Create `tests/Feature/ClientLockApiTest.php` (ClientApiTestCase + `SitesSchema` + `MailCompletionSchema`) with lock tests: one record per lock-list table owned by the client's group (an active and a disabled website, a mailbox with SMTP enabled, a mailbox with `disablesmtp = y`), assert datalog `u` rows (`dbidx`, new column value, `sys_userid` = client's control-panel user), no row for already-disabled columns with unchanged owner, one `session_id`, and `client.tmp_data` equal to `serialize()` of the expected legacy array (all table keys present, string owner ids)
- [x] T009 [US1] Add unlock tests to `tests/Feature/ClientLockApiTest.php`: restore matrix (previously active enabled, previously disabled stays `n`, SMTP-disabled mailbox keeps `disablesmtp = y`), `prev_active` removed while `prev_sys_userid` and unrelated keys stay, unlock of a hand-built legacy snapshot, unreadable/empty snapshot enables everything
- [x] T010 [US1] Add no-op and edge tests to `tests/Feature/ClientLockApiTest.php`: `locked` omitted or unchanged writes no extra datalog rows, `locked: true` on `POST /clients` stores the flag only, client without `sys_group`/`sys_user` rows still returns 200, records of other clients untouched

### Implementation for User Story 1

- [x] T011 [US1] Implement `lock(int $clientId, int $lockUserId)` in `app/Services/ClientLockService.php` (legacy order, `DatalogService::updateRecord` per record ordered by key, snapshot `prev_active` / `prev_sys_userid`, skip absent tables but keep their empty keys)
- [x] T012 [US1] Implement `unlock(int $clientId, int $unlockUserId)` in `app/Services/ClientLockService.php` (restore rule, owner rewrite, remove `prev_active`, keep other keys)
- [x] T013 [US1] In `app/Services/ClientService.php` `updateClient()`: re-read `locked`/`canceled` with `lockForUpdate()`, compare with the filled raw values, call lock/unlock after the sys_user/sys_group sync; replace the "lock/cancel record snapshots … NOT ported" docblock note
- [x] T014 [US1] Run `ClientLockApiTest` and the full suite; `vendor/bin/pint` on `app/Services/ClientLockService.php` and `app/Services/ClientService.php`

**Checkpoint**: US1 lock/unlock works; suite green

---

## Phase 4: User Story 1 (continued) — Locked-client write guard (FR-013)

**Goal**: while a client is locked, client and reseller keys cannot re-enable or add its lock-managed records (403, no datalog); admin keys unaffected

**Independent Test**: TenantFixtures (admin / reseller / clientA under reseller / clientB) with clientA locked; enable and create attempts per key

### Tests (write first, must fail)

- [x] T015 [US1] Create `tests/Feature/LockedClientWriteGuardTest.php` (`SitesSchema` + `MailCompletionSchema` + `TenantSchema`, TenantFixtures, servers assigned per spec 016): with clientA locked, clientA and reseller keys get 403 problem+json and zero datalog rows for `PUT /sites/web-domains/{id}` `active: true`, `PUT /mail/users/{id}` enabling receive or send, `PUT /sites/cron-jobs/{id}` `active: true`, `POST /mail/domains` and `POST /sites/web-domains` owned by clientA; allowed cases: an unrelated field update of a locked record, disabling a record, the same writes for unlocked clientB, and every write with the admin key

### Implementation

- [x] T016 [US1] Create `app/Services/LockedClientGuard.php` (`check(BaseModel $model, array $original, bool $isCreate)`: admin / no sys fields / non-lock table short-circuit, owner client via `sys_group.client_id` + `client.locked`, disabled→enabled detection per lock column from `ClientLockService` metadata, throw `AuthorizationException`)
- [x] T017 [US1] Invoke the guard in `app/Models/BaseModel.php` `save()` — create: after the spec 012 limit checks; update: right after the spec 011 write gate — before any DB write
- [x] T018 [US1] Call the guard explicitly in `app/Services/WebDomainService.php` `create()` before `DB::table('web_domain')->insertGetId()` (raw insert bypasses `BaseModel::save()`, like the existing limit checks)
- [x] T019 [US1] Re-audit create/update paths of lock-list tables that bypass `BaseModel::save()` (`DB::table(...)->insert*`/`update`, `DatalogService::insertRecord/updateRecord`) in `app/Services/` and `app/Http/Controllers/`; guard any new finding and record it in `specs/019-client-lock-cancel/research.md` R7
- [x] T020 [US1] Run `LockedClientWriteGuardTest`, the spec 011/012/016 scoping and limit tests, and the full suite; pint on `app/Services/LockedClientGuard.php`, `app/Models/BaseModel.php`, `app/Services/WebDomainService.php`

**Checkpoint**: suspension holds against non-admin keys; suite green

---

## Phase 5: User Story 2 — Block and allow control-panel login with cancel (Priority: P2)

**Goal**: `canceled` toggles `sys_user.active`, including on create; API keys unaffected

**Independent Test**: create with `canceled: true` → `active = 0`; toggles; keys still authenticate

### Tests (write first, must fail)

- [x] T021 [US2] Create `tests/Feature/ClientCancelApiTest.php`: `POST /clients` with `canceled: true` → `sys_user.active = 0` (default create → 1), `PUT canceled` toggles 0/1, unchanged value no-op, cancel while locked changes only the login flag, no `sys_user` datalog rows, and a client-scoped key of a canceled and locked client still gets 200 on `GET /me`

### Implementation

- [x] T022 [US2] Implement `setLoginActive(int $clientId, bool $active)` in `app/Services/ClientLockService.php` (plain `UPDATE sys_user SET active` by `client_id`)
- [x] T023 [US2] In `app/Services/ClientService.php`: call `setLoginActive()` on a `canceled` change in `updateClient()` (after lock/unlock) and create the control-panel user with `active` = `canceled ? 0 : 1` in `createSysUser()`
- [x] T024 [US2] Run `ClientCancelApiTest` and the full suite; pint on changed files

**Checkpoint**: US1 and US2 independently functional

---

## Phase 6: User Story 3 — Reseller parity (Priority: P3)

**Goal**: `/resellers/{id}` lock/cancel affects only the reseller's own records and login, with legacy `sys_userid` attribution

**Independent Test**: reseller R with an own website and client C (under R) with a website

### Tests (write first, must fail)

- [x] T025 [US3] Create `tests/Feature/ResellerLockApiTest.php` (ClientApiTestCase + `SitesSchema`): `PUT /resellers/{R}` `locked: true` disables only R's group records with datalog `sys_userid` = acting key's user (admin 1), unlock writes R's control-panel user, C untouched; `canceled` toggles only R's `sys_user.active`; `POST /resellers` with `canceled: true` creates the login inactive

### Implementation

- [x] T026 [US3] In `app/Services/ClientService.php` `updateClient()`: pass lock user = `IspContext::sysUserId()` and unlock user = the reseller's control-panel user when the model is a `ClientReseller`, the client's control-panel user otherwise
- [x] T027 [US3] Run `ResellerLockApiTest` and the full suite; pint on changed files

**Checkpoint**: all user stories functional

---

## Phase 7: Polish & Cross-Cutting Concerns

- [x] T028 [P] Document client lock, cancel, cancel on create, change-only side effects and the locked-client write guard in `README.md` (module section and "Known deviations from legacy ISPConfig")
- [x] T029 Run pint on all changed PHP files and the full suite in Docker; confirm the suite count grew from 761 with zero failures (`specs/019-client-lock-cancel/quickstart.md` §1)
- [ ] T030 Deploy to isp-test (`ispconfig-rest update`) and run the manual check with a temporary client from `specs/019-client-lock-cancel/quickstart.md` §2, including cleanup

---

## Dependencies & Execution Order

- **Setup (T001–T004)** → **Foundational (T005–T007)** → stories.
- **US1 lock/unlock (T008–T014)** is the MVP; **guard (T015–T020)** depends on the lock column metadata from T006 and on T013 (locked state set through the API in tests is optional — tests may seed `client.locked = 'y'` directly).
- **US2 (T021–T024)** depends only on Foundational; T023 edits `ClientService.php` after T013 (same file → sequential).
- **US3 (T025–T027)** depends on T011–T013 (reuses lock/unlock) and T023 (reseller create cancel path).
- **Polish (T028–T030)** after all stories; T030 needs everything pushed.
- Within each phase: tests before implementation; `ClientService.php` tasks (T013, T023, T026) are sequential; `ClientLockService.php` tasks (T006, T007, T011, T012, T022) are sequential.

## Parallel Opportunities

```text
T002 + T003                      (different contract files)
T008 (US1 tests) + T021 (US2 tests) + T025 (US3 tests) — different test files, once Foundational is done
T015 (guard tests) alongside T011/T012 (service implementation)
T028 README alongside T029
```

## Implementation Strategy

1. Setup + Foundational.
2. US1 lock/unlock → validate (MVP: suspension works).
3. US1 guard → validate (suspension holds for non-admin keys) — required before the WHMCS module relies on locks.
4. US2 cancel → validate (panel login blocking, cancel on create).
5. US3 reseller parity → validate.
6. Polish, deploy to isp-test, manual check.
