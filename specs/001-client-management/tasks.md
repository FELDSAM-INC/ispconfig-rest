# Tasks: Client Management

**Input**: Design documents from `/specs/001-client-management/`
**Prerequisites**: plan.md, spec.md

**Note**: Brownfield migration — every checked `[x]` task below describes work that already exists on `main` (file paths verified 2026-07-04). Unchecked items live only in the final **Gaps** section.

**Tests**: Tests are OPTIONAL in this project (see constitution). This feature has one: `tests/ClientApiTest.php` (clients resource only).

**Organization**: Tasks are grouped by user story (US1 clients, US2 resellers, US3 templates, US4 assignments, US5 domains, US6 circles) per spec.md priorities.

## Format: `[ID] [P?] [Story] Description`

## Path Conventions (this project)

See `.specify/templates/tasks-template.md` — flow per resource is spec YAML → model → (service) → controller → routes → Swagger verification. All paths below are real files.

---

## Phase 1: Setup (contract & legacy research)

- [x] T001 Author module endpoint specs in `api/modules/client/`: `clients.yaml`, `resellers.yaml`, `templates.yaml`, `template_assignments.yaml`, `circles.yaml`, `domains.yaml`; register them in `api/modules/client/_index.yaml` and wire every path into `api/openapi.yaml`
- [x] T002 [P] Author shared schemas `api/components/schemas/Client.yaml` (895 lines, `x-db-table: client`), `ClientReseller.yaml`, `ClientTemplate.yaml`, `ClientTemplateAssigned.yaml`, `ClientCircle.yaml`, `ClientDomain.yaml`; reuse `components/parameters/{limit,offset,sort,order}.yaml` and `components/responses/*` error refs
- [x] T003 [P] Extract legacy behavior from `source_code/interface/web/client/` — `form/client.tform.php` (username/email/password rules), `form/reseller.tform.php` (`limit_client` semantics), `form/client_template.tform.php` (`db_history=no`), `form/client_circle.tform.php`, `form/domain.tform.php` (unique+regex), `client_edit.php` (reseller ownership), `domain_edit.php` (`sys_perm_group='ru'`), `client_templates.inc.php` merge rules — captured in spec.md → ISPConfig Parity and plan.md → Legacy Research

---

## Phase 2: Foundational (blocking prerequisites)

- [x] T004 Datalog write plumbing shared by all stories: `app/Models/BaseModel.php` overrides `save()`/`delete()` to journal `i`/`u`/`d` with `{new, old}` diffs into `sys_datalog` via `app/Services/DatalogService.php` (`dbidx = "<pk>:<value>"`, boolean normalization for `YesNoBoolean` casts)
- [x] T005 Create model `app/Models/Client.php` extending `BaseModel` (`$table='client'`, `$primaryKey='client_id'`, `$fillable`, `$hidden=['password']`, static `$rules`, relations `masterTemplate`/`addonTemplates`/`domains`)
- [x] T006 [P] Create read-only lookup models `app/Models/SysUser.php` (with `defaultGroup` relation) and `app/Models/SysGroup.php` (`client_id` link) for reseller-ownership and domain-group resolution
- [x] T007 [P] Auth + request plumbing reused by all endpoints: `api.auth` → `app/Http/Middleware/ApiAuthMiddleware.php` (401 on missing/invalid `X-API-Key`), `PutPatchInputMiddleware` for form-encoded PUT; `app/Http/Controllers/Controller.php` helpers `getCurrentUserId()`/`getCurrentGroupId()`
- [x] T008 Confirm datalog behavior per entity: `client`/`client_template`/`client_circle`/`domain` reach `sys_datalog` with correct table + pk names (verified by `ClientApiTest` datalog assertions for `client`)

**Checkpoint**: Foundation shipped — all stories build on T004–T008

---

## Phase 3: User Story 1 - Manage the client lifecycle (P1) 🎯 MVP

**Goal**: CRUD `/api/v1/clients` with reseller-ownership handling and datalog journaling

