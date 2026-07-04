# Tasks: Sites Module

**Input**: Design documents from `/specs/006-sites-module/`
**Prerequisites**: plan.md (required), spec.md (required)

**Tests**: OPTIONAL per constitution — the sites spec does not request tests, so no test tasks are included. Verification is via Swagger UI "Try it out" + `sys_datalog` inspection (spec SC-001/SC-002).

**Organization**: Tasks are grouped by user story (spec.md US1–US7) so each story is independently implementable and testable.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1–US7 from spec.md
- Exact file paths in every description

## Path Conventions (this project)

| Artifact | Path |
|----------|------|
| OpenAPI endpoint spec | `api/modules/sites/[resource].yaml` (already indexed in `api/modules/sites/_index.yaml` and `api/openapi.yaml`) |
| OpenAPI schema | `api/components/schemas/[Entity].yaml` |
| Model | `app/Models/[Entity].php` — **must extend `App\Models\BaseModel`** |
| Controller | `app/Http/Controllers/Api/V1/[Entity]Controller.php` |
| Service | `app/Services/[Name]Service.php` |
| Routes | `routes/web.php` — inside the `api.auth` group, specific-before-general order |

**The per-resource implementation flow is always**: spec YAML (verify/fix) → model → (service if needed) → controller → routes → Swagger verification.

---

## Phase 1: Setup — resolve clarifications and true up the contract

**Purpose**: The YAML contract is the source of truth (Principle I), but spec FR-039…FR-043 document places where it contradicts the verified DB schema/legacy behavior. Resolve every open decision and make the YAML correct **before any PHP is written**.

- [ ] T001 Resolve gating decisions D1–D7 (plan.md Constitution Check table; spec FR-012, FR-015, FR-028, FR-039, FR-040, FR-041, FR-042, FR-043) with the project owner; record each resolution in `specs/006-sites-module/spec.md` and remove the corresponding NEEDS CLARIFICATION markers
- [ ] T002 [P] Fix schema metadata per D1 in `api/components/schemas/CronJob.yaml` (`x-db-table: web_cron` → `cron`), `api/components/schemas/WebFolder.yaml` (`x-db-field: id` → `web_folder_id`), `api/components/schemas/WebFolderUser.yaml` (→ `web_folder_user_id`), `api/components/schemas/WebdavUser.yaml` (→ `webdav_user_id`)
- [ ] T003 [P] Rework `api/components/schemas/WebDomain.yaml` per D1/D7: remove or remap the phantom columns listed in spec FR-041 (`http2`, `hsts*`, `proxy_domain`/`proxy_paths`/`proxy_http_version`/`proxy_*_timeout`, `ssl_redirect`→`rewrite_to_https`, `ssl_dn_*`→`ssl_domain`, `stats_user`/`stats_auth`/`stats_allow_ip`, `php_fastcgi_binary`/`php_fpm_ini_dir`/`php_fpm_pool_dir`/`php_fpm_settings`, `custom_config`→`apache_directives`/`nginx_directives`, `alias`), rename `ssl_organisational_unit`→`ssl_organisation_unit`, fix `php` enum (`no|fast-cgi|cgi|mod|suphp|php-fpm|hhvm` + `server_php_id`), `stats_type` enum (`awstats|goaccess|webalizer|''`), `hd_quota` bounds (`-1` unlimited, no 0 for vhost), drop `created_at`/`updated_at`, and align the `required` list with real NOT NULL/business rules
- [ ] T004 [P] Update remaining schemas per D6/D7: `api/components/schemas/Database.yaml` (type enum per D6, `parent_domain_id` requiredness, drop timestamps), `api/components/schemas/{FtpUser,ShellUser,WebChildDomain,WebFolder,WebFolderUser,WebdavUser,CronJob}.yaml` (drop `created_at`/`updated_at` per D7; verify `DatabaseUser.yaml` against `web_database_user` — verified correct, no change expected)
- [ ] T005 Update path files per D2/D3/D4 in `api/modules/sites/web-domains.yaml` (DELETE 400 wording vs cascade per D2; SSL upload/renew semantics per D3) and all ten `api/modules/sites/*.yaml` list descriptions (remove or implement-match the `page`/`per_page` + `field[op]=value` prose per D4)
- [ ] T006 Verify the module renders end-to-end: `/api/documentation` shows all 54 sites operations, every `$ref` resolves through `api/openapi.yaml` → `api/modules/sites/*` → `api/components/*`

