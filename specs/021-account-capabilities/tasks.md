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
- [x] T014 Run Pint on changed PHP files and the full suite in Docker
- [x] T015 Deploy to isp-test and run `specs/021-account-capabilities/quickstart.md` §2 with a temporary client; record results here

## Dependencies

Phase 1 → Phase 2 → US1 → US2 (shares `AccountCapabilitiesService`) → Polish. T001/T002 and T005/T006 parallel.

## Results (T014–T015, 2026-09-15)

- T014: Pint clean on changed files; full suite 1019 passed (baseline 999).
- T015: deployed `fcc1176` to isp-test (`ispconfig-rest status`: 1.0.0-rc.3 (fcc1176)). Temporary client
  `qa0213f072e` (client 12: `limit_ssl=y`, `limit_ssl_letsencrypt=n`, `limit_wildcard=n`, `force_suexec=y`,
  `web_php_options=no,php-fpm`, `web_servers=1`), temporary QA admin key 36 and client key 37. All 18 checks matched:

| Case | Expected | Got |
|---|---|---|
| client `GET /me/capabilities` | 200, `ssl` true, `ssl_letsencrypt`/`wildcard` false, `suexec_forced` true, `php_modes` `["no","php-fpm"]`, default `php-fpm`, not locked | 200, as expected |
| client `?client_id=999999` / `?server_id=1` | 404 / 400 | 404 / 400 |
| client `GET /me/php-versions?server_id=1` | 200, versions 1–5 `modes=php-fpm`, no id 0 (server hides default) | 200, as expected |
| `?server_id=1&mode=fast-cgi` (mode not in plan) | 200, empty | 200, total 0 |
| `?server_id=999` / `?mode=hhvm` | 422 `server_id` / 422 `mode` | 422 / 422 |
| client `POST /sites/web-domains` with first listed version (1) | 201 | 201 |
| admin `GET /me/capabilities` without / with `client_id=12` | 422 / 200 identical to the client view | 422 / 200, identical |
| admin `GET /servers/1/php-versions/5`, `POST` private version for client 12 | 200 / 201 | 200 / 201 (id 6) |
| client list after the private version | id 6 listed | listed |
| client `PUT` website `server_php_id=6` | 200 | 200 |
| admin `PUT /clients/12 locked=true`, client capabilities | 200, `locked` true | 200, `locked=true` |

- Cleanup: website 12, PHP version 6 and client 12 deleted with the QA admin key (204); datalog processed
  (`server.updated` 458); API keys 36, 37 deleted by SQL (`name LIKE 'qa%'`); no `qa021` client, sys_group, sys_user,
  web_domain, server_php, api_keys rows, `/var/www` symlinks or client directory remain. Remaining keys: 1, 2, 20, 27.
