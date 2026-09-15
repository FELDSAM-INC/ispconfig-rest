# Implementation Plan: DNS Zone Wizard For Scoped Keys

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-16 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/029-dns-zone-wizard/spec.md`

## Summary

- A new read-only resource `GET /dns/zone-templates` lists `dns_template` rows with `visible = true` **without** the
  row-level read predicate — the rule the legacy wizard applies (`dns_wizard.php:73`) — exposing only `id`, `name`
  and the declared placeholder `fields`. `GET /dns/templates` keeps its spec 011 scoping and admin-only writes.
- A new `POST /dns/soa/from-template` expands a template: `DnsZoneWizardService` replaces the placeholders, parses
  the `[ZONE]`/`[DNS_RECORDS]` text, and inside one `DB::transaction()` writes the zone inactive, the records, and
  the zone activation — one change set via the existing `AttachChangeSetId`.
- `ClientLimitService` gains a batch entry point so the template's whole record set is counted against
  `limit_dns_record` (and the zone against `limit_dns_zone`) **before** any write; the existing per-row chokepoint
  in `BaseModel::save()` is unchanged and remains defence in depth.
- Request validation reuses `ResolvesAssignedServer` (spec 016), `ResolvesClientOwnership` (`client_id`) and
  spec 024's readable-reference scoping for the optional DKIM lookup.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)  
**Primary Dependencies**: laravel/framework ^12; dev: phpunit ^11  
**Storage**: MySQL `dbispconfig` — reads `dns_template`, `mail_domain`, `client`, `server`; writes `dns_soa` and
`dns_rr` through datalog only  
**Testing**: PHPUnit feature tests on sqlite in-memory, Docker `php:8.3-cli` (baseline 1158)  
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: one template read, one limit count per capped resource, N+2 journal entries per zone  
**Constraints**: all writes in one transaction and one change set; every refusal before the first write  
**Scale/Scope**: 2 endpoints, 2 schemas, 1 service, 1 controller, 1 form request, 1 limit helper

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: `zone-templates.yaml`, `soa.yaml`, `DnsZoneTemplate.yaml`, `DnsZoneFromTemplate.yaml` and
  both `_index` files are written before the PHP (contracts/dns-zone-wizard.md).
- [x] **Datalog-only writes (II)**: the zone and every record go through `DnsSoa`/`DnsRecord` (BaseModel); no direct
  SQL writes. Reads of `dns_template`/`mail_domain` are plain queries.
- [x] **Legacy parity (III)**: `dns_wizard.php` and `lib/classes/dns_wizard.inc.php` (research R1–R9); the six
  deviations are listed in the spec's ISPConfig Parity section and agreed there (owner-delegated 2026-09-16).
- [x] **Route discipline (IV)**: `dns/soa/from-template` registered before `dns/soa/{dnsSoa}` (which is
  `whereNumber`); `dns/zone-templates` is a distinct path that cannot shadow `dns/templates`.
- [x] **HTTP contract (V)**: `{data, meta}` list, 201 create with `X-Change-Set-Id`, problem+json refusals, 403
  `limit-reached` and 422 `error_types` per spec 023.
- [x] **No schema changes**: no migrations; test schema gains only guarded table creation.

**Post-design re-check (after Phase 1)**: all gates pass. One design point worth naming: `GET /dns/zone-templates`
deliberately ignores the row-read predicate that spec 011 applies everywhere else. It is not a scoping regression —
it mirrors the legacy wizard exactly, exposes no owner, no permissions and no template text, and the management
resource keeps its predicate. No Complexity Tracking entries.

## Project Structure

### Documentation (this feature)

```text
specs/029-dns-zone-wizard/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/dns-zone-wizard.md
├── checklists/requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
api/components/schemas/DnsZoneTemplate.yaml          # wizard template entry (id, name, fields)
api/components/schemas/DnsZoneFromTemplate.yaml      # wizard request body
api/components/schemas/_index.yaml                   # register both
api/modules/dns/zone-templates.yaml                  # GET /dns/zone-templates
api/modules/dns/soa.yaml                             # + /dns/soa/from-template
api/modules/dns/_index.yaml                          # + zone-templates
api/openapi.yaml                                     # + both paths
routes/api/dns.php                                   # 2 routes (from-template before {dnsSoa})
app/Http/Controllers/Api/V1/DnsZoneTemplateController.php  # index
app/Http/Controllers/Api/V1/DnsSoaController.php           # + storeFromTemplate
app/Http/Requests/StoreDnsSoaFromTemplateRequest.php       # placeholders, server, client_id
app/Services/DnsZoneWizardService.php                      # replace, parse, expand, create
app/Services/ClientLimitService.php                        # + checkBatchCreate()
tests/Feature/DnsZoneWizardApiTest.php                     # list, expansion, limits, DKIM, DNSSEC
tests/Support/DnsSchema.php                                # guarded mail_domain for the DKIM case
README.md                                                  # DNS paragraph
specs/002-dns-management/spec.md                           # supersede "the wizard has no REST counterpart"
```

## Phases

- **Phase 0 (research)**: done — research.md R1–R12.
- **Phase 1 (design)**: done — data-model.md, contracts/dns-zone-wizard.md, quickstart.md.
- **Phase 2 (contract)**: schemas and paths first, Swagger spec test green.
- **Phase 3 (US2)**: template list — tests, then controller and route.
- **Phase 4 (US1)**: wizard — tests, then request, service and controller action.
- **Phase 5 (US3)**: batch limit enforcement — tests, then `ClientLimitService::checkBatchCreate()`.
- **Phase 6 (US4)**: DKIM and DNSSEC flags — tests, then service support.
- **Phase 7 (polish)**: README, spec 002 note, Pint, full suite.
- **Phase 8 (verification)**: deploy to isp-test, run quickstart §2, record results in tasks.md, clean up.