**Independent Test**: `POST /api/v1/clients` with X-API-Key → `sys_datalog` row `(client, i)` (this is `ClientApiTest::testClientCreation`)

### Tests for User Story 1

- [x] T009 [US1] Feature test `tests/ClientApiTest.php`: `testClientListing` (200 + `{items,total,limit,offset}`), `testClientCreation` (202 + datalog `i`), `testClientUpdate` (202 + datalog `u`, `dbidx=client_id:1`, skip-if-missing), `testClientDeletion` (202 + datalog `d`) — assertions encode the shipped 202s, not the contract's 201/200/204

### Implementation for User Story 1

- [x] T010 [US1] Implement `index`/`show` in `app/Http/Controllers/Api/V1/ClientController.php` — generic `filter[]`, `sort`/`order` (default `client_id asc`), `limit`(25)/`offset`, `{items,total,limit,offset}` body; `show` appends `template_assignments` via `ClientTemplateService` and 404s on unknown id
- [x] T011 [US1] Implement `store` — required `company_name`/`contact_name`/`email`/`username`(unique)/`password`(min 8) on top of `Client::$rules`; `parent_client_id` resolution to reseller `sys_user.userid`/`default_group` with 400 paths (mirrors `client_edit.php`); system-field defaults (`riud`/`riud`/``); DB transaction + rollback; datalog via `Client::save()`
- [x] T012 [US1] Implement `update`/`destroy` — unique-username-except-self rule, `sys_*` fields stripped from input, reseller re-parent / reset-to-admin logic, transaction + `\Log::error` context, datalog `u`/`d`
- [x] T013 [US1] Register routes `GET/POST clients`, `GET/PUT/DELETE clients/{id}` in `routes/web.php` (lines 61–66) **after** all literal `clients/...` routes — ordering comment in file

**Checkpoint**: Clients CRUD shipped and covered by the only feature test

---

## Phase 4: User Story 2 - Manage resellers (P2)

**Goal**: `/api/v1/resellers` restricted to `limit_client > 0 OR = -1` clients

**Independent Test**: POST full reseller payload with `limit_client=-1` → datalog `(client, i)`; `limit_client=0` → 400

### Implementation for User Story 2

- [x] T014 [P] [US2] Create model `app/Models/ClientReseller.php` extending `Client` with global scope `reseller` (`limit_client > 0 OR = -1`), reseller-specific `$rules`, `clients()` hasMany via `parent_client_id`
- [x] T015 [US2] Implement `app/Http/Controllers/Api/V1/ClientResellerController.php` — index with declared filters (`contact_name`/`company_name`/`email` LIKE, `customer_no` exact) returning `{data, pagination:{total,limit,offset}}`; show as `{data}`; store with full required limit profile + `limit_client` 400 guard; update with sometimes-rules + `sys_*` stripping; destroy with 409 while `clients()` exist, else 204
- [x] T016 [US2] Register routes `GET/POST resellers`, `GET/PUT/DELETE resellers/{id}` in `routes/web.php` (lines 69–73) — independent prefix, no shadowing risk

**Checkpoint**: Resellers shipped (see Gaps G5: `limit_client` not fillable undermines creation)

---

## Phase 5: User Story 3 - Client template catalog (P3)

**Goal**: CRUD `/api/v1/clients/templates` for `m`/`a` limit templates

**Independent Test**: POST `{template_name, template_type:'m'}` → 201 + datalog `(client_template, i)`

### Implementation for User Story 3

- [x] T017 [P] [US3] Create model `app/Models/ClientTemplate.php` extending `BaseModel` (`client_template`/`template_id`, integer casts for all `limit_*`, `$attributes` defaults matching legacy column defaults, `clients()` relation, static `$rules`)
- [x] T018 [US3] Implement `app/Http/Controllers/Api/V1/ClientTemplateController.php` — index (`filter[]` LIKE + `search` over name/description), store (201, Validator + 422 `{message, errors}`, system-field defaults), show/update/destroy via `findOrFail` (404), update strips `sys_*` (202 shipped), destroy with in-use 409 guard then 204
- [x] T019 [US3] Register routes `clients/templates[...]` in `routes/web.php` (lines 42–46) **before** `clients/{id}` and before assignment routes capture `{client_id}`

