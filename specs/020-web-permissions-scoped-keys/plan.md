# Implementation Plan: Web Permission Enforcement for Scoped Keys

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-15 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/020-web-permissions-scoped-keys/spec.md`

## Summary

Apply the ISPConfig 3.3.1p1 client rules for websites to client and reseller API keys:

- `WebPermissionService` derives the acting account's web permissions (plan flags, allowed PHP modes, Options-tab
  access, plain-client vs reseller) and produces (a) field violations for a request and (b) the raw attribute
  values legacy forces on every non-admin save.
- `PhpVersionService` lists the PHP versions usable for a server, client set and mode (legacy client list query)
  and the server's hidden-default setting; feature 021 reuses it for `GET /me/php-versions`.
- A request concern `EnforcesWebPermissions` adds the violations to the validator of `POST`/`PUT
  /sites/web-domains` (422, nothing written); `WebDomainService::create()/update()` apply the forced values and
  the default PHP version; `WebDomainSslController` refuses certificate operations without SSL in the plan (403).

Admin keys skip every new step. No new endpoints, no schema changes; the contract documents the restrictions.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)  
**Primary Dependencies**: laravel/framework ^12, Eloquent ORM; dev: phpunit ^11, mockery, faker  
**Storage**: MySQL `dbispconfig` (reads `client`, `sys_group`, `sys_ini`, `server`, `server_php`; `web_domain` writes stay datalogged)  
**Testing**: PHPUnit feature tests in `tests/Feature/` on sqlite in-memory, run in Docker `php:8.3-cli` (baseline 971)  
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: at most 3 extra queries per scoped website write (client row, sites config already cached per request by the service, PHP version lookup only when PHP fields matter)  
**Constraints**: refusals before any write; forced values inside the existing single datalog entry; admin behavior byte-identical  
**Scale/Scope**: 2 website write endpoints + 3 certificate operations; ~40 fields classified

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: no new paths; `api/components/schemas/WebDomain.yaml` field descriptions and
  `api/modules/sites/web-domains.yaml` POST/PUT/SSL descriptions are updated first
  (contracts/web-domains-contract-changes.md). 403 and 422 responses are already declared on those operations.
- [x] **Datalog-only writes (II)**: no new writes; forced values join the existing `web_domain` datalog entry
  (create: raw insert + datalog `i` as today; update: `BaseModel::save()`).
- [x] **Legacy parity (III)**: `web_vhost_domain_edit.php`, `form/web_vhost_domain.tform.php`,
  `tform_base.inc.php::applyValueLimit()`, `ajax_get_json.php` read on isp-test (research R1–R7); deviations are
  owner-delegated and listed in the spec.
- [x] **Route discipline (IV)**: no route changes.
- [x] **HTTP contract (V)**: 422 problem+json with `errors` per field (validator after-hook); 403 problem+json via
  `AuthorizationException` for certificate operations.
- [x] **No schema changes**: no migrations; test schemas gain the legacy client flag columns and `server_php`.

**Post-design re-check (after Phase 1)**: all gates pass; no Complexity Tracking entries.

## Project Structure

### Documentation (this feature)

```text
specs/020-web-permissions-scoped-keys/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/web-domains-contract-changes.md
├── checklists/requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
api/components/schemas/WebDomain.yaml              # field descriptions (restricted fields)
api/modules/sites/web-domains.yaml                 # POST/PUT + SSL operation descriptions
app/Services/WebPermissionService.php              # NEW account permissions, violations, forced values
app/Services/PhpVersionService.php                 # NEW usable PHP versions, hidden default
app/Http/Requests/Concerns/EnforcesWebPermissions.php  # NEW validator after-hook
app/Http/Requests/StoreWebDomainRequest.php        # use concern (create context)
app/Http/Requests/UpdateWebDomainRequest.php       # use concern (update context)
app/Services/WebDomainService.php                  # apply forced values + default PHP version
app/Http/Controllers/Api/V1/WebDomainSslController.php  # 403 without SSL / Let's Encrypt
tests/Support/TenantSchema.php                     # client flag columns + web_php_options
tests/Support/SitesSchema.php                      # server_php table (hasTable-guarded)
tests/Feature/WebPlanFlagsScopedKeyTest.php        # NEW US1
tests/Feature/WebPhpScopedKeyTest.php              # NEW US2
tests/Feature/WebAdminOptionsScopedKeyTest.php     # NEW US3
README.md                                          # client key restrictions note
```

**Structure Decision**: services hold the rules (shared with feature 021); the request concern only maps
violations to validator errors so controllers stay thin (Principle IV).

## Legacy Research (Phase 0 focus)

See research.md: R1 forced plan flags, R2 PHP mode list, R3 PHP version list and reset, R4 hidden default version,
R5 Options/SSL tab visibility, R6 domain-tab read-only fields, R7 reseller vs client identity, R8 change detection.

## Complexity Tracking

None.
