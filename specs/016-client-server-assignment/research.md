# Research: Client Server Assignment for Non-Admin Keys

**Feature**: 016-client-server-assignment | **Date**: 2026-09-14

Sources: this repository at `06dc558` + spec 016; ISPConfig 3.3.1p1 on `isp-test.feldhost.cz`
(`/usr/local/ispconfig/interface/web`, read-only) and its live `dbispconfig` (read-only SELECTs).

## R1 — Where resolution and validation live

- **Decision**: Request layer. `App\Services\ServerAssignmentService` computes valid servers; request trait
  `App\Http\Requests\Concerns\ResolvesAssignedServer` merges the non-admin default into the input in
  `prepareForValidation()` (after the base class normalization) and provides the assignment rule closures.
- **Rationale**: Every covered create reads `server_id` from validated input before writing, and several
  per-server checks run during or right after validation: `DnsSoaRequest::after()` / `DnsSlaveRequest::after()`
  (origin collision on the selected server), `WebDatabaseController::store()` (`assertUniquePerServer`,
  `assertPostgresUsersUnused`), `WebDomainService::create()` (`assertUniqueVhost`). Merging the default before
  validation lets all of them work unchanged, and a validation failure guarantees no controller code and no
  `sys_datalog` row runs (FR-002). The errors come out as the standard 422 problem+json `errors.server_id`
  through `Problem::fromThrowable`.
- **Alternatives considered**:
  - *Resolve in controllers/services after validation*: needs changes in six controllers plus
    `WebDomainService`, duplicates per-server checks that already ran against a missing value, and the
    admin `required` rule would have to be relaxed separately.
  - *BaseModel::save() chokepoint (as spec 012)*: `WebDomainService` writes vhosts with a raw insert that
    bypasses `BaseModel`, and defaulting must happen before the per-server uniqueness checks; an error there
    would also not be a field validation error.
  - *Middleware*: would have to know every resource's service and body semantics; no per-field errors.

## R2 — Which lists apply (acting identity)

