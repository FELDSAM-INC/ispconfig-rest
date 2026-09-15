---

description: "Task list for spec 036 — administration and file-transfer links for scoped keys"
---

# Tasks: Administration and File-Transfer Links for Scoped Keys

**Input**: Design documents from `/specs/036-admin-links/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1207 on `760276b`).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US3 from spec.md

---

## Phase 1: Setup (contract first)

- [ ] T001 [P] Create `api/components/schemas/HostingLinks.yaml`, `DatabaseAdministrationLink.yaml`, `HostingLinkServer.yaml`, `FileTransferLink.yaml` per data-model.md; register them in `api/components/schemas/_index.yaml`
- [ ] T002 Create `api/modules/me/hosting-links.yaml` (description, `client_id` parameter, 200 example, error responses); register it in `api/modules/me/_index.yaml` and `api/openapi.yaml`
- [ ] T003 Verify the YAML parses and the spec is served (`tests/Feature/SwaggerSpecServerTest.php`)

---

## Phase 2: Tests (write first, must fail)

- [ ] T004 [US1] Create `tests/Feature/MeHostingLinksApiTest.php` database-link cases: `[SERVERNAME]` resolved per server; `[DATABASENAME]` left in place; `available` false when `dblist_phpmyadmin_link` is not `y` or the address is empty (servers still listed); server composition — assigned database servers in order, then servers hosting the client's databases, mirrors and non-database servers skipped, each server once
- [ ] T005 [US2] [P] Same class: `file_transfer` mirrors `webftp_url` verbatim, `available` false when empty
- [ ] T006 [US3] [P] Same class: neither setting configured → both parts unavailable and the call still succeeds; the response carries only `client_id`, `database_administration` and `file_transfer`; target rules (client key own account, reseller own and child, foreign 404, admin without `client_id` 422, unknown parameter 400, no key 401); nothing journaled

---

## Phase 3: Implementation

- [ ] T007 [US1] [US2] Create `app/Services/HostingLinkService.php`: read `phpmyadmin_url`, `dblist_phpmyadmin_link` and `webftp_url` via `SitesConfigService::globalConfig('sites')`; compose the account's database servers with the spec 031 rule (`assignedServerIds($scope, 'db')` then non-mirror `db_server` servers hosting the client's `web_database` rows); resolve `[SERVERNAME]` per server
- [ ] T008 [US1] [US2] Create `app/Http/Controllers/Api/V1/MeHostingLinksController.php` (invokable, `ReadsAccountQuery`, `resolveTarget`) and register `me/hosting-links` in `routes/api/me.php`

---

## Phase 4: Documentation

- [ ] T009 README: the new endpoint next to `/me/mail-settings` and `/me/hosting-addresses`, including that
      `[DATABASENAME]` stays for the consumer to substitute

---

## Phase 5: Verification

- [ ] T010 Full suite green in Docker on PHP 8.3; Pint clean on the changed files
- [ ] T011 Deploy the pushed commits to isp-test and run quickstart.md §2 with a temporary client
- [ ] T012 Clean up per quickstart.md §3 and record the results in this file
