# Implementation Plan: Sites Module

**Branch**: `006-sites-module` | **Date**: 2026-07-04 | **Spec**: `specs/006-sites-module/spec.md`
**Input**: Feature specification from `/specs/006-sites-module/spec.md`

## Summary

Implement the entire (fully specced, entirely unbuilt) sites module: 10 resources / 54 operations over ISPConfig's `web_domain`, `ftp_user`, `shell_user`, `web_database`, `web_database_user`, `cron`, `web_folder`, `web_folder_user`, `webdav_user` tables. The OpenAPI contract already exists under `api/modules/sites/` and is registered in `api/openapi.yaml`; the work is pure PHP: one `BaseModel` subclass + one controller per resource, three shared services (sites config/prefixes, password hashing, web-domain provisioning defaults), and route registration. Pattern to mirror: `app/Http/Controllers/Api/V1/MailDomainController.php` + `app/Models/MailDomain.php` (model-held validation rules, `Validator::make`, DB transaction + rollback + `\Log::error`, direct HTTP responses). Eight NEEDS CLARIFICATION items (spec FR-012/015/028/039/040/041/042/043) gate parts of the work — most importantly contract metadata fixes (`web_cron`→`cron`, wrong `x-db-field` PKs, phantom `web_domain` columns) and the delete-cascade-vs-400 decision.

## Technical Context

**Language/Version**: PHP ^7.3|^8.0 (Laravel Lumen 8.3)
**Primary Dependencies**: laravel/lumen-framework, Eloquent ORM; dev: phpunit ^9.5, mockery, fakerphp
**Storage**: MySQL — ISPConfig's `dbispconfig` database (schema owned by ISPConfig; never migrated by this project; all writes via `sys_datalog`)
**Testing**: PHPUnit (`vendor/bin/phpunit`) — optional per constitution; the spec does not request tests
**Target Platform**: Linux server alongside an ISPConfig installation
**Project Type**: Contract-first REST API (monolith)
**Performance Goals**: N/A (CRUD volumes; heaviest call is web-domain delete cascade — bounded by per-site record counts)
**Constraints**: async write semantics via `sys_datalog` (201 create / 200 update / 204 delete); behavioral parity with `source_code/interface/web/sites/` incl. derived fields, prefixing, and password hash formats; read-only access to serialized server config (`server` table) and sites global config (`sys_ini`) needed for `document_root` templates and name prefixes
**Scale/Scope**: 10 resources, 54 operations, 10 models, 10-11 controllers, 3 services, ~50 routes

## Constitution Check

*GATE: pass-by-design for planned work; re-verify at implementation checkpoints.*

- [x] **Spec-first (I)**: all `api/modules/sites/*.yaml` definitions exist and are registered in `api/openapi.yaml`; bodies reference `api/components/schemas/*`. **Caveat**: spec FR-041 lists schema metadata errors (wrong `x-db-table`/`x-db-field`, phantom `web_domain` columns) that must be fixed in the YAML *first* — the plan treats the corrected YAML as the contract. No URL patterns or response shapes are invented in PHP.
- [x] **Datalog-only writes (II)**: every model extends `App\Models\BaseModel`; multi-record flows (LE two-step create, database→database-user `server_id` sync, folder-user cascade, web-domain delete cascade) are expressed as multiple `save()`/`delete()` calls on BaseModel instances — never raw `DB::table()->update/insert/delete` on ISPConfig tables. Reads of `server`/`sys_ini` config are read-only and allowed.
- [x] **Legacy parity (III)**: `source_code/interface/web/sites/` reviewed file-by-file; validations/defaults/side effects captured in spec "ISPConfig Parity" (15 numbered behaviors) and FR-007…FR-037. Deviations are enumerated (folder/webdav restricted updates, no client-limit enforcement) or flagged NEEDS CLARIFICATION.
- [x] **Route discipline (IV)**: all routes go into the existing `api.auth` group in `routes/web.php`; ordering constraint derived from the YAML paths: `sites/web-domains/{id}/ssl/renew` before `sites/web-domains/{id}/ssl` before `sites/web-domains/{id}` (see Structure Decision). All other sites paths have distinct literal prefixes and cannot shadow each other or existing routes.
- [x] **HTTP contract (V)**: 201/200/204 write codes as declared; errors `{message, error}` / 422 `{message, errors}`. **Flagged conflict** (spec FR-040): the sites YAMLs declare `{data, pagination}` list shapes (like the mail/dns controllers) while the constitution text says `{items,total,limit,offset}` — resolution is a gating decision (D4 below); whichever wins, spec YAML and PHP must agree.
- [x] **No schema changes**: no migrations; `database/` untouched; `created_at`/`updated_at` absent from ISPConfig tables → models keep `$timestamps = false` (spec FR-039).

