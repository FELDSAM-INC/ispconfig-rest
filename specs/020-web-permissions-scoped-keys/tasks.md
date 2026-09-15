---

description: "Task list for spec 020 — web permission enforcement for scoped keys"
---

# Tasks: Web Permission Enforcement for Scoped Keys

**Input**: Design documents from `/specs/020-web-permissions-scoped-keys/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 971 passing).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1, US2, US3 from spec.md

---

## Phase 1: Setup (contract first)

- [x] T001 Add the "Client and reseller keys" restriction descriptions to the restricted properties in `api/components/schemas/WebDomain.yaml` per `specs/020-web-permissions-scoped-keys/contracts/web-domains-contract-changes.md`
- [x] T002 [P] Update the POST/PUT `/sites/web-domains` and SSL operation descriptions in `api/modules/sites/web-domains.yaml`
- [x] T003 Verify the contract parses and is served by running `tests/Feature/SwaggerSpecServerTest.php`

---

## Phase 2: Foundational (blocking prerequisites)

- [x] T004 Add the legacy client flag columns (`limit_ssl`, `limit_ssl_letsencrypt`, `limit_cgi`, `limit_ssi`, `limit_perl`, `limit_ruby`, `limit_python`, `force_suexec`, `limit_hterror`, `limit_wildcard`, `limit_directive_snippets` with DDL defaults) and `web_php_options` to both branches of `tests/Support/TenantSchema.php`
- [x] T005 [P] Add a hasTable-guarded `server_php` table (same columns as `tests/Support/ServerSchema.php`) to `tests/Support/SitesSchema.php`
- [x] T006 Create `app/Services/PhpVersionService.php`: `usable(int $serverId, array $clientIds, string $mode)` (active, server, client ids, mode binaries, ordered by sortprio then id), `supportsMode()`, `defaultHidden(int $serverId)`, `defaultName(int $serverId)`, `serverType(int $serverId)`
- [x] T007 Create `app/Services/WebPermissionService.php` with `forScope(AuthScope)` (AccountWebPermissions per data-model.md, memoized per scope) and the field class constants

**Checkpoint**: full suite still 971 green

---

## Phase 3: User Story 1 — Plan flags hold for customer keys (P1) 🎯 MVP

### Tests (write first, must fail)

- [x] T008 [US1] Create `tests/Feature/WebPlanFlagsScopedKeyTest.php` (SitesSchema + TenantSchema + tenant keys, server 1 assigned): for each plan flag field create/update with the forbidden value → 422 on that field and no datalog row; repeated current value not refused; forced suEXEC false → 422; several violations in one response; admin key unrestricted
- [x] T009 [US1] Add forcing tests to `tests/Feature/WebPlanFlagsScopedKeyTest.php`: seeded site with `cgi/ssi/perl/ruby/python/ssl/ssl_letsencrypt = y`, `errordocs = 1`, `directive_snippets_id = 3`, `suexec = n` saved by a client key with `{"active": true}` → 200 and the datalog `u` shows forced values; reseller key uses the reseller's own plan; allowed flags (limit `y`) accepted

### Implementation

- [x] T010 [US1] Implement `WebPermissionService::violations()` plan-flag rules and `forcedAttributes()` in `app/Services/WebPermissionService.php`
- [x] T011 [US1] Create `app/Http/Requests/Concerns/EnforcesWebPermissions.php` (validator after-hook for non-admin scopes; context: create/update, type, server id, current raw attributes or model defaults, owner client) and use it in `app/Http/Requests/StoreWebDomainRequest.php` and `app/Http/Requests/UpdateWebDomainRequest.php`
- [x] T012 [US1] Apply `forcedAttributes()` in `WebDomainService::create()` (before the Let's Encrypt two-step detection) and `update()` (before save) in `app/Services/WebDomainService.php`

**Checkpoint**: US1 tests green, full suite green

---

## Phase 4: User Story 2 — PHP modes and versions within the plan (P1)

### Tests (write first, must fail)

- [x] T013 [US2] Create `tests/Feature/WebPhpScopedKeyTest.php`: system/client mode intersection (sent on create, changed on update, unchanged accepted, empty system list), create default mode, server_php_id unknown/inactive/other server/other client/wrong mode → 422, own private version accepted, child vhost uses parent server, mode without versions stores 0, hidden default: omitted → first usable (sortprio, id), explicit 0 → 422, no usable version → 422, unchanged 0 on unrelated update accepted; admin unrestricted

### Implementation

- [x] T014 [US2] Implement PHP mode and version rules in `WebPermissionService::violations()` and the create default mode + hidden-default version in `forcedAttributes()` using `PhpVersionService` (`app/Services/WebPermissionService.php`)

**Checkpoint**: US1 + US2 green

---

## Phase 5: User Story 3 — Administrator-only settings (P2)

### Tests (write first, must fail)

- [ ] T015 [US3] Create `tests/Feature/WebAdminOptionsScopedKeyTest.php`: each Options-tab field changed by a client key → 422, default/current value accepted; reseller with `reseller_can_use_options = y` accepted and `n` refused; SSL-tab fields without `limit_ssl` → 422; plain client identity fields on a vhost → 422 (reseller allowed, child types allowed); `subdomain = "*"` on `vhostsubdomain` → 422; `POST`/`DELETE /ssl` without SSL → 403, renew without Let's Encrypt → 403, `GET /ssl` allowed; admin unrestricted

### Implementation

- [ ] T016 [US3] Implement Options/SSL-tab, identity and child wildcard rules in `app/Services/WebPermissionService.php`
- [ ] T017 [US3] Add `assertCertificateOperation()` to `app/Services/WebPermissionService.php` and call it from `store`, `destroy`, `renew` in `app/Http/Controllers/Api/V1/WebDomainSslController.php`

**Checkpoint**: all stories green

---

## Phase 6: Polish

- [ ] T018 [P] Document the client key website restrictions in `README.md`
- [ ] T019 Run Pint on changed PHP files and the full suite in Docker
- [ ] T020 Deploy to isp-test and run `specs/020-web-permissions-scoped-keys/quickstart.md` §2 with a temporary client; record results here

## Dependencies

Phase 1 → Phase 2 → US1 → US2 (shares `WebPermissionService`) → US3 → Polish. T001/T002, T004/T005 parallel.
