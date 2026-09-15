---

description: "Task list for spec 021 — account capabilities for scoped keys"
---

# Tasks: Account Capabilities for Scoped Keys

**Input**: Design documents from `/specs/021-account-capabilities/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 999 passing).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1, US2 from spec.md

---

## Phase 1: Setup (contract first)

- [x] T001 [P] Create `api/components/schemas/AccountCapabilities.yaml` and `api/components/schemas/AccountPhpVersion.yaml` per data-model.md and register both in `api/components/schemas/_index.yaml`
- [x] T002 Create `api/modules/me/capabilities.yaml` and `api/modules/me/php-versions.yaml` per `specs/021-account-capabilities/contracts/me-endpoints.md`, register them in `api/modules/me/_index.yaml` and the paths in `api/openapi.yaml`
- [x] T003 Verify the YAML parses and the spec is served (`tests/Feature/SwaggerSpecServerTest.php`)

---

## Phase 2: Foundational (blocking prerequisites)

- [x] T004 Add a failing nginx test to `tests/Feature/WebPhpScopedKeyTest.php` (nginx web server: a `fast-cgi` website accepts an FPM-only version and refuses a FastCGI-only one), then map `fast-cgi` to the FPM columns on nginx servers in `PhpVersionService::usable()` (`app/Services/PhpVersionService.php`)
- [x] T005 Create `app/Services/AccountCapabilitiesService.php` with `resolveTarget(AuthScope, ?int)` (delegates to `UsageService::resolveTargetClient()`) and `accountWebServers(int $clientId)` (assigned web servers, then non-mirror web servers hosting the client's websites by id)
- [x] T006 [P] Ensure `tests/Support/TenantSchema.php` has `client.locked` and `client.canceled` (add with default `'n'` if missing)

**Checkpoint**: full suite green

---

## Phase 3: User Story 1 — Panel reads the plan's website capabilities (P1) 🎯 MVP

### Tests (write first, must fail)

- [x] T007 [US1] Create `tests/Feature/MeCapabilitiesApiTest.php`: flag mapping, `php_modes` and `php_default_mode` (system ∩ client, empty system list), `locked`/`canceled`, `account_type`; reseller own / own client / other client 404; admin without `client_id` 422, with client 200, unknown 404; client key naming another client 404; unknown parameter 400; invalid `client_id` 422; no datalog; pairing with feature 020 writes (reported `ssl=false` → `PUT ssl=true` refused, `ssl=true` → accepted; listed PHP mode accepted, unlisted refused)

### Implementation

- [x] T008 [US1] Implement `AccountCapabilitiesService::capabilities(int $clientId)` from `WebPermissionService::forClient()` and `defaultPhpMode()` in `app/Services/AccountCapabilitiesService.php`
- [x] T009 [US1] Create `app/Http/Controllers/Api/V1/MeCapabilitiesController.php` (query validation as `UsageSummaryController`) and route `me/capabilities` in `routes/api/me.php`

**Checkpoint**: US1 tests green

---

## Phase 4: User Story 2 — Panel lists the PHP versions a website may use (P1)

### Tests (write first, must fail)

- [x] T010 [US2] Create `tests/Feature/MePhpVersionsApiTest.php`: order and `modes` per version, `mode` filter, own/other private versions, inactive and binary-less versions excluded, default entry first when not hidden and absent when hidden (name from `php_default_name`), plan without version modes → empty, `server_id` outside the account → 422, invalid `server_id`/`mode` → 422, no `server_id` → assigned servers then hosting servers, admin `client_id` rules, `limit`/`offset` meta and 400 on bad values, unknown parameter 400, entries have exactly `id`, `name`, `server_id`, `modes`, `is_default`; pairing: every listed version accepted by `PUT /sites/web-domains/{id}` with that mode, an unlisted one refused

### Implementation

- [x] T011 [US2] Implement `AccountCapabilitiesService::phpVersions(int $clientId, ?int $serverId, ?string $mode)` in `app/Services/AccountCapabilitiesService.php`
- [x] T012 [US2] Create `app/Http/Controllers/Api/V1/MePhpVersionsController.php` (parameters, `HandlesListQuery` limit/offset, slicing) and route `me/php-versions` in `routes/api/me.php`

**Checkpoint**: all stories green

---

## Phase 5: Polish

- [x] T013 [P] Document `GET /me/capabilities` and `GET /me/php-versions` in `README.md`
- [ ] T014 Run Pint on changed PHP files and the full suite in Docker
- [ ] T015 Deploy to isp-test and run `specs/021-account-capabilities/quickstart.md` §2 with a temporary client; record results here

## Dependencies

Phase 1 → Phase 2 → US1 → US2 (shares `AccountCapabilitiesService`) → Polish. T001/T002 and T005/T006 parallel.
