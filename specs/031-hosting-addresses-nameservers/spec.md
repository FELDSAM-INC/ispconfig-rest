# Feature Specification: Hosting Addresses and Name Servers for Scoped Keys

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-16  
**Status**: Draft  
**Module**: me (plus server and dns data)  
**Input**: User description: "Hosting addresses and name servers for scoped keys: client keys need, for their assigned web/mail/dns servers, the public IPv4/IPv6 addresses usable for A/AAAA records (legacy server_ip rows with client visibility / `ip_type`, `virtualhost` flags; don't leak other clients' dedicated IPs — legacy client_id on server_ip), and the name server host names + IPs to give to a registrar (legacy DNS server config / SOA defaults `ns` from system config or dns templates). Proposed: extend `GET /me/servers` entries with `ipv4[]`, `ipv6[]` (public, client-usable) and a `GET /me/nameservers` (or include in /me/servers dns block) — decide per legacy data, document. Must fit the WHMCS module `specs/005-dns/contracts/ispconfig-rest-calls.md` (`GET /me/hosting-addresses` → web addresses, mail host, name servers)."

## Context

A customer who manages DNS in the panel needs three facts about the hosting: which addresses to use in A/AAAA records
so a domain reaches its website, which host name receives its mail, and which name servers to enter at the domain
registrar. The API only exposes server addresses to administrators (`/servers/{id}/ip-addresses`), and `/me/servers`
deliberately carries only server ids and names. The WHMCS module therefore falls back to the address and name server
fields of its own WHMCS server entry, which are often empty or out of date.

ISPConfig keeps the addresses in `server_ip` (shared addresses with `client_id = 0`, addresses dedicated to one
client otherwise). It has no "name server" setting: the zone import (`dns_import.php`) treats the DNS server holding
the zone, its mirror servers and the system setting "External DNS servers" (`dns_external_slave_fqdn`) as the
installation's name servers and creates NS records for exactly those names. The zone wizard asks for NS1/NS2 as free
text.

This feature adds one read-only account endpoint that describes the hosting addresses and name servers of an account
from these sources, without exposing other clients' dedicated addresses.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Panel points a domain at the customer's hosting (Priority: P1)

A customer creates a zone and chooses "Use my hosting for the website". The panel reads the account's hosting
addresses and suggests A and AAAA records with the public addresses of the customer's web server, and an MX record
with the host name of its mail server.

**Why this priority**: wrong addresses break customer websites; the module currently depends on manually maintained
WHMCS server fields.

**Independent Test**: create servers with shared, dedicated (own and other client), private and IPv6 addresses;
assign them to a client; call `GET /me/hosting-addresses` with client, reseller and admin keys and compare the lists.

**Acceptance Scenarios**:

1. **Given** a client assigned to web server 1 with shared public addresses `192.0.2.10` (IPv4) and `2001:db8::10`
   (IPv6), **When** its key reads `GET /me/hosting-addresses`, **Then** `web` contains server 1 with `is_default:
   true`, `ipv4: ["192.0.2.10"]` and `ipv6: ["2001:db8::10"]`.
2. **Given** an address of server 1 dedicated to this client, **Then** it is listed; **given** an address dedicated
   to another client, **then** it is never listed.
3. **Given** a private or reserved address (e.g. `10.0.0.5`, `127.0.0.1`, `fd00::5`, `fe80::1`), **Then** it is not
   listed (it cannot be used in public DNS).
4. **Given** addresses marked as "HTTP NameVirtualHost" or not, **Then** both are listed.
5. **Given** mail server 3 assigned, **Then** `mail` contains server 3 with its `server_name` (the host for MX
   records) and its public addresses.
6. **Given** a website or mail domain of the client on a server that is not in the account's assignment lists,
   **Then** that server follows the assigned servers in the list, with `is_default: false`.

---

### User Story 2 - Panel tells the customer which name servers to enter at the registrar (Priority: P1)

A customer's domain is registered elsewhere. The panel shows the name servers (and their addresses for glue records)
that answer for the customer's zones.

