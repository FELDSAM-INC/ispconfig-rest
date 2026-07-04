# Feature Specification: System Module (global config panels, directive snippets, resync)

**Feature Branch**: `008-system-module`
**Created**: 2026-07-04
**Status**: Draft (reverse-engineered from contract + legacy source; not yet implemented)
**Module**: system
**Input**: Existing OpenAPI contract `api/modules/system/*.yaml` (authored 2025-06-07, never implemented) + legacy ISPConfig source `source_code/interface/web/admin/` (system_config, directive_snippets) and `source_code/interface/web/tools/` (resync).

> This module has the highest impedance mismatch with the project's CRUD/datalog conventions of any module so far: two of its three sub-features are not row-CRUD at all (a singleton INI-blob settings resource and a fire-and-forget action endpoint), and the contract itself contains internal inconsistencies and one schema that contradicts the legacy table it claims to represent. Those tensions are surfaced below as NEEDS CLARIFICATION items rather than resolved silently.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Read and update the global settings panels (Priority: P1)

An API consumer (installer script, admin control panel, IaC tool) reads ISPConfig's global interface settings — the same values shown in legacy ISPConfig under System → Interface → Main Config (tabs: Sites, Mail, DNS, Domains, Misc) — and updates individual settings, e.g. sets `default_dnsserver`, changes the `dbname_prefix`, or flips `maintenance_mode`. The whole configuration is one record: row `sysini_id = 1` of `sys_ini`, whose `config` column is an INI-formatted text blob (`[section]` headers + `key=value` lines, parsed by legacy `ini_parser`). The API presents that blob as one composite resource (`GET/PUT /system/config`) and five per-section resources (`GET/PUT /system/config/{sites,mail,dns,domains,misc}`) — read/update only, no create, no delete, no list.

**Why this priority**: These panels are the namesake of the module and the prerequisite for automating an ISPConfig installation (default server IDs, prefixes, password policy). Everything else in the module is additive.

**Independent Test**: `GET /api/v1/system/config/dns` with a valid `X-API-Key`, verify the four DNS keys against the live `sys_ini.config` blob; `PUT` a changed `default_dnsserver`, verify 200, verify a `sys_datalog` row (`dbtable=sys_ini`, `dbidx=sysini_id:1`, `action=u`) whose new `config` string still contains every key of every *other* section unchanged.

**Acceptance Scenarios**:

1. **Given** a populated `sys_ini` row, **When** `GET /system/config`, **Then** 200 with `sysini_id` and the `sites`, `mail`, `dns`, `domains`, `misc` section objects per `SystemConfig.yaml`.
2. **Given** a PUT to one section with a subset of keys, **When** the update is applied, **Then** the re-serialized blob preserves all keys not present in the request — including legacy keys the API schema does not expose at all (e.g. `smtp_*` in `[mail]`, `client_protection` in `[sites]`) — and the response echoes the section per the YAML.
3. **Given** an invalid value (e.g. `dbname_prefix` failing legacy regex `/^[a-zA-Z0-9\-\_\[\]]{0,50}$/`, or a non-IP in `maintenance_mode_exclude_ips`), **When** PUT, **Then** 422 `{message, errors}` and no datalog entry is written.
4. **Given** any successful config PUT, **Then** exactly one `sys_datalog` entry is produced for `sys_ini` (mirroring legacy `datalogUpdate('sys_ini', {config}, 'sysini_id', 1)`), never a direct table write.

---

### User Story 2 - Manage directive snippets (Priority: P2)

A hosting automation tool manages reusable web-server configuration snippets (Apache/nginx/PHP/proxy) that sites reference via `web_domain.directive_snippets_id` — the legacy System → Directive Snippets screen. This is the module's only conventional CRUD entity: table `directive_snippets`, datalogged (`db_history=yes` in the legacy tform), endpoints `GET/POST /system/directive-snippets` and `GET/PUT/DELETE /system/directive-snippets/{id}`.

**Why this priority**: Full CRUD with clean `BaseModel` datalog fit — the least-risk, highest-convention-conformance slice; deliverable independently of the config panels.

**Independent Test**: POST a snippet (`name`, `type=php`, `snippet`), verify 201 + `sys_datalog` action `i`; list with `?type=php&active=y`; PUT rename; DELETE and verify datalog `d`.

**Acceptance Scenarios**:

1. **Given** a valid payload, **When** POST, **Then** 201 with the created snippet; `sys_userid/sys_groupid/sys_perm_*` defaulted (`riud`/`riud`/`''` per legacy `auth_preset`); `customer_viewable` defaults `n`, `active` defaults `y`, `update_sites` defaults `n` (DB default; the legacy edit form pre-checks it — see Assumptions).
2. **Given** a snippet with the same `name` and `type` already exists, **When** POST/PUT, **Then** the write is rejected (legacy `validate_server_directive_snippets::validate_snippet` enforces uniqueness of (name, type); the YAML declares 409 Conflict for POST).
3. **Given** a snippet of type `apache`/`nginx` referenced by a `web_domain` row (or a `php` snippet listed in another snippet's `required_php_snippets` that is itself in use), **When** DELETE, or PUT setting `active` `y→n`, or PUT setting `customer_viewable` `y→n`, **Then** the operation is rejected (legacy: `error_delete_snippet_active_sites` / `error_disable_snippet_active_sites` / `error_hide_snippet_active_sites`) — 400 for DELETE per the YAML. [NEEDS CLARIFICATION: the YAML's PUT declares no 409/400-with-reason for these two in-use rejections; proposed: 400 `{message, error}`]
4. **Given** PUT with `update_sites=y` and `active=y`, **When** the update succeeds, **Then** every affected `web_domain` row is re-emitted to `sys_datalog` as a forced full-record update (legacy `onAfterUpdate` → `datalogUpdate('web_domain', $website, 'domain_id', $id, true)`), so the web servers rewrite the vhosts using the snippet.
5. **Given** `type` outside `apache|nginx|php|proxy`, **When** POST/PUT, **Then** 422.

---

### User Story 3 - Trigger a service resync (Priority: P3)

After a server migration, restore, or mirror change, an operator re-emits configuration to the server daemons: `POST /system/resync` with flags (`resync_all`, `resync_sites`, `resync_dns`, …) and per-service server IDs (0 = all active servers of that type). This is an **action endpoint, not CRUD**: it writes nothing of its own; for every matching record of the selected services it inserts a *forced* update entry into `sys_datalog` (legacy `datalogUpdate($table, $full_record, $idx, $id, true)` — forced means an entry is written even though no column changed). DNS is special: instead of full-record re-emission it bumps every `dns_rr` and `dns_soa` serial (`increase_serial`) and datalogs only the serial change. `GET /system/resync/servers` lists candidate servers (legacy: `active = 1 AND mirror_server_id = 0`, filterable by server type).

**Why this priority**: Operationally valuable disaster-recovery tooling, but it depends on the rest of the API ecosystem existing and touches the datalog machinery in an unconventional way.

**Independent Test**: Seed one active web domain; `POST /system/resync` `{"resync_sites": 1, "web_server_id": 0}`; verify 204 and one new `sys_datalog` row per active `web_domain` with `action=u` and a full-record payload; `GET /system/resync/servers?server_type=web` returns the seeded server.

**Acceptance Scenarios**:

1. **Given** `resync_all=1` and `all_server_id`, **When** POST, **Then** all service flags are treated as set and `all_server_id` is propagated to every per-service server ID (legacy `onSubmit` behavior), and 204 is returned after the datalog entries are inserted.
2. **Given** `resync_dns=1`, **When** POST, **Then** for every matching active `dns_soa` zone each active `dns_rr` gets an increased serial datalogged, then the zone's `dns_soa.serial` is increased and datalogged (exact legacy order), reusing `DnsSerialService`.
3. **Given** `resync_db=1`, **Then** `web_database_user` (all rows, including inactive) is re-emitted before `web_database` (active only) — legacy order and active-filter per table (see Parity table).
4. **Given** a `*_server_id` that is not an existing server, **When** POST, **Then** 400 per the YAML.
5. **Given** `resync_client=1`, **Then** every `client` row is re-emitted; the legacy interface additionally raises the `client:client:on_after_update` interface-plugin event, which this API cannot replicate. [NEEDS CLARIFICATION: accept as documented deviation, or exclude `resync_client` from scope?]

---

### User Story 4 - Manage DNS Certification Authorities (Priority: P4)