- **Decision**: The client row `client_id = AuthScope::$clientId` (the key's `sys_user.client_id`), memoized
  per request inside the service. Client keys use their own row; reseller keys use the reseller's own row,
  also when `client_id` assigns the new record to one of their clients (FR-009). Admin scopes never read it.
- **Rationale**: Legacy resolves `SELECT client.web_servers FROM sys_group, client WHERE sys_group.client_id =
  client.client_id AND sys_group.groupid = ?` with the logged-in user's `default_group`
  (`web_vhost_domain_edit.php:115`, `mail_domain_edit.php:134`, `database_edit.php:79`, `dns_soa_edit.php:155`).
  `ApiKeyAuth` binds client keys to exactly that user/group pair (feature 011 FR-019), and
  `AuthScope::$clientId` already carries `sys_user.client_id` (used by `ClientLimitService::clientRow`).
- **Alternatives considered**: target client's lists for reseller-created resources (legacy does not do it);
  merging lists of all groups in `sys_user.groups` (legacy does not).
- **Edge**: a non-admin user without a client row (`client_id = 0`) has empty lists → creates return the
  "no server assigned" 422 and discovery returns empty lists.

## R3 — Valid entries, order and default

- **Decision**: Parse the CSV (trim, integers > 0, drop duplicates keeping first occurrence), then keep only ids
  of existing servers with `mirror_server_id = 0` and the service flag (`web_server`, `mail_server`, `db_server`,
  `dns_server` = 1), preserving list order. The default is the first remaining id (owner decision 2026-09-14).
  `server.active` is ignored.
- **Rationale**: Legacy web preselects `web_servers[0]` (`web_vhost_domain_edit.php:116-117`); the API needs one
  rule for all services. Filtering prevents offering or defaulting to deleted, mirror or wrong-type servers
  (declared deviation). Legacy never filters by `active`.
- **Implementation note**: one `server` query per service: `whereIn('server_id', $ids)->where($flag, 1)
  ->where('mirror_server_id', 0)`, re-ordered in PHP by list position.

## R4 — Error shapes

- **Decision** (all 422 problem+json, `errors.server_id`):
  - Unassigned **or** nonexistent / mirror / wrong-flag id (non-admin): `The selected server is not available
    for this account.` — one message for all, so ids cannot be probed (FR-002, SC-003).
  - No valid server for the service (non-admin, with or without `server_id`): `No {web|mail|database|DNS} server
    is assigned to this account.` (FR-004). Reached through the rule closure when a value is sent and through
    the `server_id.required` message when no default could be merged.
  - Secondary DNS without a valid `default_slave_dnsserver`: `No secondary DNS server is assigned to this account.`
  - Different `server_id` on non-admin DNS zone / secondary zone update: `The server cannot be changed after
    creation.` (same wording as the existing immutable rules in `SitesRequest::immutableRule` and
    `NormalizesMailInput::immutableAttributeRule`).
  - Non-integer or `< 1` values: Laravel `integer` / `min` messages (no information about servers).
- **Admin keys**: rules untouched — `required`, `integer`, `Rule::exists(...)` → Laravel's existing
  `The selected server id is invalid.` stays (SC-004).

## R5 — Web domains: vhost vs vhost children

- **Decision**: Resolve/validate the `web` service only for `type = vhost` (the default type). For
  `vhostsubdomain`/`vhostalias` non-admin keys may omit `server_id` (`sometimes|integer`); `WebDomainService`
  already forces the parent's server, group and quota (FR-012). Admin rules unchanged (`required`).
- **Rationale**: Legacy's server check is only applied on insert of `type vhost`
  (`web_vhost_domain_edit.php` `server_chosen_not_ok`), children inherit from the parent.

## R6 — Databases

- **Decision**: Service `db` from `client.db_servers`; the parent site's server is not forced.
- **Rationale**: Legacy `database_edit.php:249-253` checks `db_servers`, not the parent web server; remote
  database servers are legitimate. Default merged before `assertUniquePerServer()` runs.
- **Contract note**: `Database.yaml` never listed `server_id` as required although `StoreWebDatabaseRequest`
  requires it; the description now states it is required for admin keys (no `required` change needed).

## R7 — Secondary DNS zones

- **Decision**: Non-admin: the value is `client.default_slave_dnsserver`, valid only if it references an
  existing non-mirror `dns_server = 1` server. Omitted → merged; a different value → R4 not-available 422;
  missing/invalid default → R4 secondary-DNS 422. Admin unchanged.
- **Rationale**: `dns_slave_edit.php:182-193` forces the client's `default_slave_dnsserver` for non-admins.

## R8 — Fetchmail

- **Decision**: Non-admin: the value is `mail_user.server_id` of the destination mailbox (after the
  existing IDN/lower-case normalization). Omitted → merged; a different value → R4 not-available 422. When
  the mailbox does not exist or is not readable by the key, the `existingMailboxRule` error on `destination` applies and no server
  is merged. Admin unchanged.
- **Rationale**: `mail_get_edit.php:97` always copies the destination mailbox's `server_id`.
- **Decision (owner decision 2026-09-14), destination scoping**: `MailGetRequest::existingMailboxRule()` checked existence
  without the read predicate, so a non-admin key could target another tenant's mailbox (a gap feature 011 left in
  reference checks). For non-admin keys the rule now checks existence through the key's read predicate
  (`AuthScope::applyReadPredicate('r')` on `mail_user`), used by both `StoreMailGetRequest` and
  `UpdateMailGetRequest`. An unreadable mailbox fails with the same message as a nonexistent one, so other tenants'
  addresses cannot be probed; admin keys are unchanged. Legacy parity: the destination datasource in
  `mail/form/mail_get.tform.php` selects `email FROM mail_user WHERE {AUTHSQL}`.

## R9 — Server changes on update

- **Decision**: Non-admin `PUT /dns/soa/{id}` and `PUT /dns/slaves/{id}`: `server_id` → `sometimes|integer` plus
  an immutability closure against the bound record's raw `server_id` (current value accepted, FR-008). Admin
  keeps `sometimes|integer|exists(dns_server, non-mirror)`. Web domains, mail domains, databases and fetchmail
  are already immutable for all keys (`UpdateWebDatabaseRequest`, `UpdateMailGetRequest`, and the existing
  update requests) — no change.
- **Rationale**: Legacy silently restores the stored `server_id` for non-admins (`dns_soa_edit.php`,
  `dns_slave_edit.php`); the API rejects instead (declared deviation, consistent with other immutable fields).

## R10 — Discovery (`GET /me/servers`)

- **Decision**: Response `AssignedServers` object: `web`, `mail`, `db`, `dns` arrays of `AssignedServer`
  `{server_id, server_name, is_default}` in list order, and `dns_slave` as one `AssignedServer` or `null`.
  - Non-admin: from R2/R3 lists; `is_default` true for the first entry of each list; `dns_slave` from R7 with
    `is_default: true`.
  - Admin: all non-mirror servers per flag ordered by `server_id`; `is_default` from `SystemConfigService::
    getSection('sites')['default_webserver']`, `['default_dbserver']`, `getSection('mail')['default_mailserver']`,
    `getSection('dns')['default_dnsserver']` (true only when that id is in the list; otherwise no entry is marked);
    `dns_slave` = the `dns.default_slave_dnsserver` server when eligible, else `null`.
  - No other fields (FR-010, SC-005); 200 for any valid key; not paginated (bounded by server count).