**Checkpoint**: Contract is internally consistent and matches the verified DB schema — Principle I baseline established.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Shared services every story depends on. No story work before this phase completes.

- [ ] T007 Create `app/Services/SitesConfigService.php`: read sites global config from `sys_ini` (`ftpuser_prefix`, `shelluser_prefix`, `webdavuser_prefix`, `dbname_prefix`, `dbuser_prefix`, `default_remote_dbserver`, `postgresql_database`) and misc config (`ssh_authentication`); read per-server web/server config from `server.config` (`website_path`, `htaccess_allow_override`, `php_open_basedir`, `enable_sni`, `log_retention`, `php_fpm_default_chroot`, `server_type`, `ip_address`); port `tools_sites::replacePrefix`/`getPrefix` prefix resolution (`[CLIENTID]`/`[CLIENTNAME]`/`[WEBSITEID]`-style placeholders — verify exact placeholder set in `source_code/interface/lib/classes/tools_sites.inc.php`)
- [ ] T008 [P] Create `app/Services/PasswordHashService.php`: `crypt()` sha512-crypt (legacy CRYPT), MySQL `PASSWORD()`-style `*SHA1` hash (legacy MYSQL), MYSQLSHA2, POSTGRESHA256, and WebDAV digest `md5(username:dir:password)` — mirror `source_code/interface/lib/classes/auth.inc.php` hash routines exactly
- [ ] T009 Create `app/Services/WebDomainProvisionService.php`: (a) derived-field generation for web domains (`document_root` from `website_path` template with `[website_id]`/`[website_idhash_1..4]`/`[client_id]`/`[client_idhash_1..4]` — port `id_hash()` from `web_vhost_domain_edit.php`; `system_user`=`web{id}`, `system_group`=`client{client_id}`, `allow_override`, `php_open_basedir`, `added_date`/`added_by`, `log_retention`, `php_fpm_chroot`); (b) parent-domain-derived field sync helper (server_id/sys_groupid/dir/uid/gid/puser/pgroup) reused by US2/US4/US5/US6 controllers; (c) placeholder for the delete cascade (implemented in T015)
- [ ] T010 Confirm datalog plumbing for sites tables: via a scratch script, `save()`/`delete()` a throwaway row through a temporary BaseModel subclass for `web_folder` and verify the `sys_datalog` row has `dbtable=web_folder`, `dbidx=web_folder_id:<id>`, `action` i/d and a JSON diff payload ISPConfig 3.2 daemons accept (compare against a row written by the legacy UI if available)

**Checkpoint**: Foundation ready — user story implementation can begin.

---

## Phase 3: User Story 1 — Web domain lifecycle (Priority: P1) 🎯 MVP

**Goal**: Full CRUD on `/api/v1/sites/web-domains` with legacy-parity derived fields, validations, and delete semantics.

**Independent Test**: POST a vhost body via Swagger; assert 201 + generated `document_root`/`system_user`/`system_group` + datalog `i`; then GET list/show, PUT partial update (200, immutable fields untouched), DELETE (204 + resolved D2 semantics).

