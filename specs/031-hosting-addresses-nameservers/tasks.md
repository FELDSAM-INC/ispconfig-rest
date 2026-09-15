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
- [x] T011 Deploy to isp-test and run `specs/031-hosting-addresses-nameservers/quickstart.md` §2 with temporary clients; record results here

## Dependencies

Phase 1 → tests → implementation → Polish. T001/T002 parallel; T007 before T008.

## Results (T010–T011, 2026-09-16)

- T010: Pint clean on the new files; full suite 1148 passed (baseline 1141 + 7 new tests).
- T011: deployed `acc002e` to isp-test (`ispconfig-rest status`: 1.0.0-rc.3 (acc002e)). Temporary clients
  `qa031ae7f8b0` (30) and `qa031be7f8b0` (31), QA admin key 69 and client key 70, temporary `server_ip` rows 3 and 4.
  Keys were never printed. Results:

| Case | Expected | Got |
|---|---|---|
| client A `GET /me/hosting-addresses` | `web[0]`/`mail[0]`/`dns[0]` = server 1, `ipv4 ["185.174.170.53"]`, `ipv6 ["2a0b:a901::b9ff:feae:aa35"]`, `is_default true`, mail host `isp-test.feldhost.cz` | as expected |
| same, `dns[0].nameservers` | `[{isp-test.feldhost.cz, both addresses}]` (no external DNS servers configured) | as expected |
| admin adds `203.0.113.77` dedicated to client B and shared private `10.31.31.31` | 201, 201 | as expected |
| client A reads again | addresses unchanged (other client's dedicated and private addresses hidden) | as expected |
| admin `GET ?client_id=31` | server 1 with the shared addresses **and** B's own `203.0.113.77` | as expected (see note) |
| admin `GET` without `client_id` | 422 `errors.client_id` | 422 `The client id is required for admin keys.` |
| client A `GET ?client_id=31` | 404 | 404 |
| client A `GET ?foo=1` | 400 | 400 `Unknown parameter 'foo'. Allowed: client_id.` |

  Note: the quickstart expected empty lists for client B; ISPConfig gives every new client the installation's default
  servers (`web_servers = mail_servers = dns_servers = 1` on both temporary clients), so B legitimately gets server 1 —
  with its own dedicated address, which client A never sees. The quickstart step was corrected; the empty-list case is
  covered by the automated tests.

- Cleanup: `server_ip` rows 3 and 4 and clients 30, 31 deleted with the QA admin key (204); datalog processed
  (`server.updated` 784 = last id 784); API keys 69, 70 deleted by SQL (`name LIKE 'qa%'`); no `qa031` client,
  sys_group or sys_user rows, no pending datalog, `server_ip` back to rows 1 and 2, `/etc/network/interfaces`
  unchanged (`auto_network_configuration = n`). Remaining keys: 1, 2, 20, 27, 50 (untouched).

