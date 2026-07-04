# Feature Specification: Sites Module

**Feature Branch**: `006-sites-module`
**Created**: 2026-07-04
**Status**: Draft (reverse-engineered from contract + legacy source; not yet implemented)
**Module**: sites
**Input**: Reverse-engineered from the existing OpenAPI contract under `api/modules/sites/` (10 path files, 10 schemas under `api/components/schemas/`) and the legacy ISPConfig implementation under `source_code/interface/web/sites/` (form definitions, edit/del action files) plus the vendored DB schema `source_code/install/sql/ispconfig3.sql`. **No PHP for this module exists yet** — there are no `app/Models`/`app/Http/Controllers/Api/V1` files and no `routes/web.php` entries for any `sites/*` path. The reference implementation pattern to mirror is `MailDomainController` + `MailDomain` model.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Web domain lifecycle (Priority: P1)

An API consumer (hosting automation, billing panel, migration tool) creates, lists, inspects, updates, and deletes web domains (ISPConfig vhosts) via `/api/v1/sites/web-domains`. A web domain is the central entity of the sites module — every other resource in this module (FTP/shell users, databases, cron jobs, folders, WebDAV users, child domains) hangs off a `parent_domain_id` that points at a vhost. On create, the system must generate the derived provisioning fields exactly like legacy ISPConfig (document_root from the server's `website_path` template, `system_user` = `web{domain_id}`, `system_group` = `client{client_id}`, `allow_override`/`php_open_basedir` from server web config) so the ISPConfig daemons can provision the site from the datalog entry.

**Why this priority**: Without web domains nothing else in the module can be created — every other resource validates its `parent_domain_id` against `web_domain`.

**Independent Test**: `POST /api/v1/sites/web-domains` with `X-API-Key` and a valid vhost body; assert HTTP 201, a `web_domain` row with generated `document_root`/`system_user`/`system_group`, and a `sys_datalog` row `dbtable=web_domain`, `action=i` whose payload contains the final (derived) values. Then GET/PUT/DELETE the same record.

**Acceptance Scenarios**:

1. **Given** a valid `server_id` (a web server), a unique lowercase `domain`, and `type=vhost`, **When** POSTing to `/api/v1/sites/web-domains`, **Then** the API returns 201, the record has server-generated `system_user`/`system_group`/`document_root`, and a datalog `i` entry exists.
2. **Given** a domain with mixed case or an IDN, **When** POSTing, **Then** the domain is lower-cased (and IDN-converted to ASCII, as legacy `TOLOWER`+`IDNTOASCII` filters do) before validation and storage.
3. **Given** `hd_quota=0` for a `type=vhost` domain, **When** POSTing, **Then** the API returns 422 (legacy forbids 0 quota on vhosts; only `-1` or a positive integer matching `/^(\-1|[0-9]{1,10})$/` is allowed).
4. **Given** `ssl_letsencrypt=y` and `ssl=y` on create, **When** POSTing, **Then** the record is first datalogged as inserted with `ssl=n`/`ssl_letsencrypt=n` and immediately followed by a datalog `u` entry enabling both — mirroring legacy `_letsencrypt_on_insert` two-step behavior (LE cannot be activated before the site exists).
5. **Given** an existing vhost, **When** PUTting a partial body, **Then** the API returns 200 and immutable fields (`server_id`, and for non-admin contexts `system_user`/`system_group`) are not changed even if supplied.
6. **Given** an existing vhost with FTP users, cron jobs, and child domains, **When** DELETEing it, **Then** [NEEDS CLARIFICATION: the contract's DELETE 400 description says deletion must be *refused* when child domains / databases / FTP users exist, but legacy ISPConfig (`web_vhost_domain_del.php`) *cascades*: it datalog-deletes child domains, ftp_user, shell_user, cron, webdav_user, web_backup, web_folder(+users) rows and datalog-updates `web_database.parent_domain_id` to 0. One behavior must be chosen before implementation.]
7. **Given** a nonexistent ID, **When** GET/PUT/DELETE `/sites/web-domains/{id}`, **Then** 404 with `{message, error}` shape.

---

### User Story 2 - FTP and shell access provisioning (Priority: P2)

An API consumer provisions file access for a website: FTP users (`/sites/ftp-users`) and SSH/shell users (`/sites/shell-users`). Usernames are automatically prefixed per the ISPConfig sites config (`ftpuser_prefix` / `shelluser_prefix`, e.g. `web[website_id]_` or `[client_id]_`), and home directory, uid/gid, `server_id` and `sys_groupid` are derived from the parent web domain — the consumer cannot pick them.

**Why this priority**: Right after a site exists, uploading content (FTP/SSH) is the first operational need.

**Independent Test**: Create a vhost, then `POST /api/v1/sites/ftp-users` with `{parent_domain_id, username, password, quota_size}`; assert 201, stored `username` = prefix + submitted name, `dir` = the web domain's `document_root`, `uid`/`gid` = the domain's `system_user`/`system_group`, `server_id`/`sys_groupid` copied from the domain, and a datalog `i` on `ftp_user`. Repeat analogously for `shell-users`.

**Acceptance Scenarios**:

1. **Given** a valid parent vhost, **When** POSTing an FTP user, **Then** 201 and the response's `username` is the full prefixed name; `dir`, `uid`, `gid`, `server_id` are server-derived (read-only in the contract).
2. **Given** a `username` failing `/^[\w\.\-@\+]{0,64}$/` (FTP) or `/^[\w\.\-]{0,32}$/` (shell), **When** POSTing, **Then** 422.
3. **Given** a shell username whose prefixed form exceeds 32 characters, **When** POSTing, **Then** 422 (legacy: "username must not exceed 32 chars").
4. **Given** a shell username on the legacy blacklist (`interface/lib/shelluser_blacklist`, e.g. `root`) or failing `is_allowed_user` checks, **When** POSTing, **Then** 422.
5. **Given** a duplicate full username (FTP or shell — legacy UNIQUE validator on the table), **When** POSTing, **Then** 422.
6. **Given** an update that changes `parent_domain_id`, **Then** `server_id`, `dir`, `uid`/`gid` (FTP) or `puser`/`pgroup` (shell) and `sys_groupid` are re-derived from the new parent domain (legacy `onAfterUpdate`).
7. **Given** a `parent_domain_id` that does not exist, **When** POSTing, **Then** 400/422 per contract (`no_domain_perm` in legacy).
8. **Given** an FTP/shell `dir` containing `..` or `./`, **When** writing, **Then** 422 (legacy path-traversal check).

---

### User Story 3 - Database provisioning (Priority: P3)

An API consumer creates database users (`/sites/database-users`) and databases (`/sites/databases`) for a site. Database and user names are automatically prefixed (`dbname_prefix` / `dbuser_prefix`, default `c{client_id}` style patterns), passwords are stored in the hash formats ISPConfig's daemons expect, and creating/updating a database force-touches the linked `web_database_user` rows in the datalog so the daemons (re)create the DB grants.

**Why this priority**: Databases are the next most common provisioning step after file access; databases depend on database users, so both ship together.

**Independent Test**: `POST /api/v1/sites/database-users` with `{database_user, database_password}`; assert 201, prefixed `database_user`, `server_id=0`, and hashed password columns (`database_password` MySQL-PASSWORD format, `database_password_sha2`, `database_password_postgres`) populated. Then `POST /api/v1/sites/databases` linking that user; assert 201, prefixed `database_name`, and an extra datalog `u` on `web_database_user` syncing its `server_id`.

**Acceptance Scenarios**:

1. **Given** a valid `database_name` matching `/^[a-zA-Z0-9_]{2,64}$/`, **When** POSTing a database, **Then** 201 and the stored name is `prefix + database_name`, cropped to 64 chars.
2. **Given** prefix+name in the blacklist (`dbispconfig` — the API's own DB name — or `mysql`), **When** POSTing, **Then** 422. Same for database users against (`root`, `mysql`, the API's DB user).
3. **Given** a duplicate `database_name` on the same `server_id`, **When** POSTing, **Then** 422 (legacy duplicate check is per server).
4. **Given** `parent_domain_id=0` or missing, **When** POSTing a database, **Then** [NEEDS CLARIFICATION: contract `Database.yaml` documents `parent_domain_id` default 0 = "standalone", but legacy `database_edit.php` errors with `database_site_error_empty` when `parent_domain_id == 0` — site is mandatory in legacy].
5. **Given** the parent web domain lives on a different server than the database, **When** writing, **Then** the web server's IP (plus configured `default_remote_dbserver` IPs and mirror IPs) is auto-appended to `remote_ips` and `remote_access` is forced to `y` (legacy behavior).
6. **Given** an update attempting to change `database_name` (non-admin), `database_charset`, or `server_id`, **Then** 422 — all three are immutable on update in legacy.
7. **Given** an update without `database_user_id`, **Then** 422 (legacy `database_user_missing_txt`); the linked user's and read-only user's `sys_groupid` must match the parent domain's `sys_groupid`.
8. **Given** `type=postgresql`, **Then** the chosen user (and ro-user) must not already be used by another PostgreSQL database on that server (legacy uniqueness constraint for PG users).
9. **Given** a database user create, **Then** `server_id` is forced to `0` (legacy: "we need this on all servers") and the plaintext password is written into `database_password` (MySQL `PASSWORD()`-style hash), `database_password_sha2` (SHA2), and `database_password_postgres` (SHA-256) columns.
10. **Given** a database-user username whose prefixed form exceeds 32 characters, **Then** 422 (legacy crops at 32 and errors with `database_user_error_len`).

---

### User Story 4 - Child domains: subdomains and alias domains (Priority: P4)

An API consumer adds subdomains and alias domains to an existing site via `/sites/web-child-domains`. These are stored in the same `web_domain` table with `type=subdomain` or `type=alias`; for subdomains, the full domain is composed server-side as `{domain}.{parent_domain}`.

**Why this priority**: Common day-2 operation, but strictly dependent on an existing vhost.

**Independent Test**: `POST /api/v1/sites/web-child-domains` with `{parent_domain_id, domain: "blog", type: "subdomain"}`; assert 201, stored `domain` = `blog.<parent domain>`, `type=subdomain`, `server_id` and `sys_groupid` copied from the parent, and a datalog `i` on `web_domain`.

**Acceptance Scenarios**:

1. **Given** `type=subdomain` and a bare label, **When** POSTing, **Then** the stored domain is `label.parentdomain` (lower-cased); **Given** `type=alias`, the submitted `domain` is used as-is (full domain).
2. **Given** `redirect_type=proxy` and a `redirect_path` starting with `/`, **When** writing, **Then** 422 (legacy: proxy requires a URL).
3. **Given** `redirect_path` not matching the legacy redirect regex (URL or absolute path ending in `/`), **Then** 422.
4. **Given** an update that changes `parent_domain_id`, **Then** `sys_groupid` is re-pointed at the new parent's group and a no-op datalog `u` is written for the *old* parent `web_domain` row so its vhost config is regenerated (legacy `onAfterUpdate`).
5. **Given** the API consumer supplies `type`, **Then** the server still forces `type` to `subdomain`/`alias` and `server_id` to the parent's server (legacy fixed values).

---

### User Story 5 - Cron jobs (Priority: P5)

An API consumer schedules recurring jobs for a site via `/sites/cron-jobs` — URL calls, full shell commands, or chrooted commands — with standard cron time fields validated exactly like legacy ISPConfig.

**Why this priority**: Useful automation but depends only on a vhost; lower operational urgency than access/databases.

**Independent Test**: `POST /api/v1/sites/cron-jobs` with `{parent_domain_id, run_min: "*/5", run_hour: "*", run_mday: "*", run_month: "*", run_wday: "*", command: "https://example.com/cron.php"}`; assert 201, `type` resolved to `url`, `server_id`/`sys_groupid` from the parent domain, datalog `i` on table `cron`.

**Acceptance Scenarios**:

1. **Given** a `command` starting with `http://` or `https://`, **Then** `type` is forced to `url` regardless of the submitted value; otherwise `type` is derived from the owning client's `limit_cron_type` (`full` or `chrooted`) — admin-owned sites get `full` (legacy `onSubmit`).
2. **Given** a URL command, **Then** it must parse (`parse_url`) with scheme http/https and a valid hostname; `{DOMAIN}` placeholders are substituted with the parent domain before validation; commands containing `\`, newlines, CR, or null bytes are rejected.
3. **Given** any `run_*` field with characters outside `0-9 , - / *`, adjacent separators (`--`, `,,`, `-/`…), or values outside the field range (min 0-59, hour 0-23, mday 1-31, month 1-12, wday 0-7), **Then** 422; `@reboot` is accepted **only** in `run_month`.
4. **Given** a client with `limit_cron_type=url`, **Then** non-url jobs are rejected; **Given** `limit_cron_frequency` > computed job frequency, **Then** 422 (legacy last-chance check in `onInsertSave`/`onUpdateSave`).
5. **Given** creation succeeds, **Then** `server_id` and `sys_groupid` are copied from the parent web domain (legacy `onAfterInsert`).

---

### User Story 6 - Protected folders and WebDAV (Priority: P6)

An API consumer protects a directory with HTTP auth (`/sites/web-folders` + `/sites/web-folder-users`) or provisions WebDAV access (`/sites/webdav-users`).

**Why this priority**: Least frequently used resources of the module; they complete parity with the legacy Sites UI.

**Independent Test**: Create a folder `{parent_domain_id, path: "/protected"}` → 201 with datalog `i` on `web_folder`; add a user `{web_folder_id, username, password}` → 201 with CRYPT-hashed password and datalog `i` on `web_folder_user`; create a WebDAV user → 201 with `username` prefixed per `webdavuser_prefix` and password stored as `md5(username:dir:password)` digest.

**Acceptance Scenarios**:

1. **Given** a folder `path` failing `/^[\w\.\-\_\/]{0,255}$/`, **Then** 422; **Given** a (parent_domain_id, path) pair already protected, **Then** 422 (legacy duplicate check).
2. **Given** a folder create/update, **Then** `server_id` comes from the parent domain and `sys_groupid` is synced to the parent domain's group after insert (and re-synced when the parent changes).
3. **Given** a web-folder-user whose (web_folder_id, username) pair already exists, **Then** 422; usernames must match `/^[\w\.\-]{0,64}$/`; `server_id`/`sys_groupid` derive from the folder.
4. **Given** a web-folder-user or web-folder update, **Then** per contract only `password`/`active` (user) and `active` (folder) are updatable. [Deviation note: legacy allows changing folder path/parent and folder-user username/folder; the contract is intentionally stricter — implement the contract.]
5. **Given** a WebDAV user create, **Then** username gets the `webdavuser_prefix`, must be unique table-wide, `dir` must not contain `..` or `./`, and the stored `password` is the digest hash `md5(username:dir:password)` (legacy stores an Apache digest-auth hash, not a crypt hash).
6. **Given** a WebDAV user update, **Then** `username` and `dir` are immutable (legacy restores old values in `onBeforeUpdate`; contract marks them read-only on update); a changed password is re-digested with the *stored* username and dir.

---

### User Story 7 - SSL certificate management for a web domain (Priority: P7)

An API consumer reads, uploads, removes, or renews the SSL certificate of a web domain via the `/sites/web-domains/{id}/ssl` subresource.

**Why this priority**: Valuable but the contract's mapping onto legacy `web_domain` columns has open questions; the core CRUD stories deliver value without it.

**Independent Test**: `POST /api/v1/sites/web-domains/{id}/ssl` with `{ssl_cert, ssl_key}`; assert 200 and a datalog `u` on `web_domain` carrying `ssl_cert`, `ssl_key`, `ssl_action='save'`. GET returns the PEM fields; DELETE returns 204 with `ssl_action='del'` datalogged.

**Acceptance Scenarios**:

1. **Given** a domain with `ssl=y` and stored cert fields, **When** GETting `/ssl`, **Then** 200 with `ssl_cert`/`ssl_key`/CA/chain fields; **Given** no certificate configured, **Then** 204.
2. **Given** an upload, **Then** the write maps to `web_domain` columns `ssl_cert`, `ssl_key`, `ssl_bundle` (contract's `ssl_ca_cert`/`ssl_cert_chain` both correspond to the single legacy `ssl_bundle` column — [NEEDS CLARIFICATION: contract exposes two CA-related fields; the table has only `ssl_bundle`]) and sets `ssl_action='save'` (legacy `ssl_action` drives the server plugin).
3. **Given** a removal, **Then** `ssl_action='del'` is datalogged and 204 returned.
4. **Given** a renew request on a domain with `ssl_letsencrypt=n`, **Then** 400. [NEEDS CLARIFICATION: legacy has no interface-triggered "renew" — LE renewal is automatic server-side. The closest parity implementation is a forced no-change datalog `u` on the domain to make the LE plugin re-run.]
5. **Given** certificate/key that do not match or malformed PEM, **Then** 400 per contract (implement with `openssl_x509_read`/`openssl_x509_check_private_key`, analogous to `MailDomain::validateDkimPrivateKey`).

---

### Edge Cases

- Missing/invalid `X-API-Key` → 401 on every endpoint (existing `api.auth` middleware).
- Referencing a nonexistent `parent_domain_id` / `web_folder_id` / `database_user_id` → 400/422 (legacy `no_domain_perm` / `no_folder_perm`).
- ISPConfig `y`/`n` enum flags everywhere (`active`, `ssl`, `cgi`, `log`, `remote_access`, …) — expose as `y`/`n` strings per the contract; the `YesNoBoolean` cast pattern used by `MailDomain` converts to booleans in JSON, which would *violate* this module's contract enums ([NEEDS CLARIFICATION: keep raw `y`/`n` (contract-faithful) or reuse `YesNoBoolean` (codebase-consistent)?]).
- Deleting a web folder must also delete its `web_folder_user` rows (legacy cascade in `web_vhost_domain_del.php`; contract notes "associated web folder users will also be deleted").
- `sys_perm_*` defaults: `riud`/`riud`/`''` (every sites tform `auth_preset`).
- Writable-but-derived fields: consumers may POST `server_id` on several resources, but the value is always overwritten from the parent domain — document and implement consistently.
- Async semantics: 201/200/204 confirm the datalog entry, not the applied change (constitution Principle II).
- Pagination shape conflict: the sites YAMLs declare list responses as `{data: [...], pagination: {...}}` (`Pagination.yaml`, Laravel-paginator style, also what `MailDomainController`/DNS controllers return) while the constitution Principle V mandates `{items,total,limit,offset}` — see FR-040.

## API Contract *(mandatory)*

- **Spec files** (existing — implement as-is): `api/modules/sites/web-domains.yaml`, `web-child-domains.yaml`, `ftp-users.yaml`, `shell-users.yaml`, `databases.yaml`, `database-users.yaml`, `cron-jobs.yaml`, `web-folders.yaml`, `web-folder-users.yaml`, `webdav-users.yaml`, indexed by `api/modules/sites/_index.yaml`; all paths already registered in `api/openapi.yaml`.
- **Shared schemas** (existing): `api/components/schemas/{WebDomain,WebChildDomain,FtpUser,ShellUser,Database,DatabaseUser,CronJob,WebFolder,WebFolderUser,WebdavUser}.yaml`; shared `Pagination.yaml`, parameters `limit/offset/sort/order`, responses `BadRequest/Unauthorized/Forbidden/NotFound/Conflict/UnprocessableEntity/InternalServerError`.
- **Endpoints** (54 operations; error responses per YAML: 400/401/403/404/409/422/500 as declared):

| # | Method | Path | Purpose | Success code |
|---|--------|------|---------|--------------|
| 1 | GET | `/api/v1/sites/web-domains` | List web domains (paginated, `search`) | 200 |
| 2 | POST | `/api/v1/sites/web-domains` | Create web domain | 201 |
| 3 | GET | `/api/v1/sites/web-domains/{id}` | Show web domain | 200 |
| 4 | PUT | `/api/v1/sites/web-domains/{id}` | Update web domain (partial) | 200 |
| 5 | DELETE | `/api/v1/sites/web-domains/{id}` | Delete web domain | 204 |
| 6 | GET | `/api/v1/sites/web-domains/{id}/ssl` | Get SSL certificate | 200 (204 if none) |
| 7 | POST | `/api/v1/sites/web-domains/{id}/ssl` | Upload SSL certificate | 200 |
| 8 | DELETE | `/api/v1/sites/web-domains/{id}/ssl` | Remove SSL certificate | 204 |
| 9 | POST | `/api/v1/sites/web-domains/{id}/ssl/renew` | Renew Let's Encrypt certificate | 200 |
| 10 | GET | `/api/v1/sites/web-child-domains` | List child domains (`type`, `parent_domain_id` filters) | 200 |
| 11 | POST | `/api/v1/sites/web-child-domains` | Create subdomain/alias | 201 |
| 12 | GET | `/api/v1/sites/web-child-domains/{id}` | Show child domain | 200 |
| 13 | PUT | `/api/v1/sites/web-child-domains/{id}` | Update child domain | 200 |
| 14 | DELETE | `/api/v1/sites/web-child-domains/{id}` | Delete child domain | 204 |
| 15 | GET | `/api/v1/sites/ftp-users` | List FTP users (`search`) | 200 |
| 16 | POST | `/api/v1/sites/ftp-users` | Create FTP user | 201 |
| 17 | GET | `/api/v1/sites/ftp-users/{id}` | Show FTP user | 200 |
| 18 | PUT | `/api/v1/sites/ftp-users/{id}` | Update FTP user | 200 |
| 19 | DELETE | `/api/v1/sites/ftp-users/{id}` | Delete FTP user | 204 |
| 20 | GET | `/api/v1/sites/shell-users` | List shell users (`search`) | 200 |
| 21 | POST | `/api/v1/sites/shell-users` | Create shell user | 201 |
| 22 | GET | `/api/v1/sites/shell-users/{id}` | Show shell user | 200 |
| 23 | PUT | `/api/v1/sites/shell-users/{id}` | Update shell user | 200 |
| 24 | DELETE | `/api/v1/sites/shell-users/{id}` | Delete shell user | 204 |
| 25 | GET | `/api/v1/sites/databases` | List databases (`search`) | 200 |
| 26 | POST | `/api/v1/sites/databases` | Create database | 201 |
| 27 | GET | `/api/v1/sites/databases/{id}` | Show database | 200 |
| 28 | PUT | `/api/v1/sites/databases/{id}` | Update database | 200 |
| 29 | DELETE | `/api/v1/sites/databases/{id}` | Delete database | 204 |
| 30 | GET | `/api/v1/sites/database-users` | List database users (`search`) | 200 |
| 31 | POST | `/api/v1/sites/database-users` | Create database user | 201 |
| 32 | GET | `/api/v1/sites/database-users/{id}` | Show database user | 200 |
| 33 | PUT | `/api/v1/sites/database-users/{id}` | Update database user | 200 |
| 34 | DELETE | `/api/v1/sites/database-users/{id}` | Delete database user | 204 |
| 35 | GET | `/api/v1/sites/cron-jobs` | List cron jobs (`search`) | 200 |
| 36 | POST | `/api/v1/sites/cron-jobs` | Create cron job | 201 |
| 37 | GET | `/api/v1/sites/cron-jobs/{id}` | Show cron job | 200 |
| 38 | PUT | `/api/v1/sites/cron-jobs/{id}` | Update cron job | 200 |
| 39 | DELETE | `/api/v1/sites/cron-jobs/{id}` | Delete cron job | 204 |
| 40 | GET | `/api/v1/sites/web-folders` | List web folders (`parent_domain_id`, `active`) | 200 |
| 41 | POST | `/api/v1/sites/web-folders` | Create web folder | 201 |
| 42 | GET | `/api/v1/sites/web-folders/{id}` | Show web folder | 200 |
| 43 | PUT | `/api/v1/sites/web-folders/{id}` | Update web folder (`active` only) | 200 |
| 44 | DELETE | `/api/v1/sites/web-folders/{id}` | Delete web folder (+ its users) | 204 |
| 45 | GET | `/api/v1/sites/web-folder-users` | List folder users (`web_folder_id`, `active`) | 200 |
| 46 | POST | `/api/v1/sites/web-folder-users` | Create folder user | 201 |
| 47 | GET | `/api/v1/sites/web-folder-users/{id}` | Show folder user | 200 |
| 48 | PUT | `/api/v1/sites/web-folder-users/{id}` | Update folder user (`password`/`active`) | 200 |
| 49 | DELETE | `/api/v1/sites/web-folder-users/{id}` | Delete folder user | 204 |
| 50 | GET | `/api/v1/sites/webdav-users` | List WebDAV users (`parent_domain_id`, `active`) | 200 |
| 51 | POST | `/api/v1/sites/webdav-users` | Create WebDAV user | 201 |
| 52 | GET | `/api/v1/sites/webdav-users/{id}` | Show WebDAV user | 200 |
| 53 | PUT | `/api/v1/sites/webdav-users/{id}` | Update WebDAV user (`password`/`active`) | 200 |
| 54 | DELETE | `/api/v1/sites/webdav-users/{id}` | Delete WebDAV user | 204 |

List responses per contract: `{ "data": [ <Entity> ], "pagination": <Pagination.yaml> }` with `limit`/`offset`/`sort`/`order` declared as query parameters (see FR-040 for the declared-vs-described conflict).

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference**: `source_code/interface/web/sites/` — consulted: `form/web_vhost_domain.tform.php`, `form/web_childdomain.tform.php`, `form/ftp_user.tform.php`, `form/shell_user.tform.php`, `form/database.tform.php`, `form/database_user.tform.php`, `form/cron.tform.php`, `form/web_folder.tform.php`, `form/web_folder_user.tform.php`, `form/webdav_user.tform.php`; action files `web_vhost_domain_edit.php`, `web_vhost_domain_del.php`, `web_childdomain_edit.php`, `ftp_user_edit.php`, `shell_user_edit.php`, `database_edit.php`, `database_user_edit.php`, `cron_edit.php`, `web_folder_edit.php`, `web_folder_user_edit.php`, `webdav_user_edit.php`; validator `interface/lib/classes/validate_cron.inc.php`; DB schema `source_code/install/sql/ispconfig3.sql` (+ `ISPConfig-DB-Structure.txt`).

### Resource → legacy form → table → datalog actions

| Resource | Legacy tform | Table (verified in install SQL) | PK | Datalog actions |
|----------|--------------|--------------------------------|----|-----------------|
| Web domain | `web_vhost_domain.tform.php` | `web_domain` | `domain_id` | i / u / d (+ cascaded d/u on delete, see below; + double i→u for Let's Encrypt on create) |
| Web child domain | `web_childdomain.tform.php` | `web_domain` (type `subdomain`/`alias`) | `domain_id` | i / u / d (+ forced no-op u on old parent when reparenting) |
| FTP user | `ftp_user.tform.php` | `ftp_user` | `ftp_user_id` | i / u / d |
| Shell user | `shell_user.tform.php` | `shell_user` | `shell_user_id` | i / u / d |
| Database | `database.tform.php` | `web_database` | `database_id` | i / u / d (+ forced u on linked `web_database_user` rows to sync `server_id`) |
| Database user | `database_user.tform.php` | `web_database_user` | `database_user_id` | i / u / d |
| Cron job | `cron.tform.php` | `cron` — **not** `web_cron` (see FR-041) | `id` | i / u / d |
| Web folder | `web_folder.tform.php` | `web_folder` | `web_folder_id` (schema wrongly says `id`, FR-041) | i / u / d (+ cascaded d of its `web_folder_user` rows) |
| Web folder user | `web_folder_user.tform.php` | `web_folder_user` | `web_folder_user_id` (schema wrongly says `id`) | i / u / d |
| WebDAV user | `webdav_user.tform.php` | `webdav_user` | `webdav_user_id` (schema wrongly says `id`) | i / u / d |

### Subtle legacy behaviors an implementer MUST mirror

1. **web_domain derived fields on insert** (`web_vhost_domain_edit.php:1369-1417`): `document_root` = server web config `website_path` with `[website_id]`, `[website_idhash_1..4]`, `[client_id]`, `[client_idhash_1..4]` placeholders replaced; `system_user` = `web{domain_id}`; `system_group` = `client{client_id}`; `allow_override` = web config `htaccess_allow_override`; `php_open_basedir` from web config template (`[website_path]`, `[website_domain]`); `added_date`/`added_by`; `log_retention` from server config (fallback 10); `php_fpm_chroot` default from web config. For `vhostsubdomain`/`vhostalias`: `system_user`/`system_group`/`document_root`/`allow_override` and `sys_groupid` are copied from the **parent** domain and `hd_quota` is forced to 0. The REST implementation must compute these before/within the datalog write so daemons receive complete records.
2. **Let's Encrypt two-step create** (`web_vhost_domain_edit.php:1311-1427`): if `ssl_letsencrypt=y && ssl=y` on insert, both are temporarily set `n`, then after the row exists a datalog INSERT with the final record and a datalog UPDATE re-enabling `ssl`/`ssl_letsencrypt` are written.
3. **web_domain immutability on update** (`onBeforeUpdate`): `server_id` cannot change (error + restore); non-admin group perms restrict `domain`/`ip_address`/`ipv6_address` changes; `system_user`/`system_group`/`web_folder` are always restored from DB on non-admin edits (`onSubmit:1086-1090`).
4. **Domain normalization**: `TOLOWER` + `IDNTOASCII` save filters on `domain` (both vhost and child tforms); custom validators `validate_domain::web_domain` / `sub_domain` / `alias_domain`.
5. **PHP mode/version coupling** (`web_vhost_domain_edit.php:1284-1306`): `server_php_id` (custom PHP version) is only honored for `php` in {`php-fpm`, `fast-cgi`} and reset to 0 otherwise; legacy `php` values are `no|fast-cgi|cgi|mod|suphp|php-fpm|hhvm` (tform line 255), DB default `y` — the contract's enum differs (FR-041). PHP-FPM dynamic pool: `pm_max_children >= pm_max_spare_servers >= pm_start_servers >= pm_min_spare_servers > 0` must hold when `pm=dynamic`.
6. **nginx rewrite_rules and custom_php_ini line validators** (`web_vhost_domain_edit.php:1174-1257`): line-by-line regex whitelists; must be ported for parity if these fields are writable.
7. **SNI check**: when server web config `enable_sni != 'y'`, only one `ssl=y` domain per `ip_address` is allowed.
8. **web_domain delete cascade** (`web_vhost_domain_del.php`): datalog-deletes all non-vhost children (`parent_domain_id = id AND type != 'vhost'`), `ftp_user`, `shell_user`, `cron`, `webdav_user`, `web_backup` rows, APS instances; datalog-updates `web_database.parent_domain_id → 0`; deletes `web_folder` rows and their `web_folder_user` rows. Conflicts with the contract's 400 refusal wording (FR-042).
9. **Username/DB-name prefixing** (`tools_sites::replacePrefix/getPrefix`, sites global config keys `ftpuser_prefix`, `shelluser_prefix`, `webdavuser_prefix`, `dbname_prefix`, `dbuser_prefix`): on insert the prefix is computed and *prepended* to the stored name and stored separately in the `*_prefix` column; on update the *old* record's prefix is reused (names keep their original prefix). Responses should expose the un-prefixed name plus `username_prefix`/`username_full` per the schemas.
10. **FTP/shell/webdav/folder/cron derive from parent**: `server_id` is always overwritten with the parent web domain's `server_id`; after insert (`onAfterInsert`) `sys_groupid` is set to the parent's, plus per-resource fields: FTP `dir`=document_root, `uid`/`gid`=system_user/system_group (validated by `is_allowed_user`/`is_allowed_group` — reject `root`); shell `dir`, `puser`, `pgroup` likewise; webdav `server_id`+`sys_groupid`.
11. **Password storage formats** (tform `encryption` attribute): CRYPT (sha-512 crypt) for `ftp_user.password`, `shell_user.password`, `web_folder_user.password`, `web_domain.stats_password`; MYSQL (`PASSWORD()`-style `*SHA1`) for `web_database_user.database_password`; MYSQLSHA2 for `database_password_sha2`; POSTGRESHA256 for `database_password_postgres`; WebDAV: `md5(username:dir:password)` digest computed in `webdav_user_edit.php:166`. Plaintext must never be stored.
12. **Shell auth mode**: system config `misc.ssh_authentication` = `password` ⇒ `ssh_rsa` nulled; = `key` ⇒ `password` nulled (`shell_user_edit.php:129-138`). Shell username blacklist file `interface/lib/shelluser_blacklist`.
13. **Database remote-access auto-fix** and **linked-user datalog touch**: described in User Story 3; both run on insert *and* update (`database_edit.php`).
14. **Cron type derivation & frequency/type limits**: described in User Story 5 (`cron_edit.php:145-162, 168-227`).
15. **Client limit checks** (`limit_web_domain`, `limit_web_subdomain`, `limit_web_aliasdomain`, `limit_ftp_user`, `limit_shell_user`, `limit_database`, `limit_database_quota`, `limit_cron*`, `limit_webdav_user`, `limit_ssl*`, per-client `web_php_options`/`ssh_chroot` value limits, traffic/hd quota sums incl. reseller roll-ups): legacy enforces these per logged-in client. The REST API is key-authenticated (admin-scope) — see Assumptions for the proposed scope cut.

- **Tables written (via datalog only)**: `web_domain` (i/u/d), `ftp_user` (i/u/d), `shell_user` (i/u/d), `web_database` (i/u/d), `web_database_user` (i/u/d), `cron` (i/u/d), `web_folder` (i/u/d), `web_folder_user` (i/u/d), `webdav_user` (i/u/d); cascades additionally touch `web_backup` (d) if legacy delete parity is chosen.
- **System fields handling**: defaults `sys_perm_user='riud'`, `sys_perm_group='riud'`, `sys_perm_other=''` (all sites tforms); `sys_userid` from the authenticated context (default 1/admin, as `MailDomain::boot()` does); `sys_groupid` derived from the parent web domain for all child resources (never trusted from input); `server_id` derived from the parent domain (or `0` for database users, first/selected web server for vhosts).
- **Intentional deviations from legacy**: (a) web-folder and folder-user/webdav-user updates are restricted to the fields the contract allows (stricter than legacy); (b) client/reseller limit enforcement is out of scope for the admin-scoped API key (Assumption 4); (c) everything else must match legacy or be resolved via the NEEDS CLARIFICATION items below.

## Requirements *(mandatory)*

### Functional Requirements

**Cross-cutting**

- **FR-001**: All 54 operations MUST be implemented exactly as declared in `api/modules/sites/*.yaml` — paths, methods, parameters, request/response schemas, status codes (201 create / 200 update / 204 delete; never 202).
- **FR-002**: All list endpoints MUST support `limit`, `offset`, `sort`, `order` query parameters (shared components) plus the per-resource filters declared in the YAML (`search`, `type`, `parent_domain_id`, `web_folder_id`, `active`).
- **FR-003**: Every model MUST extend `App\Models\BaseModel` with explicit `$table`/`$primaryKey` so all writes flow through `sys_datalog` via `DatalogService`; no direct writes to ISPConfig tables.
- **FR-004**: Errors MUST use `{message, error}` (500/400/404) and Lumen validation errors `{message, errors}` (422) as in `MailDomainController`; unknown IDs → 404; missing API key → 401.
- **FR-005**: Multi-step writes (derived-field updates, cascades, linked-record touches) MUST run inside a DB transaction with rollback and contextual `\Log::error` on failure.
- **FR-006**: `writeOnly` schema fields (all passwords) MUST be `$hidden` on models and never returned; `readOnly` fields MUST be ignored on input.

**Web domains**

- **FR-007**: POST `/sites/web-domains` MUST create a `web_domain` row of `type` vhost (or vhostsubdomain/vhostalias with a valid `parent_domain_id`), lower-casing and IDN-encoding `domain`, validating `hd_quota`/`traffic_quota` against `/^(\-1|[0-9]{1,10})$/`, and rejecting `hd_quota=0` for vhosts.
- **FR-008**: The system MUST generate `document_root`, `system_user` (`web{id}`), `system_group` (`client{client_id}`), `allow_override`, `php_open_basedir`, `added_date`, `added_by`, `log_retention`, `php_fpm_chroot` per legacy behavior #1 and include the final values in the datalog payload.
- **FR-009**: Update MUST keep `server_id` immutable and preserve `system_user`/`system_group`/`web_folder` unless the caller is explicitly allowed (legacy admin-only advanced tab).
- **FR-010**: The Let's Encrypt two-step create (legacy behavior #2) MUST be reproduced when `ssl=y && ssl_letsencrypt=y` on create.
- **FR-011**: When `pm=dynamic`, the PHP-FPM pool inequality (legacy behavior #5) MUST be validated; `server_php_id` MUST be zeroed unless `php` ∈ {`php-fpm`,`fast-cgi`}.
- **FR-012**: DELETE behavior (refuse-with-400 vs legacy cascade) — [NEEDS CLARIFICATION — see FR-042; the implementation MUST NOT silently pick one].

**SSL subresource**

- **FR-013**: GET `/{id}/ssl` MUST return the stored `ssl_cert`, `ssl_key`, bundle and `ssl_letsencrypt` flag, or 204 when no cert is configured.
- **FR-014**: POST `/{id}/ssl` MUST validate PEM cert/key consistency, write `ssl_cert`/`ssl_key`/`ssl_bundle` with `ssl_action='save'` via datalog `u`, and return 200; DELETE MUST datalog `ssl_action='del'` and return 204.
- **FR-015**: POST `/{id}/ssl/renew` MUST return 400 when `ssl_letsencrypt != 'y'`; the renew mechanism itself is [NEEDS CLARIFICATION — no legacy interface equivalent; proposed: forced datalog `u`].

**Child domains**

- **FR-016**: POST `/sites/web-child-domains` MUST store rows in `web_domain` with server-forced `type` (`subdomain`/`alias`), `server_id` from the parent, composed full domain for subdomains, and post-insert `sys_groupid` sync to the parent's group.
- **FR-017**: `redirect_type=proxy` with a path-style `redirect_path` MUST be rejected; `redirect_path` MUST match the legacy redirect regex; `seo_redirect`/`subdomain` enums per schema (alias-only in legacy).
- **FR-018**: Reparenting MUST re-sync `sys_groupid` and write a forced no-op datalog `u` for the old parent domain row.

**FTP users**

- **FR-019**: POST MUST prefix `username` with the resolved `ftpuser_prefix`, store the prefix in `username_prefix`, enforce table-wide uniqueness of the full username and regex `/^[\w\.\-@\+]{0,64}$/`, and CRYPT-hash `password`.
- **FR-020**: `server_id`, `dir`, `uid`, `gid`, `sys_groupid` MUST be derived from the parent web domain on insert and re-derived when `parent_domain_id` changes; `uid`/`gid` failing `is_allowed_user`/`is_allowed_group` (e.g. `root`) MUST abort with an error.
- **FR-021**: `quota_size` MUST accept `-1` or 0..9999999999; `dir` values containing `..` or `./` MUST be rejected.

**Shell users**

- **FR-022**: POST MUST apply `shelluser_prefix`, enforce `/^[\w\.\-]{0,32}$/`, the ≤32-char prefixed-name limit, the shell-user blacklist and `is_allowed_user` check; CRYPT-hash `password`; `chroot` ∈ {`no`,`jailkit`}.
- **FR-023**: `server_id`, `dir`, `puser`, `pgroup`, `sys_groupid` MUST be derived from the parent domain after insert; system config `ssh_authentication` MUST null out `ssh_rsa` (password mode) or `password` (key mode).

**Databases & database users**

- **FR-024**: Database create MUST apply `dbname_prefix`, enforce `/^[a-zA-Z0-9_]{2,64}$/` on the un-prefixed name, ≤64 chars prefixed, blacklist {API DB name, `mysql`}, and per-server uniqueness of `database_name`.
- **FR-025**: Database update MUST reject changes to `database_name` (non-admin), `database_charset`, and `server_id`, and require `database_user_id`.
- **FR-026**: The remote-access auto-fix (append web-server + `default_remote_dbserver` + mirror IPs to `remote_ips`, force `remote_access='y'` when web and DB servers differ) MUST run on insert and update.
- **FR-027**: Insert/update MUST write forced datalog `u` entries for the linked `database_user_id` / `database_ro_user_id` rows syncing their `server_id`; linked users' `sys_groupid` MUST match the parent domain's.
- **FR-028**: PostgreSQL databases MUST enforce that the rw and ro users are not reused by another PostgreSQL DB on the same server. `type=mongo` acceptance is [NEEDS CLARIFICATION: contract offers `mongo` but the vendored legacy tform offers only `mysql` (+ `postgresql` when enabled)].
- **FR-029**: Database-user create MUST apply `dbuser_prefix`, enforce `/^[a-zA-Z0-9_]{2,64}$/`, ≤32 chars prefixed, blacklist {API DB user, `mysql`, `root`}, force `server_id=0`, require a password, and populate `database_password` (MYSQL hash), `database_password_sha2`, `database_password_postgres` from the submitted plaintext.

**Cron jobs**

- **FR-030**: The model MUST map table `cron` with PK `id` (not `web_cron`, FR-041); `server_id`/`sys_groupid` MUST derive from the parent domain.
- **FR-031**: `run_min/hour/mday/wday` MUST pass the legacy `run_time_format` validation and `run_month` `run_month_format` (which additionally accepts `@reboot`); ranges per legacy behavior #14.
- **FR-032**: `command` MUST pass the legacy `command_format` validation (URL parsing with `{DOMAIN}` substitution; no `\`, CR/LF/NUL); `type` MUST be derived server-side (url → `url`; otherwise client's `limit_cron_type` or `full` for admin-owned sites).

**Web folders & folder users**

- **FR-033**: Folder create MUST validate `path` against `/^[\w\.\-\_\/]{0,255}$/`, reject duplicate (parent_domain_id, path), derive `server_id`/`sys_groupid` from the parent; folder update MUST accept only `active` (contract).
- **FR-034**: Folder DELETE MUST also datalog-delete all `web_folder_user` rows of that folder before deleting the folder (contract note + legacy cascade).
- **FR-035**: Folder-user create MUST validate `/^[\w\.\-]{0,64}$/`, reject duplicate (web_folder_id, username), CRYPT-hash the password, derive `server_id`/`sys_groupid` from the folder; update MUST accept only `password`/`active`.

**WebDAV users**

- **FR-036**: Create MUST apply `webdavuser_prefix`, enforce table-wide username uniqueness and `/^[\w\.\-@]{0,64}$/`, reject `dir` containing `..`/`./`, derive `server_id`/`sys_groupid` from the parent domain, and store `password` as `md5(username:dir:password)`.
- **FR-037**: Update MUST keep `username` and `dir` immutable and accept only `password`/`active`; password changes re-compute the digest with the stored username/dir.

**Contract hygiene / open items**

- **FR-038**: Responses MUST include the read-only convenience fields the schemas declare where cheaply derivable (`username_full`, `database_name_full`); relational display fields (`server_name`, `parent_domain`, `web_folder_path`) SHOULD be included via joins/relationships — confirm per resource during implementation.
- **FR-039**: `created_at`/`updated_at` appear in most schemas but **no ISPConfig sites table has these columns** — models MUST set `$timestamps = false` (BaseModel default) and the fields will be absent from responses. [NEEDS CLARIFICATION: remove them from the schemas or accept their absence.]
- **FR-040**: List response/parameter shape [NEEDS CLARIFICATION]: the sites YAMLs declare `{data, pagination}` with `limit`/`offset`/`sort`/`order` parameters, but (a) the endpoint descriptions document `page`/`per_page` and `field[op]=value` filtering that is neither declared as parameters nor implemented anywhere in this codebase, and (b) constitution Principle V mandates `{items,total,limit,offset}`. The reference `MailDomainController` implements `{data, pagination}` with `per_page`. Decide once for the module: implement the *declared* contract (`{data, pagination}` + limit/offset honored) and fix the YAML descriptions, or amend the YAML to the constitution shape.
- **FR-041**: Schema metadata errors that MUST be corrected in the YAML before implementation (they contradict the verified DB schema):
  - `CronJob.yaml` `x-db-table: web_cron` → actual table `cron`; 
  - `WebFolder.yaml`/`WebFolderUser.yaml`/`WebdavUser.yaml` `x-db-field: id` → actual PKs `web_folder_id`/`web_folder_user_id`/`webdav_user_id`;
  - `WebDomain.yaml` requires/declares columns that do not exist in `web_domain`: `http2`, `hsts`, `hsts_max_age`, `hsts_include_subdomains`, `hsts_preload`, `proxy_domain`, `proxy_paths`, `proxy_http_version`, `proxy_connect_timeout`, `proxy_read_timeout`, `proxy_send_timeout`, `ssl_redirect` (DB has `rewrite_to_https`), `ssl_dn_commonname`/`ssl_dn_email` (DB has `ssl_domain`), `stats_user`/`stats_auth`/`stats_allow_ip`, `php_fastcgi_binary`/`php_fpm_ini_dir`/`php_fpm_pool_dir`/`php_fpm_settings`, `custom_config` (DB has `apache_directives`/`nginx_directives`), `alias`; `ssl_organisational_unit` → DB column `ssl_organisation_unit`; `php` enum (`fpm`, `phpX.Y` don't exist — legacy: `no|fast-cgi|cgi|mod|suphp|php-fpm|hhvm` + `server_php_id`); `stats_type` enum (`none` → legacy empty string; legacy also has `goaccess`); `hd_quota` "minimum 1, default 1000" (DB default 0, legacy default -1, `-1` = unlimited). [NEEDS CLARIFICATION: fix the schemas (recommended, keeps Principle I intact) vs. implement a mapping layer.]
- **FR-042**: Web-domain DELETE semantics [NEEDS CLARIFICATION]: contract 400 ("cannot be deleted because it has child domains / databases / FTP users") vs legacy cascade (behavior #8). Recommendation: follow legacy (constitution Principle III) and fix the YAML description, but this changes the declared contract and must be an explicit decision.
- **FR-043**: `y`/`n` flag representation [NEEDS CLARIFICATION]: contract enums say string `y`/`n`; the codebase's `YesNoBoolean` cast returns booleans. Pick one for the module (contract-faithful `y`/`n` recommended) and apply consistently.

### Key Entities

- **WebDomain**: a vhost/site (also vhostsubdomain/vhostalias) — table `web_domain`, schema `api/components/schemas/WebDomain.yaml`, model `app/Models/WebDomain.php` (future). Parent of everything below via `parent_domain_id`.
- **WebChildDomain**: subdomain or alias domain — table `web_domain` (`type` ∈ {subdomain, alias}), schema `WebChildDomain.yaml`, model `app/Models/WebChildDomain.php` (future; same table, scoped by type).
- **FtpUser**: FTP account bound to a site — table `ftp_user`, schema `FtpUser.yaml`, model `app/Models/FtpUser.php` (future).
- **ShellUser**: SSH account bound to a site — table `shell_user`, schema `ShellUser.yaml`, model `app/Models/ShellUser.php` (future).
- **WebDatabase**: database bound to a site — table `web_database`, schema `Database.yaml`, model `app/Models/WebDatabase.php` (future); references `WebDatabaseUser` via `database_user_id`/`database_ro_user_id`.
- **WebDatabaseUser**: credential set shared by databases — table `web_database_user`, schema `DatabaseUser.yaml`, model `app/Models/WebDatabaseUser.php` (future).
- **CronJob**: scheduled job for a site — table `cron` (PK `id`), schema `CronJob.yaml`, model `app/Models/CronJob.php` (future).
- **WebFolder**: HTTP-auth-protected directory — table `web_folder` (PK `web_folder_id`), schema `WebFolder.yaml`, model `app/Models/WebFolder.php` (future); parent of WebFolderUser.
- **WebFolderUser**: HTTP-auth credential for a folder — table `web_folder_user` (PK `web_folder_user_id`), schema `WebFolderUser.yaml`, model `app/Models/WebFolderUser.php` (future).
- **WebdavUser**: WebDAV account for a site directory — table `webdav_user` (PK `webdav_user_id`), schema `WebdavUser.yaml`, model `app/Models/WebdavUser.php` (future).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: All 54 operations in the table above respond as specified (paths, parameters, bodies, status codes); Swagger UI (`/api/documentation`) renders the whole sites module and "Try it out" succeeds against a populated `dbispconfig`.
- **SC-002**: Every write produces a well-formed `sys_datalog` entry (`dbtable`, `dbidx`, `action` ∈ i/u/d) that a stock ISPConfig 3.2 server daemon processes without error — including the multi-entry flows (LE two-step create, database→database-user sync, folder-user cascade).
- **SC-003**: For each documented legacy validation case (FR-007…FR-037), the API's accept/reject decision matches legacy ISPConfig behavior; derived fields (`document_root`, `system_user`, prefixes, password hashes) are byte-identical to what the legacy interface would have written for the same input.
- **SC-004**: No plaintext password is ever stored or returned by any endpoint; all `writeOnly` fields are absent from every response.
- **SC-005**: `routes/web.php` ordering audit passes: `sites/web-domains/{id}/ssl/renew` → `sites/web-domains/{id}/ssl` → `sites/web-domains/{id}` registered in that order; no existing route shadowed.
- **SC-006**: All NEEDS CLARIFICATION items (FR-012/015/028/039/040/041/042/043) are resolved and recorded before the corresponding code is written; the YAML contract and implementation agree afterwards.

## Assumptions

- Only the endpoints already specced under `api/modules/sites/` are in scope; no new endpoints (e.g. per-domain nested lists like `/sites/web-domains/{id}/ftp-users`) are invented.
- Existing `X-API-Key` middleware (`api.auth`) is reused; the API key operates with admin scope. Consequently legacy *per-client* limit checks (`limit_ftp_user`, `limit_web_domain`, quota roll-ups, reseller limits — legacy behavior #15) are **out of scope**; ISPConfig's `sys_perm_*`/`sys_groupid` fields are still populated correctly so the ISPConfig UI shows the records under the right client.
- `sys_userid` defaults to 1 (admin) as in `MailDomain::boot()`; `sys_groupid` for vhosts defaults to the admin group unless a client group is supplied — child resources always inherit the parent domain's group.
- A populated `dbispconfig` database is available, including `server` rows with serialized web/server config (needed for `website_path`, `htaccess_allow_override`, `php_open_basedir`, `enable_sni`, `log_retention`) and `sys_ini` sites config (prefix patterns). Reading these configs is a read-only concern (no datalog).
- Legacy behavior verified against the `source_code/` version currently vendored (ISPConfig 3.2.x head with `server_php_id`, `jailkit_chroot_app_*`, GoAccess stats).
- The `search` query parameter maps to a LIKE filter over each resource's natural name column (`domain`, `username`, `database_name`/`database_user`, `command`, `path`) — the YAML does not specify the searched columns.
- Tests are optional per the constitution; this spec does not mandate them (tasks include none). Verification happens through Swagger UI and datalog inspection (SC-001/SC-002).
