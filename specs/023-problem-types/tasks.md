---

description: "Task list for spec 023 — machine-readable problem types"
---

# Tasks: Machine-Readable Problem Types

**Input**: Design documents from `/specs/023-problem-types/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1048 passing).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1, US2, US3 from spec.md

---

## Phase 1: Setup (contract first)

- [x] T001 [P] Create `api/components/schemas/ProblemLimit.yaml` and `api/components/schemas/ForbiddenProblem.yaml`, add `error_types` to `api/components/schemas/ValidationProblem.yaml`, document type names in `api/components/schemas/Problem.yaml`, register new schemas in `api/components/schemas/_index.yaml`
- [x] T002 Update `api/components/responses/Forbidden.yaml` (schema `ForbiddenProblem`, examples) and `api/components/responses/UnprocessableEntity.yaml` (examples with/without `error_types`)
- [x] T003 [P] Create `docs/problems.md` with one section per type name
- [x] T004 Verify every `$ref` resolves and the served spec parses (`tests/Feature/SwaggerSpecServerTest.php`)

---

## Phase 2: Foundational (blocking prerequisites)

- [x] T005 Create `tests/Feature/ProblemTypeRenderingTest.php`: typed `ProblemAuthorizationException` renders 403 with type URI, extension members, unchanged title/detail; plain `AuthorizationException` keeps `about:blank`; `ValidationException` renders `validation-failed`; collector tags produce `error_types` only for fields present in `errors`; `ProblemType::uri()` matches the `docs/problems.md` headings
- [x] T006 Implement `app/Support/ProblemType.php`, `app/Support/ProblemTypeCollector.php` (scoped binding in `app/Providers/AppServiceProvider.php`), `app/Exceptions/ProblemAuthorizationException.php` and rendering in `app/Support/Problem.php`

**Checkpoint**: full suite green

---

## Phase 3: User Story 1 — account locked (P1) 🎯 MVP

- [ ] T007 [US1] Assert `type = …#account-locked` in `assertRefused()` of `tests/Feature/LockedClientWriteGuardTest.php` and `tests/Feature/WebBackupLockedClientTest.php`
- [ ] T008 [US1] Throw typed exceptions in `app/Services/LockedClientGuard.php` (`check()`, `checkBackupWrite()`)

---

## Phase 4: User Story 2 — limits (P1)

- [ ] T009 [US2] Assert `limit-reached` + `limit` in `tests/Feature/ClientLimitSitesTest.php` (client website cap) and `tests/Feature/ClientLimitResellerTest.php` (reseller scope); assert `quota-exceeded` + `limit` (client and reseller, unlimited request → `requested = null`) in `tests/Feature/ClientQuotaSumTest.php`
- [ ] T010 [US2] Pass limit values through `deny()` and throw typed exceptions in `app/Services/ClientLimitService.php`

---

## Phase 5: User Story 3 — plan features and servers (P2)

- [ ] T011 [US3] Assert `feature-not-allowed` + `feature` in `tests/Feature/BackupLimitGateTest.php` (endpoint gate and `backup_*` fields), `tests/Feature/WebAdminOptionsScopedKeyTest.php` (certificate operations, options and SSL tab `error_types`), the `scope.limit` gate test in `tests/Feature/ScopingMailModuleTest.php`; `error_types` in `tests/Feature/WebPlanFlagsScopedKeyTest.php` and `tests/Feature/WebPhpScopedKeyTest.php`; `server-not-assigned` in `tests/Feature/ClientServerAssignmentTest.php` and `tests/Feature/ClientServerAssignmentWritesTest.php`; ordinary 422 without `error_types`; identity-field errors untyped
- [ ] T012 [US3] Type `RequireClientLimit`, `RequireBackupAccess`, `EnforcesBackupLimit`, `WebPermissionService::assertCertificateOperation()`; add `WebPermissionService::typedViolations()` and tag in `EnforcesWebPermissions`; tag in `ResolvesAssignedServer`

---

## Phase 6: Polish

- [ ] T013 [P] README "Problem types" section
- [ ] T014 Pint on changed PHP files; full suite green
- [ ] T015 Deploy to isp-test and run quickstart.md §2–§3 (temporary client only), record results here

---

## Dependencies & Execution Order

- Phase 1 → Phase 2 → US1, US2, US3 (independent emitters, sequential commits) → Polish.
