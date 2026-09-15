# Implementation Plan: Account Capabilities for Scoped Keys

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-15 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/021-account-capabilities/spec.md`

## Summary

Two read-only endpoints in the `me` module describe what feature 020 lets an account's own key do:

- `GET /me/capabilities` — `AccountCapabilitiesService::capabilities()` presents
  `WebPermissionService::forClient()` (the same derivation 020 enforces) plus `client_id`, `account_type`,
  `locked`, `canceled` and the create default PHP mode (`WebPermissionService::defaultPhpMode()`).
- `GET /me/php-versions` — `AccountCapabilitiesService::phpVersions()` walks the account's web servers
  (assigned list, then servers hosting its websites), lists `PhpVersionService::usable()` per allowed version mode
  and adds the server's "Default" entry unless hidden.

Both reuse `UsageService::resolveTargetClient()` for the `client_id` rules (admin 422/404, client own, reseller
own or its clients). `PhpVersionService::usable()` gains the legacy nginx mapping (`fast-cgi` uses the FPM
columns) so the list and the 020 enforcement stay identical on nginx servers.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)  
**Primary Dependencies**: laravel/framework ^12; dev: phpunit ^11  
**Storage**: MySQL `dbispconfig` — reads only (`client`, `sys_group`, `sys_ini`, `server`, `server_php`, `web_domain`)  
**Testing**: PHPUnit feature tests in `tests/Feature/` on sqlite in-memory, Docker `php:8.3-cli` (baseline 999)  
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: capabilities ≤ 3 queries; php-versions ≤ 4 queries + 2 per considered server (config, versions)  
**Constraints**: no writes, no datalog; no server paths or binaries in responses; values identical to 020 rules  
**Scale/Scope**: 2 GET endpoints, 2 new schemas, 1 new service, 2 controllers

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: `api/modules/me/capabilities.yaml`, `api/modules/me/php-versions.yaml`,
  `api/components/schemas/AccountCapabilities.yaml`, `AccountPhpVersion.yaml` are written and registered
  (`api/modules/me/_index.yaml`, `api/components/schemas/_index.yaml`, `api/openapi.yaml`) before code
  (contracts/me-endpoints.md).
- [x] **Datalog-only writes (II)**: read-only endpoints; nothing written.
- [x] **Legacy parity (III)**: `web_vhost_domain_edit.php` 240–272, `ajax_get_json.php` 66–125,
  `form/web_vhost_domain.tform.php`, `tform_base.inc.php::applyValueLimit()` (research R2–R4); deviations
  owner-delegated and listed in the spec.
- [x] **Route discipline (IV)**: static paths `me/capabilities`, `me/php-versions` in `routes/api/me.php` inside
  the `api.key` group, outside the admin gate; invokable controllers stay thin.
- [x] **HTTP contract (V)**: bare object for capabilities; `{data, meta}` list with `limit`/`offset` for versions;
  problem+json 400 (unknown parameter, bad limit/offset), 401, 404 (target client), 422 (`client_id`,
  `server_id`, `mode`).
- [x] **No schema changes**: no migrations; test schema already has `server_php` and the client plan columns
  (feature 020); `TenantSchema` gains `locked`/`canceled` only if missing.

**Post-design re-check (after Phase 1)**: all gates pass; no Complexity Tracking entries.

## Project Structure

### Documentation (this feature)

```text
specs/021-account-capabilities/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/me-endpoints.md
├── checklists/requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
api/modules/me/capabilities.yaml                   # NEW GET /me/capabilities
api/modules/me/php-versions.yaml                   # NEW GET /me/php-versions
api/modules/me/_index.yaml                         # register both
api/components/schemas/AccountCapabilities.yaml    # NEW
api/components/schemas/AccountPhpVersion.yaml      # NEW
api/components/schemas/_index.yaml                 # register both
api/openapi.yaml                                   # paths /me/capabilities, /me/php-versions
app/Services/AccountCapabilitiesService.php        # NEW capabilities + account PHP versions
app/Services/PhpVersionService.php                 # nginx fast-cgi → FPM columns
app/Http/Controllers/Api/V1/MeCapabilitiesController.php  # NEW invokable
app/Http/Controllers/Api/V1/MePhpVersionsController.php   # NEW invokable (HandlesListQuery limit/offset)
routes/api/me.php                                  # two routes
tests/Feature/MeCapabilitiesApiTest.php            # NEW US1 (+ pairing with 020 writes)
tests/Feature/MePhpVersionsApiTest.php             # NEW US2 (+ pairing with 020 writes, nginx)
README.md                                          # me endpoints note
```

**Structure Decision**: one service owns the presentation of both reads so the controllers only parse query
parameters; derivation stays in `WebPermissionService`/`PhpVersionService` (single source with feature 020).

## Legacy Research (Phase 0 focus)

See research.md: R1 target account, R2 capability sources, R3 PHP version list, R4 default entry, R5 account web
servers, R6 response shapes and consumer fit, R7 parameters and errors.

## Complexity Tracking

None.
