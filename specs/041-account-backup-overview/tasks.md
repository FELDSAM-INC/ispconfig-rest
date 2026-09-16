# Tasks: Account-Wide Backup Overview

**Feature**: 041 | **Spec**: [spec.md](spec.md) | **Plan**: [plan.md](plan.md)

## Format: `[ID] [P?] [Story] Description`

- `[P]` = may run in parallel with the other `[P]` tasks of the same phase (different files, no shared state)
- `[Story]` = the user story the task serves (US1, US2) or `—` for shared work

## Path Conventions (this project)

Contract in `api/`, code in `app/`, routes in `routes/api/`, tests in `tests/Feature/` with shared fixtures
in `tests/Support/`. The OpenAPI specification is the source of truth and lands before the code.

## Phase 1: Setup

- [x] T001 — Confirm the baseline suite is green before touching anything (`php artisan test`, expect 1258 passing).

## Phase 2: Foundational (Blocking Prerequisites)

Contract first (constitution I): nothing in `app/` is written before these land.

- [x] T002 [P] — Reuse the shipped `api/components/schemas/WebBackup.yaml` for `latest[]` entries instead of defining a second representation, so field parity with the per-website list is structural (correction made 2026-09-16: a reduced schema had invented `type`/`size_bytes`/`format`, which the service does not emit).
- [x] T003 [P] — Create `api/components/schemas/AccountBackupOverview.yaml`: `web_domain_id`, `domain`, `server_id`, `backups_available`, `total`, `latest[]` → `WebBackup`.
- [x] T004 — Create `api/modules/me/backups.yaml`: `/me/backups` `get` with `client_id`, `limit`, `offset`; 200 `{data, meta}`; 400 unknown parameter, 401, 403 `feature-not-allowed` (`limit_backup`), 404 unknown/foreign client, 422 `client_id`; description states vhost-only, per-type `latest`, and that visibility equals the per-website list.
- [x] T005 — Wire the contract: `backups` entry in `api/modules/me/_index.yaml` and `/me/backups` in `api/openapi.yaml`.
- [x] T006 — Commit the contract phase (`Add the account backup overview contract`) and push.

## Phase 3: User Story 1 - Newest backup of every website in one call (Priority: P1) 🎯 MVP

### Tests for User Story 1 (REQUIRED) ⚠️

- [ ] T007 [US1] — Create `tests/Feature/MeBackupsApiTest.php` on `WebBackupApiTestCase`: key required (401); one entry per vhost website ordered by domain; newest backup per type in `latest`, newest first; website without backups → `latest: []`, `total: 0`; `total` counts visible backups.
- [ ] T008 [P] [US1] — Add to that class: `backups_available` false for a website on the server without a `backup_dir`; subdomains and alias domains absent; another tenant's website absent; a backup on a foreign server carries `download_available: false`.
- [ ] T009 [P] [US1] — Add the two promise assertions: field-by-field equality of a `latest` entry with the same row from `GET /sites/web-domains/{id}/backups` (SC-003), and a query-count assertion proving the count does not grow from 1 to 5 websites (SC-002, FR-008).
- [ ] T010 [US1] — Run the new class and confirm it fails for the right reason (route missing), not on fixture errors.

### Implementation for User Story 1

- [ ] T011 [US1] — Add page-level server-id resolution to `app/Services/WebBackupService.php` (database servers of many websites in one grouped query), keeping the per-website method as the single definition of the rule (research R4).
- [ ] T012 [US1] — Create `app/Services/AccountBackupService.php`: count + page of vhost websites under the scoped read predicate, one grouped `web_database` query, one `web_backup` query for the page, `backupsAvailable()` once per distinct server, folding newest-per-type and totals in PHP; every entry field from `backupRepresentation()`.
- [ ] T013 [US1] — Create `app/Http/Controllers/Api/V1/MeBackupsController.php` using `ReadsAccountQuery` (`client_id`, `limit`, `offset`; unknown → 400) and `AccountCapabilitiesService::resolveTarget()`; delegate to the service.
- [ ] T014 [US1] — Register `GET me/backups` in `routes/api/me.php` with the module comment naming spec 041.
- [ ] T015 [US1] — Run the class until green; run the full suite to prove nothing else moved.

## Phase 4: User Story 2 - Same plan gate as the per-website endpoints (Priority: P2)

### Tests for User Story 2 (REQUIRED) ⚠️

- [ ] T016 [US2] — Add: client key whose client has `limit_backup = 'n'` → 403 with problem type `feature-not-allowed` and `feature: limit_backup`; admin key unaffected by the flag.
- [ ] T017 [P] [US2] — Add: admin key without `client_id` → 422; with a known client → that client's overview; unknown client → 404; non-numeric/zero → 422; reseller key sees its own websites and may pass one of its clients' ids.
- [ ] T018 [P] [US2] — Add: unknown query parameter → 400; `limit`/`offset` paging with `meta.total` unchanged; a locked client still reads the overview (spec 019 leaves reads untouched).

### Implementation for User Story 2

- [ ] T019 [US2] — Apply the gate in the controller before any work, reusing `WebBackupService::backupAllowed()` and the spec 023 `feature-not-allowed` problem with `feature: limit_backup`.
- [ ] T020 [US2] — Run the class until green.

## Phase 5: Polish & Cross-Cutting Concerns

- [ ] T021 — Full suite on `php:8.3-cli` (expect 1258 + the new tests, no regressions) and Pint on every changed file.
- [ ] T022 — README: add `GET /me/backups` to the Modules section, stating it is read-only, vhost-only, plan-gated and one call per account.
- [ ] T023 — Commit the implementation phase and push.
- [ ] T024 — Deploy to isp-test (`ispconfig-rest update && ispconfig-rest status`) and confirm the deployed commit is on `origin/main`.
- [ ] T025 — Run quickstart §4 live with temporary clients: baseline, real backup, overview equality with the per-website list, plan gate, isolation, admin `client_id` rules, unknown parameter, paging.
- [ ] T026 — Cleanup per quickstart §5 and verify nothing is left (no `qa*` client, key, directory or backup file; no pending remote action).
- [ ] T027 — Record the live results in this file and commit.

## Dependencies & Execution Order

### Phase Dependencies

Phase 1 → Phase 2 (contract) → Phase 3 (US1) → Phase 4 (US2) → Phase 5. US2 depends on the controller from
US1 existing; the gate is added to it rather than duplicated.

### Within Each User Story

Tests before implementation, always. Within Phase 2, T002 and T003 are independent files; T004 references
both, T005 references T004.

### Parallel Opportunities

T002/T003; T008/T009 (different test methods in the same class, written together); T017/T018.

## Implementation Strategy

**MVP** is User Story 1: the overview answers in one call with correct per-type latest backups. User Story 2
makes its refusals match the per-website endpoints — required before release, but the MVP is already useful
to a consumer whose plan includes backups.

## Notes

- No migrations, no writes, no file system access anywhere in this feature.
- The per-website endpoints stay the reference for visibility and representation; if a test ever shows the
  two disagreeing, the overview is wrong, not the detail endpoint.