An admin manages the CAA-record certification authority list (legacy System → Main Config → DNS CAs tab; table `dns_ssl_ca`) used by ISPConfig to auto-create `CAA` DNS records (e.g. for Let's Encrypt via `le_caa_autocreate_options`). Contract: `GET/POST /system/config/dns-cas`, `GET/PUT/DELETE /system/config/dns-cas/{id}`.

**Why this priority**: Blocked by a contract/legacy contradiction that must be clarified before implementation (see below); functionally niche.

**Independent Test**: (once the schema question is resolved) POST a CA, list it, update `active`, delete it; verify DB effect and (if decided) datalog entries.

**Acceptance Scenarios**:

1. **Given** the CA list, **When** `GET /system/config/dns-cas`, **Then** 200 paginated `{data, pagination}`.
2. **Given** a CA with duplicate `ca_issue`, **When** POST, **Then** rejected (table has `UNIQUE KEY (ca_issue)`).
3. [NEEDS CLARIFICATION — blocking]: `DnsCaConfig.yaml` describes `{id, name, private_key, certificate, intermediates, active:boolean}` with `multipart/form-data` upload, but the legacy table `dns_ssl_ca` is `{id, sys_*, active enum('N','Y'), ca_name, ca_issue, ca_wildcard enum('Y','N'), ca_iodef text, ca_critical tinyint}` — CAA policy data, not certificate material. The schema appears to have been authored for a different concept and must be rewritten against `dns_ssl_ca` (or the feature re-scoped) before any implementation.
4. [NEEDS CLARIFICATION]: legacy writes to `dns_ssl_ca` are **direct SQL, not datalogged** (`plugin_system_config_dns_ca::onUpdate` uses `$app->db->query(UPDATE/INSERT…)`; the list plugin `DELETE`s directly; the legacy INSERT statement is even syntactically broken — `INSERT INTO (…` with no table name — i.e. CA *creation* is broken in legacy 3.2). The table is interface-only (consumed by interface plugins to generate `dns_rr` CAA records); no server daemon processes it. Does the constitution's datalog-only rule apply, or is `dns_ssl_ca` legitimately a direct-write (or `BaseModel`-datalogged-anyway) exception? Recommendation: still route through `BaseModel` for auditability — harmless, since no daemon consumes the entries.

---

### Edge Cases

- Missing/invalid `X-API-Key` → 401 on every endpoint (`api.auth` middleware), matching the YAMLs' 401 responses.
- `sys_ini` blob empty or row missing (fresh/corrupt install): GET must return the section objects with legacy tform defaults rather than 500; PUT must create/normalize the blob. [NEEDS CLARIFICATION: legacy assumes row 1 always exists (installer seeds it) — proposed: 500 with clear message if the singleton row is absent]
- Concurrent PUTs to two different config sections read-modify-write the same blob → lost update risk. Mitigation: perform read-merge-write inside a DB transaction with the `sys_ini` row locked (`SELECT ... FOR UPDATE`).
- Config keys are stored as strings in the INI blob; integer-typed schema fields (`default_webserver`, `session_timeout`, `min_password_length`, `customer_no_*`) must be cast on read and serialized back as plain strings.
- The INI format cannot represent newlines in values, and legacy applies `STRIPTAGS`/`STRIPNL` filters on most text fields — multi-line input must be rejected or stripped identically.
- `y`/`n` flags throughout (`sys_ini` sections, `directive_snippets.active`), but `dns_ssl_ca.active` is **uppercase** `'N','Y'` — the shared `YesNoBoolean` cast does not fit unmodified.
- Resync with a `server_id` of a server that exists but has no matching records: legacy reports "no results"; API returns 204 regardless (nothing to queue is a success).
- `resync.yaml` POST declares 204 "operations have been queued" — the datalog inserts are synchronous; "queued" refers to ISPConfig's asynchronous processing (Constitution II semantics).

## API Contract *(mandatory)*

- **Spec files**: `api/modules/system/{system-config,sites-config,mail-config,dns-config,domains-config,misc-config,dns-cas-config,directive-snippets,resync}.yaml` — existing; implement as-is *except* the flagged inconsistencies below, which need spec fixes first (spec-first: fix YAML, then code).
- **Shared schemas**: `api/components/schemas/{SystemConfig,SystemSitesConfig,SystemMailConfig,SystemDnsConfig,SystemDomainsConfig,SystemMiscConfig,DirectiveSnippet,DnsCaConfig,ResyncRequest,Server,Pagination}.yaml` — existing.
- **Endpoints** (24 operations, 10 paths):

| Method | Path | Kind | Purpose | Success | Errors declared |
|--------|------|------|---------|---------|-----------------|
| GET | `/api/v1/system/config` | config resource (GET/PUT-only) | Whole config, all sections | 200 | 401, 500 |
| PUT | `/api/v1/system/config` | config resource | Update any/all sections | 200 (echoes `SystemConfig`) | 400, 401, 403, 500 |
| GET | `/api/v1/system/config/sites` | config resource | Sites section | 200 | 401, 500 |
| PUT | `/api/v1/system/config/sites` | config resource | Update sites section | 200 (echoes section) | 400, 401, 403, 500 |
| GET | `/api/v1/system/config/mail` | config resource | Mail section | 200 | 401, 500 |
| PUT | `/api/v1/system/config/mail` | config resource | Update mail section | 200 (**`{success, message}` — inconsistent, see below**) | 400, 401, 403, 500 |
| GET | `/api/v1/system/config/dns` | config resource | DNS section | 200 | 401, 500 |
| PUT | `/api/v1/system/config/dns` | config resource | Update DNS section | 200 (echoes section) | 400, 401, 403, 500 |
| GET | `/api/v1/system/config/domains` | config resource | Domains section | 200 | 401, 500 |
| PUT | `/api/v1/system/config/domains` | config resource | Update domains section | 200 (echoes section) | 400, 401, 403, 500 |
| GET | `/api/v1/system/config/misc` | config resource | Misc section | 200 | 401, 500 |
| PUT | `/api/v1/system/config/misc` | config resource | Update misc section | 200 (echoes section) | 400, 401, 403, 500 |
| GET | `/api/v1/system/config/dns-cas` | full CRUD | List DNS CAs (`{data, pagination}`, `offset/limit/order/sort`) | 200 | 401, 500 |
| POST | `/api/v1/system/config/dns-cas` | full CRUD | Create DNS CA (**declares `multipart/form-data`**) | 201 | 400, 401, 403, 500 |
| GET | `/api/v1/system/config/dns-cas/{id}` | full CRUD | Show DNS CA | 200 | 401, 404, 500 |
| PUT | `/api/v1/system/config/dns-cas/{id}` | full CRUD | Update DNS CA (JSON) | 200 | 400, 401, 403, 404, 500 |
| DELETE | `/api/v1/system/config/dns-cas/{id}` | full CRUD | Delete DNS CA | 204 | 401, 403, 404, 500 |
| GET | `/api/v1/system/directive-snippets` | full CRUD | List snippets (`{data, pagination}`; filters `type`, `active`) | 200 | 401, 500 |
| POST | `/api/v1/system/directive-snippets` | full CRUD | Create snippet | 201 | 400, 401, **409**, 500 |
| GET | `/api/v1/system/directive-snippets/{id}` | full CRUD | Show snippet | 200 | 401, 404, 500 |
| PUT | `/api/v1/system/directive-snippets/{id}` | full CRUD | Update snippet | 200 | 400, 401, 404, 500 |
| DELETE | `/api/v1/system/directive-snippets/{id}` | full CRUD | Delete snippet | 204 | 400, 401, 403, 404, 500 |
| POST | `/api/v1/system/resync` | **action** | Queue resync datalog re-emission | 204 (no body) | 400, 401, 500 |
| GET | `/api/v1/system/resync/servers` | action support (read-only list) | Servers eligible for resync (filters `server_type`, `active`) | 200 | 401, 500 |

**Contract defects found (fix YAML first, then implement)**:

1. **Undefined security scheme**: the config and resync YAMLs declare `security: [basicAuth]`, but `api/openapi.yaml` defines only `apiKeyAuth` (X-API-Key) and applies it globally. `basicAuth` is a dangling reference; Swagger validation of these operations is broken. [NEEDS CLARIFICATION: drop the per-operation `security:` blocks (inherit global apiKeyAuth) — recommended — or introduce basicAuth for real?]
2. **Unregistered paths**: `api/openapi.yaml` registers neither `/system/config/dns-cas/{id}` nor `/system/resync/servers` under `paths:` — those operations are invisible in Swagger UI despite existing in the module files.
3. **Inconsistent PUT response**: `mail-config.yaml` PUT 200 returns `{success, message}` while its four sibling panels echo the section schema. [NEEDS CLARIFICATION: align mail to echo `SystemMailConfig` — recommended]
4. **`DnsCaConfig.yaml` contradicts `dns_ssl_ca`** (see User Story 4) — including its `multipart/form-data` request body, which no other module uses and which conflicts with `PutPatchInputMiddleware` conventions.
5. **Schema/legacy field-name drift in `SystemMailConfig.yaml`**: spec declares `mailbox_show_quota_tab`, `mailbox_show_forwarding_tab`, `mailbox_show_filter_tab`; the legacy `[mail]` section has none of these — its fields are `mailbox_show_autoresponder_tab`, `mailbox_show_mail_filter_tab`, `mailbox_show_custom_rules_tab`, `mailbox_show_last_access`. Unknown keys written into the blob would be dead data; legacy keys are silently unreachable. [NEEDS CLARIFICATION: correct the schema to the legacy key set?]
6. **Schemas are deliberate(?) subsets**: `SystemSitesConfig.yaml` omits legacy `[sites]` keys `webdavuser_prefix`, `dblist_phpmyadmin_link`, `phpmyadmin_url`, `webftp_url`, `client_protection`, `vhost_subdomains`, `vhost_aliasdomains`, `client_username_web_check_disabled`, `backups_include_into_web_quota`, `reseller_can_use_options`, `show_aps_menu`; `SystemMailConfig.yaml` omits the SMTP block (`admin_mail`, `admin_name`, `smtp_enabled/host/port/user/pass/crypt`) and `mailbox_show_*` per item 5; `SystemConfig.yaml` omits `sys_ini.default_logo`/`custom_logo`. [NEEDS CLARIFICATION: confirm subset is intended; regardless, PUT must preserve unexposed keys]
7. **`ResyncRequest.yaml` omits `resync_mailget`/`mailget_server_id`**, which legacy supports and which legacy `resync_all=1` enables. Its `x-db-field` annotations are also fictitious (no table backs this schema). [NEEDS CLARIFICATION: add mailget or document as out of scope]

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference**:
  - `source_code/interface/web/admin/form/system_config.tform.php` — tabs `sites`/`mail`/`dns`/`domains`/`misc` (+ plugin-only `dns_ca` tab); `db_table=sys_ini`, `db_table_idx=sysini_id`, **`db_history=yes`**; all field validators/defaults.
  - `source_code/interface/web/admin/system_config_edit.php` — read via `getconf->get_global_config($section)`, write via `ini_parser->get_ini_string()` + `datalogUpdate('sys_ini', {config}, 'sysini_id', 1)`; side effects listed below.
  - `source_code/interface/lib/classes/ini_parser.inc.php` — blob format: `[section]\nkey=value\n` lines; values trimmed; no quoting/escaping.
  - `source_code/interface/web/admin/form/directive_snippets.tform.php` (+ `directive_snippets_edit.php`, `directive_snippets_del.php`, `lib/classes/validate_server_directive_snippets.inc.php`) — **`db_history=yes`**.
  - `source_code/interface/lib/classes/plugin_system_config_dns_ca.inc.php` + `plugin_system_config_dns_ca_list.inc.php` + `lib/plugins/system_config_dns_ca_plugin.inc.php` — DNS CA tab (direct SQL, no datalog; consumed to auto-create CAA `dns_rr` records).
  - `source_code/interface/web/tools/resync.php` + `tools/form/resync.tform.php` (**`db_history=no`** — the tool itself stores nothing).
- **Legacy behaviors to mirror** (validators/defaults):

| Area | Field | Legacy rule |
|------|-------|-------------|
| sites | `dbname_prefix`, `dbuser_prefix`, `ftpuser_prefix`, `shelluser_prefix` | regex `/^[a-zA-Z0-9\-\_\[\]]{0,50}$/` |
| sites | `web_php_options` | NOTEMPTY; comma-separated subset of `no,fast-cgi,cgi,mod,suphp,php-fpm,hhvm` |
| sites | `default_remote_dbserver` | `validate_database::valid_ip_list` (comma-separated IPs) |
| sites | `ssh_authentication` | one of `''`, `password`, `key` |
| sites | checkboxes | default `n` except `client_protection=y` (unexposed), `le_caa_autocreate_options=y` |
| mail | `webmail_url` | regex `/^[0-9a-zA-Z\:\/\-\.\[\]]{0,255}$/` |
| mail | `mailmailinglist_url` | regex `/^[0-9a-zA-Z\:\/\-\.]{0,255}$/` |
| mail | text fields | STRIPTAGS+STRIPNL on save; `admin_mail`/`smtp_host` additionally IDN-to-ASCII + lowercase (unexposed fields) |
| dns | all four fields | server-ID selects + STRIPTAGS/STRIPNL on `dns_external_slave_fqdn`; `dns_show_zoneexport` default `n` |
| domains | `new_domain_html` | STRIPTAGS on save; `use_domain_module` default `n` |
| misc | `custom_login_link` | regex `/^(http|https):\/\/.*|^$/` |
| misc | `maintenance_mode_exclude_ips` | ISIP per comma-separated item, empty allowed |
| misc | `customer_no_template` | regex `/^[a-zA-Z0-9\-\_\[\]]{0,50}$/` |
| misc | `min_password_length` | INTEGER, legacy default `5`; `min_password_strength` in `'',1..5` |
| snippets | `name` | NOTEMPTY; STRIPTAGS+STRIPNL; unique per (`name`,`type`) |
| snippets | `type` | must be in `apache,nginx,php,proxy` |
| snippets | `required_php_snippets` | comma-separated IDs of active `php`-type snippets |

- **Legacy side effects on config update** (all executed by the interface in `onUpdateSave`, mostly **direct SQL bypassing datalog**):

| Trigger | Legacy effect | Datalogged? | API relevance |
|---------|--------------|-------------|---------------|
| `[sites] client_protection` toggled | bulk `UPDATE web_domain` ownership/perms | **No** | Field not in API schema → out of scope unless schemas expanded |
| `[sites] vhost_subdomains`/`vhost_aliasdomains` y→n while such domains exist | silently forced back to `y` | n/a | Fields not in API schema → out of scope |
| `[mail] smtp_pass` submitted empty | previous password kept | n/a | Field not in API schema → out of scope |
| `[mail] smtp_enabled=y` without `admin_mail`/`admin_name` | validation error | n/a | Fields not in API schema → out of scope |
| `[misc] session_timeout` changed | writes `sys_config` interface config (`$app->conf`) | **No** | [NEEDS CLARIFICATION: replicate `sys_config` write, or blob-only?] |
| `[misc] maintenance_mode=y` | `DELETE FROM sys_session` except own session | **No** | Interface sessions don't exist for API consumers. [NEEDS CLARIFICATION: replicate kill-sessions (locks out UI users) or document deviation?] |
| `[domains] use_domain_module` first turned on | `REPLACE INTO domain` seeded from `mail_domain`, `web_domain` (non-sub/vhostsub), `dns_soa` | **No** (direct REPLACE) | Field IS in API schema → side effect in scope. [NEEDS CLARIFICATION: legacy seeding bypasses datalog for the `domain` table — mirror exactly (direct write, constitution violation) or datalog the inserts (behavior deviation)?] |
| checkbox absent from submission | treated as unchecked (`value[0]`) | n/a | JSON PUT semantics differ: absent key = "leave unchanged", not "uncheck" — intentional REST deviation, documented |

- **Tables written (via datalog only)**:

| Table | Actions | Trigger |
|-------|---------|---------|
| `sys_ini` | `u` only (singleton row 1, `{config}` payload) | any config PUT |
| `directive_snippets` | `i`/`u`/`d` | snippets CRUD |
| `web_domain` | forced `u` (full record, no diff) | snippet PUT with `update_sites=y`; resync sites |
| `ftp_user`, `webdav_user`, `shell_user`, `cron`, `web_database_user`+`web_database`, `mail_domain`+`spamfilter_policy`, `mail_user`+`mail_forwarding`, `mail_access`+`mail_content_filter`+`mail_user_filter`+`spamfilter_users`+`spamfilter_wblist`, `mail_mailinglist`, `mail_transport`, `mail_relay_recipient`, `openvz_vm`, `client` | forced `u` (full record) | resync flags (legacy per-table active-filter: active-only for `web_domain`, `ftp_user`, `webdav_user`, `shell_user`, `cron`, `web_database`, `mail_domain`, `mail_forwarding`, `mail_access`, `mail_content_filter`, `spamfilter_wblist`, `openvz_vm`; ALL rows for `web_database_user`, `spamfilter_policy`, `mail_user`, `mail_user_filter`, `spamfilter_users`, `mail_mailinglist`, `mail_transport`, `mail_relay_recipient`, `client`) |
| `dns_rr`, `dns_soa` | `u` with `{serial}` only, serial increased | resync dns (rr per zone first, then soa) |
| `dns_ssl_ca` | legacy: **not datalogged** (direct SQL; insert broken) | DNS CA CRUD [NEEDS CLARIFICATION per User Story 4] |

- **Forced-update caveat (constitutional)**: legacy resync and `update_sites` use `datalogUpdate(..., force=true)`, emitting an entry with an unchanged record. `App\Models\BaseModel::save()` deliberately suppresses no-change datalogs ("No changes to log"), so these flows **cannot** go through `BaseModel::save()`; they must call `App\Services\DatalogService::log()` directly with a full-record `u` payload. That is still "writes only via sys_datalog", but it bypasses the "never roll custom save/delete logic" letter of Principle II. [NEEDS CLARIFICATION: bless direct `DatalogService::log()` for forced re-emission in a service (recommended: add a `ResyncService`), or extend `BaseModel` with a `forceDatalog()` method?]
- **System fields handling**: `sys_ini` has no `sys_*`/`server_id` columns — datalog entries carry `server_id=0`, `sys_userid=1`. `directive_snippets` creations default `sys_userid=1`, `sys_groupid=1` (admin), `sys_perm_user='riud'`, `sys_perm_group='riud'`, `sys_perm_other=''` per legacy `auth_preset`; no `server_id` column (snippets are global). Resync datalog entries carry each re-emitted record's own `server_id`/`sys_userid`.
- **Permission parity**: legacy gates system config behind admin user type + `admin_allow_system_config` security setting, and snippets/resync behind the admin module. The API has a single flat `X-API-Key` (admin-equivalent) — matching every module shipped so far.
- **Intentional deviations from legacy**: JSON absent-key = unchanged (vs. checkbox-absent = unchecked); resync responds 204 instead of legacy's HTML progress report; API cannot raise interface plugin events (`client:client:on_after_update`); maintenance-mode session purge probably not replicated (pending clarification).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST expose the `sys_ini` singleton (row `sysini_id=1`) as `GET /system/config` returning `sysini_id` plus the five section objects, parsed from the INI blob exactly as legacy `ini_parser::parse_ini_string` does (CRLF-tolerant, `[section]` headers, `key=value`, trimmed).
- **FR-002**: System MUST expose each section at `GET /system/config/{sites|mail|dns|domains|misc}` per the corresponding schema, filling absent keys with legacy tform defaults.
- **FR-003**: `PUT /system/config` and `PUT /system/config/{section}` MUST merge submitted keys into the existing blob (read → merge → re-serialize via the `get_ini_string` format), preserving all unsubmitted and un-schema'd keys and all other sections, inside a transaction with the `sys_ini` row locked.
- **FR-004**: Every config write MUST be persisted exclusively through a `sys_datalog` update entry for `sys_ini` (`action=u`, payload containing the new `config` string), mirroring legacy `datalogUpdate('sys_ini', …, 'sysini_id', 1)`.
- **FR-005**: Config PUTs MUST validate each submitted field with the legacy validator table above (regexes, IP lists, enums, integer casts) and return 422 `{message, errors}` on failure without writing anything.
- **FR-006**: System MUST provide directive-snippets CRUD per `directive-snippets.yaml`: paginated list (`{data, pagination}`, `offset/limit/order/sort`, filters `type`, `active`), show, create (201), update (200), delete (204), all writes via a `DirectiveSnippet` model extending `BaseModel`.
- **FR-007**: Directive-snippet writes MUST enforce: `name` non-empty, STRIPTAGS/STRIPNL-equivalent normalization, uniqueness of (`name`, `type`) (409 on create per YAML), `type` ∈ {apache, nginx, php, proxy}, `customer_viewable`/`active`/`update_sites` ∈ {y, n}, `required_php_snippets` containing only IDs of active `php` snippets.
- **FR-008**: System MUST reject DELETE of an in-use snippet (apache/nginx referenced by `web_domain.directive_snippets_id`; php required — directly or transitively via `required_php_snippets` — by an in-use snippet) with 400, and reject PUTs that deactivate or un-publish (`customer_viewable` y→n) an in-use snippet.
- **FR-009**: On snippet update with `update_sites=y` and `active=y`, system MUST re-emit a forced full-record `sys_datalog` update for every affected `web_domain` (legacy affected-site resolution: by `directive_snippets_id` for apache/nginx; via `required_php_snippets` REGEXP for php).
- **FR-010**: `POST /system/resync` MUST accept `ResyncRequest`, expand `resync_all=1` to all service flags with `all_server_id` propagated, and for each enabled service insert forced full-record `u` datalog entries for every matching record, honoring the legacy per-table active-only/all-rows filter and legacy emission order.
- **FR-011**: DNS resync MUST NOT re-emit full records; it MUST increase each active `dns_rr` serial (datalog `{serial}` update) per matching active zone and then increase and datalog the zone's `dns_soa.serial`, reusing `App\Services\DnsSerialService`.
- **FR-012**: Resync MUST resolve `*_server_id = 0` to all servers of the relevant type with `active = 1 AND mirror_server_id = 0`, and reject unknown server IDs with 400.
- **FR-013**: `POST /system/resync` MUST return 204 with no body after all datalog entries are written (transactional: all-or-nothing per request).
- **FR-014**: `GET /system/resync/servers` MUST list servers (paginated `{data, pagination}`) filterable by `server_type` (web|mail|dns|db|file|vserver → `server.{type}_server = 1`) and `active`, defaulting to the legacy candidate rule (`active=1`, `mirror_server_id=0`).
- **FR-015**: System MUST provide DNS CA CRUD at `/system/config/dns-cas[/{id}]` — **blocked** by [NEEDS CLARIFICATION: rewrite `DnsCaConfig.yaml` against `dns_ssl_ca` (`ca_name`, `ca_issue` unique, `ca_wildcard` Y/N, `ca_iodef`, `ca_critical`, `active` Y/N) and drop multipart, or re-scope]. Once resolved, writes SHOULD go through a `BaseModel` subclass even though legacy does not datalog this table (see Parity).
- **FR-016**: All endpoints MUST require `X-API-Key` (`api.auth` middleware) and return 401 without it; the YAMLs' dangling `basicAuth` declarations MUST be fixed in the spec, not implemented.
- **FR-017**: Errors MUST follow `{message, error}` (or `{message, errors}` for 422 validation), status codes per the endpoint table; unexpected failures 500 with contextual logging and transaction rollback.
- **FR-018**: The spec registration gaps MUST be fixed before implementation: add `/system/config/dns-cas/{id}` and `/system/resync/servers` to `api/openapi.yaml` `paths:`, align `mail-config.yaml` PUT 200 body, and resolve `SystemMailConfig.yaml` field-name drift (contract defects 1–5, 7).
- **FR-019**: System MUST handle the `use_domain_module` enable side effect per clarification outcome (seed `domain` table on first n→y transition) — [NEEDS CLARIFICATION: mirror legacy direct `REPLACE INTO` (violates Principle II) vs. datalogged inserts vs. omit].
- **FR-020**: Integer-typed config fields MUST serialize back into the blob as plain `key=value` strings; `y`/`n` enum fields MUST be stored exactly as `y`/`n`.

### Key Entities

- **SystemConfig**: the global settings singleton — table `sys_ini` (columns `sysini_id`, `config` longtext INI blob, `default_logo`, `custom_logo`; **no sys_* fields, no server_id**), schema `api/components/schemas/SystemConfig.yaml` (+ 5 section schemas), future model `app/Models/SysIni.php` + parser/merger service `app/Services/SystemConfigService.php`. Not a collection — one row, update-only.
- **DirectiveSnippet**: reusable web-server config snippet — table `directive_snippets` (pk `directive_snippets_id`; `master_directive_snippets_id` intentionally unexposed), schema `api/components/schemas/DirectiveSnippet.yaml`, future model `app/Models/DirectiveSnippet.php`. Referenced by `web_domain.directive_snippets_id`; php snippets composable via `required_php_snippets` (CSV of IDs).
- **DnsCa**: CAA-record certification authority — table `dns_ssl_ca` (pk `id`, unique `ca_issue`, uppercase `Y`/`N` flags), schema `api/components/schemas/DnsCaConfig.yaml` (**currently wrong — see FR-015**), future model `app/Models/DnsSslCa.php`. Consumed by interface plugins to auto-create CAA `dns_rr` records.
- **ResyncRequest**: action DTO, no table (its `x-db-field` annotations are vestigial) — schema `api/components/schemas/ResyncRequest.yaml`, handled by future `app/Services/ResyncService.php`.
- **Server**: read-only listing source for `/system/resync/servers` — table `server`, schema `api/components/schemas/Server.yaml`, future model `app/Models/Server.php` (read-only; note `app/Models/MailDomain.php` already references a nonexistent `Server::class` in its `server()` relation, which this model incidentally fixes).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: All 24 operations across the 10 paths respond as declared in `api/modules/system/*.yaml` (after the contract-defect fixes), verified via Swagger UI "Try it out" for every operation.
- **SC-002**: A config PUT round-trip (`GET` → change one key → `PUT` → `GET`) alters exactly that key in the stored blob; a byte-level diff of `sys_ini.config` before/after shows no other key or section touched, and exactly one new `sys_datalog` row (`dbtable=sys_ini`, `action=u`) exists.
- **SC-003**: The stored blob after any API write is parseable by legacy `ini_parser::parse_ini_string` and renders correctly in the legacy System → Main Config UI (no data loss for unexposed legacy fields).
- **SC-004**: Directive-snippet create/update/delete produce well-formed `sys_datalog` `i`/`u`/`d` entries that a stock ISPConfig server processes without error; all documented in-use guards and the (name, type) uniqueness rule behave identically to the legacy validation cases.
- **SC-005**: `POST /system/resync {"resync_all":1, "all_server_id":0}` on a seeded database produces datalog entry counts per table equal to the legacy tool's output for the same dataset, and every DNS zone's serial strictly increases.
- **SC-006**: Zero direct writes to ISPConfig tables in the diff (grep-verifiable: no `DB::table(...)->update/insert/delete` against ISPConfig tables outside `DatalogService`), except any explicitly clarified exceptions (FR-015, FR-019).
- **SC-007**: All NEEDS CLARIFICATION items in this spec are resolved (spec updated) before their dependent tasks start; none remain at feature completion.

## Assumptions

- Only the endpoints already specced under `api/modules/system/` are in scope; legacy-only surfaces (logo upload, language editor, remote users, interface `sys_config`) are out of scope.
- The existing `X-API-Key` middleware (`api.auth`) is the sole auth mechanism and is treated as admin-equivalent (legacy admin + `admin_allow_system_config` gate collapses onto key possession); the `basicAuth` mentions in the YAML are treated as a spec defect (FR-016).
- A populated `dbispconfig` database is available with the installer-seeded `sys_ini` row 1 present.
- Legacy behavior verified against the `source_code/` ISPConfig 3.2.x tree currently vendored; parity claims trace to the files listed in the Parity section.
- The `{data, pagination}` list envelope (per `api/components/schemas/Pagination.yaml`) is used for the two list endpoints, matching every existing module spec — noting it deviates from the constitution's Principle V wording (`{items,total,limit,offset}`); Principle I (spec is source of truth) wins, as it does in all shipped controllers.
- `update_sites` create-default follows the DB default `n` (the YAML example `y` reflects the legacy edit-form pre-check, which only matters on update).
- Resync writes all datalog rows synchronously in the request; for very large installations this may be slow — accepted for the first iteration (no job queue exists in this project).
- `BaseModel::save()` — like legacy `datalogUpdate()` — performs the actual table write *and* journals to `sys_datalog`, so config changes take effect for the interface immediately; the `sys_ini` datalog entries serve mirroring/audit (no server daemon plugin reacts to `sys_ini`).
