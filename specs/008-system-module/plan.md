# Implementation Plan: System Module (global config panels, directive snippets, resync)

**Branch**: `008-system-module` | **Date**: 2026-07-04 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/008-system-module/spec.md`

**Status**: Draft plan for a not-yet-implemented module, reverse-engineered from the existing (but defective — see spec "Contract defects") OpenAPI contract and the legacy ISPConfig source. Several NEEDS CLARIFICATION items in the spec gate parts of this plan.

## Summary

Implement the `system` module's 24 specced operations: (a) the `sys_ini` singleton settings blob exposed as one composite + five per-section GET/PUT config resources, (b) `directive_snippets` as conventional datalogged CRUD, (c) `POST /system/resync` as a datalog re-emission action plus its `GET /system/resync/servers` helper, and (d) `dns_ssl_ca` CRUD (blocked on a contract rewrite). Technical approach: a new `SystemConfigService` encapsulating INI parse/merge/serialize against a `SysIni` model (singleton, update-only, still through `BaseModel`); a plain `DirectiveSnippet` model/controller pair mirroring `MailDomain`; a `ResyncService` that performs legacy-faithful forced datalog emission via `DatalogService::log()` directly (BaseModel's no-change suppression makes `save()` unusable for resync), reusing `DnsSerialService` for the DNS path.

## Technical Context

**Language/Version**: PHP ^7.3|^8.0 (Laravel Lumen 8.3)
**Primary Dependencies**: laravel/lumen-framework, Eloquent ORM; dev: phpunit ^9.5, mockery, fakerphp
**Storage**: MySQL — ISPConfig's `dbispconfig` database (schema owned by ISPConfig; never migrated by this project; all writes via `sys_datalog`). Feature-specific tables: `sys_ini` (singleton blob), `directive_snippets`, `dns_ssl_ca`, `server` (read-only), plus the ~20 tables resync re-emits (read + datalog only).
**Testing**: PHPUnit (`vendor/bin/phpunit`) — the spec does not request tests; none planned (constitution: optional).
**Target Platform**: Linux server alongside an ISPConfig installation
**Project Type**: Contract-first REST API (monolith)
**Performance Goals**: `resync_all` on a large installation emits one datalog row per record across ~20 tables synchronously in one request — acceptable for v1 (admin-only tool, matches legacy which does the same in one page load); no queue infrastructure exists or is planned.
**Constraints**: async write semantics via `sys_datalog` (spec status codes: 201 create / 200 update / 204 delete / **204 for the resync action**); behavioral parity with legacy ISPConfig (`source_code/interface/web/admin/`, `source_code/interface/web/tools/resync.php`); INI blob round-trip must be losslessly readable by legacy `ini_parser`.
**Scale/Scope**: 10 paths / 24 operations; 4 entities (1 singleton, 2 CRUD tables, 1 read-only) + 1 action DTO; ~4 controllers, ~4 models, 2 new services.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: all endpoint YAMLs exist under `api/modules/system/` — **but the contract itself needs repair before code** (dangling `basicAuth` scheme; `/system/config/dns-cas/{id}` and `/system/resync/servers` unregistered in `api/openapi.yaml`; `mail-config.yaml` PUT response inconsistent; `SystemMailConfig.yaml` field names contradict the legacy key set; `DnsCaConfig.yaml` contradicts `dns_ssl_ca` outright). Phase-1 tasks fix the YAML first, then implement verbatim. Bodies already reference `api/components/schemas/`.
- [ ] **Datalog-only writes (II)** — ⚠️ three honest tensions, not silently passed:
  1. *Config blob*: fits — `SysIni extends BaseModel`, update-only; legacy datalogs the same way (`datalogUpdate('sys_ini', {config}, 'sysini_id', 1)`). The unconventional part is the write **unit** (whole INI blob per PUT), handled by `SystemConfigService` merge logic, not by bypassing `BaseModel`.
  2. *Resync + snippet `update_sites`*: legacy uses **forced** datalog updates (entry emitted with zero column changes). `BaseModel::save()` intentionally suppresses no-change datalogs, so these flows cannot use model `save()`; `ResyncService` will call `DatalogService::log()` directly with full-record `u` payloads. This respects the *invariant* (writes reach ISPConfig only through `sys_datalog`) while breaking the *letter* ("never roll custom save/delete logic") — recorded in Complexity Tracking; spec FR-010/FR-011 carry the NEEDS CLARIFICATION on blessing this vs. adding `BaseModel::forceDatalog()`. Note resync's re-emission writes **no table rows at all** (datalog inserts only), unlike `save()`.
  3. *`dns_ssl_ca` and the `use_domain_module` seeding*: legacy performs **direct SQL without datalog** for both (and legacy's `dns_ssl_ca` INSERT is literally broken SQL). Plan: route `DnsSslCa` through `BaseModel` anyway (harmless superset of legacy) and gate the `domain`-seeding side effect on its spec clarification (FR-019).
- [x] **Legacy parity (III)**: legacy implementation reviewed in depth — `admin/form/system_config.tform.php`, `admin/system_config_edit.php`, `admin/form/directive_snippets.tform.php` + edit/del + `validate_server_directive_snippets`, `lib/classes/plugin_system_config_dns_ca*.inc.php`, `lib/plugins/system_config_dns_ca_plugin.inc.php`, `tools/resync.php`, `lib/classes/ini_parser.inc.php`, `lib/classes/getconf.inc.php`. Validations/defaults/side effects captured in the spec's Parity section, including the ones the API schemas cannot reach (unexposed fields).
- [x] **Route discipline (IV)**: all routes inside the `api.auth` group in `routes/web.php`. Within `system/`, only two parameterized patterns exist (`system/config/dns-cas/{id}`, `system/directive-snippets/{id}`); every other path is literal, so shadowing risk is low — still register section routes and `system/resync/servers` before their parents by convention (see Structure Decision).
- [ ] **HTTP contract (V)** — ⚠️ one wording conflict: the module YAMLs (like every shipped module spec) declare list envelopes `{data, pagination: {...Laravel-style...}}` via `Pagination.yaml`, while Principle V's text says `{items,total,limit,offset}`. Per Principle I the YAML wins (this is also what all existing controllers ship). Additional per-spec quirks honored: config PUT returns 200 echoing the resource; resync POST returns 204; snippet POST declares 409 for duplicates. Errors `{message, error}` / 422 `{message, errors}` per constitution.
- [x] **No schema changes**: no migrations; `sys_ini`, `directive_snippets`, `dns_ssl_ca`, `server` used as-is.

## Project Structure

### Documentation (this feature)

```text
specs/008-system-module/
├── plan.md              # This file
├── spec.md              # Feature spec (reverse-engineered, Draft)
└── tasks.md             # Task list (/speckit-tasks output)
```

(No separate research.md/data-model.md — the Legacy Research and Key Entities sections of spec.md/plan.md carry that content for this brownfield reverse-engineering.)

### Source Code (repository root)

```text
api/
├── openapi.yaml                                   # FIX: register /system/config/dns-cas/{id} and /system/resync/servers path refs
├── modules/system/
│   ├── _index.yaml                                # exists — already references all 9 files
│   ├── system-config.yaml                         # FIX: drop dangling `security: basicAuth`
│   ├── sites-config.yaml                          # FIX: security block
│   ├── mail-config.yaml                           # FIX: security block + PUT 200 body ({success,message} → echo SystemMailConfig)
│   ├── dns-config.yaml                            # FIX: security block
│   ├── domains-config.yaml                        # FIX: security block
│   ├── misc-config.yaml                           # FIX: security block
│   ├── dns-cas-config.yaml                        # REWRITE pending clarification (multipart + schema mismatch)
│   ├── directive-snippets.yaml                    # implement as-is
│   └── resync.yaml                                # FIX: security block; (mailget gap pending clarification)
└── components/schemas/
    ├── SystemConfig.yaml                          # as-is
    ├── SystemSitesConfig.yaml                     # as-is (subset of legacy keys — confirmed by clarification)
    ├── SystemMailConfig.yaml                      # FIX: mailbox_show_* field names vs legacy key set (clarification)
    ├── SystemDnsConfig.yaml                       # as-is (matches legacy exactly)
    ├── SystemDomainsConfig.yaml                   # as-is (matches legacy exactly)
    ├── SystemMiscConfig.yaml                      # as-is (matches legacy exactly)
    ├── DirectiveSnippet.yaml                      # as-is (master_directive_snippets_id intentionally unexposed)
    ├── DnsCaConfig.yaml                           # REWRITE against dns_ssl_ca pending clarification
    └── ResyncRequest.yaml                         # pending clarification: add resync_mailget/mailget_server_id?

