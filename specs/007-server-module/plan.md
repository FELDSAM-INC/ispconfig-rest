# Implementation Plan: Server Module

**Branch**: `007-server-module` | **Date**: 2026-07-04 | **Spec**: `specs/007-server-module/spec.md`
**Input**: Feature specification from `/specs/007-server-module/spec.md`
**Status**: Draft — planned against an existing, unimplemented contract (`api/modules/server/*.yaml`); no server-module PHP exists yet.

## Summary

Implement the fully-specced, entirely unbuilt **server** module: 50 operations over 22 paths covering server inventory (`server`), the INI-blob server configuration (`server.config` + 10 section endpoints), server IP addresses (`server_ip`), NAT IP mappings (`server_ip_map`), per-server firewall port lists (`firewall`), and additional PHP versions (`server_php`). Approach: mirror the `MailDomainController`/`MailDomain` reference pattern per resource (thin controller, `BaseModel` subclass with legacy validation rules, datalog-only writes), plus one dedicated service — `ServerConfigService` — that reproduces ISPConfig's `ini_parser`/`getconf` read-modify-write semantics for the config blob. Several contract-vs-schema mismatches (spec.md FR-014/FR-022/FR-052…FR-054, FR-062…FR-064) must be resolved spec-first before the affected code is written.

## Technical Context

**Language/Version**: PHP ^7.3|^8.0 (Laravel Lumen 8.3)  
**Primary Dependencies**: laravel/lumen-framework, Eloquent ORM; dev: phpunit ^9.5, mockery, fakerphp  
**Storage**: MySQL — ISPConfig's `dbispconfig` database (schema owned by ISPConfig; never migrated by this project; all writes via `sys_datalog`). Tables touched: `server`, `server_ip`, `server_ip_map`, `firewall`, `server_php` (reads may also touch `client` for reference checks; the rspamd side effect touches `spamfilter_users`/`spamfilter_wblist` — pending FR-051 clarification)  
**Testing**: PHPUnit (`vendor/bin/phpunit`) — the spec requests no tests; verification is Swagger UI "Try it out" + `sys_datalog` inspection  
**Target Platform**: Linux server alongside an ISPConfig installation  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: N/A — admin-scale traffic; the only hot spot is re-serializing the config blob (a few KB of INI text) per section PUT  
**Constraints**: async write semantics via `sys_datalog` (201 create / 200 update / 204 delete); behavioral parity with `source_code/interface/web/admin/` (forms `server*.tform.php`, `firewall.tform.php`; actions `server_config_edit.php` etc.); the config blob MUST stay parseable by legacy `ini_parser` at all times  
**Scale/Scope**: 6 resources, 5 tables + 1 virtual (config), 50 operations, 5 new models, 6 new controllers, 1 new service, ~30 route registrations

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: all six YAMLs exist under `api/modules/server/` with schemas in `api/components/schemas/`. **Caveat — the contract itself needs surgical fixes before implementation can mirror it verbatim**: root `api/openapi.yaml` misses 4 config-section paths (FR-062); path-param name mismatches `server_id` vs `{id}` (FR-063); `ServerIp.yaml` declares a nonexistent `active` column and `Server.yaml` requires nonexistent `ip_address`/`hostname` columns (FR-014/FR-022); section-config schemas diverge from the legacy field inventories (FR-053/FR-054). These are spec edits (Phase 1), not code workarounds.
- [x] **Datalog-only writes (II)**: `Server`, `ServerIp`, `ServerIpMap`, `Firewall`, `ServerPhp` all extend `App\Models\BaseModel`. Config updates are an update of the `server` row's `config` attribute through the `Server` model — no custom save path. Note: `BaseModel` derives the datalog `server_id` from the record's `server_id` attribute, which for the `server` table is the PK itself — correct, matches legacy `datalogUpdate('server', ..., 'server_id', $id)`.
- [x] **Legacy parity (III)**: legacy reviewed (see Legacy Research below); validations/defaults/side effects captured in spec.md's parity map with file citations. Open parity questions are flagged as NEEDS CLARIFICATION, not decided silently.
- [x] **Route discipline (IV)**: all routes go into the `api.auth` group in `routes/web.php`. Ordering hazard identified: `servers/configs` MUST be registered before `servers/{id}`; all nested `servers/{id}/...` routes are registered before the bare `servers/{id}` block for uniformity. No collision with existing routes (no `servers*` routes exist today; `/monitor/servers/status` lives under the `monitor/` prefix).
- [x] **HTTP contract (V)**: status codes 200/201/204 + 400/401/404/409/422/500 per the YAMLs; errors `{message, error}`. **Flagged tension**: the YAMLs (this module and all others) define list responses as `{data, pagination}` with a Laravel-paginator `Pagination.yaml`, while Principle V's text says `{items,total,limit,offset}`. Per Principle I the YAML wins; recorded in Complexity Tracking for a constitution amendment rather than silently diverging either way.
- [x] **No schema changes**: no migrations; `database/` untouched.