**Checkpoint**: Template catalog shipped (see Gaps G2: destroy guard broken)

---

## Phase 6: User Story 4 - Template assignments (P3)

**Goal**: `/api/v1/clients/{client_id}/templates` managing master field + pivot rows with limit recomputation

**Independent Test**: POST `{client_template_id}` for an `a` template → 201 `{client_id, client_template_id, is_master:false, template}` + pivot row + recomputed client limits

### Implementation for User Story 4

- [x] T020 [US4] Create service `app/Services/ClientTemplateService.php` — `createTemplateAssignment` (master vs additional dispatch, duplicate exceptions), `deleteTemplateAssignment`, `updateClientMasterTemplate`, `updateClientTemplates`/`parseLegacyTemplateAssignments` (legacy `assigned_id:template_id` and `/id/` bookkeeping), `validateTemplateAssignments`, and `applyClientTemplates` porting legacy merge rules (sum, `-1` unlimited, CHECKBOX less-limited, CHECKBOXARRAY/MULTIPLE union, reseller `limit_client` adjustment) writing merged limits back through `Client::save()` (datalogged)
- [x] T021 [US4] Implement `app/Http/Controllers/Api/V1/ClientTemplateAssignmentController.php` — constructor-injected service; index (master + addon rows as `{client_id, client_template_id, is_master, template}`), show (checks master then pivot, 404), store (422 `exists:` rule, 400 service validation, 409 duplicates, 201 — **contract-compliant**), destroy (204, 404 via "not found" exception match)
- [x] T022 [US4] Register routes `clients/{client_id}/templates[/{template_id}]` in `routes/web.php` (lines 49–52) between literal template routes and `clients/{id}`

**Checkpoint**: Assignments shipped; the only fully status-code-compliant client-era controller

---

## Phase 7: User Story 5 - Client domains (P4)

**Goal**: CRUD `/api/v1/clients/domains` against the `domain` table with client-group ownership

**Independent Test**: POST `{client_id, domain}` → datalog `(domain, i)` row carrying the client's `groupid` + `sys_perm_group='ru'`

### Implementation for User Story 5

- [x] T023 [P] [US5] Create model `app/Models/ClientDomain.php` extending `BaseModel` (`domain`/`domain_id`, fillable `domain` + `sys_*`, `sysGroup()` relation)
- [x] T024 [US5] Implement `app/Http/Controllers/Api/V1/ClientDomainController.php` — index with `client_id`→`sys_group.groupid` filter (empty result for group-less clients) + generic `filter[]`, `{items,total,limit,offset}`; store validating `client_id` `exists:` + `domain` unique, setting `sys_groupid` from the client's group and `sys_perm_group='ru'` (mirrors `domain_edit.php::onAfterInsert`); update (unique-except-self); destroy; 404s on unknown ids
- [x] T025 [US5] Register routes `clients/domains[...]` **first** in the group in `routes/web.php` (lines 35–39) — the file's "more specific routes first" anchor

**Checkpoint**: Domain module shipped

---

## Phase 8: User Story 6 - Client circles (P5)

**Goal**: CRUD `/api/v1/clients/circles` for named client-id groupings

**Independent Test**: POST `{circle_name, client_ids:"1,2", active:"y"}` → 201 + datalog `(client_circle, i)`; duplicate name → 409

### Implementation for User Story 6

- [x] T026 [P] [US6] Create model `app/Models/ClientCircle.php` extending `BaseModel` (`client_circle`/`circle_id`, `YesNoBoolean` cast on `active` with default, `$rules`, `getClientIdsArray()`/`setClientIdsFromArray()` helpers)
- [x] T027 [US6] Implement `app/Http/Controllers/Api/V1/ClientCircleController.php` — index with declared filters (`active`, `circle_name`/`description` LIKE) returning `{data, pagination:{total,offset,limit}}` (limit default 15); store (201, duplicate-name 409, `validateClientIds()` 400, `setSystemFields()` from auth helpers); show (404); update (sometimes-rules + `Rule::unique(...)->ignore()`, client-id revalidation, 202 shipped); destroy (204)
- [x] T028 [US6] Register routes `clients/circles[...]` in `routes/web.php` (lines 55–59) above `clients/{id}`

