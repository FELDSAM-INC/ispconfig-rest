# Implementation Plan: Client Server Assignment for Non-Admin Keys

**Branch**: `016-client-server-assignment` | **Date**: 2026-09-14 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/016-client-server-assignment/spec.md`

**Status**: Draft — planning stage; this plan describes FUTURE code. No app code changes yet.

## Summary

Non-admin keys (client and reseller) may only place web domains (`vhost`), mail domains, databases and
DNS zones on servers listed in the acting identity's client row (`web_servers`, `mail_servers`,
`db_servers`, `dns_servers`), and get the first valid list entry when they omit `server_id`. Secondary
DNS zones are forced to `default_slave_dnsserver`, fetchmail entries to the destination mailbox's server,
and non-admin updates cannot move DNS zones or secondary zones. Admin keys are byte-identical to today.
A new read-only `GET /me/servers` lets any key discover the servers it may use.

Technical approach (research.md R1): resolution and validation live in the **request layer**. A new
`App\Services\ServerAssignmentService` computes the acting identity's valid servers per service; a new
request trait `ResolvesAssignedServer` (a) merges the default `server_id` in `prepareForValidation()` for
non-admin keys, so every existing downstream per-server check (vhost uniqueness, database name
uniqueness, DNS origin collisions) keeps working unchanged, and (b) swaps the admin `Rule::exists` for an
assignment rule that returns one identical 422 message for unassigned and nonexistent servers. Rejections
happen during validation, before any controller or `sys_datalog` write. No new tables, no ISPConfig
schema changes.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12) — target platform  
**Primary Dependencies**: laravel/framework ^12, Eloquent ORM; dev: phpunit ^11, mockery, faker  
**Storage**: MySQL — ISPConfig's `dbispconfig` (never migrated; writes via `sys_datalog`). New reads only: `client` (`web_servers`, `mail_servers`, `db_servers`, `dns_servers`, `default_slave_dnsserver`), `server` (`server_name`, service flags, `mirror_server_id`), `sys_ini` (system config defaults, admin discovery only), `mail_user.server_id` (fetchmail derivation).  
**Testing**: PHPUnit (`vendor/bin/phpunit`), feature tests in `tests/Feature/` on the SQLite in-memory `tests/Support/*Schema.php` + `TenantFixtures` pattern (feature 011/012) — REQUIRED per constitution v2  
**Target Platform**: Linux server alongside an ISPConfig installation  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: at most one `client` row read (memoized per request) plus one `server` query per non-admin create; admin creates: zero extra queries. `GET /me/servers`: one `client` read + one `server` query (+ one `sys_ini` read for admin keys).  
**Constraints**: admin-key behavior unchanged (FR-005, SC-004 — all existing admin tests pass unmodified); identical 422 body for unassigned vs nonexistent servers (FR-002, SC-003); rejections write no datalog; legacy parity citations per spec; coordination with spec 014 for the `me` module layout.  
**Scale/Scope**: 1 new endpoint (`GET /me/servers`); 8 modified request classes (6 store, 2 update); 1 new service; 1 new request trait; 1 new invokable controller; 1 new route line (+ `routes/api/me.php` if 014 has not merged); contract: 1 new path file, 2 new schemas, 6 schema description/`required` changes, operation descriptions on 8 operations; test support: `TenantSchema` columns + one `TenantFixtures` helper; 3 new feature test files; non-admin create tests of 011/012 updated to assign servers.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: PASS — Phase 1 authors `api/modules/me/servers.yaml`, `AssignedServers.yaml`/`AssignedServer.yaml` and the `server_id` changes of `WebDomain`, `MailDomain`, `Database`, `DnsSoa`, `DnsSlave`, `MailGet` before code (drafts in `contracts/`). All covered POST/PUT operations already declare 422 (verified 2026-09-14: POST web-domains, mail/domains, databases, dns/soa, dns/slaves, mail/fetchmail; PUT dns/soa, dns/slaves).
- [x] **Datalog-only writes (II)**: PASS — no new write path and no ISPConfig table writes. The feature only chooses/validates `server_id` before the existing BaseModel/WebDomainService writes; rejected requests fail validation and write nothing. New code is read-only query-builder SELECTs (same pattern as `ClientLimitService::clientRow`).
- [x] **Legacy parity (III)**: PASS — reviewed on ISPConfig 3.3.1p1 (`/usr/local/ispconfig/interface/web`): `sites/web_vhost_domain_edit.php:115-122,155-181`, `mail/mail_domain_edit.php:134-155,307-312`, `sites/database_edit.php:79-95,185-191,249-253,346`, `dns/dns_soa_edit.php:155-190,272-278`, `dns/dns_slave_edit.php:182-193`, `mail/mail_get_edit.php:97`, `client/form/reseller.tform.php` (resellers carry the same list columns). Deviations are declared in the spec (defaulting for all services, identical 422, 422 instead of silent restore on update, skipping invalid list entries).
- [x] **Route discipline (IV)**: PASS — one new route `GET me/servers` in `routes/api/me.php`, required from `routes/api.php` inside the `api.key` group and outside every `scope.admin` group (any valid key). Literal path; no parameterized `me/{x}` route exists or is planned, so no shadowing. Existing module route files are untouched.
- [x] **HTTP contract (V)**: PASS — rejections are RFC 9457 422 `application/problem+json` with `errors.server_id` via `App\Support\Problem::fromThrowable` (FormRequest `ValidationException`); discovery is a single-resource 200 object (not a list, so no `{data, meta}` envelope); 401 for invalid keys via existing middleware.
- [x] **No schema changes**: PASS — no migrations. Test-only `tests/Support/TenantSchema.php` gains the client list columns and the `server.db_server` flag where a module schema omitted them (production `dbispconfig` already has them).
- [x] **Tests required**: PASS (planned) — three new feature test files cover the FR-013 matrix, and every 011/012 test that creates covered resources with non-admin keys is updated to assign servers (research.md R12).

**Post-design re-check (after Phase 1)**: PASS — the design adds no violations; see Complexity Tracking (empty).

## Project Structure

### Documentation (this feature)

```text
specs/016-client-server-assignment/
├── spec.md
├── plan.md              # This file
├── research.md          # Phase 0: decisions R1–R15
├── data-model.md        # Phase 1: entities, resolution algorithm, validation matrix
├── quickstart.md        # Phase 1: verification steps
├── contracts/
│   ├── me-servers.yaml                     # draft api/modules/me/servers.yaml
│   ├── AssignedServers.yaml                # draft api/components/schemas/AssignedServers.yaml
│   ├── AssignedServer.yaml                 # draft api/components/schemas/AssignedServer.yaml
│   └── schema-and-operation-changes.md     # exact edits to existing contract files
├── checklists/requirements.md
└── tasks.md             # Phase 2 (/speckit-tasks) — NOT created by this command
```

### Source Code (repository root)

```text
api/
├── openapi.yaml                                  # + paths /me/servers; + schemas AssignedServers, AssignedServer
├── modules/me/
│   ├── _index.yaml                               # owned by 014; 016 adds `servers` entry (creates file if 014 not merged)
│   └── servers.yaml                              # NEW — GET /me/servers
├── modules/sites/web-domains.yaml                # POST description: server_id resolution for non-admin keys
├── modules/sites/databases.yaml                  # POST description
├── modules/mail/domains.yaml                     # POST description
├── modules/mail/fetchmail.yaml                   # POST description
├── modules/dns/soa.yaml                          # POST + PUT descriptions
├── modules/dns/slave.yaml                        # POST + PUT descriptions
└── components/schemas/
    ├── AssignedServers.yaml                      # NEW
    ├── AssignedServer.yaml                       # NEW
    ├── WebDomain.yaml, MailDomain.yaml,          # server_id removed from `required` (except Database: never listed),
    ├── DnsSoa.yaml, DnsSlave.yaml, MailGet.yaml  # descriptions document admin vs non-admin behavior
    └── Database.yaml                             # description only

app/
├── Services/ServerAssignmentService.php          # NEW — assigned servers per service, defaults, slave DNS, discovery
├── Http/Requests/Concerns/ResolvesAssignedServer.php   # NEW — default merge + assignment/immutability rules
├── Http/Requests/StoreWebDomainRequest.php       # type vhost: resolve 'web'; child types: server_id optional for non-admin
├── Http/Requests/StoreMailDomainRequest.php      # 'mail'
├── Http/Requests/StoreWebDatabaseRequest.php     # 'db'
├── Http/Requests/StoreDnsSoaRequest.php          # 'dns'
├── Http/Requests/UpdateDnsSoaRequest.php         # non-admin: server_id immutable
├── Http/Requests/StoreDnsSlaveRequest.php        # non-admin: forced default_slave_dnsserver
├── Http/Requests/UpdateDnsSlaveRequest.php       # non-admin: server_id immutable
├── Http/Requests/StoreMailGetRequest.php         # non-admin: destination mailbox's server
└── Http/Controllers/Api/V1/MeServersController.php     # NEW — invokable, GET /me/servers

routes/
├── api.php                                       # require api/me.php inside api.key, outside scope.admin (014 owns; 016 adds if absent)
└── api/me.php                                    # + Route::get('me/servers', MeServersController::class)

tests/
├── Support/TenantSchema.php                      # client: web_servers, mail_servers, db_servers, dns_servers, default_slave_dnsserver; server: db_server (ensureColumns)
├── Support/TenantFixtures.php                    # + assignServers(string $tenant, array $lists, ?int $slaveDns = null)
├── Feature/ClientServerAssignmentTest.php        # NEW — US1 matrix (web/mail/db/dns × admin/client/reseller/no-servers)
├── Feature/ClientServerAssignmentWritesTest.php  # NEW — US3: slaves, fetchmail, SOA/slave PUT immutability
├── Feature/MeServersApiTest.php                  # NEW — US2 discovery
└── Feature/{ClientLimitDnsTest,ClientLimitMailTest,ClientLimitResellerTest,ClientLimitSitesTest,
             ClientQuotaSumTest,ScopingSitesModuleTest,ScopingDnsModuleTest,ScopingMailModuleTest,
             ScopedBindingTest,AuthScopeTest}.php  # setUp assigns servers where non-admin keys create covered resources
```

**Structure Decision**: Single-project layout above. Controllers and services of the covered resources are
not changed: the default is merged into the request input before validation, so `WebDomainService::create`,
`WebDatabaseController::store` (`assertUniquePerServer`), `DnsSoaRequest::after()` and
`DnsSlaveRequest::after()` (origin collisions) and `DnsSlaveController::guardUniqueOrigin` all see the
resolved `server_id`. The only new route is `me/servers`, a literal path in its own module file.

## Dependency on Spec 014 (me module)

Spec 014 owns the `me` module: `api/modules/me/_index.yaml`, `api/modules/me/me.yaml` (`GET /me`) and
`routes/api/me.php`, required from `routes/api.php` inside the `api.key` group and outside `scope.admin`.
016 adds `api/modules/me/servers.yaml`, one `_index.yaml` entry and one route line. If 016 is implemented
before 014 merges, 016 creates `api/modules/me/_index.yaml`, `routes/api/me.php` and the `require` line in
exactly that layout (without `me.yaml`), and 014 then adds its own entries. The invokable
`MeServersController` avoids touching 014's controller.

## Legacy Research (Phase 0 focus)

Summarized in research.md (R2–R9, R11). Key facts from ISPConfig 3.3.1p1:

- The acting identity's lists come from the client row joined through the user's **default group**
  (`sys_group.groupid = $_SESSION['s']['user']['default_group']`), so a reseller uses its own client row,
  also when assigning the new record to one of its clients.
- Web preselects `web_servers[0]`; mail/DNS preselect only when exactly one server is offered; all refuse
  an unlisted server on insert (`server_chosen_not_ok` / `error_not_allowed_server_id`) and silently restore
  the stored `server_id` on update for non-admins.
- Secondary zones take `client.default_slave_dnsserver`; fetchmail takes the destination mailbox's server.
- Admins: any server; defaults from `sys_ini` (`sites.default_webserver`, `sites.default_dbserver`,
  `mail.default_mailserver`, `dns.default_dnsserver`, `dns.default_slave_dnsserver`).

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

No violations.
