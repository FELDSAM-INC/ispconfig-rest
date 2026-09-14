# ISPConfig REST API

A modern, contract-first REST API for [ISPConfig 3.3](https://www.ispconfig.org/), built on Laravel 12. It exposes ISPConfig's full administration surface — clients, DNS, mail, sites, servers, monitoring, and system configuration — as ~270 industry-standard REST endpoints, while remaining a first-class citizen of ISPConfig's own change-management system.

## How it works

The API connects directly to ISPConfig's `dbispconfig` MySQL database, but **never modifies ISPConfig tables directly**. Every write is journaled through ISPConfig's `sys_datalog` table in the exact byte format the legacy interface produces, so ISPConfig's server daemons pick up and apply changes precisely as if they came from the built-in panel. Behavioral parity with the legacy interface (validation rules, derived fields, side effects, cascades) is reverse-engineered from the ISPConfig source and enforced by tests.

The OpenAPI 3 contract in [`api/`](api/) is the source of truth — the PHP implements it, not the other way around. Explore it live at `/api/documentation` (Swagger UI).

## Requirements

- PHP ≥ 8.3
- Composer
- Network access to an ISPConfig 3.3 MySQL database (`dbispconfig`) — reverse-engineered against 3.3.0p1, validated live against 3.3.1p1

## Install on an ISPConfig server (recommended)

Run the installer on your existing ISPConfig 3.3 host and it wires everything up:

```bash
curl -sSL https://raw.githubusercontent.com/FELDSAM-INC/ispconfig-rest/main/install.sh | sudo bash
```

What it does:

- **Reads the runtime database credentials** from ISPConfig's own config
  (`/usr/local/ispconfig/interface/lib/config.inc.php`).
- **Creates the API-owned `api_keys` table using a privileged (root) DB login** —
  by default MySQL root over the local unix socket, exactly as ISPConfig's own
  installer does — so the runtime user needs no `CREATE` right and nothing about
  ISPConfig is modified. (Pass `--db-admin-pass` if root needs a password.)
- **Detects your web server** (Apache or nginx) and creates a **dedicated
  php-fpm pool plus a dedicated vhost** on its own HTTPS port (default 8090) that
  **reuses the ISPConfig panel SSL certificate** (`ispserver.crt`/`.key`/`.bundle`) —
  the same php-fpm serving model ISPConfig uses for its own sites. The vhost is
  standalone — it never edits ISPConfig's own interface (8080) or apps (8081)
  vhosts, which ISPConfig regenerates on update. (If php-fpm isn't installed,
  Apache falls back to mod_php.)
- **Opens the port in ISPConfig's own firewall** — it adds the port to the
  server's `firewall` record through the datalog, so ISPConfig's firewall plugin
  (bastille/ufw) reconfigures natively. It never creates a firewall record where
  none exists (that would restrict the firewall to only this port); if ISPConfig
  isn't managing the firewall it falls back to an active ufw/firewalld.
- **Registers `ispconfig-rest`** in your PATH and offers to mint an admin key.

Every prompt has a flag and `ISPC_REST_*` env var for unattended installs — see
`sudo ./install.sh --help`. Reference templates live in [`deploy/`](deploy/):
[Apache vhost](deploy/apache-vhost.conf.example),
[nginx vhost](deploy/nginx-vhost.conf.example),
[php-fpm pool](deploy/php-fpm-pool.conf.example).

### Managing the installation

```bash
ispconfig-rest status                        # service state, version, DB connectivity
ispconfig-rest update                        # pull latest, install deps, migrate, restart
ispconfig-rest key:create "my integration"   # mint an admin key
ispconfig-rest key:create "acme" --client-id 42   # mint a client-scoped key
ispconfig-rest key:list --client-id 42       # list keys (never shows secrets)
ispconfig-rest key:revoke 17                 # revoke (deactivate) a key
ispconfig-rest firewall:allow 8090           # open a port in the ISPConfig firewall
ispconfig-rest restart | logs -f | version | uninstall
```

## Manual / development installation

```bash
git clone https://github.com/FELDSAM-INC/ispconfig-rest.git && cd ispconfig-rest
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env` with your ISPConfig database credentials (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`), then create the API's own key table and mint a key:

```bash
php artisan migrate            # creates only the api_keys table — ISPConfig tables are never migrated
php artisan api:key:create "my integration"
```

Run the development server with `php artisan serve`.

## Authentication

Every request requires an API key in the `X-API-Key` header. Keys are stored SHA-256-hashed and bound to an ISPConfig `sys_userid`/`sys_groupid`, which all datalogged changes are attributed to.

```
X-API-Key: isp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

For local development, setting `API_DEV_KEY` in `.env` enables a fixed key that authenticates as the ISPConfig admin (local/development/testing environments only).

### Permission scope

Each key is bound to an ISPConfig user, and access follows ISPConfig's own `sys_perm_*` model:

- A key for the **admin** user (`sys_userid` 1) has unrestricted access — the default when you mint a key without `--client-id`.
- A key bound to a **client or reseller** (`php artisan api:key:create "label" --client-id=N`) sees and mutates only the rows that user's AUTHSQL grants (own rows, rows in its groups, world-readable rows). Rows it cannot read return `404`; rows it can read but not modify return `403`. The admin-only modules — `servers`, `system`, `monitor`, and `resellers` — return `403` in full. On create, the key's identity is stamped onto the row; client-supplied `sys_userid`/`sys_groupid` values are ignored.

Scoped keys are also bound by their client's **resource limits** (`client.limit_*`): creating past a booked cap (e.g. `limit_maildomain`) returns `403`, and quota-sum limits (mailbox/web/database quota) are enforced on create and update. Resellers are additionally capped by their own limits. Admin keys are unaffected.

Scoped keys may only place new websites (vhosts), mail domains, databases and DNS zones on servers assigned to their account (`web_servers`, `mail_servers`, `db_servers`, `dns_servers` on the client row; resellers use their own row). When `server_id` is omitted, the first assigned server is used; an unassigned or nonexistent server returns `422` on `server_id` with the same message. Secondary DNS zones always use the account's `default_slave_dnsserver`, fetchmail jobs the destination mailbox's server, and fetchmail destinations must be mailboxes the key can read. Scoped keys cannot move DNS zones or secondary zones to another server. `GET /api/v1/me/servers` lists the servers a key may use, with the default marked. Resellers created through the API have no server lists until an admin assigns them.

### Managing keys over HTTP

Admin keys manage keys remotely under `/system/api-keys` (client and reseller keys receive `403`):

- `POST /system/api-keys` with `{"name": "...", "client_id": 42}` creates a key bound to the client's
  control-panel identity; omit `client_id` for an admin key. The plaintext `key` is returned **only in this
  response**.
- `GET /system/api-keys` lists keys (filters `client_id`, `active`, and `name` with `*` wildcards);
  `GET /system/api-keys/{id}` shows one. The key and its hash are never returned.
- `PUT /system/api-keys/{id}` renames a key or sets `active` to revoke or re-activate it;
  `DELETE /system/api-keys/{id}` deletes it. A key cannot revoke or delete itself (`409`).
- Deleting a client with `DELETE /clients/{id}` deactivates the client's keys.

Any valid key can call `GET /me` to read its own identity and scope.

## Conventions

- **Lists**: `GET /api/v1/{module}/{resource}?limit=25&offset=0&sort=domain&order=asc` returns `{ "data": [...], "meta": { "total", "limit", "offset" } }`. Unknown query parameters are rejected with `400` — filters are never silently ignored.
- **Errors**: RFC 9457 `application/problem+json` — `{ "type", "title", "status", "detail" }`, plus an `errors` map on validation failures (`422`).
- **Status codes**: `200` read/update, `201` create, `204` delete, `400/401/404/409/422` as problem+json.
- **Async writes**: a successful write confirms the `sys_datalog` journal entry; ISPConfig's daemons apply it within their next cycle (typically ≤ 1 minute). Track processing via `GET /api/v1/monitor/data-logs?unprocessed_only=true&server_id={id}`.
- **Booleans**: ISPConfig's `y/n` enum columns are exposed as JSON booleans and stored in the column's native case.

## Modules

| Module | Resources |
|--------|-----------|
| `clients` | clients, resellers, client domains, templates, template assignments, circles |
| `dns` | zones (SOA), records (incl. SPF/DKIM/DMARC stored as TXT like legacy), slave zones, templates |
| `mail` | domains, mailboxes (+ autoresponder/cc/filters/password/spamfilter sub-resources), forwards, alias domains, fetchmail, transports, relay domains/recipients, access rules, content filters, spamfilter config/policies/users/wblist |
| `sites` | web domains (+ SSL sub-resource), child domains, FTP/shell users, databases, database users, cron jobs, web folders/folder users, WebDAV users |
| `servers` | servers, per-section server config, firewall, IP addresses, IP mappings, PHP versions |
| `system` | global config panels, directive snippets, DNS CAA policies, resync |
| `monitor` | datalog journal, per-server status, system logs |

## Client lock and cancel

`locked` and `canceled` on `POST/PUT /clients` and `/resellers` carry ISPConfig's lock and cancel side effects:

- **Lock** (`locked` false → true): every website, mail domain, mailbox (receiving and sending), forward, fetchmail entry, database, FTP/shell/WebDAV user, protected folder and cron job of the client is disabled through the datalog, and the previous states are kept in the client's lock snapshot (`client.tmp_data`, legacy format — locks are interchangeable with the ISPConfig interface). **Unlock** restores them.
- **Cancel** (`canceled`): disables or enables the client's ISPConfig interface login; services and API keys are not affected. `canceled: true` on create starts with the login disabled.
- Side effects run only when the value changes. Reseller locks affect only the reseller's own services.
- While a client is locked, client and reseller keys cannot re-enable its services or add new ones for it (`403`); admin keys can.

## Testing

```bash
php artisan test
```

The suite (560+ tests) runs against an in-memory sqlite database with ISPConfig-shaped schemas and asserts, among other things, the exact byte format of every `sys_datalog` payload. CI runs on every push (`.github/workflows/tests.yml`).

## Known deviations from legacy ISPConfig

Deliberate and documented in code where they occur:

- **Interface-session behaviors are not replicated**: `use_domain_module` first-enable domain seeding, `maintenance_mode` session purge, and `session_timeout` → `sys_config` sync are legacy UI-session concerns with no REST equivalent.
- **`resync_client` does not raise the interface plugin event** `client:client:on_after_update` (un-raisable outside the legacy interface); datalog re-emission is performed.
- **`server.config` has two write disciplines, both legacy-faithful**: the server-config endpoints datalog their updates (as `server_config_edit.php` does); the mail `spamfilter/config` endpoint writes without datalog (as the legacy spamfilter panel does).
- **Directive-snippet in-use checks use exact ID matching** — legacy's REGEXP substring-matches (snippet 5 matches "15"); a regression test documents the divergence.
- **Deleting a DNS zone with records returns `400`** instead of legacy's silent cascade (declared in the contract).
- **DNS CAA policy writes are datalogged** although legacy writes `dns_ssl_ca` with direct SQL (whose insert is broken upstream) — a documented superset.

- **Client cancel applies on create**: `canceled: true` creates the control-panel login inactive; legacy ignores both flags on insert.
- **Client lock and cancel run only when the flag changes** (legacy panel behavior); the legacy remote API re-runs them on every update.
- **Locked clients' services cannot be re-enabled or extended by client and reseller keys** (`403`); legacy allows both.

## Project governance

Engineering rules live in [`.specify/memory/constitution.md`](.specify/memory/constitution.md); per-module specifications in [`specs/`](specs/). The legacy ISPConfig source used as the parity reference is expected (untracked) at `source_code/`.

## License

BSD-3-Clause — see the LICENSE file for details.