- [ ] T011 [US1] Create `app/Models/WebDomain.php` extending `BaseModel` (`$table='web_domain'`, `$primaryKey='domain_id'`, `$fillable` per corrected `WebDomain.yaml`, `$hidden=['stats_password','ssl_key']` (confirm exposure of `ssl_key` vs the SSL GET endpoint), `$attributes` defaults from `form/web_vhost_domain.tform.php` (`active=y`, `type=vhost`, `vhost_type=name`, `cgi/ssi=n`, `suexec=y`, `errordocs=1`, `subdomain=www`, `php` default, `allow_override='All'`, `traffic_quota=-1`, `sys_perm_*` `riud/riud/''`), static `$rules` + `getValidationRules($id)` following `app/Models/MailDomain.php`)
- [ ] T012 [US1] Implement `index`/`show` in `app/Http/Controllers/Api/V1/WebDomainController.php`: list shape per resolved D4, `limit`/`offset`/`sort`/`order` params, `search` → `domain LIKE`, 404 on missing ID
- [ ] T013 [US1] Implement `store`: normalize domain (lowercase + `idn_to_ascii`), validate per legacy (`domain` regex, `hd_quota`/`traffic_quota` `/^(\-1|[0-9]{1,10})$/`, `hd_quota!=0` for vhost, `pm` inequality when `pm=dynamic`, `server_php_id` only with `php` ∈ {php-fpm,fast-cgi}, SNI duplicate-cert check when `enable_sni!='y'`, nginx `rewrite_rules` / `custom_php_ini` line validators from `web_vhost_domain_edit.php:1174-1257`); write via `save()`; apply `WebDomainProvisionService` derived fields inside the same transaction; implement the Let's Encrypt two-step create (datalog `i` with ssl=n then datalog `u` enabling ssl/ssl_letsencrypt); return 201
- [ ] T014 [US1] Implement `update`: partial rules via `getValidationRules($id)`; enforce immutability of `server_id` and preservation of `system_user`/`system_group`/`web_folder` per spec FR-009; for `type` vhostsubdomain/vhostalias force `hd_quota=0` and parent-derived fields; return 200
- [ ] T015 [US1] Implement `destroy` per resolved D2 — if legacy cascade: inside one transaction datalog-delete children (`web_domain` rows with `parent_domain_id=id AND type!='vhost'`), `ftp_user`, `shell_user`, `cron`, `webdav_user`, `web_backup` rows, detach `web_database` (`parent_domain_id→0` via datalog `u`), delete `web_folder`+`web_folder_user` rows, then the domain itself; if contract-400: return 400 with `{message,error}` when dependents exist. Either way 204 on success. Complete the cascade helper in `app/Services/WebDomainProvisionService.php`
- [ ] T016 [US1] Register routes in `routes/web.php` inside the `api.auth` group, new "Sites" block after the Monitor block: `GET/POST sites/web-domains`, `GET/PUT/DELETE sites/web-domains/{id}` — leave a marked slot ABOVE the `{id}` routes for the US7 SSL routes; verify no shadowing of existing routes
- [ ] T017 [US1] Verify against Swagger UI: all five web-domain operations match `api/modules/sites/web-domains.yaml` (paths/params/bodies/status codes); inspect `sys_datalog` rows for i/u/d and the LE two-step sequence

**Checkpoint**: Web domains fully functional — MVP. All other stories can now reference real parent domains.

---

## Phase 4: User Story 2 — FTP and shell access provisioning (Priority: P2)

**Goal**: CRUD for `/sites/ftp-users` and `/sites/shell-users` with prefixing, parent-derived fields, and correct password hashing.

**Independent Test**: Against a US1-created vhost, POST an FTP user and a shell user; assert prefixed usernames, derived `dir`/`uid`/`gid` (`puser`/`pgroup`), CRYPT password hashes, datalog `i` rows; exercise 422 cases (bad regex, >32 shell chars, blacklisted name, duplicate).

