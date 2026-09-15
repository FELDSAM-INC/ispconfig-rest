---

description: "Task list for spec 031 — hosting addresses and name servers for scoped keys"
---

# Tasks: Hosting Addresses and Name Servers for Scoped Keys

**Input**: Design documents from `/specs/031-hosting-addresses-nameservers/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1141 passing).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US3 from spec.md

---

## Phase 1: Setup (contract first)

- [x] T001 [P] Create `api/components/schemas/HostingAddresses.yaml`, `HostingServer.yaml`, `HostingDnsServer.yaml`, `NameServer.yaml`; register them in `api/components/schemas/_index.yaml`
- [x] T002 [P] Create `api/modules/me/hosting-addresses.yaml`; register it in `api/modules/me/_index.yaml` and `api/openapi.yaml`
- [x] T003 Verify the YAML parses and the spec is served (`tests/Feature/SwaggerSpecServerTest.php`)

---

## Phase 2: User Stories 1–3 (P1/P2) 🎯 MVP

The three stories share one read-only endpoint; they are tested in one test class.

### Tests (write first, must fail)

- [x] T004 [US1] Create `tests/Feature/MeHostingAddressesApiTest.php` address and server list cases: shared / own dedicated / other client's dedicated / private / loopback / link-local / `ip_type` mismatch / duplicate rows / NameVirtualHost flag; web list in assignment order skipping mirror and missing servers, then servers hosting the client's websites; mail list with hosting servers; empty lists for a client without servers or resources; nothing journaled
- [x] T005 [US2] Name server cases in the same class: server and mirrors ordered by name with their addresses, external setting split on commas/whitespace with trailing dots removed and case-insensitive duplicates dropped, second primary hosting a zone, no setting
- [x] T006 [US3] Target cases in the same class: reseller key own and child view (reseller-dedicated address only in the reseller view), foreign client 404; admin without `client_id` 422, unknown 404, valid id equals the client view; client key with other id 404; unknown parameter 400

### Implementation

- [x] T007 Create `app/Services/HostingAddressService.php` (server lists, address rule, name servers)
- [x] T008 Create `app/Http/Controllers/Api/V1/MeHostingAddressesController.php` and the route in `routes/api/me.php`

**Checkpoint**: T004–T006 green

---

## Phase 3: Polish

- [x] T009 [P] Mention `GET /me/hosting-addresses` in `README.md`
- [x] T010 Run Pint on changed PHP files and the full suite in Docker
- [ ] T011 Deploy to isp-test and run `specs/031-hosting-addresses-nameservers/quickstart.md` §2 with temporary clients; record results here

## Dependencies

Phase 1 → tests → implementation → Polish. T001/T002 parallel; T007 before T008.