**Checkpoint**: All six resources shipped

---

## Phase 9: Polish & Cross-Cutting

- [x] T029 Swagger delivery: `SwaggerController` + routes `/api/documentation`, `/api/spec`, `/api/modules/{path}`, `/api/components/{path}` serve the client module YAML; module renders in Swagger UI
- [x] T030 Shared `YesNoBoolean` cast in `app/Casts/YesNoBoolean.php` used by `ClientCircle` (and later modules) instead of ad-hoc `y`/`n` handling; `BaseModel` datalog diffing normalizes cast booleans
- [x] T031 Transactions + rollback + contextual `\Log::error` on all multi-step writes (clients, resellers, circles, templates, assignments)

---

## Dependencies & Execution Order (as built)

- Phase 1 (contract) and Phase 2 (BaseModel/DatalogService/auth) underpin everything; historically parts of the YAML were written alongside or after the controllers (this module predates strict spec-first), which is visible in the contract/implementation drift documented in spec.md.
- US1 (clients) blocks US2 (reseller model extends Client) and US4 (assignments mutate `client.template_master`); US3 (templates) blocks US4; US5/US6 independent.
- `routes/web.php` ordering: T025 (domains) → T019 (templates) → T022 (assignments) → T028 (circles) → T013 (clients) → T016 (resellers); all edits sequential in one file.

---

## Gaps

Genuine defects, contract deviations and missing pieces found during reverse-engineering. Unchecked = not done on `main` as of 2026-07-04.

### Defects (endpoints broken as shipped)

- [ ] G1 **`GET /clients/{id}` always 500s**: `ClientController::show()` → `ClientTemplateService::getClientTemplateAssignments()` references `App\Models\ClientTemplateAssigned`, which does not exist anywhere in the codebase (imported in `app/Services/ClientTemplateService.php:7` and `app/Http/Controllers/Api/V1/ClientTemplateAssignmentController.php:8`). Create the model (extending `BaseModel`, table `client_template_assigned`, pk `assigned_template_id`) or drop the dead references. Also breaks `updateClientTemplates()`.
- [ ] G2 **`DELETE /clients/templates/{id}` always 500s**: `ClientTemplate::clients()` (`app/Models/ClientTemplate.php:181`) is `hasMany(Client::class, 'template_id', 'template_id')` but the `client` table has no `template_id` column — the in-use check throws a QueryException on every call. Should check `client.template_master` plus `client_template_assigned` rows, like legacy `client_template_del.php::onBeforeDelete`.
- [ ] G3 **PUT `/clients/circles/{id}` 500s instead of 404** for unknown ids: `ClientCircleController::update()` (line 144) throws un-imported `NotFoundException` (class does not exist in that namespace). Return the same 404 JSON as `show`/`destroy`.
- [ ] G4 **Plaintext passwords**: no hashing anywhere on the client/reseller write path — the request `password` is stored verbatim in `client.password` and serialized into the `sys_datalog` payload. Legacy uses CRYPT (`client.tform.php` `'encryption' => 'CRYPT'`).

### Constitution / contract alignment