app/
├── Http/Controllers/Api/V1/
│   ├── SystemConfigController.php                 # NEW — show()/update() + showSection($section)/updateSection($section)
│   ├── DirectiveSnippetController.php             # NEW — index/show/store/update/destroy (mirror MailDomainController)
│   ├── DnsCaController.php                        # NEW — index/show/store/update/destroy (blocked on schema rewrite)
│   └── ResyncController.php                       # NEW — store() [POST /system/resync], servers() [GET /system/resync/servers]
├── Models/
│   ├── SysIni.php                                 # NEW — extends BaseModel; $table='sys_ini', $primaryKey='sysini_id'; fillable: config
│   ├── DirectiveSnippet.php                       # NEW — extends BaseModel; validation rules per legacy tform; YesNoBoolean casts
│   ├── DnsSslCa.php                               # NEW — extends BaseModel; UPPERCASE Y/N flags (YesNoBoolean unfit as-is)
│   └── Server.php                                 # NEW — extends BaseModel, read-only use; also heals MailDomain::server()
├── Services/
│   ├── SystemConfigService.php                    # NEW — parse_ini_string/get_ini_string ports, defaults, section merge, validation map
│   └── ResyncService.php                          # NEW — per-service table map (table, idx, server type, active-filter, order),
│                                                  #        forced datalog emission via DatalogService::log(), DNS serial path
└── Casts/                                         # possibly NEW UpperYesNoBoolean for dns_ssl_ca (or reuse strategy per clarification)

