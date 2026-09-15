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
- **Aligns the API timezone with the server** — `APP_TIMEZONE` follows the system
  timezone, because ISPConfig writes traffic dates in local time; `--timezone TZ` sets it
  explicitly. `ispconfig-rest update` keeps an automatically chosen timezone in sync (after upgrading
  from a release without it, run `update` twice) and `ispconfig-rest status` shows it.
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

Scoped keys are also bound by their client's **resource limits** (`client.limit_*`): creating past a booked cap (e.g. `limit_maildomain`, or `limit_dns_record` for DNS records counted by the account's group) returns `403`, and quota-sum limits (mailbox/web/database quota) are enforced on create and update. Resellers are additionally capped by their own limits. Admin keys are unaffected.

Scoped keys may only place new websites (vhosts), mail domains, databases and DNS zones on servers assigned to their account (`web_servers`, `mail_servers`, `db_servers`, `dns_servers` on the client row; resellers use their own row). When `server_id` is omitted, the first assigned server is used; an unassigned or nonexistent server returns `422` on `server_id` with the same message. Secondary DNS zones always use the account's `default_slave_dnsserver`, fetchmail jobs the destination mailbox's server, and fetchmail destinations must be mailboxes the key can read. Scoped keys cannot move DNS zones or secondary zones to another server. `GET /api/v1/me/servers` lists the servers a key may use, with the default marked. Resellers created through the API have no server lists until an admin assigns them.

Scoped keys are also bound by their plan's **website options**, as in the ISPConfig interface (the acting account's own client row; resellers use their own):

- `ssl`, `ssl_letsencrypt`, `cgi`, `ssi`, `perl`, `ruby`, `python`, `errordocs`, `directive_snippets_id` and `subdomain: "*"` need the matching `limit_*` option, and `suexec: false` is refused when `force_suexec` is set (`422` on the field). Options outside the plan are also switched off on every save, as the interface does.
- `php` must be one of the modes allowed by the system and client `web_php_options`; when omitted on create, `fast-cgi` is used if allowed, else the first allowed mode. `server_php_id` must be an active version on the website's server, public or the account's own, that supports the mode; when the server hides the default version, a real version is required and the first available one is used when omitted.
- The Options-tab settings (`allow_override`, `pm*`, `php_open_basedir`, `custom_php_ini`, `*_directives`, ports, `log_retention`, jailkit fields…) cannot be changed unless the key is a reseller and `reseller_can_use_options` is enabled; the SSL-tab fields (`ssl_state` … `ssl_domain`) need `limit_ssl`. Plain client keys cannot change `domain`, `ip_address`, `ipv6_address` or `vhost_type` of a website.
- Uploading or deleting a certificate (`/ssl`) returns `403` without `limit_ssl`; renewing also needs `limit_ssl_letsencrypt`.

Only values that differ from the stored (or default) value are checked, so repeating a website's current settings is accepted. Admin keys are unaffected.

A panel can read these rules with the customer's own key: `GET /api/v1/me/capabilities` returns the plan's website options, the allowed PHP modes (and the mode a new website gets) and whether the account is locked or canceled; `GET /api/v1/me/php-versions` lists the PHP versions the account's websites may use per web server and mode (`server_id`, `mode`), starting with the server's default version unless the server hides it. Reseller keys may pass `client_id` for one of their clients; admin keys must pass it.

The capabilities also carry `mail`: whether autoresponders and mail filters can be changed, whether a spam filter level can be chosen, whether DKIM is available on the account's mail servers, whether mailboxes log in with a custom name, and the mailbox password policy. `GET /api/v1/me/mail-settings` returns what a mail program needs for each mail server of the account (server name, IMAP 993 and POP3 995 with SSL/TLS, SMTP 587 with STARTTLS and 465 with SSL/TLS, webmail address) plus the same password policy. Client and reseller keys cannot write autoresponders or mail filter settings when the installation hides those mailbox tabs (403 `feature-not-allowed`), and never custom mail filter scripts (`custom_mailfilter`, administrator keys only). `GET /api/v1/usage/summary` also counts catch-all addresses, mail alias domains, mail filter rules and fetchmail accounts.

`GET /api/v1/me/hosting-addresses` gives a panel what it needs to point domains at the hosting: per web and mail server of the account the public IPv4 and IPv6 addresses for A and AAAA records (the server's addresses shared with all clients or dedicated to this client; private, loopback, link-local and reserved addresses and other clients' dedicated addresses are never listed) and the mail server name for MX records, plus for each DNS server the name servers to hand to a registrar (the server and its mirrors, then the installation's external DNS servers, as ISPConfig's zone import composes them). Reseller keys may pass `client_id` for one of their clients; admin keys must pass it.

Customer keys can choose the spam filter level of their mailboxes and mail domains: `PUT /api/v1/mail/users/{id}/spamfilter` with `policy_id` (0 = inherit) and `POST`/`PUT /api/v1/mail/domains` with `spamfilter_policy_id` (0 = no policy); both are also returned on reads. Only levels listed by `GET /api/v1/mail/spamfilter/policies` for that key are accepted. The level is stored in the mailbox's or domain's spam filter user row, as ISPConfig does.

