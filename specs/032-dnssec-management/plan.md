# Implementation Plan: DNSSEC Management For Scoped Keys

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-16 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/032-dnssec-management/spec.md`

## Summary

- A new `DnssecStatusService` reads a zone's DNSSEC columns, derives the state (`off` / `pending` / `signed` /
  `unavailable`) and parses the server's notes into `ds_records` and `dnskey_records` — modelled on
  `LetsEncryptStatusService` (spec 022).
- A new sub-resource `GET /dns/soa/{id}/dnssec` returns it, scoped through the existing route-model binding, so a
  zone the key cannot read is a 404.
- `DnsSoaRequest::after()` gains the legacy mirror rule: enabling `dnssec_wanted` where the zone's DNS server has
  mirrors is refused with a typed 422, alongside the existing `update_acl` and `origin` rules.
- `DnsSoa` masks `dnssec_info` for non-admin scopes on serialization; every other DNSSEC column stays visible.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)  
**Primary Dependencies**: laravel/framework ^12; dev: phpunit ^11  
**Storage**: MySQL `dbispconfig` — reads `dns_soa`, `server`; writes nothing new  
**Testing**: PHPUnit feature tests on sqlite in-memory, Docker `php:8.3-cli` (baseline 1186)  
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: one zone read plus one mirror count per request; parsing is a few regex passes over a small
text column  
**Constraints**: read-only feature; the refusal must write nothing; no private key material in any response  
**Scale/Scope**: 1 endpoint, 1 schema, 1 service, 1 request rule, 1 serialization change

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: `DnsSoaDnssec.yaml`, the `/dns/soa/{id}/dnssec` path, the PUT description and the
  `dnssec_info` description are written before the PHP (contracts/dnssec.md).
- [x] **Datalog-only writes (II)**: no writes at all; the existing `PUT` path is unchanged except for one refusal.
- [x] **Legacy parity (III)**: `dns_soa_edit.php` 92–102/157–168, `dns_soa_edit.htm` 155–172,
  `bind_plugin.inc.php` 79–194/392–406, `powerdns_plugin.inc.php` 491–560 (research R2–R5); the two deviations are
  agreed in the spec.
- [x] **Route discipline (IV)**: `dns/soa/{dnsSoa}/dnssec` registered with the zone routes, `whereNumber` on the
  parameter; no shadowing of `dns/soa/from-template` or `dns/soa/{id}`.
- [x] **HTTP contract (V)**: 200 with a shared schema, 404 through model binding, 422 with `error_types` per spec
  023.
- [x] **No schema changes**: no migrations.

**Post-design re-check (after Phase 1)**: all gates pass. One point worth naming: masking `dnssec_info` narrows an
existing response field for non-admin keys. It is a deliberate deviation (spec, deviation 2) because the same
column carries a raw command log on PowerDNS installations, and the parsed sub-resource replaces it without loss.
No Complexity Tracking entries.

## Project Structure

### Documentation (this feature)

```text
specs/032-dnssec-management/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/dnssec.md
├── checklists/requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
api/components/schemas/DnsSoaDnssec.yaml        # new: state + DS/DNSKEY records
api/components/schemas/DnsSoa.yaml              # dnssec_info: admin-only note
api/components/schemas/_index.yaml              # register DnsSoaDnssec
api/modules/dns/soa.yaml                        # + /dns/soa/{id}/dnssec, PUT mirror rule
api/openapi.yaml                                # + the sub-resource path
routes/api/dns.php                              # + GET dns/soa/{dnsSoa}/dnssec
app/Http/Controllers/Api/V1/DnsSoaController.php # + dnssec()
app/Services/DnssecStatusService.php            # new: availability, state, parser
app/Http/Requests/DnsSoaRequest.php             # + mirror rule on dnssec_wanted
app/Models/DnsSoa.php                           # mask dnssec_info for non-admin scopes
tests/Feature/DnsSoaDnssecApiTest.php           # new: states, parsing, mirror rule, masking, scoping
README.md                                       # DNS paragraph
specs/005-dns/…                                 # (module repo) consumer note, not touched here
```

## Phases

- **Phase 0 (research)**: done — research.md R1–R8.
- **Phase 1 (design)**: done — data-model.md, contracts/dnssec.md, quickstart.md.
- **Phase 2 (contract)**: schema and paths first, Swagger spec test green.
- **Phase 3 (US1)**: status sub-resource — tests, then service, controller action and route.
- **Phase 4 (US3)**: the mirror rule on read and write — tests, then the request rule.
- **Phase 5 (US4)**: mask `dnssec_info` for non-admin keys — tests, then the model change.
- **Phase 6 (polish)**: README, Pint, full suite.
- **Phase 7 (verification)**: deploy to isp-test, run quickstart §2 (including the temporary mirror row, restored
  afterwards), record results in tasks.md, clean up.