routes/web.php                                     # register system routes inside the api.auth group (see below)
```

**Structure Decision**: Routes slot into `routes/web.php` after the Monitor block, in this order (literal-specific before parameterized/general):

```php
// System - config sections (specific) before composite config (general)
GET/PUT  system/config/sites|mail|dns|domains|misc  → SystemConfigController@showSection/updateSection
GET/POST system/config/dns-cas                      → DnsCaController@index/store
GET/PUT/DELETE system/config/dns-cas/{id}           → DnsCaController@show/update/destroy
GET/PUT  system/config                              → SystemConfigController@show/update
// System - directive snippets
GET/POST system/directive-snippets                  → DirectiveSnippetController@index/store
GET/PUT/DELETE system/directive-snippets/{id}       → DirectiveSnippetController@show/update/destroy
// System - resync (servers helper before the action path for consistency)
GET      system/resync/servers                      → ResyncController@servers
POST     system/resync                              → ResyncController@store
```

Controller-convention deviation (flagged): `SystemConfigController` (singleton resource: `show/update` + section variants) and `ResyncController` (action: `store` returning 204 + a non-CRUD `servers` reader) do not fit the `index/show/store/update/destroy` set. Method names stay as close as possible (`show`/`update`/`store`); the extra methods (`showSection`, `updateSection`, `servers`) are the honest minimum for the specced surface.

## Legacy Research (Phase 0 — completed during reverse-engineering)

- **Form definitions**:
  - `system_config.tform.php`: `db_table=sys_ini`, `db_history=yes`, tabs `sites/mail/dns/domains/misc` (+ plugin-only `dns_ca` tab with empty `fields`). Full validator/default table extracted into spec Parity section. Note: the file defines `tabs['domains']` twice, but the second is inside a `/* TODO: Branding */` comment — effective tab set is the five above + dns_ca.
  - `directive_snippets.tform.php`: `db_history=yes`; `name` NOTEMPTY + custom uniqueness validator (per name+type) + STRIPTAGS/STRIPNL; `type` SELECT apache/nginx/php/proxy; `required_php_snippets` CHECKBOXARRAY sourced from active php snippets, comma separator.
  - `resync.tform.php`: `db_history=no` — pure action form, stores nothing.
- **Actions/side effects**:
  - `system_config_edit.php::onUpdateSave`: re-serializes the *entire* global config array and issues one `datalogUpdate('sys_ini', {config}, 'sysini_id', 1)`; plus non-datalogged side effects (client_protection perm rewrite, vhost_* disable guards, smtp_pass keep-old, session_timeout → `$app->conf`, maintenance_mode → session purge, use_domain_module → `REPLACE INTO domain` seeding). Most touch fields the API schemas don't expose; the two that are reachable (`maintenance_mode`, `use_domain_module`) carry NEEDS CLARIFICATION in the spec.
  - `directive_snippets_edit.php`: in-use guards on deactivate/hide (`onBeforeUpdate`), forced `web_domain` datalog re-emission when `update_sites=y && active=y` (`onAfterUpdate`); `directive_snippets_del.php::onBeforeDelete` blocks deleting in-use snippets (transitively for php snippets via `required_php_snippets` REGEXP).
  - `tools/resync.php::onSubmit`: the authoritative per-service map — table, index field, server-type column, per-table active-only vs all-rows, emission order (db: users before databases; mail: domain then spamfilter_policy; mailbox: mail_user then mail_forwarding; mailfilter: five tables; dns: rr serials then soa serial via `increase_serial`; client: full re-emit + interface plugin event the API cannot raise). `resync_all=1` expands all flags and propagates `all_server_id`. Legacy also supports `resync_mailget` (absent from `ResyncRequest.yaml`).
  - `plugin_system_config_dns_ca*.php`: `dns_ssl_ca` maintained with direct SQL (no datalog); INSERT statement syntactically broken in the vendored version (CA creation dead in legacy UI); table consumed by `system_config_dns_ca_plugin` to auto-create CAA `dns_rr` records on LE cert issuance and CAA record edits.
- **Permission checks**: system_config requires admin user type + `admin_allow_system_config`; snippets/resync require the admin module. API maps all of this to possession of a valid `X-API-Key` (consistent with all shipped modules).
- **List definition**: `admin/list/directive_snippets.list.php` → filterable columns inform the specced `type`/`active` query filters.
- **Blob format** (`ini_parser.inc.php`): `[section]` + `key=value` lines, values trimmed, no escaping/quoting, sections lowercased on parse, `get_ini_string` emits a trailing blank line per section. `getconf::get_global_config($section)` reads row `sysini_id=1` with `stripslashes()`.

## Complexity Tracking

> Filled because the Constitution Check has ⚠️ items.

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|--------------------------------------|
| `ResyncService` calls `DatalogService::log()` directly (bypasses `BaseModel::save()`) | Legacy resync/`update_sites` semantics are *forced* datalog emission with unchanged records; `BaseModel::save()` suppresses no-change datalogs by design, and `save()` would also re-write table rows resync must not touch | Extending `BaseModel` with a public `forceDatalog()` was considered — viable, but it grows the sacred base class for a single action feature; pending the spec's NEEDS CLARIFICATION, the service-level call keeps the blast radius in one file. Either resolution keeps all writes inside `sys_datalog` |
| Whole-blob write unit for `sys_ini` (one `config` string carries five schemas' worth of fields) | That is the physical storage model ISPConfig owns; per-key writes don't exist | Exposing raw `config` text would push INI parsing onto every consumer and break the specced section schemas |
| Possible direct `REPLACE INTO domain` on first `use_domain_module` enable (FR-019, unresolved) | Exact legacy parity of a reachable side effect | Datalogged inserts deviate from legacy observable behavior (extra datalog rows for `domain`); omission silently breaks the domain module — decision deferred to clarification, not taken unilaterally |
| Non-CRUD controller methods (`showSection`/`updateSection`/`servers`) | The specced surface is a singleton resource + an action endpoint; forcing them into `index/show/store/update/destroy` would misname operations | Separate controllers per section (5× boilerplate) or a fake "section id" parameter both obscure the contract |