`POST /api/v1/mail/domains/{id}/dkim` generates a DKIM key pair on the server (key size from the mail server's `dkim_strength`, optional `selector`), switches DKIM on and publishes the TXT record when the domain's DNS zone is hosted here; `GET /api/v1/mail/domains/{id}/dkim` returns the status, public key and DNS record. Generation returns `409` when the mail server has no DKIM key directory. Switch DKIM off with `PUT /api/v1/mail/domains/{id}` `{"dkim": false}`. The private key is never returned to client and reseller keys, neither by the DKIM endpoints nor by the mail domain resource.

Mailboxes expose `disableimap`, `disablepop3`, `disablesmtp` and `disabledeliver` (switching IMAP off also disables Sieve, switching delivery off also sets the LDA/LMTP flags); customer keys of a locked account cannot switch sending back on. Mailbox passwords on `POST /api/v1/mail/users`, `PUT /api/v1/mail/users/{id}` and `PUT /api/v1/mail/users/{id}/password` must satisfy the installation password policy for every key type (`min_password_length`, default 8, and `min_password_strength` with the ISPConfig strength scale, or ASCII-only mode); refusals use the ISPConfig messages.

References inside a request body follow the same read scope: for client and reseller keys, the mail domain of a mailbox, forward, alias, catch-all or alias domain, an alias's destination mailboxes, the parent website of subdomains, FTP/shell/WebDAV users, cron jobs, protected folders and databases, a database's users, a folder user's folder, a DNS record's zone and an allow/deny list entry's spam filter user must all be visible to the key. A reference the key cannot see is rejected exactly like a nonexistent one (`400`, `404` or `422` as for a missing value).

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
- **Problem types**: refusals an integration acts on carry a stable `type` URI instead of `about:blank` (spec 023, [docs/problems.md](docs/problems.md)): `…/docs/problems.md#account-locked`, `#limit-reached` and `#quota-exceeded` (403, with a `limit` member — `name`, `scope`, `max`, `used`, plus `unit` and `requested` for quotas), `#feature-not-allowed` (403, with `feature`, the client limit column) and `#validation-failed` (422), whose `error_types` map marks fields refused as `feature-not-allowed` or `server-not-assigned`. Status codes, titles and details are unchanged; compare the `type`, not the text.
- **Status codes**: `200` read/update, `201` create, `204` delete, `400/401/404/409/422` as problem+json.
- **Let's Encrypt outcome**: `GET /api/v1/sites/web-domains/{id}/ssl/status` reports `none`, `requested`, `issued` or `failed` for a website's free certificate. ISPConfig switches Let's Encrypt back off without a journal entry when issuance fails, so the state is derived from the enabling change, its processing status and the current flags. Failure reasons (`domain_not_reachable`, `issuance_failed`, `certificate_not_found`, `client_unavailable`) need the server log level set to Warning or Debug (System → Server Config → Server → Log level); otherwise the reason is `unknown`. Certificate validity is returned only when the API can read `<document_root>/ssl/<domain>-le.crt` (single-server installations).
- **Async writes**: a successful write confirms the `sys_datalog` journal entry; ISPConfig's daemons apply it within their next cycle (typically ≤ 1 minute). Every write that journaled at least one entry returns an `X-Change-Set-Id` header; any key polls `GET /api/v1/changes/{id}` until the status is `applied`, `failed` or `stalled`, or lists its pending and failed changes with `GET /api/v1/changes?status=pending`. No-change updates, validation failures and writes to API-owned data (API keys) return no header. `/monitor/data-logs` stays the admin view of journal payloads.
- **Booleans**: ISPConfig's `y/n` enum columns are exposed as JSON booleans and stored in the column's native case.

## Modules

| Module | Resources |
|--------|-----------|
| `clients` | clients, resellers, client domains, templates, template assignments, circles |
| `dns` | zones (SOA), records (incl. SPF/DKIM/DMARC stored as TXT like legacy), slave zones, templates |
| `mail` | domains, mailboxes (+ autoresponder/cc/filters/password/spamfilter sub-resources), forwards, alias domains, fetchmail, transports, relay domains/recipients, access rules, content filters, spamfilter config/policies/users/wblist |
| `sites` | web domains (+ SSL, Let's Encrypt status, backups, backup jobs and backup settings sub-resources), child domains, FTP/shell users, databases, database users, cron jobs, web folders/folder users, WebDAV users |
| `servers` | servers, per-section server config, firewall, IP addresses, IP mappings, PHP versions |
| `system` | global config panels, directive snippets, DNS CAA policies, resync |
| `changes` | processing status of journaled writes: change sets, pending/failed list, record view (every key) |
| `usage` | read-only usage statistics: plan summary against limits, website/mailbox/database usage, traffic history (every key) |
| `monitor` | datalog journal, per-server status, system logs |

## Client lock and cancel

`locked` and `canceled` on `POST/PUT /clients` and `/resellers` carry ISPConfig's lock and cancel side effects:

- **Lock** (`locked` false → true): every website, mail domain, mailbox (receiving and sending), forward, fetchmail entry, database, FTP/shell/WebDAV user, protected folder and cron job of the client is disabled through the datalog, and the previous states are kept in the client's lock snapshot (`client.tmp_data`, legacy format — locks are interchangeable with the ISPConfig interface). **Unlock** restores them.
- **Cancel** (`canceled`): disables or enables the client's ISPConfig interface login; services and API keys are not affected. `canceled: true` on create starts with the login disabled.
- Side effects run only when the value changes. Reseller locks affect only the reseller's own services.
- While a client is locked, client and reseller keys cannot re-enable its services or add new ones for it (`403`); admin keys can.
- While a client is locked, client and reseller keys also cannot start, restore or delete its website backups or change backup settings (`403`); listing backups and preparing downloads still work.

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
- **Website plan options are refused explicitly**: client and reseller keys get `422` for options outside their plan and for administrator-only settings, which the interface only hides or silently resets; PHP mode and version lists, read-only domain fields and the SSL tab are enforced server-side (legacy enforces them in the form only).

## Project governance

Engineering rules live in [`.specify/memory/constitution.md`](.specify/memory/constitution.md); per-module specifications in [`specs/`](specs/). The legacy ISPConfig source used as the parity reference is expected (untracked) at `source_code/`.

## License

BSD-3-Clause — see the LICENSE file for details.
