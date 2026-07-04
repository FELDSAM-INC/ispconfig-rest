# Feature Specification: Server Module

**Feature Branch**: `007-server-module`  
**Created**: 2026-07-04  
**Status**: Draft (reverse-engineered from contract + legacy source; not yet implemented)  
**Module**: server  
**Input**: Existing OpenAPI contract under `api/modules/server/` (servers, server-config, ip-addresses, ip-mappings, firewall, php-versions) + legacy ISPConfig admin module (`source_code/interface/web/admin/`). No PHP implementation exists yet — no `Server*` model, controller, service, or route is present in the codebase.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Discover and inspect servers (Priority: P1)

An API consumer (provisioning script, dashboard, capacity planner) lists all ISPConfig servers and inspects a single server to learn its name, enabled service roles (mail/web/dns/file/db/vserver/xmpp), mirror configuration, and active state. This is the read-heavy backbone of the module: every other server-scoped resource (`/servers/{id}/...`) presumes the consumer can first resolve a server ID.

**Why this priority**: Read-only, zero datalog risk, immediately useful (other implemented modules such as `mail/domains` already require a valid `server_id`), and it unblocks all nested endpoints.

**Independent Test**: Seed/point at a populated `dbispconfig`; call `GET /api/v1/servers` and `GET /api/v1/servers/1` with a valid `X-API-Key`; verify pagination envelope, field set, and 404 for a nonexistent ID.

**Acceptance Scenarios**:

1. **Given** a database with N rows in `server`, **When** the consumer calls `GET /servers`, **Then** it receives 200 with `data` (array of Server objects) and `pagination`, honoring `limit`/`offset`/`sort`/`order`.
2. **Given** server 1 exists, **When** the consumer calls `GET /servers/1`, **Then** it receives 200 with that server's record (`server_id`, `server_name`, service-role flags as 0/1 integers, `mirror_server_id`, `active`).
3. **Given** no server with id 999, **When** the consumer calls `GET /servers/999`, **Then** it receives 404 with `{message, error}`.
4. **Given** a missing/invalid `X-API-Key`, **When** any endpoint is called, **Then** 401 is returned.

---

### User Story 2 - Manage server IP addresses (Priority: P2)

A consumer managing multi-IP web servers lists the IPs registered for a server, adds a new IPv4/IPv6 address (optionally bound to a client and to virtualhost ports), updates it, or removes it — all through `sys_datalog` so ISPConfig's server daemon picks the change up.

**Why this priority**: IP records (`server_ip`) drive website IP assignment; this is the most commonly automated server-admin task after inspection.

**Independent Test**: `POST /servers/1/ip-addresses` with a fresh IP; verify 201, a `sys_datalog` row (`server_ip`, action `i`); then `PUT` and `DELETE` and verify actions `u`/`d`.

**Acceptance Scenarios**:

1. **Given** server 1 exists, **When** the consumer POSTs `{ip_type: "IPv4", ip_address: "10.0.0.5"}` to `/servers/1/ip-addresses`, **Then** 201 is returned, defaults `virtualhost=y`, `virtualhost_port=80,443`, `client_id=0` are applied, and a datalog insert row exists.
2. **Given** `ip_type=IPv4` and `ip_address=fe80::1`, **When** POSTed, **Then** 422 — the address must validate against the declared type (legacy `check_server_ip`).
3. **Given** an IP already registered, **When** the same `ip_address` is POSTed again, **Then** 422 (legacy UNIQUE validator on `ip_address`).
4. **Given** an existing IP on server 1, **When** the consumer PUTs a body with `server_id=2`, **Then** the change is rejected — legacy forbids moving an IP between servers (`server_ip_edit.php::onBeforeUpdate`).
5. **Given** `virtualhost_port=80,,443x`, **When** POSTed, **Then** 422 (must match `/^([0-9]{1,5}\,{0,1}){1,}$/i`).

---

### User Story 3 - Manage server IP mappings (Priority: P2)

A consumer running servers behind NAT maintains source→destination IP mappings (`server_ip_map`) so ISPConfig rewrites internal IPs to public ones.

**Why this priority**: Companion of US2; small table, simple validations, same nested-resource pattern.

**Independent Test**: CRUD against `/servers/1/ip-mappings`, verifying datalog rows on `server_ip_map` and IPv4 validation of `destination_ip`.

**Acceptance Scenarios**:

1. **Given** server 1, **When** the consumer POSTs `{source_ip: "10.0.0.5", destination_ip: "203.0.113.9"}`, **Then** 201 with default `active=y` and a datalog insert.
2. **Given** `destination_ip=not-an-ip` (or an IPv6 address), **When** POSTed, **Then** 422 — legacy validates destination with `ISIPV4` and `NOTEMPTY`.
3. **Given** an empty `source_ip`, **When** POSTed, **Then** 422 (legacy `NOTEMPTY`).

---

### User Story 4 - Manage firewall rule-sets (Priority: P3)

A consumer opens/closes ports on a server by editing that server's single firewall record (`firewall` table): comma-separated TCP/UDP ports and `port:port` ranges.

**Why this priority**: Valuable but lower frequency; carries the subtle one-record-per-server constraint.

**Independent Test**: `POST /servers/{id}/firewall` on a server without a firewall record → 201; repeat → 409; `PUT`/`DELETE` produce datalog `u`/`d` rows on `firewall`.

**Acceptance Scenarios**:

