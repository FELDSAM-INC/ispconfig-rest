# Feature Specification: Client Server Assignment for Non-Admin Keys

**Feature Branch**: `016-client-server-assignment`  
**Created**: 2026-09-14  
**Status**: Draft  
**Module**: cross-cutting (sites / mail / dns) plus caller server discovery  
**Input**: User description: "Client-scoped keys must create web domains, mail domains, databases and DNS zones without knowing server ids: default the server from the client's assigned servers, reject servers the client is not allowed to use, and let a key discover which servers it may use. Admin keys stay unchanged."

## Context

Every ISPConfig client row lists the servers it may use per service: `web_servers`, `mail_servers`,
`db_servers` and `dns_servers` (comma-separated server ids), plus `default_slave_dnsserver` for
secondary DNS zones. The legacy panel only offers those servers to non-admin users and refuses any
other server on submit ("Chosen server is not allowed for this account.").

The API today ignores these lists. Creating a web domain, mail domain, database or DNS zone only
checks that `server_id` exists and has the matching service flag, and `server_id` is required even
for client keys, which cannot list servers because `/servers/**` is admin-only (feature 011). A
client-scoped key can therefore place resources on any server of the installation, and a consumer
such as a customer hosting panel cannot create anything without knowing internal server ids.
Feature 011 explicitly left field-level restrictions such as `server_id` out of scope; this feature
closes that gap for server selection.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Create hosting resources on assigned servers without server ids (Priority: P1)

A customer panel acting with a client-scoped key creates a website, a mail domain, a database and a
DNS zone. It does not send `server_id`; the API places each resource on a server assigned to that
client for the service. If the panel does send a `server_id`, the API accepts it only when that server
is assigned to the client.

**Why this priority**: This is both the isolation boundary (a client must not consume servers it has not
been assigned) and the precondition for any consumer that serves customers with client-scoped keys.
It is a viable MVP on its own.

**Independent Test**: Seed web servers 1 and 2, mail server 3 and client A with `web_servers = "2"`,
`mail_servers = "3"`. With A's key, `POST /sites/web-domains` without `server_id` → 201 with
`server_id = 2`; with `server_id = 1` → 422 with an `errors.server_id` entry and no `sys_datalog` row;
with `server_id = 99` (nonexistent) → the identical 422 body. `POST /mail/domains` without `server_id`
→ 201 on server 3. With an admin key, `POST /sites/web-domains` with `server_id = 1` → 201 and without
`server_id` → 422 (unchanged).

**Acceptance Scenarios**:

1. **Given** a non-admin key whose client has exactly one valid assigned server for a service, **When** it
   creates a web domain (type `vhost`), mail domain, database or DNS zone without `server_id`, **Then** 201
   and the resource is created on that server; the response shows the resolved `server_id`.
2. **Given** a client with several assigned servers for a service, **When** its key creates the resource
   without `server_id`, **Then** the resource is created on the first valid server in the client's list
   order (legacy web form preselection).
3. **Given** a non-admin key, **When** it supplies a `server_id` that is assigned to its client and eligible
   for the service, **Then** the create proceeds as today.
4. **Given** a non-admin key, **When** it supplies a `server_id` that exists but is not assigned to its client,
   **Then** 422 problem+json with an `errors.server_id` message identical to the one for a nonexistent
   server, and no `sys_datalog` row is written.
5. **Given** a client with no valid assigned server for a service (empty list, or only deleted, mirror or
   wrong-type servers), **When** its key creates such a resource with or without `server_id`, **Then** 422
   with `errors.server_id` stating that no server of that type is assigned to the account.
6. **Given** an admin key, **When** it creates any of these resources, **Then** behavior is unchanged:
   `server_id` is required and any existing non-mirror server with the service flag is accepted.

---

### User Story 2 - Discover assigned servers (Priority: P2)

Before offering a choice (or to show where a new website will live), a consumer asks the API which
servers the calling key may use per service and which one is used by default.

**Why this priority**: Consumers can already create resources after US1 by omitting `server_id`; discovery
is needed only when a customer is offered a choice between several servers or for display.

**Independent Test**: Client A with `web_servers = "2,1"`, `mail_servers = "3"`, `db_servers = ""`,
`default_slave_dnsserver = 4`. `GET /me/servers` with A's key → 200 with web servers 2 (default) and 1,
mail server 3 (default), an empty db list, and slave DNS server 4; each entry contains only the server id,
server name and default flag. With an admin key → every eligible server per service, defaults taken from
the system configuration.

**Acceptance Scenarios**:

1. **Given** a non-admin key, **When** it calls `GET /me/servers`, **Then** 200 returns, for `web`, `mail`,
   `db` and `dns`, the valid servers from its client's lists in list order with the default marked, and for
   `dns_slave` the client's `default_slave_dnsserver` (or null).
