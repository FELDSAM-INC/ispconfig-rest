---

description: "Task list for the server module (reverse-engineered draft — feature not yet implemented)"
---

# Tasks: Server Module

**Input**: Design documents from `/specs/007-server-module/` (spec.md, plan.md)
**Prerequisites**: plan.md (required), spec.md (required for user stories); contract already exists under `api/modules/server/`

**Tests**: Not requested by the spec (constitution: optional) — no test tasks included. Verification is Swagger UI "Try it out" + `sys_datalog` inspection per story checkpoints.

**Organization**: Tasks are grouped by user story (US1–US7 from spec.md) to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1…US7)
- Include exact file paths in descriptions

## Path Conventions (this project)

| Artifact | Path |
|----------|------|
| OpenAPI endpoint spec | `api/modules/server/[resource].yaml` (registered in `api/modules/server/_index.yaml` + root `api/openapi.yaml`) |
| OpenAPI schema | `api/components/schemas/[Entity].yaml` |
| Model | `app/Models/[Entity].php` — **must extend `App\Models\BaseModel`** |
| Controller | `app/Http/Controllers/Api/V1/[Entity]Controller.php` |
| Service | `app/Services/ServerConfigService.php` |
| Routes | `routes/web.php` — inside the `api.auth` group, specific-before-general order |

**The per-resource implementation flow is always**: spec YAML → model → (service if needed) → controller → routes → Swagger verification.

---

## Phase 1: Setup (contract corrections & clarification gate)

**Purpose**: Make the existing contract implementable and resolve every NEEDS CLARIFICATION before PHP is written. The YAMLs are the source of truth — they get fixed first, code mirrors them after.

- [ ] T001 Resolve FR-014/FR-015 (Server schema vs `server` table): decide fate of phantom `ip_address`/`hostname` (drop vs derive read-only from config blob) and of hidden real columns (`proxy_server`, `firewall_server`, `updated`, `dbversion`); record decisions in `specs/007-server-module/spec.md` and amend `api/components/schemas/Server.yaml` accordingly
- [ ] T002 [P] Resolve FR-022: remove (or explicitly mark ignored) the phantom `active` property in `api/components/schemas/ServerIp.yaml` — `server_ip` has no such column (`source_code/install/sql/ispconfig3.sql`)
- [ ] T003 [P] Resolve FR-052: define semantics of `POST /servers/{id}/configs` (201) and `DELETE /servers/{id}/configs` (204) in `api/modules/server/server-config.yaml` (initialize-blob / reset-blob vs removing the operations); record decision in spec.md
- [ ] T004 [P] Resolve FR-053/FR-054: reconcile `api/components/schemas/ServerConfig.yaml` and the ten `Server*Config.yaml` section schemas with the legacy field inventories extracted from `source_code/interface/web/admin/form/server_config.tform.php` (server/mail/web/dns/fastcgi/xmpp/jailkit/ufw/vlogger/cron/rescue); document the ufw field mapping (legacy tab is commented out — `ufw_enable`/`ufw_default_input_policy`/... vs contract `ufw_enabled`/`ufw_default_incoming`/...)
- [ ] T005 [P] Fix FR-063 path-parameter mismatches: rename `server_id` → `id` on PUT/DELETE in `api/modules/server/servers.yaml` and on POST/PUT/DELETE `/servers/{id}/configs` + PUT `/servers/{id}/configs/mail` in `api/modules/server/server-config.yaml`
- [ ] T006 [P] Fix FR-062: register `/servers/{id}/configs/web`, `/servers/{id}/configs/dns`, `/servers/{id}/configs/fastcgi`, `/servers/{id}/configs/xmpp` in the `paths` section of `api/openapi.yaml`
- [ ] T007 [P] Fix FR-064 schema hygiene: `x_db_table` → `x-db-table` and add `sys_*` properties in `api/components/schemas/ServerFirewall.yaml`; normalize `x_db_table` in `ServerConfig*.yaml`; align `active` typing between `Server.yaml` (integer 0/1 — matches DB) and `ServerConfig.yaml`
- [ ] T008 Verify the corrected spec parses: Swagger UI (`/api/documentation`) renders the full server module, all 22 paths visible, no `$ref` resolution errors
- [ ] T009 [P] Confirm remaining behavioral clarifications and record answers in spec.md: FR-031 (`source_ip` membership check vs NOTEMPTY-only), FR-051 (replicate rspamd re-sync side effect?), FR-061 (enforce `web_server=1 AND mirror_server_id=0` for PHP versions?)