## Project Structure

### Documentation (this feature)

```text
specs/007-server-module/
├── spec.md              # Feature spec (done — reverse-engineered draft)
├── plan.md              # This file
└── tasks.md             # Task list (/speckit-tasks output)
# research.md / data-model.md / contracts/ are not generated separately:
# legacy research is condensed below and in spec.md's parity map; the
# contract already exists under api/modules/server/.
```

### Source Code (repository root)

```text
api/
├── openapi.yaml                                  # EDIT: register /servers/{id}/configs/{web,dns,fastcgi,xmpp} (FR-062)
├── modules/server/
│   ├── _index.yaml                               # exists — verify only
│   ├── servers.yaml                              # EDIT: param name server_id → id on PUT/DELETE (FR-063)
│   ├── server-config.yaml                        # EDIT: param names (FR-063); resolve POST/DELETE semantics (FR-052)
│   ├── ip-addresses.yaml                         # exists — implement as-is
│   ├── ip-mappings.yaml                          # exists — implement as-is
│   ├── firewall.yaml                             # exists — implement as-is
│   └── php-versions.yaml                         # exists — implement as-is
└── components/schemas/
    ├── Server.yaml                               # EDIT: resolve ip_address/hostname (FR-014), hidden columns (FR-015)
    ├── ServerIp.yaml                             # EDIT: drop phantom `active` (FR-022)
    ├── ServerFirewall.yaml                       # EDIT: x_db_table → x-db-table, add sys_* (FR-064)
    ├── ServerConfig.yaml + Server*Config.yaml    # EDIT: reconcile with legacy field inventories (FR-053/FR-054)
    ├── ServerIpMap.yaml, ServerPhp.yaml          # exist — verify only
    └── Pagination.yaml                           # reuse

app/
├── Http/Controllers/Api/V1/
│   ├── ServerController.php                      # NEW — index/show/store/update/destroy (/servers)
│   ├── ServerConfigController.php                # NEW — index (all configs), show, store?, update, destroy?,
│   │                                             #        showSection($id,$section), updateSection($id,$section)
│   ├── ServerIpController.php                    # NEW — nested CRUD (/servers/{id}/ip-addresses)
│   ├── ServerIpMapController.php                 # NEW — nested CRUD (/servers/{id}/ip-mappings)
│   ├── ServerFirewallController.php              # NEW — nested CRUD (/servers/{id}/firewall)
│   └── ServerPhpController.php                   # NEW — nested CRUD (/servers/{id}/php-versions)
├── Models/
│   ├── Server.php                                # NEW — table server, PK server_id; integer 0/1 flags; also fixes
│   │                                             #        the dangling Server::class ref in MailDomain::server()
│   ├── ServerIp.php                              # NEW — table server_ip, PK server_ip_id
│   ├── ServerIpMap.php                           # NEW — table server_ip_map, PK server_ip_map_id
│   ├── Firewall.php                              # NEW — table firewall, PK firewall_id (UNIQUE server_id)
│   └── ServerPhp.php                             # NEW — table server_php, PK server_php_id
└── Services/
    └── ServerConfigService.php                   # NEW — INI blob parse/serialize + section read-modify-write
                                                  #        (ini_parser/getconf-compatible), mail-section rules,
                                                  #        rspamd side effect (pending FR-051)

routes/web.php                                    # EDIT: server module block (ordering: servers/configs first,
                                                  #        nested servers/{id}/* next, bare servers/{id} last)
```

**Structure Decision**: one controller per resource, mirroring `MailDomainController`. The new route block is appended after the existing "Mail Domain endpoints" block inside the `api.auth` group, ordered: (1) `servers/configs`, (2) `servers/{id}/configs/{section}` + `servers/{id}/configs`, (3) `servers/{id}/ip-addresses*`, `servers/{id}/ip-mappings*`, `servers/{id}/firewall*`, `servers/{id}/php-versions*`, (4) `servers` + `servers/{id}`. Section endpoints route to two shared controller actions with a `{section}` parameter validated against a whitelist (mail, web, dns, fastcgi, xmpp, jailkit, ufw, vlogger, cron, rescue) rather than 20 near-identical methods — response shape per section is still schema-exact.

**Why `ServerConfigService` is structurally required (not gold-plating)**: the config blob is one TEXT column shared by 12+ logical sections. Legacy semantics are parse-whole → replace-section → serialize-whole → datalog (`server_config_edit.php::onUpdateSave`, `getconf.inc.php`, `ini_parser.inc.php`). Putting that in a controller would duplicate it across 10 section endpoints and violate the thin-controller rule; putting it in the model would hide business logic in persistence. The service owns: ISPConfig-compatible `parse(string): array` and `serialize(array): string`, `getSection`, `replaceSection` (preserving unknown sections such as `server`/`getmail`), checkbox-default backfilling, mail-section guard rules, and the rspamd re-sync side effect (if confirmed by FR-051).

## Legacy Research (Phase 0 focus)

