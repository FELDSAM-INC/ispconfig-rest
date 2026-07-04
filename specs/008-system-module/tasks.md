# Tasks: System Module (global config panels, directive snippets, resync)

**Input**: Design documents from `/specs/008-system-module/`
**Prerequisites**: plan.md, spec.md (this feature is DRAFT — several tasks are gated on the spec's NEEDS CLARIFICATION items; those gates are called out inline)

**Tests**: Not requested by the spec — no test tasks (constitution: tests optional).

**Organization**: Tasks grouped by user story (US1 config panels P1, US2 directive snippets P2, US3 resync P3, US4 DNS CAs P4), preceded by contract-repair Setup and shared Foundational phases.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1..US4
- Exact file paths in every task

## Path Conventions (this project)

| Artifact | Path |
|----------|------|
| OpenAPI endpoint spec | `api/modules/system/[resource].yaml` (registered in `api/modules/system/_index.yaml` — already done — and in `api/openapi.yaml` `paths:`) |
| OpenAPI schema | `api/components/schemas/[Entity].yaml` |
| Model | `app/Models/[Entity].php` — **must extend `App\Models\BaseModel`** |
| Controller | `app/Http/Controllers/Api/V1/[Entity]Controller.php` |
| Service | `app/Services/[Name]Service.php` |
| Cast | `app/Casts/[Name].php` |
| Routes | `routes/web.php` — inside the `api.auth` group, specific-before-general order |

**The per-resource implementation flow is always**: spec YAML → model → (service if needed) → controller → routes → Swagger verification.

---

## Phase 1: Setup — contract repair & clarifications

**Purpose**: The module's YAML predates implementation and contains defects (spec.md "Contract defects"). Spec-first means the YAML is fixed and clarifications resolved BEFORE any PHP.

- [ ] T001 Resolve spec clarifications with the maintainer and record decisions in `specs/008-system-module/spec.md`: (a) drop dangling `basicAuth` vs introduce it; (b) `mail-config.yaml` PUT 200 body alignment; (c) `SystemMailConfig.yaml` `mailbox_show_*` field-name drift vs legacy keys; (d) `DnsCaConfig.yaml` rewrite against `dns_ssl_ca` (blocks US4); (e) forced-datalog mechanism (`ResyncService` direct `DatalogService::log()` vs `BaseModel::forceDatalog()`); (f) `resync_client` interface-plugin-event gap; (g) `ResyncRequest.yaml` missing `resync_mailget`/`mailget_server_id`; (h) `use_domain_module` seeding side effect (FR-019); (i) `maintenance_mode` session-purge side effect; (j) `session_timeout` → `sys_config` side effect
- [ ] T002 Register missing path refs in `api/openapi.yaml` `paths:`: `/system/config/dns-cas/{id}` → `./modules/system/dns-cas-config.yaml#/~1system~1config~1dns-cas~1{id}` and `/system/resync/servers` → `./modules/system/resync.yaml#/~1system~1resync~1servers`
- [ ] T003 [P] Remove (or fix per T001a) the dangling `security: [basicAuth]` blocks in `api/modules/system/system-config.yaml`, `sites-config.yaml`, `mail-config.yaml`, `dns-config.yaml`, `domains-config.yaml`, `misc-config.yaml`, `resync.yaml` so operations inherit the global `apiKeyAuth`
- [ ] T004 [P] Align `api/modules/system/mail-config.yaml` PUT 200 response to echo `../../components/schemas/SystemMailConfig.yaml` (consistent with the four sibling panels) — per T001b
- [ ] T005 [P] Correct `api/components/schemas/SystemMailConfig.yaml` field set to the legacy `[mail]` keys (`mailbox_show_autoresponder_tab`, `mailbox_show_mail_filter_tab`, `mailbox_show_custom_rules_tab`, `mailbox_show_last_access`, …) — per T001c decision
- [ ] T006 [P] (Gated on T001d) Rewrite `api/components/schemas/DnsCaConfig.yaml` against table `dns_ssl_ca` (`id`, `ca_name`, `ca_issue` [unique], `ca_wildcard` Y/N, `ca_iodef`, `ca_critical`, `active` Y/N, readOnly `sys_*`) and change `api/modules/system/dns-cas-config.yaml` POST body from `multipart/form-data` to `application/json`
- [ ] T007 [P] (Gated on T001g) Add `resync_mailget` + `mailget_server_id` to `api/components/schemas/ResyncRequest.yaml` (or record the exclusion in spec.md) and strip the fictitious `x-db-field` annotations
- [ ] T008 Verify the full spec still parses after T002–T007: Swagger UI (`/api/documentation`) renders the System and Directive Snippets and Resync tags with all 24 operations, no `$ref` errors

**Checkpoint**: Contract is internally consistent and matches the clarified scope — PHP work may begin.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Models and services every story builds on.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete (except where a story-specific gate says otherwise).

- [ ] T009 [P] Create model `app/Models/SysIni.php` extending `BaseModel`: `$table='sys_ini'`, `$primaryKey='sysini_id'`, `$fillable=['config']`, `$hidden=['default_logo','custom_logo']`; no `sys_*`/`server_id` columns exist on this table (datalog entries will carry `server_id=0`, `sys_userid=1`)
- [ ] T010 [P] Create model `app/Models/DirectiveSnippet.php` extending `BaseModel`: `$table='directive_snippets'`, `$primaryKey='directive_snippets_id'`, fillable per `DirectiveSnippet.yaml` (excluding `master_directive_snippets_id`), attribute defaults (`customer_viewable='n'`, `active='y'`, `update_sites='n'`, `sys_userid=1`, `sys_groupid=1`, `sys_perm_user='riud'`, `sys_perm_group='riud'`, `sys_perm_other=''`), static validation rules mirroring legacy (NOTEMPTY name ≤255, `type` in `apache,nginx,php,proxy`, y/n enums, `required_php_snippets` CSV) — pattern: `app/Models/MailDomain.php`
- [ ] T011 [P] Create read-only model `app/Models/Server.php` extending `BaseModel`: `$table='server'`, `$primaryKey='server_id'`, casts for the `*_server` flag columns and `active`/`mirror_server_id` (this also heals the dangling `Server::class` reference in `app/Models/MailDomain.php::server()`)
- [ ] T012 Create service `app/Services/SystemConfigService.php`: port of legacy `ini_parser::parse_ini_string`/`get_ini_string` (CRLF-tolerant, `[section]`+`key=value`, trim, lowercase section on parse, trailing blank line per section on serialize, `stripslashes` on read per `getconf`), section defaults from the legacy tform table in spec.md, per-field validation-rule map (regexes/IP-list/enums/integer casts), and `mergeSection(string $section, array $changes)` performing read→merge→serialize while preserving unexposed keys
- [ ] T013 Create service `app/Services/ResyncService.php` (gated on T001e for the emission mechanism): per-service map `{table, index_field, server_type_column, active_filter (per-table), emission_order}` exactly as extracted from `source_code/interface/web/tools/resync.php` (see spec Parity "Tables written"); server-ID resolution (`0` → all `active=1 AND mirror_server_id=0` of type; unknown ID → error for controller's 400); forced full-record `u` emission via `App\Services\DatalogService::log()` (or `BaseModel::forceDatalog()` per T001e); DNS path via `App\Services\DnsSerialService` (`dns_rr` serial bumps per active zone, then `dns_soa` serial, legacy order); `resync_all` expansion incl. propagating `all_server_id`
- [ ] T014 Confirm datalog behavior end-to-end for the two writable entities: a `SysIni` update produces `dbtable=sys_ini`, `dbidx=sysini_id:1`, `action=u`; a `DirectiveSnippet` create/update/delete produces `directive_snippets` `i`/`u`/`d` — inspect `sys_datalog` rows directly

**Checkpoint**: Foundation ready — user story implementation can now begin.

---

## Phase 3: User Story 1 — Read and update the global settings panels (Priority: P1) 🎯 MVP

**Goal**: `GET/PUT /system/config` and `GET/PUT /system/config/{sites,mail,dns,domains,misc}` backed by the `sys_ini` blob, lossless for unexposed legacy keys, datalogged.

**Independent Test**: `GET /api/v1/system/config/dns` matches the live blob; `PUT` one key → 200 echo, one `sys_datalog` `sys_ini` row, byte-diff of `config` shows only that key changed.

### Implementation for User Story 1

- [ ] T015 [US1] Create `app/Http/Controllers/Api/V1/SystemConfigController.php` with `show()` (composite: `sysini_id` + five sections via `SystemConfigService`), `showSection($section)`, `update()` (accepts any subset of sections), `updateSection($section)` — reject unknown sections with 404; validation failures 422 `{message, errors}`; wrap PUT in a DB transaction with `SELECT ... FOR UPDATE` on the `sys_ini` row (lost-update guard); persist via `SysIni->save()` only; responses echo per the (fixed) YAML
- [ ] T016 [US1] Implement legacy-parity validation wiring in the controller/service: regex rules, `maintenance_mode_exclude_ips` IP-list check, `web_php_options` NOTEMPTY subset check, integer casts serialized back as strings, STRIPTAGS/STRIPNL-equivalent normalization for the text fields listed in spec Parity
- [ ] T017 [US1] (Gated on T001h/i/j) Implement — or explicitly document as deviations in spec.md — the reachable side effects: `use_domain_module` first-enable `domain` seeding, `maintenance_mode=y` session purge, `session_timeout` interface-config write
- [ ] T018 [US1] Register US1 routes in `routes/web.php` inside the `api.auth` group, after the Monitor block: the five literal `system/config/{section}` GET/PUT pairs first, then `system/config` GET/PUT (leave a marked slot between them for US4's `system/config/dns-cas` routes) — verify no shadowing of existing routes
- [ ] T019 [US1] Verify against Swagger UI (`/api/documentation`): all 12 config operations respond per `api/modules/system/*-config.yaml`; run the SC-002/SC-003 round-trip check (blob byte-diff + legacy `ini_parser` parseability)

**Checkpoint**: Config panels fully functional and independently testable — MVP delivered.

---

## Phase 4: User Story 2 — Manage directive snippets (Priority: P2)

**Goal**: Conventional datalogged CRUD for `directive_snippets` with legacy guards and the `update_sites` re-emission.

**Independent Test**: POST→201+datalog `i`; list filtered by `type`/`active`; PUT rename→200; DELETE→204+datalog `d`; duplicate (name,type) POST→409; in-use DELETE→400.

### Implementation for User Story 2

- [ ] T020 [US2] Implement `index`/`show` in `app/Http/Controllers/Api/V1/DirectiveSnippetController.php` — list envelope `{data, pagination}` per `Pagination.yaml`, `offset/limit/order/sort` per the shared parameter components, filters `type` (enum) and `active` (y/n); 404 `{message}` for missing ID (mirror `MailDomainController`)
- [ ] T021 [US2] Implement `store`/`update`/`destroy` in the same controller: Lumen validation from `DirectiveSnippet::getValidationRules()`; (name,type) uniqueness → **409** on create per YAML; in-use guards (apache/nginx: `web_domain.directive_snippets_id`; php: transitive via `required_php_snippets` REGEXP `id|,id|id,` as legacy) blocking DELETE (400), deactivation, and `customer_viewable` y→n; `required_php_snippets` entries must be active php snippet IDs; writes via model `save()`/`delete()` only; 201/200/204; transactions + rollback + `{message, error}` on 500
- [ ] T022 [US2] Implement the `update_sites=y && active=y` post-update hook: resolve affected `web_domain` rows (legacy queries) and force-emit full-record `u` datalog entries for each via `ResyncService`'s emission primitive (shared with US3; do not duplicate the mechanism)
- [ ] T023 [US2] Register routes in `routes/web.php`: `GET/POST system/directive-snippets`, `GET/PUT/DELETE system/directive-snippets/{id}` after the US1 block — re-verify group ordering
- [ ] T024 [US2] Verify against Swagger UI: all 5 operations match `api/modules/system/directive-snippets.yaml` (params, filters, 409/400 cases); confirm `sys_datalog` `i`/`u`/`d` payloads are legacy-processable

**Checkpoint**: US1 and US2 work independently.

---

## Phase 5: User Story 3 — Trigger a service resync (Priority: P3)

**Goal**: `POST /system/resync` action (forced datalog re-emission per legacy map) + `GET /system/resync/servers`.

**Independent Test**: Seeded web domain → `{"resync_sites":1,"web_server_id":0}` → 204 + one forced `u` datalog row per active `web_domain`; `?server_type=web` server listing.

### Implementation for User Story 3

- [ ] T025 [US3] Implement `app/Http/Controllers/Api/V1/ResyncController.php::store()`: validate `ResyncRequest` fields (flags 0/1, server IDs integer; unknown server ID → 400 `{message, error}`), delegate to `ResyncService` inside one DB transaction, return 204 no-body; document in a code comment that 204 = datalog rows written, application is async (Constitution II)
- [ ] T026 [US3] Implement `ResyncController::servers()`: paginated `{data, pagination}` list over `app/Models/Server.php` with `server_type` filter (`web|mail|dns|db|file|vserver` → `{type}_server = 1`), `active` filter, default legacy candidate rule `active=1 AND mirror_server_id=0`, `offset/limit/order/sort`
- [ ] T027 [US3] (Gated on T001f) Implement `resync_client` full re-emission and record the interface-plugin-event gap (`client:client:on_after_update` cannot be raised from this API) as a documented deviation in spec.md — or exclude the flag per clarification
- [ ] T028 [US3] Register routes in `routes/web.php`: `GET system/resync/servers` **before** `POST system/resync`, after the US2 block — re-verify ordering
- [ ] T029 [US3] Verify against Swagger UI: both operations match `api/modules/system/resync.yaml`; run SC-005 (datalog row counts per table vs legacy tool on the same seeded dataset; DNS serials strictly increase; `resync_db` order: `web_database_user` before `web_database`)

**Checkpoint**: US1–US3 work independently.

---

## Phase 6: User Story 4 — Manage DNS Certification Authorities (Priority: P4)

**⚠️ BLOCKED until T001d/T006 (schema rewrite) is resolved — do not start on the current `DnsCaConfig.yaml`.**

**Goal**: CRUD for `dns_ssl_ca` at `/system/config/dns-cas[/{id}]`.

**Independent Test**: POST a CA → 201; duplicate `ca_issue` rejected; PUT `active`; DELETE → 204.

### Implementation for User Story 4

- [ ] T030 [US4] Create model `app/Models/DnsSslCa.php` extending `BaseModel`: `$table='dns_ssl_ca'`, `$primaryKey='id'`, fillable per rewritten schema, `ca_issue` uniqueness rule; handle the **uppercase** `'Y'/'N'` enums (`active`, `ca_wildcard`) — add `app/Casts/UpperYesNoBoolean.php` if the rewritten schema exposes booleans, else validate raw `Y/N` strings (decide with T006)
- [ ] T031 [US4] Implement `app/Http/Controllers/Api/V1/DnsCaController.php` `index/show/store/update/destroy`: list `{data, pagination}` with shared params; 201/200/204; duplicate `ca_issue` → 400/409 per rewritten YAML; writes via model `save()`/`delete()` (datalogged — deliberate superset of legacy's direct-SQL behavior, per spec User Story 4 clarification)
- [ ] T032 [US4] Register routes in `routes/web.php` in the reserved US1 slot: `GET/POST system/config/dns-cas` and `GET/PUT/DELETE system/config/dns-cas/{id}` **before** the general `system/config` routes (literal `dns-cas` segment must not be swallowed if `system/config/{section}` ever becomes parameterized) — re-verify ordering
- [ ] T033 [US4] Verify against Swagger UI: all 5 operations match the rewritten `api/modules/system/dns-cas-config.yaml`, including the `/{id}` path registered by T002

**Checkpoint**: All four user stories independently functional.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [ ] T034 [P] Update `README.md` endpoint list with the 10 new system-module paths
- [ ] T035 [P] Code cleanup pass: controllers thin (INI/merge logic only in `SystemConfigService`, emission logic only in `ResyncService`), y/n handling via casts not ad-hoc string checks, no duplicated affected-sites queries between US2 and US3
- [ ] T036 Re-verify legacy parity for the documented validation cases (spec Parity validator table) against a stock ISPConfig instance where feasible; update spec.md "Intentional deviations" with anything found
- [ ] T037 Grep-audit Constitution II (SC-006): no `DB::table(...)->insert/update/delete` against ISPConfig tables outside `DatalogService` (and any explicitly clarified FR-019 exception); every model in the diff extends `BaseModel`
- [ ] T038 Run `vendor/bin/phpunit` (existing suite must still pass) and confirm Swagger UI renders the whole spec without errors

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: T001 first (clarifications gate T004–T007 details); T002–T007 then parallelizable; T008 last
- **Foundational (Phase 2)**: depends on Phase 1; T009–T011 parallel; T012 after T009; T013 after T001e decision; T014 after T009+T010 — BLOCKS all user stories
- **US1 (Phase 3)**: after Phase 2 (needs T009, T012); T017 gated on T001h/i/j
- **US2 (Phase 4)**: after Phase 2 (needs T010); T022 needs T013's emission primitive
- **US3 (Phase 5)**: after Phase 2 (needs T011, T013); T027 gated on T001f
- **US4 (Phase 6)**: HARD-gated on T001d + T006; then after Phase 2
- **Polish (Phase 7)**: after all delivered stories

### Within Each User Story

- Spec YAML fixes before controller work (Principle I)
- Model before controller; controller before routes; Swagger verification last
- `routes/web.php` tasks (T018, T023, T028, T032) are **sequential** — single shared file, ordering matters; never parallel

### Parallel Opportunities

- T003–T007 (different YAML files); T009–T011 (different model files); T034–T035
- US2 and US3 can proceed in parallel after Phase 2 **except** their `routes/web.php` tasks and the shared emission primitive in T013/T022

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Phase 1 (contract repair + clarifications) → Phase 2 → Phase 3
2. **STOP and VALIDATE**: Swagger "Try it out" on all 12 config operations; SC-002 blob byte-diff; `sys_datalog` inspection
3. Ship config panels alone if needed — snippets/resync/CAs are additive

### Incremental Delivery

1. US2 next (cleanest constitutional fit, full CRUD), then US3, then US4 once unblocked
2. Re-check `routes/web.php` ordering at every story boundary

---

## Notes

- Total: 38 tasks; 0 completed (feature entirely unbuilt)
- Every DB write path goes through `BaseModel::save()`/`delete()` or `DatalogService::log()` (forced re-emission per plan Complexity Tracking) — anything else violates the constitution
- Do not implement anything the YAML does not declare (e.g., no DELETE on config resources, no GET on `/system/resync`)
- Commit after each task or logical group
