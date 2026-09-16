---

description: "Task list for spec 036 — administration and file-transfer links for scoped keys"
---

# Tasks: Administration and File-Transfer Links for Scoped Keys

**Input**: Design documents from `/specs/036-admin-links/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1207 on `760276b`; 1230 passing after this feature).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US3 from spec.md

---

## Phase 1: Setup (contract first)

- [x] T001 [P] Create `api/components/schemas/HostingLinks.yaml`, `DatabaseAdministrationLink.yaml`, `HostingLinkServer.yaml`, `FileTransferLink.yaml` per data-model.md; register them in `api/components/schemas/_index.yaml`
- [x] T002 Create `api/modules/me/hosting-links.yaml` (description, `client_id` parameter, 200 example, error responses); register it in `api/modules/me/_index.yaml` and `api/openapi.yaml`
- [x] T003 Verify the YAML parses and the spec is served (`tests/Feature/SwaggerSpecServerTest.php`)

---

## Phase 2: Tests (write first, must fail)

- [x] T004 [US1] Create `tests/Feature/MeHostingLinksApiTest.php` database-link cases: `[SERVERNAME]` resolved per server; `[DATABASENAME]` left in place; `available` false when `dblist_phpmyadmin_link` is not `y` or the address is empty (servers still listed); server composition — assigned database servers in order, then servers hosting the client's databases, mirrors, missing ids, non-database servers and another client's server skipped
- [x] T005 [US2] [P] Same class: `file_transfer` mirrors `webftp_url` verbatim (a `[SERVERNAME]` in it stays), `available` false when empty
- [x] T006 [US3] [P] Same class: neither setting configured → both parts unavailable and the call still succeeds; the response carries only `client_id`, `database_administration` and `file_transfer` and no other `[sites]` value; an account without database servers; target rules (client key own account, reseller own and child, foreign 404, admin without `client_id` 422, unknown parameter 400, no key 401); nothing journaled

---

## Phase 3: Implementation

- [x] T007 [US1] [US2] Create `app/Services/HostingLinkService.php`: read `phpmyadmin_url`, `dblist_phpmyadmin_link` and `webftp_url` via `SitesConfigService::globalConfig('sites')`; compose the account's database servers with the spec 031 rule (`assignedServerIds($scope, 'db')` then non-mirror `db_server` servers hosting the client's `web_database` rows); resolve `[SERVERNAME]` per server
- [x] T008 [US1] [US2] Create `app/Http/Controllers/Api/V1/MeHostingLinksController.php` (invokable, `ReadsAccountQuery`, `resolveTarget`) and register `me/hosting-links` in `routes/api/me.php`

---

## Phase 4: Documentation

- [x] T009 README: the new endpoint next to `/me/mail-settings` and `/me/hosting-addresses`, including that `[DATABASENAME]` stays for the consumer to substitute

---

## Phase 5: Verification

- [x] T010 Full suite green in Docker on PHP 8.3 (1230 passing, 10078 assertions); Pint clean on the 4 changed files
- [x] T011 Deploy the pushed commits to isp-test and run quickstart.md §2 with a temporary client — deployed `1e7e257`, all checks matched (quickstart.md §4)
- [x] T012 Clean up per quickstart.md §3 and record the results in this file — no leftovers, QA keys 89, 90, 92 and 93 removed

---

## Implementation notes

- **The optional step 6 of the quickstart was not run live.** Setting `webftp_url` means writing the installation's
  system configuration on a server another session was using at the same time; the automated tests cover both states
  of that setting, so the live run kept the server's configuration untouched (owner-delegated decision 2026-09-16).
- **Observed while scripting the live check** (not a finding of this feature): `POST /sites/database-users` refuses a
  one-character user name with 422. The check used a longer name; the behaviour belongs to the database-user
  endpoint, not to these links.