2. **Given** list entries that reference deleted servers, mirror servers or servers without the service
   flag, **When** servers are discovered, **Then** those entries are omitted.
3. **Given** an admin key, **When** it calls `GET /me/servers`, **Then** 200 returns all non-mirror servers
   per service flag, with the defaults configured in the system configuration (`default_webserver`,
   `default_mailserver`, `default_dbserver`, `default_dnsserver`, `default_slave_dnsserver`).
4. **Given** any key, **When** the response is returned, **Then** it contains no other server data (no
   configuration, IP addresses, status or system fields).

---

### User Story 3 - Remaining server-bound writes follow the same boundary (Priority: P3)

Secondary DNS zones, fetchmail entries and server changes on update behave for non-admin keys the way
the legacy panel does, so no remaining path lets a client choose an unassigned server.

**Why this priority**: Lower-volume resources and update paths; the main creates are covered by US1.

**Independent Test**: Client A with `default_slave_dnsserver = 4`. With A's key, `POST /dns/slaves` without
`server_id` → 201 on server 4; with `server_id = 5` → 422. `POST /mail/fetchmail` for destination
`info@a.example` (mailbox on server 3) without `server_id` → 201 on server 3; with `server_id = 6` → 422.
`PUT /dns/soa/{A's zone}` with a different `server_id` → 422; with the current value → 200. Admin key:
`PUT /dns/soa/{id}` with another DNS server → 200 (unchanged). With A's key, `POST /mail/fetchmail` with client B's
mailbox as destination → 422 identical to a nonexistent mailbox.

**Acceptance Scenarios**:

1. **Given** a non-admin key, **When** it creates a secondary DNS zone, **Then** the server is the acting
   client's `default_slave_dnsserver`; a supplied different `server_id` returns 422, and a missing or invalid
   default returns 422 stating that no secondary DNS server is assigned.
2. **Given** a non-admin key, **When** it creates a fetchmail entry, **Then** the server is the destination
   mailbox's server; a supplied different `server_id` returns 422.
3. **Given** a non-admin key, **When** it updates a DNS zone or secondary DNS zone with a `server_id` other than
   the current one, **Then** 422; sending the current value is accepted.
4. **Given** web domains, mail domains and databases, **When** any key updates them, **Then** `server_id` stays
   immutable as today.