**Gating decisions to resolve before coding** (from spec):

| # | Decision | Spec ref | Recommendation |
|---|----------|----------|----------------|
| D1 | Fix schema metadata (`cron` table, real PKs, remove/rename phantom `web_domain` fields, `php`/`stats_type` enums, `hd_quota` bounds) | FR-041 | Fix YAML; contract must describe the real DB |
| D2 | Web-domain DELETE: legacy cascade vs contract 400 refusal | FR-012/FR-042 | Legacy cascade (Principle III); update YAML description |
| D3 | SSL renew endpoint semantics; CA/chain fields vs single `ssl_bundle` column | FR-013…FR-015 | Renew = forced datalog `u` guarded by `ssl_letsencrypt=y`; map both CA fields to `ssl_bundle` or collapse in YAML |
| D4 | List shape `{data,pagination}` vs `{items,total,limit,offset}`; `page/per_page` vs declared `limit/offset`; `field[op]=` filter prose | FR-040 | Implement declared YAML (`{data,pagination}`, honor `limit`/`offset`), delete the misleading description prose |
| D5 | `y`/`n` strings vs `YesNoBoolean` booleans in responses | FR-043 | Raw `y`/`n` (contract enums); do not attach `YesNoBoolean` casts in this module |
| D6 | `Database.type=mongo` and `parent_domain_id=0` standalone databases | FR-028, US3 #4 | Restrict to legacy behavior (mysql/postgresql; parent required); update YAML |
| D7 | `created_at`/`updated_at` in schemas | FR-039 | Remove from schemas |

## Project Structure

### Documentation (this feature)

```text
specs/006-sites-module/
├── spec.md              # Feature spec (this feature — done)
├── plan.md              # This file
└── tasks.md             # Task list (/speckit-tasks output)
```

(No separate research.md/data-model.md — legacy research is summarized below and in the spec's Parity section; the data model is ISPConfig's, documented per-entity in spec Key Entities.)

### Source Code (repository root)

