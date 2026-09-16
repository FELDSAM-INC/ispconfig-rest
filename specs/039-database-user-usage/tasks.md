---

description: "Task list for spec 039 — database user usage and unlink safety"
---

# Tasks: Database User Usage and Unlink Safety

**Input**: Design documents from `/specs/039-database-user-usage/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1244 on `bfa2e0a`; 1252 passing after this feature).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US3 from spec.md

---

## Phase 1: Setup (contract first)

- [x] T001 [P] Add the read-only `databases_in_use` property to `api/components/schemas/DatabaseUser.yaml`
- [x] T002 [P] Correct the DELETE description in `api/modules/sites/database-users.yaml` (it stated the opposite of the enforced behaviour) and add the `'409'` response
- [x] T003 [P] Document `resource-in-use` in `docs/problems.md` (status 409, meaning, no extension members) — and in `api/components/schemas/Problem.yaml`, which `ProblemTypeRenderingTest` also pins
- [x] T004 Verify the YAML parses and the spec is served (`tests/Feature/SwaggerSpecServerTest.php`)

---

## Phase 2: Tests (write first, must fail)

- [x] T005 [US1] Create `tests/Feature/WebDatabaseUserUsageTest.php`: a user referenced as `database_user_id` cannot be deleted (409, `type` = `resource-in-use`, legacy detail, no `sys_datalog` row, both rows survive); the same for `database_ro_user_id`; after the database is deleted the user deletes with 204; an unreferenced user always deletes with 204
- [x] T006 [US2] [P] Same class: `databases_in_use` on the single resource and on the list (owner and read-only references counted, a row naming the user twice counted once, 0 when unused); the list computes the counts without a query per row
- [x] T007 [US3] [P] Same class: an administrator key is refused exactly like a client key, and the count follows each key's read scope

---

## Phase 3: Implementation

- [x] T008 [US2] Create `app/Services/WebDatabaseUserUsageService.php`: scoped counts for one user and for a page of ids, in a single query over both credential columns, tallied so a row naming the user twice counts once
- [x] T009 [US1] [US3] Add `app/Exceptions/ProblemConflictException.php` (typed 409), register `RESOURCE_IN_USE` in `app/Support/ProblemType.php`, and render it in `app/Support/Problem.php`
- [x] T010 [US1] [US3] Guard `WebDatabaseUserController::destroy()` with the usage service before `delete()`
- [x] T011 [US2] Attach `databases_in_use` to the single and list representations (filled by the controller, exposed through `$appends`, so the accessor never queries)

---

## Phase 4: Verification

- [x] T012 Full suite green in Docker on PHP 8.3 (1252 passing, 10197 assertions); Pint clean on the 7 changed files
- [x] T013 Deploy the pushed commits to isp-test and run quickstart.md §2 with a temporary client — deployed `3e2ef1d`, every check matched (quickstart.md §4)
- [x] T014 Clean up per quickstart.md §3 and record the results in this file — no leftovers, QA keys 110–112 removed

---

## Implementation notes

- **The list guard caught my own test, not the implementation.** `test_usage_count_on_the_list_without_a_query_per_row`
  first counted two statements: the real usage query and `Schema::hasTable('web_database')`'s sqlite schema probe,
  whose SQL merely mentions the table name. The filter now counts statements that actually read the table, so the
  guard still fails if anyone reintroduces a per-row query. The implementation always issued exactly one data query
  per page.
- **The new problem type is pinned in three places.** `ProblemTypeRenderingTest` asserts that `ProblemType::NAMES`
  matches the `##` headings of `docs/problems.md` *and* that every name appears in
  `api/components/schemas/Problem.yaml`; adding the type to only the first two would have failed the suite.
- **The count is filled by the controller, never by the model.** `databases_in_use` is an appended attribute backed
  by a public property that `show()` sets for one user and `index()` sets for the whole page from one grouped query —
  an accessor that counted by itself would have reintroduced the per-row query the plan forbids.
- **The contract said the opposite of legacy.** The previous DELETE description told integrations that referencing
  databases "lose their credentials — reassign them first", which is how a working database could be broken by
  following the documentation. It now states the refusal.