5. **Given** a non-admin key, **When** it creates or updates a fetchmail entry whose destination is a mailbox the key
   cannot read (another tenant's), **Then** 422 on `errors.destination` with the same message as for a nonexistent
   mailbox, and no datalog row is written (owner decision 2026-09-14; legacy offers only readable mailboxes).

### Edge Cases

- Missing or invalid `X-API-Key` → 401 (existing behavior).
- `server_id` of 0, negative or non-integer → 422.
- Allowed lists with whitespace, duplicates or trailing commas are normalized before use.
- A reseller key uses the reseller's own assigned servers, also when it creates a resource owned by one of
  its clients (legacy uses the acting user's group, not the target client's).
- Resources that inherit their server from a parent (vhost subdomains and aliases, child domains, FTP,
  shell and WebDAV users, web folders, cron jobs, mailboxes, forwards, DNS records) keep inheriting it; the
  parent is already visible only if the key may read it (feature 011).
- A resource already located on a server that was later removed from the client's list stays readable,
  updatable and deletable; only new placements are restricted.
- A server marked inactive but assigned to the client remains usable (legacy does not filter by status).
- Concurrent change of a client's lists between discovery and create: the create is validated against the
  lists at the time of the create.
- A fetchmail destination owned by another tenant is treated exactly like a nonexistent mailbox for non-admin keys
  (no probing of other tenants' mailbox addresses); admin keys may use any existing mailbox.

## API Contract *(mandatory)*

- **Spec file(s)**:
  - `api/modules/sites/web-domains.yaml` (existing — POST: `server_id` optional for non-admin keys, 422
    documented)
  - `api/modules/mail/domains.yaml` (existing — POST, same change)
  - `api/modules/sites/databases.yaml` (existing — POST, same change)
  - `api/modules/dns/soa.yaml` (existing — POST optional server, PUT server change rejected for
    non-admin keys)
  - `api/modules/dns/slave.yaml` (existing — POST server forced for non-admin keys, PUT server change
    rejected)
  - `api/modules/mail/fetchmail.yaml` (existing — POST server derived for non-admin keys)
  - path file for `GET /me/servers`, next to the `/me` path introduced by feature 014 (new)
- **Shared schemas**: `WebDomain.yaml`, `MailDomain.yaml`, `Database.yaml`, `DnsSoa.yaml`, `DnsSlave.yaml`,
  `MailGet.yaml` (existing — `server_id` no longer listed as required; description states it is required for
  admin keys and resolved for non-admin keys); `AssignedServers.yaml` (new — per-service lists of
  `{server_id, server_name, is_default}`; `dns_slave` single entry or null). Shared problem responses reused.
- **Endpoints**:

| Method | Path | Purpose | Success code |
|--------|------|---------|--------------|
| POST | `/api/v1/sites/web-domains` | Create vhost; non-admin: default or validate assigned web server | 201 |
| POST | `/api/v1/mail/domains` | Create mail domain; non-admin: default or validate assigned mail server | 201 |
| POST | `/api/v1/sites/databases` | Create database; non-admin: default or validate assigned DB server | 201 |
| POST | `/api/v1/dns/soa` | Create DNS zone; non-admin: default or validate assigned DNS server | 201 |
| PUT | `/api/v1/dns/soa/{id}` | Update DNS zone; non-admin keys cannot change `server_id` | 200 |
| POST | `/api/v1/dns/slaves` | Create secondary zone; non-admin: client's `default_slave_dnsserver` | 201 |
| PUT | `/api/v1/dns/slaves/{id}` | Update secondary zone; non-admin keys cannot change `server_id` | 200 |
| POST | `/api/v1/mail/fetchmail` | Create fetchmail entry; non-admin: destination mailbox's server | 201 |
| GET | `/api/v1/me/servers` | Servers the calling key may use per service, with defaults | 200 |

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference** (ISPConfig 3.3.1p1, `interface/web/`):
  - `sites/web_vhost_domain_edit.php` — client users: `onShowNew` preselects the first entry of
    `client.web_servers`; `onShowEnd` offers only the client's `web_servers`; `onSubmit` on insert of a
    `vhost` errors `server_chosen_not_ok` when the server is not in the list; on update the stored
    `server_id` is restored. Admins get `default_webserver` from the sites config and any web server.
  - `mail/mail_domain_edit.php` — non-admins: select limited to `mail_servers` (single server preselected);
    on insert `error_not_allowed_server_id` ("Chosen server is not allowed for this account.") when not in
    `mail_servers`; on update `server_id` restored. Admin default `default_mailserver` from mail config.
  - `sites/database_edit.php` — non-admins limited to `db_servers`, `error_not_allowed_server_id` on submit;
    admin default `default_dbserver`.
  - `dns/dns_soa_edit.php` (and `dns_wizard.php`, `dns_import.php`) — non-admins limited to `dns_servers`,
    `error_not_allowed_server_id`, `server_id` restored on update; admin default `default_dnsserver`.
  - `dns/dns_slave_edit.php` — non-admins: insert forces `client.default_slave_dnsserver`, update restores the
    stored `server_id`; admin default `default_slave_dnsserver` from DNS config.
  - `mail/mail_get_edit.php` — `server_id` is always taken from the destination mailbox; the destination datasource
    (`mail/form/mail_get.tform.php`) lists only mailboxes matching `{AUTHSQL}`.
  - Resellers (`has_clients`) are non-admins: their own client row's lists apply.
- **Legacy behaviors to mirror**: restriction to the acting identity's lists on insert, the legacy default
  (first list entry), forced secondary DNS server, fetchmail server derivation and readable-only destination mailboxes, server immutability on update
  for non-admins, unrestricted admins.
- **Tables written (via datalog only)**: no new tables. The feature validates or resolves `server_id` before the
  existing `i`/`u` datalog writes of `web_domain`, `mail_domain`, `web_database`, `dns_soa`, `dns_slave` and
  `mail_get`; rejected requests write nothing. `GET /me/servers` is read-only (`client`, `server`,
  `sys_ini` system config).
- **System fields handling**: unchanged; the resolved `server_id` is stored on the record and carried as the
  datalog entry's `server_id`.
- **Intentional deviations from legacy**:
  - Omitted `server_id` is resolved for every covered resource (legacy UI requires an explicit choice when
    several servers are offered, except web which preselects the first entry); the API uses the same
    first-valid-entry rule for all services.
  - Unassigned and nonexistent servers return the same 422 message, so callers cannot probe server ids.
  - A different `server_id` sent by a non-admin key on update, or on secondary zone / fetchmail create, returns
    422 instead of being silently replaced (API convention for immutable fields; the current value is
    accepted).
  - List entries that reference deleted, mirror or wrong-type servers are skipped when resolving defaults and
    in discovery (legacy would offer a broken option).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: For non-admin keys (client and reseller), creating a web domain of type `vhost`, a mail domain, a
  database or a DNS zone MUST only accept a `server_id` that appears in the acting identity's list for the
  service (`web_servers`, `mail_servers`, `db_servers`, `dns_servers`) and is an existing non-mirror server with
  the matching service flag.
- **FR-002**: A rejected `server_id` MUST return 422 problem+json with an `errors.server_id` message identical to
  the one returned for a nonexistent server, and MUST NOT write a `sys_datalog` row.
- **FR-003**: When a non-admin key omits `server_id` on those creates, the system MUST use the first valid server
  in the acting identity's list order and return it in the created resource.
- **FR-004**: When the acting identity has no valid server for the service, the create MUST return 422 with an
  `errors.server_id` message stating that no server of that type is assigned to the account.
- **FR-005**: Admin keys MUST keep the current behavior on every covered endpoint: `server_id` required where it is
  required today, any existing non-mirror server with the service flag accepted, no list checks, no defaulting.
- **FR-006**: For non-admin keys, creating a secondary DNS zone MUST use the acting identity's
  `default_slave_dnsserver`; a different supplied `server_id` or a missing/invalid default MUST return 422.
- **FR-007**: For non-admin keys, creating a fetchmail entry MUST use the destination mailbox's server; a different
  supplied `server_id` MUST return 422.
- **FR-008**: For non-admin keys, updating a DNS zone or secondary DNS zone MUST reject a `server_id` different from
  the stored one with 422 and accept the current value; `server_id` of web domains, mail domains and databases
  remains immutable for all keys.
- **FR-009**: Reseller keys MUST be checked against the reseller's own lists, including when the created resource is
  owned by one of the reseller's clients.
- **FR-010**: `GET /me/servers` MUST return, for any valid key, the servers it may use per service (`web`, `mail`,
  `db`, `dns` lists in list order and `dns_slave` single server or null), each with only server id, server name
  and default flag; for admin keys all eligible servers with the system configuration defaults.
- **FR-011**: Resources already on a server that is no longer in the acting identity's list MUST remain readable,
  updatable and deletable within the existing rules.
- **FR-012**: Resources that inherit their server from a parent MUST keep inheriting it unchanged.
- **FR-013**: The OpenAPI contract MUST be updated before implementation, and feature tests MUST cover admin,
  client and reseller keys with one, several and no assigned servers, unassigned, nonexistent and mirror server
  ids, and the absence of datalog rows on rejection.
- **FR-014**: For non-admin keys, creating or updating a fetchmail entry MUST only accept a `destination` mailbox the
  key can read; any other mailbox MUST return 422 on `errors.destination` with the same message as a nonexistent
  mailbox and MUST NOT write a `sys_datalog` row (owner decision 2026-09-14). Admin keys keep accepting any existing mailbox.

### Key Entities

- **Client server assignment**: the servers a client may use per service — table `client` columns `web_servers`,
  `mail_servers`, `db_servers`, `dns_servers` (CSV) and `default_slave_dnsserver`, schema
  `api/components/schemas/Client.yaml`, model `app/Models/Client.php`.
- **Server**: a server with service flags — table `server` (`server_name`, `web_server`, `mail_server`,
  `db_server`, `dns_server`, `mirror_server_id`), schema `api/components/schemas/Server.yaml`, model
  `app/Models/Server.php`; exposed to non-admins only through the assigned servers view.
- **Assigned servers view**: per-service servers usable by the calling key with the default marked — schema
  `api/components/schemas/AssignedServers.yaml` (new).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: With only a client-scoped key, a consumer creates a website, a mail domain, a database and a DNS zone
  in four requests without supplying any server id, and every resource lands on a server assigned to that client.
- **SC-002**: Across the test matrix, zero resources are created by non-admin keys on servers outside their assigned
  servers.
- **SC-003**: A caller cannot distinguish an unassigned server id from a nonexistent one in any response.
- **SC-004**: All existing admin-key feature tests for the covered endpoints pass without modification.
- **SC-005**: The server discovery response contains no field other than server id, server name and default flag.
- **SC-006**: Zero fetchmail entries created or updated by non-admin keys deliver into another tenant's mailbox.

## Assumptions

- The first valid entry of a client's list is the default for web, mail, database and DNS servers (owner decision
  2026-09-14; consumers that need a different assigned server pass `server_id`); client rows do not carry
  per-service defaults except `default_slave_dnsserver`.
- Server status (`active`) is not considered when validating or defaulting, matching legacy.
- Admin keys do not gain defaulting from the system configuration; they only see those defaults through
  `GET /me/servers`.
- The `/me` base path follows feature 014; this feature can ship independently of 014's key endpoints.
- Resources the API does not expose (mailing lists, XMPP, virtual servers, DNS wizard and import) are out of scope.
- Reseller management is not needed by the first consumer (WHMCS panel), but reseller parity is included because the
  same checks apply to all non-admin keys.
- Resellers created through the API keep legacy seeding: only `default_*` servers are set and the `*_servers` lists stay
  empty, so a reseller key gets the "no server assigned" 422 until an admin assigns lists (owner decision 2026-09-14).
