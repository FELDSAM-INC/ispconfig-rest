# ISPConfig REST API

A modern, contract-first REST API for [ISPConfig 3.2](https://www.ispconfig.org/), built on Laravel 12. It exposes ISPConfig's full administration surface — clients, DNS, mail, sites, servers, monitoring, and system configuration — as ~270 industry-standard REST endpoints, while remaining a first-class citizen of ISPConfig's own change-management system.

## How it works

The API connects directly to ISPConfig's `dbispconfig` MySQL database, but **never modifies ISPConfig tables directly**. Every write is journaled through ISPConfig's `sys_datalog` table in the exact byte format the legacy interface produces, so ISPConfig's server daemons pick up and apply changes precisely as if they came from the built-in panel. Behavioral parity with the legacy interface (validation rules, derived fields, side effects, cascades) is reverse-engineered from the ISPConfig source and enforced by tests.

The OpenAPI 3 contract in [`api/`](api/) is the source of truth — the PHP implements it, not the other way around. Explore it live at `/api/documentation` (Swagger UI).

## Requirements

- PHP ≥ 8.2 (8.3+ recommended)
- Composer
- Network access to an ISPConfig 3.2 MySQL database (`dbispconfig`)

## Installation

```bash
git clone <repository-url> ispconfig_rest && cd ispconfig_rest
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env` with your ISPConfig database credentials (`DB_HOST`, `DB_DATABASE=dbispconfig`, `DB_USERNAME`, `DB_PASSWORD`), then create the API's own key table and mint a key:

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

> The API currently operates at **admin scope**: per-client permission enforcement (ISPConfig's `sys_perm_*` model) is not applied per key. Grant keys accordingly.

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

## Project governance

Engineering rules live in [`.specify/memory/constitution.md`](.specify/memory/constitution.md); per-module specifications in [`specs/`](specs/). The legacy ISPConfig source used as the parity reference is expected (untracked) at `source_code/`.

## License

BSD-3-Clause — see the LICENSE file for details.