- [ ] T018 [P] [US2] Create `app/Models/FtpUser.php` (`$table='ftp_user'`, `$primaryKey='ftp_user_id'`, `$hidden=['password']`, defaults `quota_size=-1`, `active='y'`, `sys_perm_*`; rules: username `/^[\w\.\-@\+]{0,64}$/` + required parent_domain_id + quota regex `/^(\-1|[0-9]{1,10})$/`)
- [ ] T019 [P] [US2] Create `app/Models/ShellUser.php` (`$table='shell_user'`, `$primaryKey='shell_user_id'`, `$hidden=['password']`, defaults `quota_size=-1`, `active='y'`, `shell='/bin/bash'`, `chroot` per client config; rules: username `/^[\w\.\-]{0,32}$/`, `chroot` in `no,jailkit`, `ssh_rsa` max 600)
- [ ] T020 [US2] Create `app/Http/Controllers/Api/V1/FtpUserController.php`: index/show (search→username LIKE) + store/update/destroy — resolve `ftpuser_prefix` via `SitesConfigService`, prepend on insert / preserve old prefix on update, enforce full-username uniqueness, CRYPT-hash password via `PasswordHashService`, derive `server_id`/`dir`/`uid`/`gid`/`sys_groupid` from the parent domain (re-derive on parent change) rejecting disallowed uid/gid (`root`), reject `dir` containing `..` or `./`; 201/200/204
- [ ] T021 [US2] Create `app/Http/Controllers/Api/V1/ShellUserController.php`: same flow with `shelluser_prefix`, ≤32-char prefixed-name check, shell-username blacklist (port `interface/lib/shelluser_blacklist` list) + `is_allowed_user` check, `ssh_authentication` mode handling (null `ssh_rsa` in password mode / null `password` in key mode), derive `server_id`/`dir`/`puser`/`pgroup`/`sys_groupid` from parent
- [ ] T022 [US2] Register routes in `routes/web.php`: `GET/POST sites/ftp-users`, `GET/PUT/DELETE sites/ftp-users/{id}`, `GET/POST sites/shell-users`, `GET/PUT/DELETE sites/shell-users/{id}` (sequential edit — no [P] on routes file)
- [ ] T023 [US2] Swagger verification for both resources against `api/modules/sites/ftp-users.yaml` and `api/modules/sites/shell-users.yaml`; datalog inspection (i/u/d on `ftp_user`, `shell_user`)

**Checkpoint**: US1+US2 independently functional.

---

## Phase 5: User Story 3 — Database provisioning (Priority: P3)

**Goal**: CRUD for `/sites/database-users` and `/sites/databases` with prefixing, hash trio, remote-access auto-fix, and linked-user datalog sync.

**Independent Test**: POST a database user (assert prefix, `server_id=0`, three hash columns, datalog `i` on `web_database_user`); POST a database linking it (assert prefix, per-server uniqueness, datalog `i` on `web_database` + forced `u` on the linked user); exercise immutability 422s on update.

- [ ] T024 [P] [US3] Create `app/Models/WebDatabaseUser.php` (`$table='web_database_user'`, `$primaryKey='database_user_id'`, `$hidden` = all four password columns; rules: `database_user` `/^[a-zA-Z0-9_]{2,64}$/`)
- [ ] T025 [P] [US3] Create `app/Models/WebDatabase.php` (`$table='web_database'`, `$primaryKey='database_id'`, defaults `type='mysql'`, `remote_access='n'` (input default; note DB column default is `y`), `backup_interval='none'`, `backup_copies=1`, `database_quota=-1`, `active='y'`; rules: `database_name` `/^[a-zA-Z0-9_]{2,64}$/`, `type` per resolved D6, `database_charset` in `'',latin1,utf8,utf8mb4`, `remote_ips` IP/hostname-list validation ported from `validate_database::valid_ip_list`)
- [ ] T026 [US3] Create `app/Http/Controllers/Api/V1/WebDatabaseUserController.php`: prefix with `dbuser_prefix` (insert) / preserve (update), ≤32-char prefixed cap, blacklist {API DB user, `mysql`, `root`}, require password on create, populate `database_password` (MYSQL hash) + `database_password_sha2` + `database_password_postgres` via `PasswordHashService`, force `server_id=0` on insert; 201/200/204
- [ ] T027 [US3] Create `app/Http/Controllers/Api/V1/WebDatabaseController.php`: prefix with `dbname_prefix`, ≤64-char cap, blacklist {`dbispconfig`, `mysql`}, per-server duplicate check, require `parent_domain_id` per resolved D6, linked-user `sys_groupid`-matches-domain check, PostgreSQL user-reuse checks, remote-access auto-fix (web-server IP + `default_remote_dbserver` + mirror IPs appended to `remote_ips`, `remote_access` forced `y` when web≠db server), update-immutability (name for non-admin/charset/server), forced datalog `u` on linked `database_user_id`/`database_ro_user_id` rows syncing `server_id` on insert AND update — all inside one transaction
- [ ] T028 [US3] Register routes in `routes/web.php`: `GET/POST sites/database-users`, `GET/PUT/DELETE sites/database-users/{id}`, `GET/POST sites/databases`, `GET/PUT/DELETE sites/databases/{id}`
- [ ] T029 [US3] Swagger verification against `api/modules/sites/databases.yaml` / `database-users.yaml`; datalog inspection incl. the forced linked-user `u` entries