1. **Given** server 1 has no firewall record, **When** the consumer POSTs `{tcp_port: "22,80,443", udp_port: "53"}`, **Then** 201 with `active=y` default and a datalog insert.
2. **Given** server 1 already has a firewall record, **When** another POST arrives, **Then** 409 Conflict — legacy declares `server_id` UNIQUE in `firewall.tform.php`.
3. **Given** `tcp_port="80,abc"` or `"99999999"`-style junk, **When** POSTed, **Then** 422 — ports must match `/^$|\d{1,5}(?::\d{1,5})?(?:,\d{1,5}(?::\d{1,5})?)*$/` (empty allowed; ranges `low:high`).
4. **Given** an existing rule on server 1, **When** a PUT tries to change `server_id`, **Then** the change is rejected (legacy `firewall_edit.php::onBeforeUpdate`).

---

### User Story 5 - Manage additional PHP versions (Priority: P3)

A consumer registers extra PHP versions (`server_php`) on a web server: name, FastCGI binary/ini dir, FPM init script/ini/pool/socket dirs, CLI binary, JK section, sort priority.

**Why this priority**: Common on modern multi-PHP hosts, but depends on server inspection (US1) and is less frequent than IP management.

**Independent Test**: CRUD against `/servers/1/php-versions`; verify datalog rows on `server_php` and legacy validation of `name`, `php_cli_binary`, `php_jk_section`.

**Acceptance Scenarios**:

1. **Given** server 1 is a web server, **When** the consumer POSTs `{name: "PHP 8.2", php_cli_binary: "/usr/bin/php8.2", php_jk_section: "php82"}`, **Then** 201 with defaults `active=y`, `sortprio=100`, `client_id=0`.
2. **Given** `php_cli_binary="php8.2"` (relative) or containing `;`, **When** POSTed, **Then** 422 — must match `/^\/[a-zA-Z0-9\/\-\_\.\s]*$/` and be non-empty.
3. **Given** `php_jk_section="php 8.2!"`, **When** POSTed, **Then** 422 — must match `/^[a-zA-Z0-9\-\_]*$/` and be non-empty.
4. **Given** an empty `name`, **When** POSTed, **Then** 422 (legacy `NOTEMPTY`); tags/newlines are stripped on save (legacy STRIPTAGS/STRIPNL filters).

---

### User Story 6 - Inspect and update server configuration (Priority: P4)

A consumer reads a server's full configuration or a single section (mail, web, dns, fastcgi, xmpp, jailkit, ufw, vlogger, cron, rescue) and updates one section at a time. The underlying storage is the `server.config` column — a single INI-style text blob with `[section]` headers that legacy ISPConfig parses/serializes with its own `ini_parser` class.

**Why this priority**: Highest complexity and highest blast radius (a corrupted blob breaks every service on the server); several contract-vs-legacy mismatches need resolution first (see Requirements).

**Independent Test**: `GET /servers/1/configs/mail` returns the parsed `[mail]` section; `PUT` with one changed key produces a datalog update on `server` whose `config` field is the full re-serialized INI string with only that section changed.

**Acceptance Scenarios**:

1. **Given** server 1 with a populated config blob, **When** the consumer calls `GET /servers/1/configs`, **Then** 200 with the parsed configuration.
2. **Given** a `PUT /servers/1/configs/mail` changing `mailbox_size_limit`, **Then** the whole blob is read, only the `[mail]` section replaced, the blob re-serialized, and a datalog `u` on table `server` (field `config`) is written — mirroring `server_config_edit.php::onUpdateSave`.
3. **Given** `mailbox_size_limit` nonzero and smaller than `message_size_limit`, **When** PUT to the mail section, **Then** 422 (legacy `onSubmit` check).
4. **Given** a PUT to any section, **Then** keys absent from the request that legacy treats as checkboxes are written with their unchecked value, and `rspamd_available` is never accepted from the client (preserved from the existing blob, legacy behavior).

---

### User Story 7 - Manage server records (Priority: P4)

A consumer registers a new server row, updates its service-role flags / mirror settings, or deletes a decommissioned server. In legacy ISPConfig, server rows are normally created by the installer; API-driven creation is an advanced, admin-only operation.

**Why this priority**: Rare operation with high risk (deleting a server does not cascade to its sites/mail/DNS records in legacy either); read path (US1) delivers most of the value.

**Independent Test**: `POST /servers` → 201 + datalog `i` on `server`; `PUT /servers/{id}` flipping `web_server` → datalog `u`; `DELETE /servers/{id}` → 204 + datalog `d`.

**Acceptance Scenarios**:

1. **Given** a valid body, **When** POSTed to `/servers`, **Then** 201 with defaults (`mail_server..xmpp_server=0`, `active=1`, `mirror_server_id=0`) and sys fields `sys_userid=1`, `sys_groupid=1`, `sys_perm_user=riud`, `sys_perm_group=riud`, `sys_perm_other=''` (legacy `auth_preset` in `server.tform.php`).
2. **Given** a PUT on server X with `mirror_server_id=X` (itself) or X=1, **Then** `mirror_server_id` is forced to 0 (legacy `server_edit.php::onSubmit`).
3. **Given** `server_name` containing HTML/newlines, **When** saved, **Then** tags and newlines are stripped (legacy STRIPTAGS/STRIPNL).
4. **Given** `DELETE /servers/{id}` on an existing server, **Then** 204 and a datalog `d`; dependent resources are NOT cascaded (parity with legacy `server_del.php`, which runs the plain tform delete).

---

### Edge Cases

