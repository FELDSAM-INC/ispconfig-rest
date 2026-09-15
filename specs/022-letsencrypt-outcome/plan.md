# Implementation Plan: Let's Encrypt Issuance Outcome

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-15 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/022-letsencrypt-outcome/spec.md`

## Summary

`GET /sites/web-domains/{id}/ssl/status` returns the Let's Encrypt outcome of one website. A new
`LetsEncryptStatusService` reads the newest `web_domain` journal entries of the website (via the read-only `DataLog`
model), picks the newest request or off entry (research R2), asks the spec 015 `ChangeStatusResolver` whether the
entry was processed and combines that with the current `ssl_letsencrypt` column (R1). For failed requests it parses
the ISPConfig letsencrypt warnings in `sys_log` into safe reason codes (R3); for issued certificates it reads the
public `<document_root>/ssl/<domain>-le.crt` when readable (R4). `WebDomainSslController::status()` returns the view.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)  
**Primary Dependencies**: laravel/framework ^12, ext-openssl; dev: phpunit ^11  
**Storage**: MySQL `dbispconfig` — reads only (`web_domain`, `sys_datalog`, `server`, `sys_log`); local file read of a
public certificate  
**Testing**: PHPUnit feature tests on sqlite in-memory, Docker `php:8.3-cli` (baseline 1019)  
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: ≤ 5 queries (binding, journal ≤ 50 rows, server table, sys_log) per request  
**Constraints**: no writes; no paths, commands or log text in responses; timestamps in API timezone  
**Scale/Scope**: 1 GET endpoint, 1 schema, 1 service, 1 controller method

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: path item `/sites/web-domains/{id}/ssl/status` in `api/modules/sites/web-domains.yaml`,
  registered in `api/openapi.yaml`; schema `api/components/schemas/WebDomainSslStatus.yaml` registered in
  `api/components/schemas/_index.yaml` — authored before code (contracts/ssl-status.md).
- [x] **Datalog-only writes (II)**: read-only; `DataLog` and `SystemLog` are the existing read-only models; the
  certificate file read is not an ISPConfig table access.
- [x] **Legacy parity (III)**: `apache2_plugin.inc.php` 1305–1330, `nginx_plugin.inc.php` 1374–1399,
  `letsencrypt.inc.php` 216–511, `app.inc.php` 257–345, `server.php` 87–91, `cron.d/200-logfiles.inc.php` 241–300
  (research R1–R6); the outcome view is an addition with no legacy equivalent.
- [x] **Route discipline (IV)**: `GET sites/web-domains/{webDomain}/ssl/status` registered above
  `…/ssl/renew` and `…/ssl` in `routes/api/sites.php` inside the `api.key` group.
- [x] **HTTP contract (V)**: bare object 200; problem+json 401/404; no write headers.
- [x] **No schema changes**: none; tests reuse `SitesSchema`, `MonitorSchema` (server watermark columns),
  `MonitorCompletionSchema` (`sys_log`).

**Post-design re-check (after Phase 1)**: all gates pass; no Complexity Tracking entries.

## Project Structure

### Documentation (this feature)

```text
specs/022-letsencrypt-outcome/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/ssl-status.md
├── checklists/requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
api/modules/sites/web-domains.yaml                  # path item /sites/web-domains/{id}/ssl/status
api/openapi.yaml                                    # register path (above /ssl)
api/components/schemas/WebDomainSslStatus.yaml      # NEW
api/components/schemas/_index.yaml                  # register schema
app/Services/LetsEncryptStatusService.php           # NEW state, reasons, certificate details
app/Http/Controllers/Api/V1/WebDomainSslController.php  # status()
routes/api/sites.php                                # GET …/ssl/status first in the SSL block
tests/Feature/WebDomainSslStatusApiTest.php         # NEW states, reasons, certificate, scoping, 401/404
README.md                                           # sites module row mentions ssl/status
```

**Structure Decision**: the endpoint belongs to the SSL subresource of web domains; the service keeps the controller
thin and is unit-testable through the feature test with seeded journal, server and log rows.

## Legacy Research (Phase 0 focus)

See research.md R1–R8 (plugin request condition and silent revert, letsencrypt warning texts, log level gating,
certificate path, retention, routing/scoping, timestamps).

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

No violations.