**Checkpoint**: Contract is self-consistent and every open decision is recorded — implementation may begin.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Models and the config service that every user story depends on.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [ ] T010 Create `app/Models/Server.php` extending `BaseModel`: `$table='server'`, `$primaryKey='server_id'`; fillable `server_name`, `{mail,web,dns,file,db,vserver,xmpp}_server` (+ `proxy_server`/`firewall_server` if T001 exposes them), `mirror_server_id`, `active`, `config`, sys fields; integer casts for 0/1 flags (NO `YesNoBoolean` — this table uses integers); defaults per legacy auth_preset (`sys_groupid=1`, perms `riud`/`riud`/`''`, flags 0, `active` 1, `mirror_server_id` 0); `getValidationRules($id=null)` following `app/Models/MailDomain.php` pattern; hide `config` from JSON serialization (exposed only via config endpoints). NOTE: this class name is already referenced by `app/Models/MailDomain.php::server()` — creating it repairs that dangling relation
- [ ] T011 [P] Create `app/Models/ServerIp.php` extending `BaseModel`: `$table='server_ip'`, `$primaryKey='server_ip_id'`; fillable server_id, client_id, ip_type, ip_address, virtualhost, virtualhost_port, sys fields; defaults `ip_type='IPv4'`, `virtualhost='y'`, `virtualhost_port='80,443'`, `client_id=0`; rules: `ip_type in:IPv4,IPv6`, `ip_address` required + unique + custom per-type `FILTER_VALIDATE_IP` check (mirror `validate_server::check_server_ip`), `virtualhost in:y,n`, `virtualhost_port regex:/^([0-9]{1,5}\,{0,1}){1,}$/i`, `client_id integer|exists:client,client_id` when nonzero
- [ ] T012 [P] Create `app/Models/ServerIpMap.php` extending `BaseModel`: `$table='server_ip_map'`, `$primaryKey='server_ip_map_id'`; fillable server_id, source_ip, destination_ip, active, sys fields; default `active='y'`; rules: `source_ip required|string|max:15` (+ membership check if T009 decides so), `destination_ip required|ipv4` (legacy ISIPV4), `active in:y,n`
- [ ] T013 [P] Create `app/Models/Firewall.php` extending `BaseModel`: `$table='firewall'`, `$primaryKey='firewall_id'`; fillable server_id, tcp_port, udp_port, active, sys fields; default `active='y'`; rules: `server_id unique:firewall,server_id` (409 semantics handled in controller), `tcp_port`/`udp_port` `regex:/^$|\d{1,5}(?::\d{1,5})?(?:,\d{1,5}(?::\d{1,5})?)*$/` with present-but-empty allowed, `active in:y,n`
- [ ] T014 [P] Create `app/Models/ServerPhp.php` extending `BaseModel`: `$table='server_php'`, `$primaryKey='server_php_id'`; fillable server_id, client_id, name, php_fastcgi_{binary,ini_dir}, php_fpm_{init_script,ini_dir,pool_dir,socket_dir}, php_cli_binary, php_jk_section, active, sortprio, sys fields; defaults `active='y'`, `sortprio=100`, `client_id=0`; rules: `name required` (strip tags/newlines on save), `php_cli_binary required|regex:/^\/[a-zA-Z0-9\/\-\_\.\s]*$/`, `php_jk_section required|regex:/^[a-zA-Z0-9\-\_]*$/`, path fields `nullable|string|max:255`
- [ ] T015 Create `app/Services/ServerConfigService.php`: ISPConfig-compatible `parse(string $blob): array` and `serialize(array $config): string` (mirror `source_code/interface/lib/classes/ini_parser.inc.php` semantics incl. `stripslashes` on read — `getconf::get_server_config`); `getConfig(Server $server): array`, `getSection(Server $server, string $section): array`, `updateSection(Server $server, string $section, array $data): Server` implementing whole-blob read-modify-write that preserves untouched/unknown sections (`server`, `getmail`, ...); checkbox-default backfill; mail-section guards (`mailbox_size_limit` vs `message_size_limit`, `rspamd_available` forced from stored blob); rspamd content_filter re-sync side effect per T009 decision (datalog-update `spamfilter_users`/`spamfilter_wblist` rows via their models or documented direct-read + datalog path)
- [ ] T016 Confirm datalog behavior for all five models: temporary tinker/dev-route exercise of `save()`/`delete()` produces correct `sys_datalog` rows (table name, PK name/value, action, old/new payload; for `server` the datalog `server_id` equals the record's own PK)

**Checkpoint**: Foundation ready — user story implementation can now begin.

---

## Phase 3: User Story 1 - Discover and inspect servers (Priority: P1) 🎯 MVP

**Goal**: Read-only server inventory: `GET /servers`, `GET /servers/{id}`.

**Independent Test**: `curl -H "X-API-Key: ..." /api/v1/servers` and `/api/v1/servers/1` — pagination envelope, field set per corrected `Server.yaml`, 404 on missing id, 401 without key.

- [ ] T017 [US1] Create `app/Http/Controllers/Api/V1/ServerController.php` with `index` (pagination `{data, pagination}` honoring `limit`/`offset`/`sort`/`order` per shared components, mirroring `MailDomainController::index`) and `show` (404 `{message,error}` when missing); ensure `config` column is not leaked in responses
- [ ] T018 [US1] Register read routes in `routes/web.php` inside the `api.auth` group — new "Server module" block after the Mail Domain block; register ONLY `get servers` + `get servers/{id}` for now, leaving a placeholder comment that all specific `servers/...` routes from later stories MUST be inserted ABOVE `servers/{id}`
- [ ] T019 [US1] Verify via Swagger UI: `/servers` and `/servers/{id}` GET render and respond per `api/modules/server/servers.yaml` (status codes, shapes); confirm no existing route broke

**Checkpoint**: Server inventory is live — MVP delivered; all nested stories now have a resolvable parent.

---

## Phase 4: User Story 2 - Manage server IP addresses (Priority: P2)

**Goal**: Full CRUD on `/servers/{id}/ip-addresses` with legacy validation parity.

**Independent Test**: POST/GET/PUT/DELETE an IP on server 1; verify 201/200/200/204, datalog i/u/d rows on `server_ip`, 422 cases (type mismatch, duplicate IP, bad port list), server_id immutability.

- [ ] T020 [US2] Create `app/Http/Controllers/Api/V1/ServerIpController.php`: `index`/`show`/`store`/`update`/`destroy` scoped by path `{id}` — 404 if parent server missing (FR-005), 404 if child belongs to another server; `server_id` always taken from path, rejected on update if body differs (legacy immutability); writes via model save/delete in DB transaction; per-type IP validation + uniqueness → 422
- [ ] T021 [US2] Register routes in `routes/web.php` ABOVE `servers/{id}`: `get/post servers/{id}/ip-addresses`, `get/put/delete servers/{id}/ip-addresses/{ip_address_id}`
- [ ] T022 [US2] Verify via Swagger UI against `api/modules/server/ip-addresses.yaml` + check `sys_datalog` rows for i/u/d

**Checkpoint**: US1 + US2 independently functional.

---

## Phase 5: User Story 3 - Manage server IP mappings (Priority: P2)

**Goal**: Full CRUD on `/servers/{id}/ip-mappings`.

**Independent Test**: CRUD a mapping on server 1; verify datalog rows on `server_ip_map`; 422 for non-IPv4 `destination_ip` and empty `source_ip`.

- [ ] T023 [P] [US3] Create `app/Http/Controllers/Api/V1/ServerIpMapController.php`: nested CRUD identical in shape to T020 (parent 404, cross-server 404, path-sourced `server_id`, transaction + datalog, `{message,error}` errors)
- [ ] T024 [US3] Register routes in `routes/web.php` ABOVE `servers/{id}`: `get/post servers/{id}/ip-mappings`, `get/put/delete servers/{id}/ip-mappings/{mapping_id}`
- [ ] T025 [US3] Verify via Swagger UI against `api/modules/server/ip-mappings.yaml` + datalog inspection

**Checkpoint**: US1–US3 independently functional.

---

## Phase 6: User Story 4 - Manage firewall rule-sets (Priority: P3)

**Goal**: Full CRUD on `/servers/{id}/firewall` with the one-record-per-server constraint and port-syntax validation.

**Independent Test**: POST on a server without a firewall row → 201; second POST → 409; PUT with bad port syntax → 422; PUT changing server_id → rejected; DELETE → 204; datalog i/u/d on `firewall`.

- [ ] T026 [P] [US4] Create `app/Http/Controllers/Api/V1/ServerFirewallController.php`: nested CRUD; `store` returns **409 Conflict** `{message,error}` when a firewall row already exists for the server (legacy UNIQUE `server_id`); `index` supports declared filters `active` (exact), `tcp_port`/`udp_port` (partial LIKE); `server_id` immutable on update; port regex validation → 422
- [ ] T027 [US4] Register routes in `routes/web.php` ABOVE `servers/{id}`: `get/post servers/{id}/firewall`, `get/put/delete servers/{id}/firewall/{firewall_id}`
- [ ] T028 [US4] Verify via Swagger UI against `api/modules/server/firewall.yaml` incl. the 409 path + datalog inspection

**Checkpoint**: US1–US4 independently functional.

---

## Phase 7: User Story 5 - Manage additional PHP versions (Priority: P3)

**Goal**: Full CRUD on `/servers/{id}/php-versions` with legacy validation parity.

**Independent Test**: CRUD a PHP version on a web server; 422 for relative `php_cli_binary`, malformed `php_jk_section`, empty `name`; defaults `active=y`, `sortprio=100`; datalog rows on `server_php`.

- [ ] T029 [P] [US5] Create `app/Http/Controllers/Api/V1/ServerPhpController.php`: nested CRUD; enforce the target-server precondition per T009 decision on FR-061 (`web_server=1 AND mirror_server_id=0` → 400/422 otherwise); tag/newline stripping on `name` and path fields before save
- [ ] T030 [US5] Register routes in `routes/web.php` ABOVE `servers/{id}`: `get/post servers/{id}/php-versions`, `get/put/delete servers/{id}/php-versions/{php_version_id}`
- [ ] T031 [US5] Verify via Swagger UI against `api/modules/server/php-versions.yaml` + datalog inspection

**Checkpoint**: US1–US5 independently functional.

---

## Phase 8: User Story 6 - Inspect and update server configuration (Priority: P4)

**Goal**: Config endpoints backed by `ServerConfigService`: list (all servers), full get, section get/put ×10, plus the POST/DELETE semantics decided in T003.

**Independent Test**: `GET /servers/1/configs/mail` returns the parsed `[mail]` section; PUT one key → datalog `u` on `server` with a re-serialized blob where only `[mail]` changed and legacy `ini_parser` still parses it; 422 for the mailbox/message size-limit violation.

- [ ] T032 [US6] Create `app/Http/Controllers/Api/V1/ServerConfigController.php`: `index` (`GET /servers/configs` — paginated configs of all servers), `show` (`GET /servers/{id}/configs`), `showSection($id, $section)` / `updateSection($id, $section)` with `$section` whitelisted to {mail, web, dns, fastcgi, xmpp, jailkit, ufw, vlogger, cron, rescue} (404 otherwise) and per-section validation rules from the reconciled `Server*Config.yaml` schemas (T004); `store`/`destroy` per the T003 decision; all writes through `ServerConfigService::updateSection` → `Server->save()` in a transaction
- [ ] T033 [US6] Register routes in `routes/web.php` with STRICT ordering inside the server block: `get servers/configs` FIRST (before any `servers/{id}` pattern), then `get/put servers/{id}/configs/{section}`, then `get/post/put/delete servers/{id}/configs` — all above bare `servers/{id}`; re-verify no shadowing (e.g., `GET /servers/configs` must not bind `{id}='configs'`)
- [ ] T034 [US6] Verify via Swagger UI against `api/modules/server/server-config.yaml`: all 12 config paths (incl. the four registered in T006) render and respond; round-trip a section PUT and confirm blob integrity (other sections byte-identical, `getmail`/`server` sections preserved) and the datalog `u` row on `server`
- [ ] T035 [US6] Verify the mail-section special cases: size-limit guard → 422; `rspamd_available` not overridable from input; if T009 confirmed the rspamd side effect, switching `content_filter` to rspamd produces datalog updates for the server's `spamfilter_users`/`spamfilter_wblist` rows

**Checkpoint**: US1–US6 independently functional.

---

## Phase 9: User Story 7 - Manage server records (Priority: P4)

**Goal**: `POST /servers`, `PUT /servers/{id}`, `DELETE /servers/{id}` with legacy defaults and mirror rules.

**Independent Test**: POST a server → 201 with defaults + datalog `i`; PUT `mirror_server_id` = own id → stored as 0; DELETE → 204 + datalog `d`, no cascade.

- [ ] T036 [US7] Extend `app/Http/Controllers/Api/V1/ServerController.php` with `store`/`update`/`destroy`: validation via `Server::getValidationRules()` (server_name required ≤255 + strip tags/newlines, 0/1 integer flags, `mirror_server_id` integer ≥0 and `exists:server,server_id` when nonzero); enforce FR-012 mirror rules in store/update; transaction + rollback + logging; 201/200/204; document in a code comment that deletion does not cascade (legacy parity)
- [ ] T037 [US7] Register write routes in `routes/web.php`: `post servers`, `put servers/{id}`, `delete servers/{id}` — keeping them BELOW every `servers/...` specific route registered in T018/T021/T024/T027/T030/T033
- [ ] T038 [US7] Verify via Swagger UI against corrected `api/modules/server/servers.yaml` (incl. the 409 declared on POST — decide/document the trigger, e.g., duplicate `server_name`, or record as reserved) + datalog i/u/d inspection

**Checkpoint**: All seven user stories functional.

---

## Phase 10: Polish & Cross-Cutting Concerns

- [ ] T039 [P] Update `README.md` endpoint list with the server module surface (if the README enumerates endpoints)
- [ ] T040 Code cleanup pass: controllers thin (validation + HTTP only), blob logic exclusively in `app/Services/ServerConfigService.php`, no ad-hoc `y`/`n` or 0/1 conversions outside models/casts, consistent `{message,error}` error bodies
- [ ] T041 Re-verify legacy parity for every documented validation case in spec.md SC-004 (US2 #2/3/5, US3 #2/3, US4 #2/3, US5 #2/3/4, US6 #3, US7 #2) against the tform files cited in the parity map
- [ ] T042 Full route-ordering audit of `routes/web.php` (Principle IV): `servers/configs` before `servers/{id}`; all nested `servers/{id}/*` before bare `servers/{id}`; no existing module route shadowed
- [ ] T043 Run `vendor/bin/phpunit` — existing suite must still pass (no server-module tests were requested)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: no dependencies; T001–T009 are the clarification/contract gate — nothing else may start before T008 passes
- **Foundational (Phase 2)**: depends on Phase 1 (models encode the corrected schemas); T010–T014 parallel; T015 depends on T004 (section field inventories) and T010 (Server model); T016 depends on T010–T015
- **User Stories (Phases 3–9)**: all depend on Phase 2. Recommended order US1 → US2 → US3 → US4 → US5 → US6 → US7 (priority order); US2–US5 controllers ([P] tasks T023, T026, T029) may be built in parallel once US1's route block exists, since they live in separate files — their route-registration tasks remain sequential
- **Polish (Phase 10)**: after all desired stories

### Within Each User Story

- Contract correction (Phase 1) precedes controller work (Principle I)
- Model before controller; controller before routes; Swagger verification last
- Route tasks (T018, T021, T024, T027, T030, T033, T037) all edit `routes/web.php` — NEVER parallel, and each re-checks ordering

### Parallel Opportunities

- Phase 1: T002–T007, T009 in parallel after/alongside T001
- Phase 2: T011–T014 in parallel with each other and with T010
- Controllers T023 (US3), T026 (US4), T029 (US5) in parallel — different files
- T039 in parallel with T040–T042

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1 (contract gate) and Phase 2 (foundation)
2. Complete Phase 3 (US1: GET /servers, GET /servers/{id})
3. **STOP and VALIDATE**: Swagger "Try it out" with the dev key; confirm inventory reads and 404/401 behavior
4. Ship — every other module gains a way to resolve `server_id`s

### Incremental Delivery

- Each subsequent story adds one nested resource without touching delivered ones; `routes/web.php` ordering re-audited at every story boundary (T042 formalizes the final audit)
- US6 (config blob) is deliberately late: highest risk, most clarifications; do not start T032 until T003/T004 decisions are recorded in spec.md

---

## Notes

- [P] tasks = different files, no dependencies — never mark two `routes/web.php` edits as [P]
- Every write path goes through `BaseModel::save()`/`delete()` — including config updates (a `Server` attribute update), never a raw `UPDATE server SET config=...`
- The `server` table's flags are INTEGER 0/1; the child tables use `y`/`n` strings — don't blur the two conventions
- Success responses confirm the `sys_datalog` entry, not the applied change (async ISPConfig processing)
- Commit after each task or logical group; avoid endpoints or fields not present in the (corrected) YAML spec