```text
api/
├── openapi.yaml                          # Already references all sites paths — no change expected
├── modules/sites/                        # EXISTING contract; edits only per gating decisions D1-D7
│   ├── _index.yaml
│   ├── web-domains.yaml                  # D2 (delete wording), D3 (ssl), D4 (list prose)
│   ├── web-child-domains.yaml            # D4
│   ├── ftp-users.yaml                    # D4
│   ├── shell-users.yaml                  # D4
│   ├── databases.yaml                    # D4, D6
│   ├── database-users.yaml               # D4
│   ├── cron-jobs.yaml                    # D4
│   ├── web-folders.yaml                  # D4
│   ├── web-folder-users.yaml             # D4
│   └── webdav-users.yaml                 # D4
└── components/schemas/                   # EXISTING; edits per D1/D6/D7
    ├── WebDomain.yaml                    # D1 (phantom columns, enums, quota), D7
    ├── WebChildDomain.yaml               # D7
    ├── FtpUser.yaml                      # D7
    ├── ShellUser.yaml                    # D7
    ├── Database.yaml                     # D1, D6, D7
    ├── DatabaseUser.yaml                 # (verified correct vs web_database_user)
    ├── CronJob.yaml                      # D1: x-db-table cron; D7
    ├── WebFolder.yaml                    # D1: x-db-field web_folder_id; D7
    ├── WebFolderUser.yaml                # D1: x-db-field web_folder_user_id; D7
    └── WebdavUser.yaml                   # D1: x-db-field webdav_user_id; D7

app/
├── Http/Controllers/Api/V1/              # FUTURE (none exist yet)
│   ├── WebDomainController.php           # index/show/store/update/destroy
│   ├── WebDomainSslController.php        # show/store/destroy + renew (ssl subresource)
│   ├── WebChildDomainController.php
│   ├── FtpUserController.php
│   ├── ShellUserController.php
│   ├── WebDatabaseController.php
│   ├── WebDatabaseUserController.php
│   ├── CronJobController.php
│   ├── WebFolderController.php
│   ├── WebFolderUserController.php
│   └── WebdavUserController.php
├── Models/                               # FUTURE — all extend BaseModel, explicit $table/$primaryKey,
│   │                                     #   static $rules + getValidationRules($id) like MailDomain
│   ├── WebDomain.php                     # web_domain / domain_id
│   ├── WebChildDomain.php                # web_domain / domain_id, global scope type IN (subdomain, alias)
│   ├── FtpUser.php                       # ftp_user / ftp_user_id
│   ├── ShellUser.php                     # shell_user / shell_user_id
│   ├── WebDatabase.php                   # web_database / database_id
│   ├── WebDatabaseUser.php               # web_database_user / database_user_id
│   ├── CronJob.php                       # cron / id        (NOT web_cron — D1)
│   ├── WebFolder.php                     # web_folder / web_folder_id
│   ├── WebFolderUser.php                 # web_folder_user / web_folder_user_id
│   └── WebdavUser.php                    # webdav_user / webdav_user_id
└── Services/                             # FUTURE shared business logic
    ├── SitesConfigService.php            # reads sys_ini sites config (ftpuser_prefix, shelluser_prefix,
    │                                     #   dbname_prefix, dbuser_prefix, webdavuser_prefix, misc.ssh_authentication)
    │                                     #   + server web/server config (website_path, htaccess_allow_override,
    │                                     #   php_open_basedir, enable_sni, log_retention); prefix resolution
    │                                     #   (replacePrefix/getPrefix port from tools_sites)
    ├── PasswordHashService.php           # CRYPT (sha512-crypt), MYSQL (*SHA1), MYSQLSHA2,
    │                                     #   POSTGRESHA256, webdav md5(user:dir:pass) digest
    └── WebDomainProvisionService.php     # document_root/system_user/system_group/php_open_basedir
                                          #   generation (id_hash placeholders), LE two-step create,
                                          #   delete cascade (per D2), parent-derived field sync helper
                                          #   reused by ftp/shell/cron/webdav/folder controllers

routes/web.php                            # FUTURE additions inside the api.auth group (ordering below)
```

**Model naming note**: schema files are `Database.yaml`/`DatabaseUser.yaml` but the PHP models are named `WebDatabase`/`WebDatabaseUser` after their tables (`web_database`, `web_database_user`) to avoid collision/confusion with Laravel's `DB`/database namespace; controllers follow the models (`WebDatabaseController`, `WebDatabaseUserController`). All other model names match their schema names.

**Structure Decision — routes/web.php ordering**: append one block after the existing "Monitor - DataLog endpoints" block:

```php
// Sites - Web Domain SSL endpoints (most specific first)
POST   sites/web-domains/{id}/ssl/renew   → WebDomainSslController@renew
GET    sites/web-domains/{id}/ssl         → WebDomainSslController@show
POST   sites/web-domains/{id}/ssl         → WebDomainSslController@store
DELETE sites/web-domains/{id}/ssl         → WebDomainSslController@destroy
// Sites - Web Domains
GET/POST sites/web-domains, GET/PUT/DELETE sites/web-domains/{id}
// Sites - Web Child Domains, FTP Users, Shell Users, Databases, Database Users,
// Cron Jobs, Web Folders, Web Folder Users, WebDAV Users — each standard 5-route block
```

Constraints verified against the actual YAML paths: the only nesting is under `sites/web-domains/{id}` — `…/ssl/renew` MUST precede `…/ssl`, which MUST precede the bare `{id}` routes. `sites/web-domains` vs `sites/web-child-domains`, and `sites/web-folders` vs `sites/web-folder-users`, are distinct literal segments (no `{param}` in the first segment), so they cannot shadow each other in Lumen/FastRoute; blocks are still registered specific-literal-first to satisfy the constitution's reviewable ordering rule. No existing route starts with `sites/`, so no interaction with client/dns/mail/monitor routes.

## Legacy Research (Phase 0 focus) — summary of findings

Full detail lives in spec.md ("Subtle legacy behaviors", 15 items). Condensed map of where each implementer-relevant fact came from:

- **Form definitions** (`form/*.tform.php`): field lists, regex validators, defaults, `y/n` checkbox values, `encryption` types (CRYPT/MYSQL/MYSQLSHA2/POSTGRESHA256/CLEARTEXT), datasource-implied FK targets (parent domain queries filter `type='vhost'`; database server queries filter `db_server=1`; vhost server queries filter `web_server=1`), IDN/TOLOWER/TRIM/NORMALIZEPATH save filters, `valuelimit` hooks (client `web_php_options`, `ssh_chroot`, `limit_cron_type`).
- **Edit actions** (`*_edit.php`): server-derived fields (`server_id`, `dir`, `uid/gid`, `puser/pgroup`, `sys_groupid` always from parent), name prefixing on insert + prefix preservation on update, blacklists (shell username file, DB name/user lists), length caps (shell 32, dbuser 32, dbname 64), immutability rules (database name/charset/server; webdav username/dir; web_domain server), cron type derivation and frequency limits, database remote-IP auto-append + linked-user datalog touch, web_domain derived-field generation and LE two-step insert.
- **Delete actions** (`*_del.php`): web_vhost_domain delete cascade (children, ftp, shell, cron, webdav, backups, APS, folders+users; databases detached not deleted); other resources delete plainly with `d` permission check.
- **Validators** (`interface/lib/classes/validate_cron.inc.php`): exact `run_time_format`/`run_month_format`/`command_format` logic to port.
- **DB schema** (`install/sql/ispconfig3.sql` lines 440, 709, 1481, 1888, 1931, 1965, 1988, 2090, 2115): authoritative table/column/PK/default verification; source of the FR-041 contract-error list.
- **Permission checks**: legacy enforces per-client limits and `sys_perm` group access via session auth; the REST API is admin-scoped by API key (spec Assumption 4) — only `sys_*` field *population* is mirrored, not interactive permission gating.

## Complexity Tracking

> Constitution Check has no violations. One design liberty documented:

| Item | Why Needed | Simpler Alternative Rejected Because |
|------|------------|--------------------------------------|
| Read-only access to `server.config` / `sys_ini.config` (serialized INI blobs) via `SitesConfigService` | document_root templates, prefixes, SNI flag, ssh_authentication mode are stored there; parity is impossible without them | Hard-coding defaults (`/var/www/clients/client[client_id]/web[website_id]`, `web[website_id]_` prefixes) would silently diverge from per-installation configuration |
| Second controller for the SSL subresource (`WebDomainSslController`) | 4 non-RESTful operations on `web_domain` (`show/store/destroy/renew` against `{id}/ssl*`) don't fit the 5-method set of `WebDomainController` | Stuffing 9 actions into one controller violates the thin-controller convention and the RESTful method-set rule |