- **Controller**: invokable `App\Http\Controllers\Api\V1\MeServersController`, thin; logic in the service.
- **Alternatives considered**: extending `GET /me` from spec 014 (couples the specs, bigger payload for every
  identity check); a filtered `GET /servers` for non-admins (would violate the 011 admin-only module gate and
  leak admin fields).

## R11 — Reseller rows and API-created resellers

- **Finding**: `client/form/reseller.tform.php` carries the same `web_servers`/`mail_servers`/`db_servers`/
  `dns_servers` list fields as clients (lines 726, 1075, 1294, 1392) plus `default_*server`. On isp-test the
  normal client row has `web=1 mail=1 db=1 dns=1 slave=1`, the reseller row's lists are empty/NULL.
- **Consequence**: `ClientService` seeds only `default_*` ids for resellers created through the API
  (`reseller_edit.php` parity), not the lists. Such reseller keys get the "no server assigned" 422 until an
  admin sets the lists (`PUT /clients/{id}` or `/resellers/{id}`). This is legacy-consistent (the legacy reseller
  sees no server options either) and is documented in the operation descriptions. Owner decision 2026-09-14: keep
  this legacy seeding; reseller list seeding is not changed.

## R12 — Test fixtures and regression scope

- **Decision**:
  - `TenantSchema`: add `web_servers`, `mail_servers`, `db_servers`, `dns_servers` (nullable string) and
    `default_slave_dnsserver` (unsigned int, default 0) to the `client` create and to the `ensureColumns`
    back-fill branch; back-fill `server.db_server` where a module schema (`DnsSchema`, `MailSchema`,
    `MailCompletionSchema`) omitted it.
  - `TenantFixtures::assignServers(string $tenant, array $lists, ?int $slaveDns = null)` writes the CSV columns.
  - Update tests that create covered resources with non-admin keys to assign servers in `setUp`: candidates
    found by search — `ClientLimitDnsTest`, `ClientLimitMailTest`, `ClientLimitResellerTest`,
    `ClientLimitSitesTest`, `ClientQuotaSumTest`, `ScopingSitesModuleTest`, `ScopingDnsModuleTest`,
    `ScopingMailModuleTest`, `ScopedBindingTest`, `AuthScopeTest` (confirm each during implementation).
  - Admin-key test files (`WebDomainApiTest`, `MailDomainApiTest`, `WebDatabaseApiTest`, `DnsSoaApiTest`,
    `DnsSlaveApiTest`, `MailRoutingApiTest`, …) stay unmodified (SC-004).
- **Rationale**: existing fixtures leave client lists empty, so every non-admin create would start returning
  422 — intended behavior that the old tests do not model.

## R13 — Contract `required` and descriptions

- **Decision**: Remove `server_id` from `required` in `WebDomain.yaml`, `MailDomain.yaml`, `DnsSoa.yaml`,
  `DnsSlave.yaml`, `MailGet.yaml`; keep the property. Descriptions state: required for admin keys; resolved from
  the account's assigned servers for client/reseller keys (with the per-resource rule); unassigned servers
  rejected with 422. Operation descriptions list the 422 cases. Exact edits in
  `contracts/schema-and-operation-changes.md`.
- **Rationale**: one schema serves both key types; JSON Schema cannot express key-dependent requiredness, and the
  project documents behavior in descriptions (as for server-generated fields).

## R14 — Coordination with spec 014

- **Decision**: 014 owns `api/modules/me/_index.yaml`, `me.yaml` and `routes/api/me.php` (inside `api.key`,
  outside `scope.admin`). 016 adds `servers.yaml`, an `_index.yaml` entry, `openapi.yaml` path
  `/me/servers` and one route line, creating the module files in that layout if 016 lands first.
- **Rationale**: owner coordination decision; keeps `/me` and `/me/servers` in one module and one route file.

## R15 — Local verification environment

- **Finding**: the project requires PHP ≥ 8.3; this workstation's default PHP is 8.1, so tests were not run
  during planning.
- **Decision**: quickstart uses `docker run --rm -v "$PWD":/app -w /app php:8.3-cli vendor/bin/phpunit` (after
  `composer install` in the same image) or the test server's PHP 8.3 in a disposable checkout; never the live
  `/opt/ispconfig-rest` installation.
