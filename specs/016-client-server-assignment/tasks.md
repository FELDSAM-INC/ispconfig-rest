---

description: "Task list for feature 016 — client server assignment for non-admin keys"
---

# Tasks: Client Server Assignment for Non-Admin Keys

**Input**: Design documents from `/specs/016-client-server-assignment/`
**Prerequisites**: plan.md, spec.md, research.md (R1–R15), data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2) — feature tests cover happy path, validation failures, auth
failures and the absence of `sys_datalog` rows on rejection. Write each story's tests first and confirm
they fail before implementing. Run with `vendor/bin/phpunit` on PHP 8.3 (see quickstart.md; this
workstation's PHP 8.1 is too old).

**Organization**: Tasks are grouped by user story (spec.md US1 P1, US2 P2, US3 P3) so each story can be
implemented, tested and delivered independently.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: User story the task belongs to (US1, US2, US3)
- Every task names its exact file path

## Path Conventions (this project)

| Artifact | Path |
|----------|------|
| OpenAPI endpoint spec | `api/modules/[module]/[resource].yaml` (+ `api/modules/[module]/_index.yaml`, `api/openapi.yaml`) |
| OpenAPI schema | `api/components/schemas/[Entity].yaml` |
| Request / request concern | `app/Http/Requests/[Name]Request.php`, `app/Http/Requests/Concerns/[Name].php` |
| Service | `app/Services/[Name]Service.php` |
| Controller | `app/Http/Controllers/Api/V1/[Name]Controller.php` |
| Routes | `routes/api/[module].php`, required from `routes/api.php` |
| Test support | `tests/Support/TenantSchema.php`, `tests/Support/TenantFixtures.php` |
| Tests (REQUIRED) | `tests/Feature/[Name]Test.php` |

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Establish the baseline and the `me` module situation before changing anything.

- [ ] T001 Run the full suite on branch `016-client-server-assignment` with the Docker `php:8.3-cli` commands from specs/016-client-server-assignment/quickstart.md and record the pre-existing result (expected all green) so later regressions are attributable
- [ ] T002 Check whether spec 014's `me` module has merged into the base branch (`api/modules/me/_index.yaml`, `routes/api/me.php`, the `require __DIR__.'/api/me.php'` line in routes/api.php) and note the result for T028/T032 (extend 014's files, or create them in 014's layout per plan.md "Dependency on Spec 014")

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Test schema, fixtures, the server assignment service and the request concern shared by all stories,
plus fixture updates for existing non-admin tests (research.md R12).

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [ ] T003 [P] Extend tests/Support/TenantSchema.php: add `web_servers`, `mail_servers`, `db_servers`, `dns_servers` (nullable string) and `default_slave_dnsserver` (unsigned int, default 0) to the `client` table create and to the `ensureColumns` back-fill branch; back-fill `server.db_server` (tinyint, default 0) where `DnsSchema`, `MailSchema` or `MailCompletionSchema` created `server` without it
- [ ] T004 [P] Add `assignServers(string $tenant, array $lists, ?int $slaveDns = null)` to tests/Support/TenantFixtures.php writing the CSV list columns (`web`, `mail`, `db`, `dns` keys → `*_servers`) and `default_slave_dnsserver` of the tenant's client row
- [ ] T005 Write tests/Feature/ServerAssignmentServiceTest.php (must fail first): CSV parsing (whitespace, duplicates, trailing commas, non-positive values), list order preserved, deleted / mirror (`mirror_server_id > 0`) / wrong-flag servers skipped, `active` ignored, `AuthScope::$clientId = 0` → empty lists, one `client` read per request (memoized), `slaveDnsServerId` valid only for existing non-mirror `dns_server = 1` servers
- [ ] T006 Implement app/Services/ServerAssignmentService.php per data-model.md "Resolution algorithm": `assignedServerIds(AuthScope $scope, string $service)`, `defaultServerId(...)`, `slaveDnsServerId(AuthScope $scope)`, `serviceLabel(string $service)` (`web`, `mail`, `database`, `DNS`), memoized client row and one `server` query per service (read-only query builder, no ISPConfig writes); make T005 pass
- [ ] T007 Implement app/Http/Requests/Concerns/ResolvesAssignedServer.php per research.md R1/R4: (a) `mergeAssignedServerDefault(string $service)` for non-admin scopes in `prepareForValidation()` after base normalization, merging only when `server_id` is absent; (b) `assignedServerRules(string $service)` — admin scopes return the caller's existing rules unchanged; non-admin: `required|integer|min:1` with `server_id.required` message "No {label} server is assigned to this account." plus a closure failing with "The selected server is not available for this account." for any id not in `assignedServerIds`; (c) `slaveDnsServerRules()` (forced default, "No secondary DNS server is assigned to this account."); (d) `immutableServerRule(callable $currentValue)` ("The server cannot be changed after creation.")
- [ ] T008 [P] Update tests/Feature/ClientLimitDnsTest.php `setUp` to `assignServers(...)` for every non-admin tenant that creates DNS zones (confirm need in T025/T043; revert if the file creates no covered resource)
- [ ] T009 [P] Update tests/Feature/ClientLimitMailTest.php `setUp` to assign mail servers for non-admin tenants creating mail domains
- [ ] T010 [P] Update tests/Feature/ClientLimitResellerTest.php `setUp` to assign servers to the reseller rows (reseller keys use their own lists, FR-009)
- [ ] T011 [P] Update tests/Feature/ClientLimitSitesTest.php `setUp` to assign web and database servers for non-admin tenants creating vhosts and databases
- [ ] T012 [P] Update tests/Feature/ClientQuotaSumTest.php `setUp` to assign web, mail and database servers for non-admin tenants
- [ ] T013 [P] Update tests/Feature/ScopingSitesModuleTest.php `setUp` to assign web and database servers for non-admin tenants
- [ ] T014 [P] Update tests/Feature/ScopingDnsModuleTest.php `setUp` to assign DNS servers (and `default_slave_dnsserver` where secondary zones are created)
- [ ] T015 [P] Update tests/Feature/ScopingMailModuleTest.php `setUp` to assign mail servers for non-admin tenants (fetchmail destinations stay within the tenant)
- [ ] T016 [P] Update tests/Feature/ScopedBindingTest.php `setUp` to assign servers where non-admin keys create covered resources
- [ ] T017 [P] Update tests/Feature/AuthScopeTest.php to assign servers where non-admin keys create covered resources

**Checkpoint**: Service, request concern and fixtures ready; the existing suite still passes (lists are not yet enforced).

---

## Phase 3: User Story 1 - Create hosting resources on assigned servers without server ids (Priority: P1) 🎯 MVP

**Goal**: Non-admin keys create vhosts, mail domains, databases and DNS zones on assigned servers, with the first
valid server as default and one indistinguishable 422 for unassigned or nonexistent servers; admin keys unchanged.

**Independent Test**: spec.md US1 Independent Test — client A with `web_servers = "2"`, `mail_servers = "3"`: omitted
`server_id` → server 2 / 3; `server_id = 1` and `99` → identical 422 `errors.server_id`, no `sys_datalog` row; admin
without `server_id` → 422 required (unchanged).

### Tests for User Story 1 (REQUIRED) ⚠️

- [ ] T018 [P] [US1] Write tests/Feature/ClientServerAssignmentTest.php covering the data-model.md validation matrix for `POST /sites/web-domains` (vhost), `/mail/domains`, `/sites/databases`, `/dns/soa`: one and several assigned servers (list order default), assigned id accepted, unassigned / nonexistent / mirror / wrong-flag ids with byte-identical 422 bodies and unchanged `sys_datalog` count, no valid server → "No {label} server is assigned to this account.", `0`/negative/non-integer → 422, reseller key uses its own lists also when creating for one of its clients, vhost subdomain/alias without `server_id` → parent's server, a resource on a server later removed from the list stays updatable and deletable (FR-011), admin keys unchanged (`required`, existing `exists` message)

### Contract for User Story 1 (spec-first)

- [ ] T019 [P] [US1] Per contracts/schema-and-operation-changes.md, remove `server_id` from `required` and add the common description in api/components/schemas/WebDomain.yaml (children use the parent's server), api/components/schemas/MailDomain.yaml and api/components/schemas/DnsSoa.yaml, and update only the description in api/components/schemas/Database.yaml
- [ ] T020 [P] [US1] Append the "Server selection" block to the POST operation descriptions in api/modules/sites/web-domains.yaml, api/modules/mail/domains.yaml, api/modules/sites/databases.yaml and api/modules/dns/soa.yaml (422 cases and messages from the contract changes table)

### Implementation for User Story 1

- [ ] T021 [P] [US1] Use `ResolvesAssignedServer` in app/Http/Requests/StoreWebDomainRequest.php: service `web` only for type `vhost` (default type); for `vhostsubdomain`/`vhostalias` non-admin keys get `sometimes|integer` so `WebDomainService` keeps forcing the parent's server; admin rules unchanged (if the rules live in app/Http/Requests/WebDomainRequest.php, override them for store only)
- [ ] T022 [P] [US1] Use `ResolvesAssignedServer` (service `mail`) in app/Http/Requests/StoreMailDomainRequest.php, keeping the admin `Rule::exists(... mirror_server_id = 0)` rule unchanged
- [ ] T023 [P] [US1] Use `ResolvesAssignedServer` (service `db`) in app/Http/Requests/StoreWebDatabaseRequest.php so `WebDatabaseController::store()` (`assertUniquePerServer`) sees the merged `server_id`
- [ ] T024 [P] [US1] Use `ResolvesAssignedServer` (service `dns`) in app/Http/Requests/StoreDnsSoaRequest.php so `DnsSoaRequest::after()` origin-collision checks run against the merged `server_id`
- [ ] T025 [US1] Run tests/Feature/ClientServerAssignmentTest.php and the full suite; confirm which of T008–T017 were required (research.md R12), revert unneeded fixture edits, and confirm admin-key test files are unmodified (SC-004)

**Checkpoint**: US1 is fully functional and deliverable as the MVP (a customer panel can create resources with a client key).

---

## Phase 4: User Story 2 - Discover assigned servers (Priority: P2)

**Goal**: `GET /me/servers` returns the servers the calling key may use per service with the default marked; no other
server data.

**Independent Test**: spec.md US2 Independent Test — client A with `web_servers = "2,1"`, `mail_servers = "3"`,
`db_servers = ""`, `default_slave_dnsserver = 4`: web 2 (default) and 1, mail 3, empty db, slave 4; admin key: all
eligible servers with system-config defaults.

### Tests for User Story 2 (REQUIRED) ⚠️

- [ ] T026 [P] [US2] Write tests/Feature/MeServersApiTest.php: client and reseller keys (lists in order, first entry `is_default`, invalid entries omitted, empty lists, `dns_slave` object or `null`), admin key (all non-mirror servers per flag ordered by `server_id`, `is_default` from `sys_ini` `sites.default_webserver`/`default_dbserver`, `mail.default_mailserver`, `dns.default_dnsserver`, `dns.default_slave_dnsserver`; no entry marked when the configured default is not eligible), entries contain exactly `server_id`, `server_name`, `is_default` (SC-005), 401 for an invalid key

### Contract for User Story 2 (spec-first)

- [ ] T027 [P] [US2] Create api/components/schemas/AssignedServer.yaml and api/components/schemas/AssignedServers.yaml from specs/016-client-server-assignment/contracts/AssignedServer.yaml and contracts/AssignedServers.yaml (`additionalProperties: false`)
- [ ] T028 [P] [US2] Create api/modules/me/servers.yaml from specs/016-client-server-assignment/contracts/me-servers.yaml and add the `servers` entry to api/modules/me/_index.yaml (create `_index.yaml` in spec 014's layout if T002 found it absent)
- [ ] T029 [US2] Register path `/me/servers` (`$ref: './modules/me/servers.yaml#/~1me~1servers'`) and schemas `AssignedServers`, `AssignedServer` in api/openapi.yaml (depends on T027, T028)

### Implementation for User Story 2

- [ ] T030 [US2] Add `assignedServersView(AuthScope $scope): array` to app/Services/ServerAssignmentService.php per research.md R10 (non-admin from assigned lists and `slaveDnsServerId`; admin from all eligible servers with defaults via `App\Services\SystemConfigService::getSection()`), at most one `client` read, one `server` query and one `sys_ini` read
- [ ] T031 [US2] Create invokable app/Http/Controllers/Api/V1/MeServersController.php returning `assignedServersView()` for the request's AuthScope as a single 200 object (no list envelope)
- [ ] T032 [US2] Add `Route::get('me/servers', MeServersController::class)` to routes/api/me.php; if T002 found the module absent, create routes/api/me.php and add `require __DIR__.'/api/me.php';` to routes/api.php inside the `api.key` group and outside every `scope.admin` group
- [ ] T033 [US2] Run tests/Feature/MeServersApiTest.php and tests/Feature/SwaggerSpecServerTest.php; confirm `/me/servers` is served in `/api/spec`

**Checkpoint**: US1 and US2 both work independently.

---

## Phase 5: User Story 3 - Remaining server-bound writes follow the same boundary (Priority: P3)

**Goal**: Secondary DNS zones use the account's secondary DNS server, fetchmail uses the destination mailbox's server
and only readable destinations (FR-014), and non-admin keys cannot move DNS zones or secondary zones.

**Independent Test**: spec.md US3 Independent Test — `POST /dns/slaves` without `server_id` → server 4, with 5 → 422;
fetchmail to A's mailbox on server 3 → 201 on server 3, with `server_id = 6` → 422, to client B's mailbox → 422
identical to a nonexistent mailbox; `PUT /dns/soa/{id}` with another server → 422, current value → 200; admin
unchanged.

### Tests for User Story 3 (REQUIRED) ⚠️

- [ ] T034 [P] [US3] Write tests/Feature/ClientServerAssignmentWritesTest.php: secondary zones (forced `default_slave_dnsserver`, different id 422, missing/invalid default → "No secondary DNS server is assigned to this account."), fetchmail server derivation (omitted → mailbox server, different id 422), FR-014 destination scoping on create and update (client A with client B's mailbox → 422 on `errors.destination` byte-identical to a nonexistent mailbox, no `sys_datalog` row; admin may use any existing mailbox), `PUT /dns/soa/{id}` and `PUT /dns/slaves/{id}` (different `server_id` → "The server cannot be changed after creation.", current value → 200, admin can change), reseller keys use their own `default_slave_dnsserver`

### Contract for User Story 3 (spec-first)

- [ ] T035 [P] [US3] Remove `server_id` from `required` and set the non-admin descriptions in api/components/schemas/DnsSlave.yaml and api/components/schemas/MailGet.yaml per contracts/schema-and-operation-changes.md
- [ ] T036 [P] [US3] Append the "Server selection" blocks to POST and PUT in api/modules/dns/slave.yaml and to POST and PUT (destination scoping) in api/modules/mail/fetchmail.yaml
- [ ] T037 [US3] Add the non-admin "cannot move the zone" sentence to `server_id` in api/components/schemas/DnsSoa.yaml and the PUT "Server selection" block in api/modules/dns/soa.yaml (same files as T019/T020 — run after them)

### Implementation for User Story 3

- [ ] T038 [P] [US3] Use `slaveDnsServerRules()` in app/Http/Requests/StoreDnsSlaveRequest.php for non-admin scopes, merging the default before `DnsSlaveRequest::after()` and `DnsSlaveController::guardUniqueOrigin` run
- [ ] T039 [P] [US3] Use `immutableServerRule()` against the bound record's stored `server_id` in app/Http/Requests/UpdateDnsSoaRequest.php for non-admin scopes (`sometimes|integer`); admin keeps `sometimes|integer|exists(dns_server, non-mirror)`
- [ ] T040 [P] [US3] Same immutability change in app/Http/Requests/UpdateDnsSlaveRequest.php
- [ ] T041 [US3] Change `existingMailboxRule()` in app/Http/Requests/MailGetRequest.php so non-admin scopes check the normalized destination against `mail_user` through `AuthScope::applyReadPredicate('r')`, failing with the existing nonexistent-mailbox message; admin unchanged (used by both StoreMailGetRequest and UpdateMailGetRequest)
- [ ] T042 [US3] In app/Http/Requests/StoreMailGetRequest.php, for non-admin scopes merge `server_id` from the readable destination mailbox's `mail_user.server_id` when omitted and reject a different value with "The selected server is not available for this account."; merge nothing when the destination is missing or unreadable (depends on T041)
- [ ] T043 [US3] Run tests/Feature/ClientServerAssignmentWritesTest.php plus tests/Feature/DnsSlaveApiTest.php, tests/Feature/DnsSoaApiTest.php and tests/Feature/MailRoutingApiTest.php (admin tests must pass unmodified) and re-confirm T008–T017

**Checkpoint**: All user stories are independently functional.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [ ] T044 [P] Document server assignment for scoped keys and `GET /me/servers` in the "Permission scope" section of README.md (resellers created through the API have no server lists until an admin assigns them — owner decision 2026-09-14)
- [ ] T045 Run the full suite in Docker `php:8.3-cli` and verify with `git diff --stat` that admin-key test files (tests/Feature/WebDomainApiTest.php, MailDomainApiTest.php, WebDatabaseApiTest.php, DnsSoaApiTest.php, DnsSlaveApiTest.php, MailRoutingApiTest.php) are unchanged (SC-004)
- [ ] T046 [P] Check route ordering with `php artisan route:list --path=me` (routes/api/me.php literal paths, no shadowing, outside `scope.admin`)
- [ ] T047 [P] Open `/api/documentation` and confirm `GET /me/servers`, `AssignedServers`, `AssignedServer` render and the six schemas no longer list `server_id` as required (api/openapi.yaml)
- [ ] T048 Execute the manual scenarios of specs/016-client-server-assignment/quickstart.md on a disposable ISPConfig + API installation only (not the shared `/opt/ispconfig-rest` without owner approval), confirming rejected requests add no journal entry

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: no dependencies.
- **Foundational (Phase 2)**: depends on Setup; blocks every user story. T005 depends on T003–T004; T006 on T005;
  T007 on T006; T008–T017 on T004.
- **US1 (Phase 3)**: depends on Foundational.
- **US2 (Phase 4)**: depends on Foundational (T006). Independent of US1; T028/T032 depend on T002.
- **US3 (Phase 5)**: depends on Foundational (T007). T037 edits the same contract files as T019/T020 and must run
  after them; everything else in US3 is independent of US1/US2.
- **Polish (Phase 6)**: after the desired stories.

### Within Each User Story

- Tests first and failing (T018, T026, T034), then contract edits, then requests/services/controllers/routes, then
  the story's test run.
- T029 after T027 + T028; T031 after T030; T032 after T031; T042 after T041.

### Parallel Opportunities

- T003 and T004; T008–T017 once T004 is done.
- US1: T018, T019, T020 together; then T021–T024 together.
- US2: T026, T027, T028 together.
- US3: T034, T035, T036 together; then T038, T039, T040 together.
- With enough capacity, US2 and US3 can proceed in parallel with US1 after Phase 2 (mind T037).

---

## Parallel Example: User Story 1

```bash
# Tests and contract edits together:
Task: "Write tests/Feature/ClientServerAssignmentTest.php (T018)"
Task: "Update WebDomain/MailDomain/DnsSoa/Database schemas (T019)"
Task: "Update POST descriptions in web-domains, domains, databases, soa (T020)"

# Then the four store requests together:
Task: "StoreWebDomainRequest (T021)"
Task: "StoreMailDomainRequest (T022)"
Task: "StoreWebDatabaseRequest (T023)"
Task: "StoreDnsSoaRequest (T024)"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Phase 1 Setup → Phase 2 Foundational.
2. Phase 3 (US1) → **STOP and VALIDATE** with T025: client keys create all four resources without server ids,
   identical 422 bodies, admin tests unchanged.
3. Deliver: this unblocks the WHMCS panel's provisioning with client-scoped keys.

### Incremental Delivery

1. US1 (MVP) → validate → merge.
2. US2 (`GET /me/servers`) → validate → merge (coordinate the `me` module with spec 014).
3. US3 (secondary zones, fetchmail incl. FR-014 destination scoping, DNS server immutability) → validate → merge.

---

## Cross-Feature Notes

- **Spec 014 (API key management)**: `GET /me/servers` extends 014's `me` module layout —
  `api/modules/me/_index.yaml`, `routes/api/me.php` required from `routes/api.php` inside `api.key` and outside
  `scope.admin`. If 016 lands first, create those files in exactly that layout (without `me.yaml`); 014 then adds
  `me.yaml`, its `_index.yaml` entry and its route line.
- **Spec 015 (change status)**: its `X-Change-Set-Id` header is unaffected — this feature only changes validation.
  Rejected requests write no journal entry (no header), and successful creates keep receiving the header from
  015's middleware without changes here. The contract edits in T019/T020/T035–T037 touch the same operation files
  as 015's header references; resolve any merge conflicts textually.
- **Specs 017 and 018**: no shared files expected; 018 also edits `api/modules/sites/web-domains.yaml` and
  `WebDomain.yaml` (backup fields), so rebase before merging.

## Notes

- [P] tasks = different files, no dependency on an incomplete task.
- Owner decisions 2026-09-14: first valid list entry is the default; API-created resellers keep legacy server
  seeding; fetchmail destination scoping (FR-014) is in scope.
- Commit after each task or logical group; stop at each checkpoint to validate the story independently.