**Checkpoint**: US1–US3 independently functional.

---

## Phase 6: User Story 4 — Child domains (Priority: P4)

**Goal**: CRUD for `/sites/web-child-domains` (subdomains + alias domains stored in `web_domain`).

**Independent Test**: POST `{parent_domain_id, domain:"blog", type:"subdomain"}`; assert stored `domain=blog.<parent>`, `type=subdomain`, `server_id`/`sys_groupid` from parent, datalog `i` on `web_domain`; reparent and assert the forced no-op `u` on the old parent row.

- [ ] T030 [US4] Create `app/Models/WebChildDomain.php` (`$table='web_domain'`, `$primaryKey='domain_id'`, global scope `whereIn('type',['subdomain','alias'])` so it never touches vhosts; defaults `active='y'`, `subdomain='www'` (alias only), `ssl_letsencrypt_exclude='n'`; rules: `type` in `subdomain,alias`, `redirect_type` enum from tform, `redirect_path` legacy regex, `seo_redirect` enum)
- [ ] T031 [US4] Create `app/Http/Controllers/Api/V1/WebChildDomainController.php`: index/show with `type`/`parent_domain_id` filters; store — compose subdomain FQDN (`domain + '.' + parent.domain`), lowercase/IDN normalize, force `type`/`server_id` server-side, post-insert `sys_groupid` sync to parent; reject `redirect_type=proxy` with path-style `redirect_path`; update — on parent change re-sync `sys_groupid` and write forced no-op datalog `u` for the OLD parent `web_domain` row; destroy → 204
- [ ] T032 [US4] Register routes in `routes/web.php`: `GET/POST sites/web-child-domains`, `GET/PUT/DELETE sites/web-child-domains/{id}` (distinct literal from `sites/web-domains` — no shadowing, but keep the block adjacent to web-domains for readability)
- [ ] T033 [US4] Swagger verification against `api/modules/sites/web-child-domains.yaml`; datalog inspection incl. reparenting side effect

**Checkpoint**: US1–US4 independently functional.

---

## Phase 7: User Story 5 — Cron jobs (Priority: P5)

**Goal**: CRUD for `/sites/cron-jobs` with exact legacy time/command validation and server-side type derivation.

**Independent Test**: POST a URL cron (`type` forced to `url`), a shell cron (type from client `limit_cron_type`/`full`); assert datalog `i` on table `cron` with `dbidx=id:<id>`; exercise 422s: bad `run_min`, `@reboot` outside `run_month`, command with newline, invalid URL host.

