---

description: "Task list for spec 039 — database user usage and unlink safety"
---

# Tasks: Database User Usage and Unlink Safety

**Input**: Design documents from `/specs/039-database-user-usage/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1244 on `bfa2e0a`).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US3 from spec.md

---

## Phase 1: Setup (contract first)

- [ ] T001 [P] Add the read-only `databases_in_use` property to `api/components/schemas/DatabaseUser.yaml`
- [ ] T002 [P] Correct the DELETE description in `api/modules/sites/database-users.yaml` (it currently states the opposite of the enforced behaviour) and add the `'409'` response
- [ ] T003 [P] Document `resource-in-use` in `docs/problems.md` (status 409, meaning, no extension members)
- [ ] T004 Verify the YAML parses and the spec is served (`tests/Feature/SwaggerSpecServerTest.php`)

---

## Phase 2: Tests (write first, must fail)

- [ ] T005 [US1] Create `tests/Feature/WebDatabaseUserUsageTest.php`: a user referenced as `database_user_id` cannot be deleted (409, `type` = `resource-in-use`, legacy detail, no `sys_datalog` row, both rows survive); the same for `database_ro_user_id`; after the database is deleted the user deletes with 204; an unreferenced user always deletes with 204
- [ ] T006 [US2] [P] Same class: `databases_in_use` on the single resource and on the list (owner and read-only references counted, a row naming the user twice counted once, 0 when unused); the list computes the counts without a query per row
- [ ] T007 [US3] [P] Same class: an administrator key is refused exactly like a client key, and its count includes every database it may read; a client key's count and refusal follow its own read scope

---

## Phase 3: Implementation

- [ ] T008 [US2] Create `app/Services/WebDatabaseUserUsageService.php`: scoped counts for one user and for a page of ids, in a single grouped query over both credential columns
- [ ] T009 [US1] [US3] Add `app/Exceptions/ProblemConflictException.php` (typed 409, mirroring `ProblemAuthorizationException`), register `RESOURCE_IN_USE` in `app/Support/ProblemType.php`, and render it in `app/Support/Problem.php`
- [ ] T010 [US1] [US3] Guard `WebDatabaseUserController::destroy()` with the usage service before `delete()`
- [ ] T011 [US2] Attach `databases_in_use` to the single and list representations of the database user resource

---

## Phase 4: Verification

- [ ] T012 Full suite green in Docker on PHP 8.3; Pint clean on the changed files
- [ ] T013 Deploy the pushed commits to isp-test and run quickstart.md §2 with a temporary client
- [ ] T014 Clean up per quickstart.md §3 and record the results in this file