- Missing/invalid `X-API-Key` → 401 on every endpoint.
- Nested resources: `{id}` (server) that does not exist → 404 before touching the child resource; child id that exists but belongs to a different server → 404 (do not leak cross-server records).
- `POST /servers/{id}/firewall` when the server already has a firewall row → 409 (contract declares Conflict; legacy enforces UNIQUE `server_id`).
- `PUT` attempting to change `server_id` of an existing `server_ip` or `firewall` record → rejected (legacy immutability).
- `y`/`n` flag fields (`active`, `virtualhost` on server_ip/ip_map/firewall/server_php) vs **integer 0/1 flags on the `server` table itself** (`active`, `mail_server`, ...) — two different flag conventions inside one module.
- Empty `tcp_port`/`udp_port` is legal (regex allows empty string → "no ports").
- Config blob edge cases: empty/missing `config` on a fresh server row; unknown sections present in the blob must be preserved untouched on section update; INI values containing `=` or newlines.
- Deleting a server that other rows reference (`mail_domain.server_id`, `server_ip.server_id`, mirrors pointing at it) — legacy does not guard this; see FR-070/Assumptions.
- Route shadowing: `/servers/configs` MUST be registered before `/servers/{id}` or "configs" will be captured as an id.

## API Contract *(mandatory)*

- **Spec files** (all existing — implement as-is, subject to the flagged clarifications):
  - `api/modules/server/servers.yaml`
  - `api/modules/server/server-config.yaml`
  - `api/modules/server/ip-addresses.yaml`
  - `api/modules/server/ip-mappings.yaml`
  - `api/modules/server/firewall.yaml`
  - `api/modules/server/php-versions.yaml`
  - Module index: `api/modules/server/_index.yaml`; root registration in `api/openapi.yaml` (incomplete — see FR-062)
- **Shared schemas** (all existing): `api/components/schemas/Server.yaml`, `ServerConfig.yaml`, `ServerMailConfig.yaml`, `ServerWebConfig.yaml`, `ServerDnsConfig.yaml`, `ServerFastCgiConfig.yaml`, `ServerXmppConfig.yaml`, `ServerJailkitConfig.yaml`, `ServerUfwConfig.yaml`, `ServerVloggerConfig.yaml`, `ServerCronConfig.yaml`, `ServerRescueConfig.yaml`, `ServerIp.yaml`, `ServerIpMap.yaml`, `ServerFirewall.yaml`, `ServerPhp.yaml`, `Pagination.yaml` (+ shared `parameters/{limit,offset,sort,order}.yaml` and `responses/*`). (`ServerStatus.yaml` belongs to the monitor module — out of scope here.)

### Endpoints (50 operations / 22 paths, verbatim from the YAMLs)