- [ ] T034 [US5] Create `app/Models/CronJob.php` (`$table='cron'` — NOT `web_cron`; `$primaryKey='id'`; defaults `type='url'`, `log='n'`, `active='y'`; static validator methods porting `validate_cron::run_time_format` (charset `0-9,-,/,*`; no adjacent separators; ranges min 0-59 / hour 0-23 / mday 1-31 / month 1-12 / wday 0-7), `run_month_format` (`@reboot` allowed), and `command_format` (http/https `parse_url` + hostname check, `{DOMAIN}` substitution from parent domain, reject `\`/CR/LF/NUL) from `source_code/interface/lib/classes/validate_cron.inc.php`)
- [ ] T035 [US5] Create `app/Http/Controllers/Api/V1/CronJobController.php`: index/show (search→command LIKE); store/update — validate parent domain, derive `type` server-side (url → `url`, else owning client's `limit_cron_type` → `full`/`chrooted`, admin-owned → `full`), derive `server_id`/`sys_groupid` from parent, enforce client `limit_cron_type=url` and `limit_cron_frequency` checks where client-owned (admin-scope: skip per spec Assumption 4 — document the skip); destroy → 204
- [ ] T036 [US5] Register routes in `routes/web.php`: `GET/POST sites/cron-jobs`, `GET/PUT/DELETE sites/cron-jobs/{id}`
- [ ] T037 [US5] Swagger verification against `api/modules/sites/cron-jobs.yaml`; datalog inspection (`dbtable=cron`)

**Checkpoint**: US1–US5 independently functional.

---

## Phase 8: User Story 6 — Protected folders and WebDAV (Priority: P6)

**Goal**: CRUD for `/sites/web-folders`, `/sites/web-folder-users`, `/sites/webdav-users`.

**Independent Test**: Create folder → user → WebDAV user chain; assert derived `server_id`/`sys_groupid`, CRYPT hash (folder user) and md5-digest (webdav), duplicate rejections; DELETE folder cascades its users.

- [ ] T038 [P] [US6] Create `app/Models/WebFolder.php` (`$table='web_folder'`, `$primaryKey='web_folder_id'`, defaults `path='/'`, `active='y'`; rules: path `/^[\w\.\-\_\/]{0,255}$/`)
- [ ] T039 [P] [US6] Create `app/Models/WebFolderUser.php` (`$table='web_folder_user'`, `$primaryKey='web_folder_user_id'`, `$hidden=['password']`; rules: username `/^[\w\.\-]{0,64}$/`)
- [ ] T040 [P] [US6] Create `app/Models/WebdavUser.php` (`$table='webdav_user'`, `$primaryKey='webdav_user_id'`, `$hidden=['password']`; rules: username `/^[\w\.\-@]{0,64}$/`, dir NOTEMPTY without `..`/`./`)
- [ ] T041 [US6] Create `app/Http/Controllers/Api/V1/WebFolderController.php`: store (parent check, duplicate (parent,path) check, derive server_id + post-insert sys_groupid), update (`active` only per contract), destroy (datalog-delete all `web_folder_user` rows of the folder, then the folder — one transaction), index filters `parent_domain_id`/`active`
- [ ] T042 [US6] Create `app/Http/Controllers/Api/V1/WebFolderUserController.php`: store (folder existence check, duplicate (folder,username) check, CRYPT hash via `PasswordHashService`, derive server_id/sys_groupid from folder), update (`password`/`active` only), destroy; index filters `web_folder_id`/`active`
- [ ] T043 [US6] Create `app/Http/Controllers/Api/V1/WebdavUserController.php`: store (prefix with `webdavuser_prefix`, table-wide username uniqueness, dir traversal checks, derive server_id/sys_groupid from parent, store `md5(username:dir:password)`), update (`password`/`active` only — username/dir immutable, re-digest with stored username/dir), destroy; index filters `parent_domain_id`/`active`
- [ ] T044 [US6] Register routes in `routes/web.php`: `GET/POST sites/web-folders`, `GET/PUT/DELETE sites/web-folders/{id}`, `GET/POST sites/web-folder-users`, `GET/PUT/DELETE sites/web-folder-users/{id}`, `GET/POST sites/webdav-users`, `GET/PUT/DELETE sites/webdav-users/{id}` (register `web-folder-users` block before or after `web-folders` — distinct literals, no shadowing; keep alphabetic block comment order)
- [ ] T045 [US6] Swagger verification against `api/modules/sites/{web-folders,web-folder-users,webdav-users}.yaml`; datalog inspection incl. folder→folder-user delete cascade

**Checkpoint**: US1–US6 independently functional — all 50 plain CRUD operations live.

---

## Phase 9: User Story 7 — SSL certificate management (Priority: P7)

**Goal**: The four SSL subresource operations on `/sites/web-domains/{id}/ssl` and `/ssl/renew` per resolved D3.

**Independent Test**: Upload a matching cert/key pair → 200 + datalog `u` with `ssl_action='save'`; GET returns PEM fields (204 when none); DELETE → 204 + `ssl_action='del'`; renew on non-LE domain → 400.

- [ ] T046 [US7] Create `app/Http/Controllers/Api/V1/WebDomainSslController.php`: `show` (200 with `ssl_cert`/`ssl_key`/bundle/`ssl_letsencrypt`, 204 when no cert), `store` (validate PEM + cert/key match via `openssl_x509_check_private_key`, write `ssl_cert`/`ssl_key`/`ssl_bundle` + `ssl_action='save'` through `WebDomain->save()`, 200), `destroy` (`ssl_action='del'`, clear cert fields per resolved D3, 204), `renew` (400 unless `ssl_letsencrypt='y'`; trigger per resolved D3 — recommended forced datalog `u`; 200)
- [ ] T047 [US7] Insert routes in `routes/web.php` at the marked slot ABOVE `sites/web-domains/{id}`: `POST sites/web-domains/{id}/ssl/renew`, then `GET/POST/DELETE sites/web-domains/{id}/ssl` — re-run the full ordering audit of the sites block (renew → ssl → {id})
- [ ] T048 [US7] Swagger verification against `api/modules/sites/web-domains.yaml` SSL paths; datalog inspection of `ssl_action` payloads

**Checkpoint**: All 54 operations implemented.

---

## Phase 10: Polish & Cross-Cutting Concerns

- [ ] T049 [P] Update `README.md` endpoint list with the sites module (54 operations)
- [ ] T050 Code cleanup pass: controllers thin (validation + HTTP only), prefix/hash/derivation logic lives in `app/Services/{SitesConfigService,PasswordHashService,WebDomainProvisionService}.php`, no ad-hoc `y`/`n` conversion contradicting resolved D5, no direct `DB::table()` writes to ISPConfig tables anywhere in the diff
- [ ] T051 Re-verify legacy parity for the documented validation cases (spec FR-007…FR-037): replay each accept/reject example from the acceptance scenarios and compare derived values (`document_root`, prefixes, hash formats) against a legacy-UI-created reference record
- [ ] T052 Final `routes/web.php` ordering audit (constitution gate 4): sites block order renew → ssl → web-domains/{id}; no shadowing of client/dns/mail/monitor routes
- [ ] T053 Run `vendor/bin/phpunit` — full existing suite must still pass

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: no dependencies; T001 (decisions) gates T002–T005; T006 last
- **Foundational (Phase 2)**: depends on Phase 1 — **BLOCKS all user stories** (T007/T008 parallel; T009 needs T007; T010 anytime after T007)
- **US1 (Phase 3)**: depends on Phase 2 — **functionally blocks US2–US7** (every other resource validates `parent_domain_id` against web domains; US7 extends US1's controller surface and routes)
- **US2–US6 (Phases 4–8)**: each depends only on US1 + Foundational; mutually independent (except shared `routes/web.php` edits — sequential)
- **US7 (Phase 9)**: depends on US1 (model + route block)
- **Polish (Phase 10)**: after all desired stories

### Within Each User Story

- Spec YAML correctness (Phase 1) before any controller work (Principle I)
- Model before controller; controller before routes; Swagger verification last
- Within US3: T026 (database users) before T027 (databases) — databases link users

### Parallel Opportunities

- T002/T003/T004 (different YAML files); T007/T008 (different services)
- Model tasks within a story marked [P] (T018/T019, T024/T025, T038/T039/T040)
- After US1, stories US2–US6 can proceed in parallel **except** their `routes/web.php` tasks (T022, T028, T032, T036, T044, T047) — single shared file, strictly sequential, ordering re-checked at every story boundary

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Phase 1: resolve D1–D7, true up the YAML
2. Phase 2: services + datalog plumbing check
3. Phase 3: web domains CRUD
4. **STOP and VALIDATE**: Swagger "Try it out" (dev auto-auth), verify `sys_datalog` rows (incl. LE two-step) against a live ISPConfig daemon if available
5. Ship/demo; then add stories in priority order — each story boundary re-checks route ordering and leaves prior endpoints untouched

### Notes

- [P] = different files, no dependencies — never two `routes/web.php` edits in parallel
- Every DB write goes through `BaseModel::save()`/`delete()` (datalog); a task writing any other way violates constitution Principle II
- Commit after each task or logical group (short imperative subjects, e.g. "Implemented sites/web-domains")
- Do not implement anything absent from the (corrected) YAML — no invented endpoints, no invented response fields