**Why this priority**: a domain delegated to the wrong name servers is unreachable; the module has no reliable source.

**Independent Test**: create a primary DNS server with two mirrors, a second primary and the external DNS servers
setting; assign DNS servers to a client; compare the `dns[].nameservers` lists.

**Acceptance Scenarios**:

1. **Given** DNS server 4 (`ns1.example.com`) assigned to the client, its mirror 5 (`ns2.example.com`) and the setting
   `dns_external_slave_fqdn = "ns3.example.net, ns4.example.org."`, **Then** `dns` contains server 4 with
   `nameservers` `ns1.example.com`, `ns2.example.com` (the server and its mirrors ordered by name), then
   `ns3.example.net`, `ns4.example.org` (trailing dots removed), each with the public addresses of its server
   (external name servers have empty address lists).
2. **Given** a zone of the client on another primary DNS server, **Then** that server follows with its own name
   servers.
3. **Given** a client without DNS servers and without zones, **Then** `dns` is empty.

---

### User Story 3 - Reseller and administrator keys describe a customer account (Priority: P2)

A reseller's or administrator's integration shows the same information for one of its customers.

**Acceptance Scenarios**:

1. **Given** a reseller key, **When** it passes `client_id` of one of its clients, **Then** the client's view is
   returned (dedicated addresses of that client included); another client id → 404.
2. **Given** an admin key without `client_id`, **Then** 422; with an unknown id → 404; with a valid id → the client's
   view.
3. **Given** a client key passing another client's id, **Then** 404; unknown parameters → 400.

### Edge Cases

- A server listed in the assignment but deleted, a mirror server or a server without the role is skipped (as
  `/me/servers`).
- Duplicate addresses on one server are listed once; addresses keep the `server_ip` order (by id).
- An address row with an `ip_type` that does not match the address format is skipped.
- An installation behind NAT that registers only private addresses in ISPConfig gets empty address lists; the
  consumer falls back to its own configuration.
- Mirror servers are listed as name servers whether or not they are active, as in the zone import.
- The zone's own NS records are not consulted: they are customer data and may point anywhere.
- Nothing is written; no datalog.

## API Contract *(mandatory)*

- **Spec file(s)**: `api/modules/me/hosting-addresses.yaml` — new; registered in `api/modules/me/_index.yaml` and
  `api/openapi.yaml`.
- **Shared schemas**: `api/components/schemas/HostingAddresses.yaml`, `HostingServer.yaml`, `HostingDnsServer.yaml`,
  `NameServer.yaml` — new; registered in `api/components/schemas/_index.yaml`.
- **Endpoints**:

| Method | Path | Purpose | Success code |
|--------|------|---------|--------------|
| GET | `/api/v1/me/hosting-addresses` | Public addresses of the account's web and mail servers, name servers of its DNS servers | 200 |

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference** (ISPConfig 3.3.1p1): `admin/form/server_ip.tform.php` (`client_id` 0 = all clients, `ip_type`,
  `virtualhost`), `admin/lib/lang/en_server_ip.lng` 8 (`virtualhost` = "HTTP NameVirtualHost");
  `sites/web_vhost_domain_edit.php` 209, 225 (address choice for client websites: `client_id = 0 OR client_id =
  <own>`); `dns/dns_import.php` 81–82 and 272–287 (name servers = `server_name` of the zone's server and of servers
  with `mirror_server_id` = that server, ordered by name, plus `dns_external_slave_fqdn` split on commas/whitespace,
  trailing dots normalized) and 634–643 (NS records created for exactly those names);
  `admin/lib/lang/en_system_config.lng` 115 ("External DNS servers (comma separated)");
  `server/lib/classes/modules.inc.php` 104–142 (mirror servers apply the records of the mirrored server);
  `server/plugins-available/apache2_plugin.inc.php` 1795–1800 (`server_ip_map` translates website addresses for web
  mirrors only, not public addresses); `lib/classes/dns_wizard.inc.php` 167–170 and the default template (`{IP}`,
  `{NS1}`, `{NS2}` are free-text wizard inputs); `mail/webmailer.php` 55–76 (mail host = `server_name`, spec 025).