| # | Method | Path | Purpose | Success | Declared errors |
|---|--------|------|---------|---------|-----------------|
| 1 | GET | `/api/v1/servers` | List servers (data + pagination) | 200 | 401, 500 |
| 2 | POST | `/api/v1/servers` | Create server | 201 | 400, 401, 409, 500 |
| 3 | GET | `/api/v1/servers/{id}` | Show server | 200 | 401, 404, 500 |
| 4 | PUT | `/api/v1/servers/{id}` | Update server | 200 | 400, 401, 404, 500 |
| 5 | DELETE | `/api/v1/servers/{id}` | Delete server | 204 | 400, 401, 403, 404, 500 |
| 6 | GET | `/api/v1/servers/configs` | List all server configurations | 200 | 401, 500 |
| 7 | GET | `/api/v1/servers/{id}/configs` | Get full config of one server | 200 | 401, 404, 500 |
| 8 | POST | `/api/v1/servers/{id}/configs` | Create server configuration | 201 | 400, 401, 500 |
| 9 | PUT | `/api/v1/servers/{id}/configs` | Update server configuration | 200 | 400, 401, 404, 500 |
| 10 | DELETE | `/api/v1/servers/{id}/configs` | Delete server configuration | 204 | 401, 404, 500 |
| 11 | GET | `/api/v1/servers/{id}/configs/mail` | Get `[mail]` section | 200 | 401, 404, 500 |
| 12 | PUT | `/api/v1/servers/{id}/configs/mail` | Update `[mail]` section | 200 | 400, 401, 404, 500 |
| 13 | GET | `/api/v1/servers/{id}/configs/web` | Get `[web]` section | 200 | 401, 404, 500 |
| 14 | PUT | `/api/v1/servers/{id}/configs/web` | Update `[web]` section | 200 | 400, 401, 404, 500 |
| 15 | GET | `/api/v1/servers/{id}/configs/dns` | Get `[dns]` section | 200 | 401, 404, 500 |
| 16 | PUT | `/api/v1/servers/{id}/configs/dns` | Update `[dns]` section | 200 | 400, 401, 404, 500 |
| 17 | GET | `/api/v1/servers/{id}/configs/fastcgi` | Get `[fastcgi]` section | 200 | 401, 404, 500 |
| 18 | PUT | `/api/v1/servers/{id}/configs/fastcgi` | Update `[fastcgi]` section | 200 | 400, 401, 404, 500 |
| 19 | GET | `/api/v1/servers/{id}/configs/xmpp` | Get `[xmpp]` section | 200 | 401, 404, 500 |
| 20 | PUT | `/api/v1/servers/{id}/configs/xmpp` | Update `[xmpp]` section | 200 | 400, 401, 404, 500 |
| 21 | GET | `/api/v1/servers/{id}/configs/jailkit` | Get `[jailkit]` section | 200 | 401, 404, 500 |
| 22 | PUT | `/api/v1/servers/{id}/configs/jailkit` | Update `[jailkit]` section | 200 | 400, 401, 404, 500 |
| 23 | GET | `/api/v1/servers/{id}/configs/ufw` | Get UFW section | 200 | 401, 404, 500 |
| 24 | PUT | `/api/v1/servers/{id}/configs/ufw` | Update UFW section | 200 | 400, 401, 404, 500 |
| 25 | GET | `/api/v1/servers/{id}/configs/vlogger` | Get `[vlogger]` section | 200 | 401, 404, 500 |
| 26 | PUT | `/api/v1/servers/{id}/configs/vlogger` | Update `[vlogger]` section | 200 | 400, 401, 404, 500 |
| 27 | GET | `/api/v1/servers/{id}/configs/cron` | Get `[cron]` section | 200 | 401, 404, 500 |
| 28 | PUT | `/api/v1/servers/{id}/configs/cron` | Update `[cron]` section | 200 | 400, 401, 404, 500 |
| 29 | GET | `/api/v1/servers/{id}/configs/rescue` | Get `[rescue]` section | 200 | 401, 404, 500 |
| 30 | PUT | `/api/v1/servers/{id}/configs/rescue` | Update `[rescue]` section | 200 | 400, 401, 404, 500 |
| 31 | GET | `/api/v1/servers/{id}/ip-addresses` | List server IPs | 200 | 400, 401, 500 |
| 32 | POST | `/api/v1/servers/{id}/ip-addresses` | Create server IP | 201 | 400, 401, 403, 500 |
| 33 | GET | `/api/v1/servers/{id}/ip-addresses/{ip_address_id}` | Show server IP | 200 | 400, 401, 404, 500 |
| 34 | PUT | `/api/v1/servers/{id}/ip-addresses/{ip_address_id}` | Update server IP | 200 | 400, 401, 403, 404, 500 |
| 35 | DELETE | `/api/v1/servers/{id}/ip-addresses/{ip_address_id}` | Delete server IP | 204 | 400, 401, 403, 404, 500 |
| 36 | GET | `/api/v1/servers/{id}/ip-mappings` | List IP mappings | 200 | 400, 401, 500 |
| 37 | POST | `/api/v1/servers/{id}/ip-mappings` | Create IP mapping | 201 | 400, 401, 403, 500 |
| 38 | GET | `/api/v1/servers/{id}/ip-mappings/{mapping_id}` | Show IP mapping | 200 | 400, 401, 404, 500 |
| 39 | PUT | `/api/v1/servers/{id}/ip-mappings/{mapping_id}` | Update IP mapping | 200 | 400, 401, 403, 404, 500 |
| 40 | DELETE | `/api/v1/servers/{id}/ip-mappings/{mapping_id}` | Delete IP mapping | 204 | 400, 401, 403, 404, 500 |
| 41 | GET | `/api/v1/servers/{id}/firewall` | List firewall rules (filters: active, tcp_port, udp_port) | 200 | 400, 401, 403, 500 |
| 42 | POST | `/api/v1/servers/{id}/firewall` | Create firewall rule | 201 | 400, 401, 403, 409, 500 |
| 43 | GET | `/api/v1/servers/{id}/firewall/{firewall_id}` | Show firewall rule | 200 | 400, 401, 403, 404, 500 |
| 44 | PUT | `/api/v1/servers/{id}/firewall/{firewall_id}` | Update firewall rule | 200 | 400, 401, 403, 404, 409, 500 |
| 45 | DELETE | `/api/v1/servers/{id}/firewall/{firewall_id}` | Delete firewall rule | 204 | 400, 401, 403, 404, 500 |
| 46 | GET | `/api/v1/servers/{id}/php-versions` | List PHP versions | 200 | 400, 401, 500 |
| 47 | POST | `/api/v1/servers/{id}/php-versions` | Create PHP version | 201 | 400, 401, 403, 500 |
| 48 | GET | `/api/v1/servers/{id}/php-versions/{php_version_id}` | Show PHP version | 200 | 400, 401, 404, 500 |
| 49 | PUT | `/api/v1/servers/{id}/php-versions/{php_version_id}` | Update PHP version | 200 | 400, 401, 403, 404, 500 |
| 50 | DELETE | `/api/v1/servers/{id}/php-versions/{php_version_id}` | Delete PHP version | 204 | 400, 401, 403, 404, 500 |

Notes on what the YAML actually allows:

- Every resource is fully writable in the contract — nothing in this module is declared read-only, including server creation/deletion and config create/delete (rows 8/10), even though legacy has no equivalent of "creating" or "deleting" a config (the blob always exists on the `server` row). See FR-052/FR-053.
- All list endpoints return `{data: [...], pagination: {...}}` referencing `Pagination.yaml` (Laravel-paginator shape: total/per_page/current_page/...), while accepting `limit`/`offset`/`sort`/`order` query parameters. This matches every other module's YAML and the existing controllers (e.g., `MailDomainController::index`), but conflicts with the constitution's Principle V wording (`{items,total,limit,offset}`) — see Assumptions.
- The YAMLs declare `security: basicAuth`; the implemented middleware is `X-API-Key` (`ApiAuthMiddleware`) — same declared-vs-actual gap as all previously implemented modules.

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference**: `source_code/interface/web/admin/` — forms `form/server.tform.php`, `form/server_config.tform.php`, `form/server_ip.tform.php`, `form/server_ip_map.tform.php`, `form/firewall.tform.php`, `form/server_php.tform.php`; actions `server_edit.php`, `server_del.php`, `server_config_edit.php`, `server_ip_edit.php`, `firewall_edit.php` (+ `_del.php`/`_list.php` siblings); helpers `source_code/interface/lib/classes/validate_server.inc.php` (`check_server_ip`), `getconf.inc.php`, `ini_parser.inc.php`. DB schema verified against `ISPConfig-DB-Structure.txt` and `source_code/install/sql/ispconfig3.sql`.