- [ ] G5 **`limit_*` fields not mass-assignable on `Client`**: `Client::$fillable` contains no `limit_*` or `gender`/`vat_id` fields, so `POST /resellers` silently drops `limit_client` (and every other limit) — the created row does not satisfy the reseller scope and disappears from `GET /resellers`. Extend `$fillable` (and `Client::$rules`) or document the field set as read-only.
- [ ] G6 **202 → contract status codes** (constitution Principle V "known deviation"): change `ClientController` store/update/destroy (202→201/200/204), `ClientDomainController` store/update/destroy (202→201/200/204), `ClientResellerController` store/update (202→201/200), `ClientCircleController` update (202→200), `ClientTemplateController` update (202→200); update `tests/ClientApiTest.php` assertions (currently pin 202) in the same change.
- [ ] G7 **List-response shape**: no client-module list endpoint matches its YAML (`{data, pagination}` with page-based `Pagination.yaml`); shipped shapes are `{items,total,limit,offset}` (clients/templates/domains) and `{data, pagination:{total,limit,offset}}` (circles/resellers). Decide direction (constitution says `{items,total,limit,offset}`) and amend either the six YAML files + `Pagination.yaml` usage or the controllers.
- [ ] G8 **Declared filters not implemented**: `clients.yaml` (`contact_name`/`company_name`/`email`/`customer_no`) and `templates.yaml` (`template_type`/`template_name`) declare filters the controllers ignore; controllers instead accept undeclared `filter[field]` arrays and `search`. Align code or YAML.
- [ ] G9 **`client_template_assigned` writes bypass datalog** (Principle II): `attach()`/`detach()` in `ClientTemplateService`/`Client::addonTemplates()` and query-builder mass deletes write the pivot directly. Legacy also skips the datalog for this table — decide and document whether to keep parity (amend constitution note) or route through a `ClientTemplateAssigned` BaseModel (see G1).
- [ ] G10 **`domains.yaml` internal inconsistency**: path key `/clients/domains/{domainId}` vs. parameter name `domain_id` (invalid OpenAPI — path template variable must match a declared path parameter). Also `GET /clients/{id}` response omits the shipped `template_assignments` field from `Client.yaml`.
- [ ] G11 **Error body shapes**: middleware 401 is `{"error": ...}`, 404s are `{"error": ...}` — neither matches the constitution's `{message, error}` nor `api/components/schemas/Error.yaml` (`{code, message, fields}`). Standardize.
- [ ] G12 **Default `limit` drift**: spec parameter default 20; controllers use 25 (clients/resellers/templates/domains) and 15 (circles).

### Legacy-parity gaps (see plan.md → Legacy Research)

- [ ] G13 **No `sys_group`/`sys_user` lifecycle**: client create does not create the client's `sys_group` (datalogged) + `sys_user`; update does not sync username/password/language to `sys_user`; delete does not remove them or cascade-delete owned records (`client_edit.php::onAfterInsert/onAfterUpdate`, `client_del.php`). REST-created clients cannot own resources or log in.
- [ ] G14 **Template updates don't re-apply limits**: `ClientTemplateController::update` never calls `ClientTemplateService::applyClientTemplates()` for assigned clients (legacy `client_template_edit.php::onAfterUpdate` does). The service method exists — wire it up.
- [ ] G15 **Missing legacy validations**: username regex `/^[\w\.\-\_]{0,64}$/` and 64-char field widths; unique `customer_no`; domain regex + IDN + lowercase; reseller `limit_client` quota enforcement when a reseller creates clients.

### Test coverage

- [ ] G16 **Untested resources**: `tests/ClientApiTest.php` covers only clients list/create/update/delete. No tests for `GET /clients/{id}` (which would have caught G1), resellers, templates (would have caught G2), assignments, circles (would have caught G3), or domains. Add per-resource tests following the existing pattern (constitution: tests when the spec requests them — this spec's SC-004/SC-005 warrant at least smoke tests for the broken paths).

### Code quality (no behavior change intended)

- [ ] G17 `ClientTemplateService::applyClientTemplates()` skip-condition (lines 427–433) has broken operator precedence (`!strpos($key, 'limit_') === 0` negates before comparing) and the `fieldTypes` array defines `force_suexec` twice; `ClientTemplateController::update` logs full request input at info level (`\Log::info('Update client template input data:'…)`) — debug leftover that can include sensitive fields; `ClientController::store` merges `'active' => 'y'` into data although `client` has no `active` column and it is not fillable (dead code).
