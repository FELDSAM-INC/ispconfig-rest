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

- [x] T007 [US1] Assert `type = …#account-locked` in `assertRefused()` of `tests/Feature/LockedClientWriteGuardTest.php` and `tests/Feature/WebBackupLockedClientTest.php`
- [x] T008 [US1] Throw typed exceptions in `app/Services/LockedClientGuard.php` (`check()`, `checkBackupWrite()`)

---

## Phase 4: User Story 2 — limits (P1)

- [x] T009 [US2] Assert `limit-reached` + `limit` in `tests/Feature/ClientLimitSitesTest.php` (client website cap) and `tests/Feature/ClientLimitResellerTest.php` (reseller scope); assert `quota-exceeded` + `limit` (client and reseller, unlimited request → `requested = null`) in `tests/Feature/ClientQuotaSumTest.php`
- [x] T010 [US2] Pass limit values through `deny()` and throw typed exceptions in `app/Services/ClientLimitService.php`

---

## Phase 5: User Story 3 — plan features and servers (P2)

- [x] T011 [US3] Assert `feature-not-allowed` + `feature` in `tests/Feature/BackupLimitGateTest.php` (endpoint gate and `backup_*` fields), `tests/Feature/WebAdminOptionsScopedKeyTest.php` (certificate operations, options and SSL tab `error_types`), the `scope.limit` gate test in `tests/Feature/ScopingMailModuleTest.php`; `error_types` in `tests/Feature/WebPlanFlagsScopedKeyTest.php` and `tests/Feature/WebPhpScopedKeyTest.php`; `server-not-assigned` in `tests/Feature/ClientServerAssignmentTest.php` and `tests/Feature/ClientServerAssignmentWritesTest.php`; ordinary 422 without `error_types`; identity-field errors untyped
- [x] T012 [US3] Type `RequireClientLimit`, `RequireBackupAccess`, `EnforcesBackupLimit`, `WebPermissionService::assertCertificateOperation()`; add `WebPermissionService::typedViolations()` and tag in `EnforcesWebPermissions`; tag in `ResolvesAssignedServer`

---

## Phase 6: Polish

- [x] T013 [P] README "Problem types" section
- [x] T014 Pint on changed PHP files; full suite green
- [x] T015 Deploy to isp-test and run quickstart.md §2–§3 (temporary client only), record results here

---

## Dependencies & Execution Order

- Phase 1 → Phase 2 → US1, US2, US3 (independent emitters, sequential commits) → Polish.

---

## T015 results — isp-test, 2026-09-15

Deployed `d56375b` (`ispconfig-rest update`, status healthy). Temporary client 17 `qa023f2c7a1`
(`limit_web_domain = 1`, `limit_ssl = y`, `limit_ssl_letsencrypt = n`, web server 1), QA admin key 45, client key 46,
website 18. `#` = `https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#`.

| Check | Status | Type / members |
|-------|--------|----------------|
| Client: create website 1 | 201 | — |
| Client: create website 2 (cap 1) | 403 | `#limit-reached`, `limit = {limit_web_domain, client, 1, 1}`, detail unchanged |
| Client: `PUT` `ssl` + `ssl_letsencrypt` | 422 | `#validation-failed`, `error_types = {ssl_letsencrypt: #feature-not-allowed}` |
| Client: create website `server_id: 99` | 422 | `error_types.server_id = #server-not-assigned` |
| Client: `POST …/ssl/renew` | 403 | `#feature-not-allowed`, `feature = limit_ssl_letsencrypt` |
| Client: `POST /mail/transports` | 403 | `#feature-not-allowed`, `feature = limit_mailrouting` |
| Client: invalid domain | 422 | `#validation-failed`, no `error_types` |
| Client: missing website | 404 | `about:blank` |
| Admin: disable website, lock client | 200, 200 | — |
| Client: re-enable website of the locked account | 403 | `#account-locked`, detail unchanged |
| Admin: unlock client | 200 | — |

Every error response was `application/problem+json`; all 17 expectations matched.

**Cleanup**: client unlocked, website 18 deleted (204), client 17 deleted (204); `server.updated` reached the last
datalog id 526; QA keys 45 and 46 deleted by id (`name LIKE 'qa%'`). No `qa023` client, group, user or website row, no
pending datalog, no vhost directory or link, no `/var/www/clients/client17`; remaining keys 1, 2, 20, 27. QA scripts and
state removed from the server.