- **Legacy behaviors to mirror**: shared/dedicated address visibility; name server composition of the zone import;
  server name as host name.
- **Tables written (via datalog only)**: none (read-only).
- **System fields handling**: not applicable.
- **Intentional deviations from legacy** (owner-delegated decisions 2026-09-16):
  - A new account endpoint instead of extending `/me/servers`: `/me/servers` describes where new resources may be
    placed and promises no other server data (spec 016); addresses and name servers serve DNS set-up and
    registrar delegation, and the module contract already expects one call.
  - Addresses are not limited to "HTTP NameVirtualHost" rows (legacy's website address picker): websites bound to
    `*` answer on every address of the server, and the flag only controls the web server configuration.
  - Private, loopback, link-local and reserved addresses are left out: they cannot be used in public DNS records.
  - Server lists include, after the assigned servers, the servers already hosting the account's websites, mail
    domains or zones (as `/me/mail-settings`, spec 025).
  - Names are returned without trailing dots; the API does not resolve external name server addresses.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `GET /me/hosting-addresses` MUST be available to every valid key; client keys describe their own client
  (own `client_id` only), reseller keys their own or a child client, admin keys MUST pass `client_id` (422 missing,
  404 unknown); other clients → 404; unknown query parameters → 400.
- **FR-002**: The response MUST contain `client_id`, `web`, `mail` and `dns`. `web` and `mail` MUST list the valid
  assigned servers of the role in assignment order (first = `is_default: true`), then other non-mirror servers of the
  role hosting the client's websites (`web_domain`) or mail domains (`mail_domain`), by id, `is_default: false`.
- **FR-003**: Each server entry MUST carry `server_id`, `server_name`, `is_default`, `ipv4` and `ipv6`; addresses come
  from `server_ip` rows of that server with `client_id` 0 or the described client, valid for their `ip_type`, public
  (not private or reserved), unique, in id order.
- **FR-004**: `dns` MUST list the valid assigned DNS servers in order, then other non-mirror DNS servers hosting the
  client's zones (`dns_soa`), each with `server_id`, `server_name`, `is_default` and `nameservers`: the server and its
  mirror servers ordered by `server_name` with their addresses (FR-003), then each name of `dns_external_slave_fqdn`
  with empty address lists; names without trailing dots, duplicates removed.
- **FR-005**: The endpoint MUST NOT write anything and MUST NOT list addresses dedicated to other clients or servers
  unrelated to the account.
- **FR-006**: Contract first; feature tests for address visibility (shared, own dedicated, other dedicated, private,
  reserved, invalid type, duplicates, NameVirtualHost flag), server lists (assigned order, invalid entries, hosting
  servers), name servers (mirrors order, external setting parsing, empty), target resolution for all key types, 400,
  no datalog.

### Key Entities

- **Server address** (`server_ip`: `server_id`, `client_id`, `ip_type`, `ip_address`, `virtualhost`).
- **Server** (`server`: `server_name`, role flags, `mirror_server_id`); **client assignment** (`client.web_servers`,
  `mail_servers`, `dns_servers`).
- **External DNS servers** (`sys_ini` [dns] `dns_external_slave_fqdn`).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 0 dedicated addresses of other clients and 0 private or reserved addresses in any response across the
  test matrix.
- **SC-002**: The name servers listed for a DNS server equal the NS records ISPConfig's zone import would create for a
  zone on that server.
- **SC-003**: A panel can fill website A/AAAA records, the MX host and the registrar name server list from one request.

## Assumptions

- The WHMCS module (spec 005) switches from its proposed flat shape to this one: website addresses from the default
  `web` entry, mail host from the default `mail` entry's `server_name`, name servers from the `dns` entry whose
  `server_id` equals the zone's `server_id` (or the default entry).
- Administrators register every public address of a server in ISPConfig's IP address list.