### Per-resource parity map

| API resource | Legacy form | Table (PK) | Datalog actions | Legacy validations / side effects to mirror |
|---|---|---|---|---|
| `/servers` | `form/server.tform.php`, `server_edit.php`, `server_del.php` | `server` (`server_id`) | i / u / d | `server_name`: STRIPTAGS+STRIPNL on save, max 255. Role flags `{mail,web,dns,file,db,vserver,xmpp}_server` INTEGER 0/1, default 0. `active` INTEGER 0/1, default 1. `mirror_server_id` forced to 0 when it equals the record's own id or when editing server 1 (`server_edit.php::onSubmit`). auth_preset: `userid=0→caller`, `groupid=1`, perms `riud/riud/''`. Delete is a plain tform delete — **no cascade** to dependent tables; legacy also gates it behind `admin_allow_server_services` + disables it in demo mode. DB also has `proxy_server`, `firewall_server`, `config`, `updated`, `dbversion` columns not exposed by the form (nor by `Server.yaml`, except `config` implicitly via configs endpoints). |
| `/servers/{id}/configs*` | `form/server_config.tform.php` (12 tabs), `server_config_edit.php` | `server` (`server_id`) — **column `config` only** | u only (legacy) | Config is ONE INI-style text blob. Legacy update flow (`onUpdateSave`): parse full blob via `ini_parser->parse_ini_string(stripslashes(...))`, replace the edited section only, re-serialize via `get_ini_string`, then `datalogUpdate('server', {config}, 'server_id', id)`. Unchecked checkboxes are written with their "unchecked" value. Mail section: reject if `mailbox_size_limit != 0 && mailbox_size_limit < message_size_limit`; `rspamd_available` is forced from the stored config, never from user input. Side effect (`onAfterUpdate`): switching `content_filter` to `rspamd` datalog-touches every `spamfilter_users` and `spamfilter_wblist` row of that server to trigger re-sync. UI-only quirks not portable to the API: nginx servers hide the fastcgi + vlogger tabs; whole form requires admin + `admin_allow_server_config` permission. Legacy tabs: server, mail, getmail, web, dns, fastcgi, xmpp, jailkit, vlogger, cron, rescue (**ufw_firewall tab is commented out** in the vendored source). |
| `/servers/{id}/ip-addresses` | `form/server_ip.tform.php`, `server_ip_edit.php` | `server_ip` (`server_ip_id`) | i / u / d | `ip_address`: CUSTOM validator `validate_server::check_server_ip` — `FILTER_VALIDATE_IP` with `FILTER_FLAG_IPV4`/`IPV6` depending on `ip_type`; plus UNIQUE across the table. `ip_type` ∈ {IPv4, IPv6}. `virtualhost` y/n default y; `virtualhost_port` regex `/^([0-9]{1,5}\,{0,1}){1,}$/i`, default `80,443`. `client_id` optional, 0 = none. `server_id` immutable on update (`onBeforeUpdate` reverts + errors). auth_preset: groupid 0 (user's default group), perms `riud/riud/''`. **No `active` column exists** (verified in install SQL) despite `ServerIp.yaml` declaring one — see FR-054. |
| `/servers/{id}/ip-mappings` | `form/server_ip_map.tform.php` | `server_ip_map` (`server_ip_map_id`) | i / u / d | `source_ip` NOTEMPTY (legacy UI offers only the server's existing IPs; column is varchar(15) → IPv4-sized). `destination_ip` ISIPV4 + NOTEMPTY (varchar(35)). `active` y/n default y. No dedicated edit-page side effects. |
| `/servers/{id}/firewall` | `form/firewall.tform.php`, `firewall_edit.php` | `firewall` (`firewall_id`) | i / u / d | `server_id` UNIQUE — at most one firewall record per server (legacy new-record UI only offers servers without one). `tcp_port`/`udp_port` regex `/^$|\d{1,5}(?::\d{1,5})?(?:,\d{1,5}(?::\d{1,5})?)*$/` (comma list, optional `low:high` ranges, empty allowed); legacy defaults tcp `21,22,25,53,80,110,143,443,465,587,993,995,3306,4190,8080,8081,40110:40210`, udp `53`. `active` y/n default y. `server_id` immutable on update (`onBeforeUpdate`). |
| `/servers/{id}/php-versions` | `form/server_php.tform.php` | `server_php` (`server_php_id`) | i / u / d | `name` NOTEMPTY + STRIPTAGS/STRIPNL. `php_cli_binary` NOTEMPTY + regex `/^\/[a-zA-Z0-9\/\-\_\.\s]*$/` (absolute path). `php_jk_section` NOTEMPTY + regex `/^[a-zA-Z0-9\-\_]*$/`. All FastCGI/FPM path fields optional, STRIPTAGS/STRIPNL, max 255. `active` y/n default y; `sortprio` INTEGER default 100; `client_id` 0 = none. Legacy restricts `server_id` choice to `web_server = 1 AND mirror_server_id = 0` servers. Columns `php_cli_binary`/`php_jk_section` exist in `install/sql/ispconfig3.sql` (the older `ISPConfig-DB-Structure.txt` predates them). |

- **Tables written (via datalog only)**: `server` (i/u/d for server records; u with the `config` field for all configuration endpoints), `server_ip` (i/u/d), `server_ip_map` (i/u/d), `firewall` (i/u/d), `server_php` (i/u/d). Config side effect may additionally touch `spamfilter_users` / `spamfilter_wblist` (u) — see FR-051.
- **System fields handling**: all five tables carry `sys_userid`, `sys_groupid`, `sys_perm_*`. Defaults per legacy auth_presets: perms `riud`/`riud`/`''` everywhere; `sys_groupid=1` for `server`, caller's group otherwise (API default: 1/admin, consistent with existing controllers). For nested resources `server_id` comes from the URL path, not the body. For the `server` table itself, `server_id` is the primary key, so `BaseModel` will naturally stamp the datalog row's `server_id` with the record's own id — matching legacy.
- **Intentional deviations from legacy**: (1) permission model — legacy gates this module behind interface-admin login + `admin_allow_server_services`/`admin_allow_server_config`; the API uses the global `X-API-Key` middleware with no per-module ACL, same as every implemented module. (2) The nginx-hides-fastcgi/vlogger-tabs behavior is UI-only and is not replicated (sections stay readable/writable regardless of `server_type`). (3) The `getmail` config section has no endpoint (contract omits it) — it remains present and untouched inside the blob.

## Requirements *(mandatory)*

### Functional Requirements

**Cross-cutting**

- **FR-001**: All 50 operations MUST be exposed exactly as defined in `api/modules/server/*.yaml` (paths, methods, parameters, status codes), behind the `api.auth` (`X-API-Key`) middleware under `API_PREFIX` (default `api/v1`).
- **FR-002**: All list endpoints MUST accept `limit`, `offset`, `sort`, `order` query parameters and return `{data, pagination}` per `Pagination.yaml` (mirroring `MailDomainController::index`).
- **FR-003**: Errors MUST use `{message, error}` bodies with 400 (bad reference), 401 (auth), 404 (missing resource), 409 (declared conflicts), 422 (validation), 500 (unexpected); writes MUST return 201/200/204 for create/update/delete.
- **FR-004**: Every write MUST go through a model extending `App\Models\BaseModel` (datalog i/u/d), wrapped in a DB transaction with rollback + contextual error logging. No direct table writes.
- **FR-005**: Nested endpoints (`/servers/{id}/...`) MUST 404 when the parent server does not exist, and MUST 404 when the child record exists but its `server_id` differs from `{id}`.
- **FR-006**: `y`/`n` columns (`server_ip.virtualhost`, `server_ip_map.active`, `firewall.active`, `server_php.active`) MUST use the `App\Casts\YesNoBoolean` pattern or be validated as `in:y,n` consistent with the schemas (which expose literal `y`/`n` strings — follow the schemas: expose `y`/`n`, cast only if the schema models booleans; here the schemas model strings, so validate `in:y,n` and store verbatim). The `server` table's flags are INTEGER 0/1 and MUST NOT be converted to `y`/`n`.

**Servers (US1, US7)**

- **FR-010**: `GET /servers` MUST list `server` rows with pagination and sorting; `GET /servers/{id}` MUST return one row or 404.
- **FR-011**: `POST /servers` MUST validate `server_name` (required, string, max 255, strip tags/newlines), role flags + `active` as integer 0/1, `mirror_server_id` integer ≥ 0, and apply defaults: role flags 0, `active` 1, `mirror_server_id` 0, sys fields per parity map.
- **FR-012**: On create and update, `mirror_server_id` MUST be forced to 0 when it equals the record's own `server_id` or when the record is server 1 (legacy `server_edit.php::onSubmit`).
- **FR-013**: `DELETE /servers/{id}` MUST datalog-delete the row without cascading; the 403 declared in the contract is reserved for a future permission model (not produced by the initial implementation).
- **FR-014**: The response fields MUST match `Server.yaml`. [NEEDS CLARIFICATION: `Server.yaml` requires `ip_address` and `hostname` and offers `xmpp_server`, but the `server` table has **no `ip_address`/`hostname` columns** — those values live in the config blob's `[server]` section (`server_config.tform.php`). Options: (a) amend `Server.yaml` to drop `ip_address`/`hostname` (parity-pure), or (b) derive them read-only from the parsed config blob and reject them on write. The contract as written cannot be satisfied by the table alone.]
- **FR-015**: [NEEDS CLARIFICATION: `Server.yaml` omits real columns `proxy_server`, `firewall_server`, `updated`, `dbversion`. Exposing them requires a schema change (spec-first); hiding them is parity-safe. Default assumption: hide.]

**Server configuration (US6)**

- **FR-050**: Reads MUST parse `server.config` with an ISPConfig-compatible INI parser (section headers `[name]`, `key=value`, `stripslashes` applied first — see `getconf::get_server_config` and `ini_parser`); section endpoints return one parsed section, `GET /servers/{id}/configs` returns the server's configuration per `ServerConfig.yaml`, and `GET /servers/configs` lists them for all servers.
- **FR-051**: Section updates MUST be read-modify-write on the whole blob: replace only the target section, preserve all other sections byte-compatibly (including `server`, `getmail`, and any unknown sections), re-serialize with ISPConfig-compatible output, and datalog-update the `server` row's `config` field. The mail-section rules of the parity map (size-limit check, `rspamd_available` preservation) MUST be enforced. [NEEDS CLARIFICATION: legacy also datalog-touches `spamfilter_users`/`spamfilter_wblist` when `content_filter` switches to `rspamd` — decide whether the API replicates this side effect; default assumption: yes, for parity.]
- **FR-052**: [NEEDS CLARIFICATION: `POST /servers/{id}/configs` (201) and `DELETE /servers/{id}/configs` (204) have no legacy equivalent — the blob is a column that always exists with the server row. Options: (a) POST = "initialize blob from ISPConfig defaults, 409/400 if non-empty", DELETE = "reset blob to empty/default"; (b) amend the contract to GET+PUT only. Must be decided before implementation.]
- **FR-053**: The `ufw` section endpoints MUST map to the blob's UFW data. [NEEDS CLARIFICATION: the legacy `ufw_firewall` tab is commented out in the vendored source, and its field names (`ufw_enable`, `ufw_manage_builtins`, `ufw_default_input_policy` ∈ ACCEPT/DROP/REJECT, `ufw_log_level` ∈ low/medium/high) do not match `ServerUfwConfig.yaml` (`ufw_enabled`, `ufw_default_incoming` ∈ deny/allow/reject, `ufw_logging` ∈ off..full, `ufw_log_dropped`). A field-mapping decision (or schema amendment) is required.]
- **FR-054**: Section schemas vs legacy field inventories diverge and MUST be reconciled before coding validation rules. Verified examples: `ServerConfig.yaml` invents `timezone`, `dns1..3`, `dns1_v6..3_v6`, `nameserver`/`nameserver2`/`nameserver3` (legacy has a single comma-separated `nameservers`), `backup_interval`, `backup_encrypt*`, `ssh_*` — none exist in the legacy `[server]` section — while omitting real fields (`firewall`, `loglevel`, `admin_notify_events`, `backup_dir`, `backup_mode`, `monit_*`, `munin_*`, `migration_mode`, ...). `ServerMailConfig.yaml` (`mail_server_type`, `mail_server_port`, ...) similarly does not match the legacy `[mail]` field list (`module`, `maildir_path`, `content_filter`, `mailbox_size_limit`, ...). [NEEDS CLARIFICATION: amend the section schemas to the legacy field inventories (recommended; the blob is the ground truth ISPConfig daemons read), or define an explicit translation layer.]

**Server IPs (US2)**

- **FR-020**: CRUD on `server_ip` scoped by `{id}`; `server_id` MUST be taken from the path and be immutable on update (reject body values that differ).
- **FR-021**: Validate `ip_type` in {IPv4, IPv6} (default IPv4); `ip_address` required, must pass `FILTER_VALIDATE_IP` for the declared type, and be unique in `server_ip`; `virtualhost` in {y,n} default y; `virtualhost_port` matches `/^([0-9]{1,5}\,{0,1}){1,}$/i` default `80,443`; `client_id` integer, default 0, must exist in `client` when nonzero (400 on bad reference).
- **FR-022**: [NEEDS CLARIFICATION: `ServerIp.yaml` declares an `active` y/n property but the `server_ip` table has no such column (verified in `install/sql/ispconfig3.sql`). Either amend the schema or silently ignore the field; writing it would fail. Default assumption: amend schema / ignore on input.]

**IP mappings (US3)**

- **FR-030**: CRUD on `server_ip_map` scoped by `{id}` with `server_id` from the path; `source_ip` required non-empty (≤15 chars); `destination_ip` required, valid IPv4 (legacy ISIPV4 — IPv6 destinations are invalid); `active` in {y,n} default y.
- **FR-031**: [NEEDS CLARIFICATION: legacy UI restricts `source_ip` to the server's registered `server_ip` addresses (SELECT datasource) but has no server-side validator for membership. Decide: enforce membership (stricter, referential) or only NOTEMPTY (literal parity). Default assumption: NOTEMPTY only, matching server-side legacy validation.]

**Firewall (US4)**

- **FR-040**: CRUD on `firewall` scoped by `{id}`; `server_id` from path, immutable on update; POST MUST return 409 when the server already has a firewall row (UNIQUE `server_id`).
- **FR-041**: `tcp_port`/`udp_port` MUST match `/^$|\d{1,5}(?::\d{1,5})?(?:,\d{1,5}(?::\d{1,5})?)*$/`; `active` in {y,n} default y. Contract marks both ports and `active` as required on the schema; legacy allows empty port strings — required-but-emptyable is the reconciled reading.
- **FR-042**: List filtering per the YAML: `active` (exact y/n), `tcp_port`/`udp_port` (partial match). (The YAML description also mentions a `server_id` filter, which is redundant under the nested path — ignore it.)

**PHP versions (US5)**

- **FR-060**: CRUD on `server_php` scoped by `{id}`; validations per the parity map (`name`, `php_cli_binary`, `php_jk_section` required with legacy regexes; path fields optional ≤255 with tag/newline stripping; `active` y/n default y; `sortprio` integer default 100; `client_id` default 0).
- **FR-061**: [NEEDS CLARIFICATION: legacy only offers servers with `web_server = 1 AND mirror_server_id = 0` when creating a PHP version. Decide whether the API enforces this as a 400/422 or accepts any existing server. Default assumption: enforce, for parity.]

**Contract hygiene (found while reverse-engineering — must be fixed in the YAMLs as part of this feature, per spec-first)**

- **FR-062**: `api/openapi.yaml` registers only part of the module: `/servers/{id}/configs/{web,dns,fastcgi,xmpp}` are defined in `server-config.yaml` but missing from the root `paths` — they will not render in Swagger UI until registered.
- **FR-063**: Path-parameter name mismatches MUST be corrected: `servers.yaml` PUT/DELETE declare a parameter named `server_id` for the `{id}` template; `server-config.yaml` POST/PUT/DELETE on `/servers/{id}/configs` and PUT on `/servers/{id}/configs/mail` do the same. As written the parameter never binds.
- **FR-064**: Schema-file inconsistencies to normalize: `ServerFirewall.yaml` and the `ServerConfig*`/section schemas use `x_db_table` while `Server.yaml`/`ServerIp.yaml`/... use `x-db-table`; `ServerFirewall.yaml` omits the `sys_*` fields present on the `firewall` table; `Server.yaml` models `active` as integer 0/1 while `ServerConfig.yaml` models `active` as `y`/`n` for the same table.

### Key Entities

| Entity | Represents | Table (PK) | OpenAPI schema | Future model |
|---|---|---|---|---|
| **Server** | A physical/virtual ISPConfig node and its service roles | `server` (`server_id`) | `api/components/schemas/Server.yaml` | `app/Models/Server.php` (also repairs the dangling `Server::class` reference in `app/Models/MailDomain.php::server()`) |
| **ServerConfig** (virtual) | Parsed view of the `server.config` INI blob (sections: server, mail, getmail, web, dns, fastcgi, xmpp, jailkit, vlogger, cron, rescue [, ufw]) | `server.config` column | `ServerConfig.yaml` + 10 `Server*Config.yaml` section schemas | no own model — read/written through `Server` + `app/Services/ServerConfigService.php` |
| **ServerIp** | An IP address registered on a server (per-site binding, vhost ports) | `server_ip` (`server_ip_id`) | `ServerIp.yaml` | `app/Models/ServerIp.php` |
| **ServerIpMap** | NAT source→destination IPv4 rewrite rule | `server_ip_map` (`server_ip_map_id`) | `ServerIpMap.yaml` | `app/Models/ServerIpMap.php` |
| **Firewall** | The (single) port-list firewall record of a server | `firewall` (`firewall_id`, UNIQUE `server_id`) | `ServerFirewall.yaml` | `app/Models/Firewall.php` |
| **ServerPhp** | An additional PHP version installed on a web server | `server_php` (`server_php_id`) | `ServerPhp.yaml` | `app/Models/ServerPhp.php` |

Relationships: all child entities `belongsTo` Server via `server_id`; `ServerIp`/`ServerPhp` optionally reference `client.client_id` (0 = unassigned); `Server.mirror_server_id` self-references `server`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: All 50 operations in the table above respond with the declared success and error status codes; Swagger UI (`/api/documentation`) renders the complete server module (including the four config-section paths currently missing from the root doc) and "Try it out" succeeds against a dev database.
- **SC-002**: Every write on `server`, `server_ip`, `server_ip_map`, `firewall`, `server_php` produces exactly one well-formed `sys_datalog` entry (correct table, PK name/value, action i/u/d, serialized old/new payload) that a stock ISPConfig server daemon processes without error; zero direct writes to those tables outside `BaseModel`.
- **SC-003**: A config-section PUT followed by a GET round-trips: the changed keys read back, every other section of the blob is byte-identical, and legacy ISPConfig's `ini_parser` can parse the stored blob (verified against `getconf::get_server_config` semantics).
- **SC-004**: All documented legacy validation cases (US2 #2/3/5, US3 #2/3, US4 #2/3, US5 #2/3/4, US6 #3, US7 #2) return 422/409 exactly as specified — parity verified case-by-case against the tform definitions cited in the parity map.
- **SC-005**: Route-ordering check passes: `GET /servers/configs` returns the config list (not a 404/parse attempt of "configs" as `{id}`), and all pre-existing module routes still resolve.
- **SC-006**: Every NEEDS CLARIFICATION item (FR-014, FR-015, FR-022, FR-031, FR-051…FR-054, FR-061) is resolved and recorded before its implementing task is started; no silent choices.

## Assumptions

- **Scope**: only the endpoints in the six existing YAMLs are in scope; the legacy `getmail` section, `iptables` table, `dbsync`, `directive_snippets`, and `/monitor/servers/status` (`ServerStatus.yaml`) belong to other features. The contract's declared `basicAuth` is treated as documentation drift; the existing `X-API-Key` middleware is reused, with no per-endpoint ACL (the 403 responses declared in the YAMLs are reserved for a future permission model).
- **Pagination shape**: the module's YAML (like every other module's) specifies `{data, pagination}` with `limit`/`offset` params; the constitution's Principle V text says `{items,total,limit,offset}`. Per Principle I (spec is source of truth) and the reference implementation (`MailDomainController`), this feature follows the YAML. The constitution/spec wording conflict is flagged for `/speckit-constitution` follow-up rather than resolved unilaterally here.
- **Sys-field defaults**: created records get `sys_userid=1`, `sys_groupid=1`, `sys_perm_user='riud'`, `sys_perm_group='riud'`, `sys_perm_other=''` unless supplied, consistent with the legacy auth_presets and the existing `MailDomain` model defaults.
- **Async semantics**: success responses confirm the datalog entry, not the applied change; ISPConfig daemons apply changes asynchronously.
- **Environment**: a populated `dbispconfig` is available; legacy behavior is verified against the `source_code/` tree currently vendored (which is newer than `ISPConfig-DB-Structure.txt` — e.g., `server_php.php_cli_binary`/`php_jk_section` exist in `install/sql/ispconfig3.sql` but not in the txt; the install SQL is treated as authoritative).
- **Tests**: none requested by this spec (constitution: optional). Verification is via Swagger UI + datalog inspection per the Success Criteria.
