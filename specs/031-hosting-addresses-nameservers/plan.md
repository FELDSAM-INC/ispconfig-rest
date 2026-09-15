# Implementation Plan: Hosting Addresses and Name Servers for Scoped Keys

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-16 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/031-hosting-addresses-nameservers/spec.md`

## Summary

- New read-only `GET /me/hosting-addresses` (`MeHostingAddressesController`) with the spec 025 target resolution
  (`AccountCapabilitiesService::resolveTarget()`, `ReadsAccountQuery`).
- New `App\Services\HostingAddressService`: per role the account's servers (assigned via
  `ServerAssignmentService::assignedServerIds()`, then servers hosting the client's `web_domain` / `mail_domain` /
  `dns_soa` rows), public `server_ip` addresses visible to the client, and for DNS servers the zone-import name server
  list (server + mirrors by name + `dns_external_slave_fqdn`).
- Contract: `api/modules/me/hosting-addresses.yaml`, schemas `HostingAddresses`, `HostingServer`, `HostingDnsServer`,
  `NameServer`.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)  
**Primary Dependencies**: laravel/framework ^12; dev: phpunit ^11  
**Storage**: MySQL `dbispconfig` — reads `client`, `server`, `server_ip`, `web_domain`, `mail_domain`, `dns_soa`,
`sys_group`, `sys_ini`; no writes  
**Testing**: PHPUnit feature tests on sqlite in-memory, Docker `php:8.3-cli` (baseline 1141)  
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: fixed number of queries per request (client row, servers, addresses, mirrors, hosting servers,
`sys_ini`)  
**Constraints**: never list other clients' dedicated addresses; read-only  
**Scale/Scope**: 1 endpoint, 1 service, 4 schemas

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: `hosting-addresses.yaml` and the four schemas written before code
  (contracts/hosting-addresses.md).
- [x] **Datalog-only writes (II)**: no writes.
- [x] **Legacy parity (III)**: `server_ip` visibility (`web_vhost_domain_edit.php` 209/225), name server composition
  (`dns_import.php` 272–287, 634–643), mirrors (`modules.inc.php` 104–142) — research R1–R4; deviations
  owner-delegated in the spec.
- [x] **Route discipline (IV)**: static `me/hosting-addresses` in `routes/api/me.php`, outside the admin gate like the
  other `/me` reads.
- [x] **HTTP contract (V)**: 200 / 400 / 401 / 404 / 422 problem+json as `/me/mail-settings`.
- [x] **No schema changes**: no migrations.

**Post-design re-check (after Phase 1)**: all gates pass; no Complexity Tracking entries.

## Project Structure

### Documentation (this feature)

```text
specs/031-hosting-addresses-nameservers/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/hosting-addresses.md
├── checklists/requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
api/modules/me/hosting-addresses.yaml                    # NEW path
api/modules/me/_index.yaml, api/openapi.yaml            # registration
api/components/schemas/HostingAddresses.yaml             # NEW
api/components/schemas/HostingServer.yaml                # NEW
api/components/schemas/HostingDnsServer.yaml             # NEW
api/components/schemas/NameServer.yaml                   # NEW
api/components/schemas/_index.yaml                       # registration
app/Services/HostingAddressService.php                   # NEW
app/Http/Controllers/Api/V1/MeHostingAddressesController.php  # NEW
routes/api/me.php                                        # route
tests/Feature/MeHostingAddressesApiTest.php              # NEW
README.md                                                # /me endpoints list
```

**Structure Decision**: a dedicated service beside `AccountMailService` (spec 025) — it reuses
`ServerAssignmentService::assignedServerIds()` and `AccountMailService::accountMailServers()` for the server order and
keeps `/me/servers` unchanged.

## Legacy Research (Phase 0 focus)

See research.md: R1 endpoint shape, R2 address visibility, R3 public filter, R4 name servers, R5 server lists.

## Complexity Tracking

None.