Condensed findings; full per-resource table with citations lives in spec.md ("ISPConfig Parity & Datalog Impact").

- **Form definitions** (`source_code/interface/web/admin/form/`):
  - `server.tform.php` — server_name STRIPTAGS/STRIPNL; 0/1 INTEGER flags for 7 roles + active(default 1); mirror_server_id; config tab commented out (config edited via server_config form). auth_preset groupid=1.
  - `server_ip.tform.php` — ip_type IPv4/IPv6; ip_address CUSTOM `validate_server::check_server_ip` (FILTER_VALIDATE_IP by declared type) + UNIQUE; virtualhost y/n def y; virtualhost_port regex `/^([0-9]{1,5}\,{0,1}){1,}$/i` def `80,443`; client_id optional (0).
  - `server_ip_map.tform.php` — source_ip NOTEMPTY; destination_ip ISIPV4+NOTEMPTY; active y/n def y.
  - `firewall.tform.php` — **server_id UNIQUE validator** (one row per server); tcp/udp port regex `/^$|\d{1,5}(?::\d{1,5})?(?:,\d{1,5}(?::\d{1,5})?)*$/`, defaults `21,22,...,40110:40210` / `53`; active y/n def y.
  - `server_php.tform.php` — name NOTEMPTY+strip filters; php_cli_binary NOTEMPTY+`/^\/[a-zA-Z0-9\/\-\_\.\s]*$/`; php_jk_section NOTEMPTY+`/^[a-zA-Z0-9\-\_]*$/`; FastCGI/FPM paths optional; sortprio def 100; server datasource filtered to `web_server=1 AND mirror_server_id=0`.
  - `server_config.tform.php` (2314 lines, `db_table = server`) — 12 tabs: server, mail, getmail, web, dns, fastcgi, xmpp, jailkit, **ufw_firewall (commented out)**, vlogger, cron, rescue. Field inventories extracted per tab (see FR-054 for the schema-mismatch consequences).
- **Actions/lib side effects**:
  - `server_edit.php::onSubmit` — mirror_server_id forced 0 if self or server 1.
  - `server_del.php` — plain tform delete; no cascade; demo-mode + `admin_allow_server_services` gates.
  - `server_ip_edit.php::onBeforeUpdate` / `firewall_edit.php::onBeforeUpdate` — server_id immutable on update.
  - `server_config_edit.php` — `onSubmit`: mailbox_size_limit ≥ message_size_limit (mail tab); `onUpdateSave`: whole-blob read-modify-write + `datalogUpdate('server', {config}, ...)`, unchecked-checkbox backfill, rspamd_available forced from stored config; `onAfterUpdate`: content_filter→rspamd triggers datalog updates on all `spamfilter_users`/`spamfilter_wblist` of the server; `onShow`: nginx hides fastcgi+vlogger tabs (UI-only, not ported).
  - `getconf.inc.php::get_server_config` — `parse_ini_string(stripslashes(config))`, per-server cache; the parser is ISPConfig's own `ini_parser`, not PHP's `parse_ini_string`.
- **Permission checks**: module `admin`, security perms `admin_allow_server_services` / `admin_allow_server_config`; API deviates deliberately (global X-API-Key), documented in spec.md.
- **List definitions**: legacy lists sort by server_name / ip / etc.; the API exposes generic `sort`/`order` plus the firewall filters declared in `firewall.yaml` (active, tcp_port, udp_port partial match).
- **DB verification**: `install/sql/ispconfig3.sql` is authoritative (newer than `ISPConfig-DB-Structure.txt`): `server_ip` has **no `active` column**; `server_php` **has** `php_cli_binary`/`php_jk_section`; `server` has `proxy_server`,`firewall_server`,`config`,`updated`,`dbversion` beyond the form fields and **no `ip_address`/`hostname`**.

## Complexity Tracking

> Constitution Check passes by design; the entries below record the flagged tensions that need an explicit decision, not code-level violations.

| Violation / Tension | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| List responses `{data, pagination}` diverge from Principle V's `{items,total,limit,offset}` wording | The module YAML + `Pagination.yaml` + every implemented controller use the Laravel-paginator shape; Principle I makes the YAML authoritative | Unilaterally switching to `{items,...}` would break contract fidelity (SC-001) and consistency with all shipped modules; fix belongs in a constitution amendment or a global spec revision, not this feature |
| Contract edits required before implementation (FR-014, FR-022, FR-052–FR-054, FR-062–FR-064) | The YAMLs contain phantom columns, param-name bugs, and unregistered paths; implementing them "verbatim" is impossible or would corrupt data | Coding around the YAML in PHP (e.g., silently dropping `active` on server_ip) violates Principle I; spec-first means the YAML is corrected first, then mirrored |
| `ServerConfigService` (new service for one feature) | Blob read-modify-write + ISPConfig-INI compatibility is shared by 22 config operations and must be byte-safe | Controller-local helpers would be duplicated 10×, and any drift corrupts every service config on a node |
