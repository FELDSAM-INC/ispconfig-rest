# Implementation Plan: DKIM Key Generation for Mail Domains

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-15 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/027-dkim-generation/spec.md`

## Summary

- New `MailDomainDkimService`: `view()` (status, public key, key size, DNS record, hosted zone, server availability)
  and `generate()` (409 unless the mail server has a DKIM path; RSA key with the server `dkim_strength`; save `dkim`,
  keys and selector through the `MailDomain` model; existing `MailDomainService::syncDnsAfterUpdate()` in the same
  transaction).
- New `MailDomainDkimController` (`GET`/`POST /mail/domains/{id}/dkim`) and `GenerateMailDomainDkimRequest`
  (`selector`).
- `MailDomainController` presentation drops `dkim_private` for non-admin scopes.
- `MailDomainService::findSoaZone()` becomes public for the `dns_managed` flag.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12, ext-openssl)  
**Primary Dependencies**: laravel/framework ^12; dev: phpunit ^11  
**Storage**: MySQL `dbispconfig` — `mail_domain` (datalog `u`), existing DKIM DNS writes to `dns_rr`/`dns_soa`; reads
`server.config`, `dns_soa`  
**Testing**: PHPUnit feature tests on sqlite in-memory, Docker `php:8.3-cli` (baseline 1104)  
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: generation ≤ 1 s for 2048-bit keys (≈ 0.4 s measured on isp-test); status read ≤ 3 queries  
**Constraints**: private key never in customer responses; datalog-only writes; refusals write nothing  
**Scale/Scope**: 2 operations, 2 schemas, 1 service, 1 controller, 1 request, 1 presentation change

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: `api/modules/mail/domain-dkim.yaml`, `MailDomainDkim.yaml`, `MailDomainDkimGenerate.yaml`, the
  `dkim_private` visibility note in `MailDomain.yaml`/`domains.yaml`, registered in `api/modules/mail/_index.yaml`,
  `api/components/schemas/_index.yaml` and `api/openapi.yaml` before code (contracts/dkim.md).
- [x] **Datalog-only writes (II)**: `mail_domain` through `BaseModel::save()`, DNS through the existing `DatalogService`
  calls.
- [x] **Legacy parity (III)**: `ajax_get_json.php` 42–113, `mail_domain_edit.php` 243–266, 348–351, 704–775,
  `mail_plugin_dkim.inc.php::check_system()`, `server_config.tform.php` 557–580 (research R1–R5); deviations
  owner-delegated in the spec.
- [x] **Route discipline (IV)**: `mail/domains/{mailDomain}/dkim` next to the domain routes, numeric constraint,
  read-scoped binding; thin controller.
- [x] **HTTP contract (V)**: 200 with `X-Change-Set-Id` on generation; problem+json 401/403/404/409/422/500.
- [x] **No schema changes**: no migrations.

**Post-design re-check (after Phase 1)**: all gates pass; no Complexity Tracking entries.

## Project Structure

### Documentation (this feature)

```text
specs/027-dkim-generation/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/dkim.md
├── checklists/requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
api/modules/mail/domain-dkim.yaml                        # NEW GET/POST /mail/domains/{id}/dkim
api/modules/mail/_index.yaml                             # register
api/modules/mail/domains.yaml                            # dkim_private visibility, generation pointer
api/components/schemas/MailDomainDkim.yaml               # NEW
api/components/schemas/MailDomainDkimGenerate.yaml       # NEW
api/components/schemas/MailDomain.yaml                   # dkim_private description
api/components/schemas/_index.yaml
api/openapi.yaml                                         # path /mail/domains/{id}/dkim
app/Services/MailDomainDkimService.php                   # NEW view + generate
app/Services/MailDomainService.php                       # findSoaZone() public
app/Http/Requests/GenerateMailDomainDkimRequest.php      # NEW selector
app/Http/Controllers/Api/V1/MailDomainDkimController.php # NEW
app/Http/Controllers/Api/V1/MailDomainController.php     # hide dkim_private for scoped keys
routes/api/mail.php
tests/Feature/MailDomainDkimApiTest.php                  # NEW US1–US4
README.md
```

**Structure Decision**: DKIM generation and presentation live in one service next to `MailDomainService`, which keeps
owning the DNS side effects; the controller only binds, validates and serializes.

## Legacy Research (Phase 0 focus)

See research.md: R1 key generation, R2 storing and DNS, R3 availability, R4 status view, R5 private key visibility,
R6 contract and change sets, R7 tests.

## Complexity Tracking

None.
