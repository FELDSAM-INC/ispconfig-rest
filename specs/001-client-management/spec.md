# Feature Specification: Client Management

**Feature Branch**: `001-client-management` — no such branch exists; the feature was built directly on `main` before spec-kit adoption. This spec is a brownfield migration (reverse-engineered from the shipped code).
**Created**: 2026-07-04
**Status**: Migrated
**Module**: client
**Input**: Reverse-engineered from `app/Http/Controllers/Api/V1/Client*.php` (6 controllers), `app/Models/Client*.php` (5 models), `app/Services/ClientTemplateService.php`, `routes/web.php` (lines 34–73), `api/modules/client/*.yaml`, `api/components/schemas/Client*.yaml`, `tests/ClientApiTest.php`, and legacy `source_code/interface/web/client/`.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Manage the client lifecycle (Priority: P1)

An API consumer (billing system, signup automation, control panel) creates, lists, inspects, updates and deletes ISPConfig clients over `/api/v1/clients`. On create, the consumer may attach the client to a reseller via `parent_client_id`; the API resolves the reseller's `sys_user`/`sys_group` (mirroring `client_edit.php`) so the new client record is owned by that reseller. All writes are journaled to `sys_datalog` through `BaseModel` — the success response confirms the journal entry, not the applied change.

**Why this priority**: Clients are the root entity of ISPConfig — every mail domain, website and DNS zone belongs to a client's group. This is the module's reason to exist and the only story with test coverage (`tests/ClientApiTest.php`).

**Independent Test**: `POST /api/v1/clients` with `X-API-Key` and the required fields, then assert a `sys_datalog` row with `dbtable=client`, `action=i` (exactly what `ClientApiTest::testClientCreation` does).

**Acceptance Scenarios**:

1. **Given** a valid API key, **When** the consumer calls `GET /clients`, **Then** the response is 200 with `{items, total, limit, offset}` (asserted by `testClientListing`; note this deviates from the `{data, pagination}` shape declared in `clients.yaml` — see Assumptions).
2. **Given** required fields `company_name`, `contact_name`, `email`, `username` (unique in `client`), `password` (min 8), **When** the consumer POSTs to `/clients`, **Then** the client row is inserted, a `sys_datalog` entry `(client, i)` is written, and the client is returned — with HTTP 202 as shipped (`testClientCreation` asserts 202; the OpenAPI contract declares 201 — known deviation).
3. **Given** `parent_client_id` referencing a client with `limit_client > 0` or `= -1`, **When** creating or re-parenting a client, **Then** `sys_userid`/`sys_groupid` are set from the reseller's `sys_user.userid`/`default_group`; **Given** the parent is not a reseller or has no `sys_user`, **Then** 400 with `{message, error}`.
4. **Given** an update that clears `parent_client_id`, **When** PUT `/clients/{id}`, **Then** ownership resets to `sys_userid=1`/`sys_groupid=1` and `parent_client_id=0` (mirrors `client_edit.php` `onAfterUpdate`), datalog action `u` with `dbidx=client_id:{id}`, HTTP 202 as shipped (contract says 200).
5. **Given** an existing client, **When** DELETE `/clients/{id}`, **Then** a datalog `d` entry is written and the shipped response is 202 (contract says 204); **Given** an unknown id, **Then** 404 `{"error": "Client not found"}`.
6. **Given** an existing client, **When** GET `/clients/{id}`, **Then** the contract says 200 with the Client schema plus the implementation adds a `template_assignments` array — **as shipped this endpoint always returns 500** because `ClientTemplateService::getClientTemplateAssignments()` references the nonexistent class `App\Models\ClientTemplateAssigned` (see Gaps in tasks.md). Documented honestly; not invented around.

---

### User Story 2 - Manage resellers (Priority: P2)

A consumer manages resellers — clients with `limit_client > 0` or `-1` — via `/api/v1/resellers`. The `ClientReseller` model is a `Client` subclass with a global scope enforcing the reseller condition, so listing/showing only ever returns resellers. Creation demands the full reseller profile (address, `template_master`, all `limit_*` fields incl. PHP/SMTP limits) and rejects non-reseller `limit_client` values with 400. Deletion is blocked with 409 while clients are still assigned (`parent_client_id`).

**Why this priority**: Resellers gate User Story 1's `parent_client_id` path and are the second pillar of ISPConfig's client hierarchy.

**Independent Test**: `POST /resellers` with a full payload where `limit_client=-1`; verify the `sys_datalog` `(client, i)` entry; `GET /resellers` must include it (but see Gaps: `limit_client` is not in `Client::$fillable`, so as shipped it is silently dropped and the created row does not satisfy the reseller scope).

**Acceptance Scenarios**:

1. **Given** `limit_client = 0` in the payload, **When** POST `/resellers`, **Then** 400 `{"message": "A reseller must have limit_client > 0 or limit_client = -1"}`.
2. **Given** a valid payload, **When** POST `/resellers`, **Then** shipped response is 202 with `{data, message}` (contract declares 201 with the same body shape).
3. **Given** a reseller with assigned clients, **When** DELETE `/resellers/{id}`, **Then** 409; **Given** no assigned clients, **Then** 204 (matches contract).
4. **Given** filters `contact_name`/`company_name`/`email` (substring) or `customer_no` (exact), **When** GET `/resellers`, **Then** the list is filtered and returned as `{data, pagination:{total, limit, offset}}` (top-level keys match `resellers.yaml`; the `pagination` object does not match the page-based `Pagination.yaml` schema).

---

### User Story 3 - Maintain the client template catalog (Priority: P3)

A consumer manages reusable limit templates (`/api/v1/clients/templates`): master templates (`template_type=m`) define a client's base limits, additional templates (`a`) stack on top. CRUD with validation of `template_name` (required) and `template_type` (`m`/`a`); deletion must be refused (409) while the template is in use by any client.

**Why this priority**: Templates make client provisioning repeatable, but clients and resellers work without them.

**Independent Test**: `POST /clients/templates` with `template_name` + `template_type=m` → 201 (matches contract) and datalog `(client_template, i)`; note legacy ISPConfig does **not** datalog `client_template` (`client_template.tform.php` has `db_history = no`) — the REST API journals more than legacy here.

**Acceptance Scenarios**:

1. **Given** a payload missing `template_name`, **When** POST, **Then** 422 `{message: "Validation failed", errors}`.
2. **Given** an existing template, **When** PUT `/clients/templates/{id}`, **Then** shipped response is 202 (contract says 200); changed limits are **not** re-applied to clients already using the template (legacy `client_template_edit.php::onAfterUpdate` re-applies them — deviation).
3. **Given** an unknown id, **When** GET/PUT/DELETE, **Then** `findOrFail` → 404.
4. **Given** a template in use, **When** DELETE, **Then** 409 — **as shipped the in-use check always fails with 500** because `ClientTemplate::clients()` joins on a nonexistent `client.template_id` column (legacy checks `client_template_assigned` and `client.template_master`/`template_additional`). See Gaps.

---

### User Story 4 - Assign templates to clients (Priority: P3)

A consumer assigns/unassigns templates to a client via `/api/v1/clients/{client_id}/templates`. Master templates are stored in `client.template_master`; additional templates in the `client_template_assigned` pivot. After every assignment change, `ClientTemplateService::applyClientTemplates()` recomputes the client's effective `limit_*`/`default_*`/server-list fields from master + additional templates (numeric limits added, `-1` wins as unlimited, CHECKBOX fields take the less-limited `y`/`n`, list fields union-merged) — mirroring legacy `client_templates.inc.php`.

**Why this priority**: Depends on US1 and US3; automates limit management for provisioning platforms.

**Independent Test**: POST `{client_template_id}` for an `a`-type template → 201 with `{client_id, client_template_id, is_master:false, template}` and a new `client_template_assigned` row; the client's limits reflect the merged templates.

**Acceptance Scenarios**:

1. **Given** a valid template id, **When** POST `/clients/{client_id}/templates`, **Then** 201 (matches contract); master templates set `template_master` (datalogged via `Client::save()`), additional templates insert into the pivot via `attach()` (**not** datalogged — matches legacy, which also skips the datalog for this table, but bypasses Principle II).
2. **Given** the template is already assigned (same master, or same additional template), **When** POST, **Then** 409.
3. **Given** a nonexistent `client_template_id`, **When** POST, **Then** 422 (`exists:client_template,template_id`).
4. **Given** an assigned template, **When** DELETE `/clients/{client_id}/templates/{template_id}`, **Then** 204 (matches contract) and limits are recomputed; unknown assignment → 404.
5. **Given** a client with a master and N additional templates, **When** GET, **Then** 200 with `data[]` of `{client_id, client_template_id, is_master, template}` entries (deviates from `ClientTemplateAssigned.yaml`, which declares `{assigned_template_id, client_id, client_template_id}`).

---

### User Story 5 - Register client domains (domain module) (Priority: P4)

When ISPConfig's "use domain module" option is active, clients may only pick from pre-registered domains. A consumer manages these via `/api/v1/clients/domains` against the `domain` table. On create, the consumer passes `client_id`; the API resolves the client's `sys_group` and stores the domain with `sys_groupid` of that group and `sys_perm_group='ru'` — exactly the post-insert fixup legacy `domain_edit.php::onAfterInsert` performs.

**Why this priority**: Only relevant to installations using the domain module; independent of the other stories.

**Independent Test**: POST `{client_id, domain}` → datalog `(domain, i)` whose row carries `sys_perm_group='ru'` and the client's `groupid`.

**Acceptance Scenarios**:

1. **Given** a `client_id` that exists and a unique `domain`, **When** POST, **Then** shipped 202 (contract says 201) and the record's `sys_groupid` matches the client's `sys_group`.
2. **Given** a duplicate domain name, **When** POST/PUT, **Then** 422 (`unique:domain`); legacy also validates the regex `/^[\w\.\-]{1,255}\.[a-zA-Z0-9\-]{2,63}$/` and lowercases/IDN-encodes — the REST API does not (deviation).
3. **Given** `?client_id=` filter, **When** GET `/clients/domains`, **Then** only domains whose `sys_groupid` belongs to that client are returned (`{items,total,limit,offset}`; `domains.yaml` declares `{total, data}` — deviation).
4. **Given** an unknown id, **When** GET/PUT/DELETE `/clients/domains/{id}`, **Then** 404.

---

### User Story 6 - Group clients into circles (Priority: P5)

A consumer manages client circles (`/api/v1/clients/circles`) — named, comma-separated lists of client ids (`client_circle` table) used by ISPConfig to filter client lists. Create validates that every id in `client_ids` exists and that `circle_name` is unique (409 on duplicate).

**Why this priority**: Pure convenience grouping; no other feature depends on it.

**Independent Test**: POST `{circle_name, client_ids:"1,2", active:"y"}` → 201 (matches contract) + datalog `(client_circle, i)`.

**Acceptance Scenarios**:

1. **Given** a `client_ids` string containing a nonexistent id, **When** POST/PUT, **Then** 400 `{message: "Invalid client IDs", error}`.
2. **Given** an existing `circle_name`, **When** POST, **Then** 409.
3. **Given** filters `active`, `circle_name`, `description` (caller-supplied `%` wildcards), **When** GET, **Then** 200 with `{data, pagination:{total, offset, limit}}`.
4. **Given** an unknown id, **When** PUT `/clients/circles/{id}`, **Then** the intent is 404 — **as shipped it 500s** (throws un-imported `NotFoundException`); DELETE and GET return 404 correctly. See Gaps.
5. Requiring `circle_name`, `client_ids` and `active` is **stricter than legacy** (`client_circle.tform.php` has no NOTEMPTY validators) — accepted deviation.

### Edge Cases

- **Missing/invalid `X-API-Key`** → 401 `{"error": "Unauthorized. API key is required."}` from `ApiAuthMiddleware` (shape has no `message` key and does not match `Error.yaml`).
- **Nonexistent parent entity**: bad `parent_client_id` → 400; `client_id` on domains → 422 (`exists:` rule); `client_template_id` → 422; unknown path ids → 404 (except the two 500 bugs listed in Gaps).
- **Parent reseller without a `sys_user`**: creating/re-parenting a client under a reseller whose `sys_user` row is missing → 400 "Parent reseller system user not found".
- **`y`/`n` flag fields**: `locked`, `canceled`, `can_use_api` validated `in:y,n`; `ClientCircle.active` uses the `YesNoBoolean` cast (API accepts/returns boolean-ish values while the column stores `y`/`n`; `BaseModel` normalizes them for datalog diffing).
- **Datalog async semantics**: success responses never confirm the applied change — ISPConfig daemons process `sys_datalog` later. The shipped 202s were chosen to signal this; the contract standardized on 201/200/204 afterwards.
- **Route shadowing**: `clients/domains`, `clients/templates`, `clients/circles`, `clients/{client_id}/templates` are registered before `clients/{id}` in `routes/web.php` (lines 34–66), so literal segments are never captured as `{id}`.
- **Reseller demotion**: PUT `/resellers/{id}` with `limit_client=0` → 400 (would silently break the parent-of relationship otherwise).
- **Mass-assignment silently drops fields**: `limit_*`, `default_*server`, `gender`, `vat_id`, `active`, etc. are absent from `Client::$fillable`; sending them is not an error — they are ignored (see Gaps).
- **Permission fields**: `sys_userid`/`sys_groupid`/`sys_perm_*` are stripped from update payloads on clients/resellers/templates; creates default them to the authenticated user (or 1/1) with `riud`/`riud`/``.

## API Contract *(mandatory)*

- **Spec file(s)** (all existing — implementation predates parts of them):
  `api/modules/client/_index.yaml`, `clients.yaml`, `resellers.yaml`, `templates.yaml`, `template_assignments.yaml`, `circles.yaml`, `domains.yaml` (all referenced from `api/openapi.yaml`).
- **Shared schemas**: `api/components/schemas/Client.yaml`, `ClientReseller.yaml`, `ClientTemplate.yaml`, `ClientTemplateAssigned.yaml`, `ClientCircle.yaml`, `ClientDomain.yaml`; shared `Pagination.yaml`, `Error.yaml`; parameters `limit` (default 20)/`offset`/`sort`/`order`; shared error responses under `api/components/responses/`.
- **Endpoints** (24; "Spec code" = what the YAML declares; "Shipped" = what the controller returns):

| Method | Path | Purpose | Spec code | Shipped |
|--------|------|---------|-----------|---------|
| GET | `/api/v1/clients` | List clients | 200 | 200 |
| POST | `/api/v1/clients` | Create client (datalog `i`) | 201 | **202** |
| GET | `/api/v1/clients/{id}` | Show client + template assignments | 200 | **500 (bug: missing model)** |
| PUT | `/api/v1/clients/{id}` | Update client (datalog `u`) | 200 | **202** |
| DELETE | `/api/v1/clients/{id}` | Delete client (datalog `d`) | 204 | **202** |
| GET | `/api/v1/resellers` | List resellers | 200 | 200 |
| POST | `/api/v1/resellers` | Create reseller | 201 | **202** |
| GET | `/api/v1/resellers/{id}` | Show reseller (`{data}` wrapper) | 200 | 200 |
| PUT | `/api/v1/resellers/{id}` | Update reseller | 200 | **202** |
| DELETE | `/api/v1/resellers/{id}` | Delete reseller (409 if has clients) | 204 | 204 |
| GET | `/api/v1/clients/templates` | List templates | 200 | 200 |
| POST | `/api/v1/clients/templates` | Create template | 201 | 201 |
| GET | `/api/v1/clients/templates/{id}` | Show template | 200 | 200 |
| PUT | `/api/v1/clients/templates/{id}` | Update template | 200 | **202** |
| DELETE | `/api/v1/clients/templates/{id}` | Delete template (409 if in use) | 204 | **500 (bug: broken relation)** |
| GET | `/api/v1/clients/{client_id}/templates` | List a client's assignments | 200 | 200 |
| POST | `/api/v1/clients/{client_id}/templates` | Assign template | 201 | 201 |
| GET | `/api/v1/clients/{client_id}/templates/{template_id}` | Show assignment | 200 | 200 |
| DELETE | `/api/v1/clients/{client_id}/templates/{template_id}` | Unassign template | 204 | 204 |
| GET | `/api/v1/clients/circles` | List circles | 200 | 200 |
| POST | `/api/v1/clients/circles` | Create circle | 201 | 201 |
| GET | `/api/v1/clients/circles/{id}` | Show circle | 200 | 200 |
| PUT | `/api/v1/clients/circles/{id}` | Update circle | 200 | **202** (404-path 500s) |
| DELETE | `/api/v1/clients/circles/{id}` | Delete circle | 204 | 204 |
| GET | `/api/v1/clients/domains` | List domains | 200 | 200 |
| POST | `/api/v1/clients/domains` | Create domain for client | 201 | **202** |
| GET | `/api/v1/clients/domains/{id}` | Show domain | 200 | 200 |
| PUT | `/api/v1/clients/domains/{id}` | Update domain | 200 | **202** |
| DELETE | `/api/v1/clients/domains/{id}` | Delete domain | 204 | **202** |

Contract-shape notes (all deviations are implementation-vs-YAML, documented, not fixed here):

- List bodies: YAML declares `{data, pagination}` (page-based `Pagination.yaml`); shipped: `{items,total,limit,offset}` for clients/templates/domains, `{data, pagination:{total,limit,offset}}` for circles/resellers. No list endpoint matches `Pagination.yaml` exactly.
- Declared list filters `contact_name`/`company_name`/`email`/`customer_no` (clients) and `template_type`/`template_name` (templates) are not implemented; the controllers instead accept an undeclared `filter[field]=value` array (clients/templates/domains) and `search` (templates). Resellers and circles implement their declared filters.
- `domains.yaml` names the path segment `{domainId}` but declares the parameter as `domain_id` (OpenAPI inconsistency in the spec file itself).
- Default `limit`: spec 20; shipped 25 (clients/resellers/templates/domains) and 15 (circles).

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference**: `source_code/interface/web/client/` — consulted: `form/client.tform.php`, `form/reseller.tform.php`, `form/client_template.tform.php`, `form/client_circle.tform.php`, `form/domain.tform.php`, `client_edit.php`, `client_del.php`, `client_template_edit.php`, `client_template_del.php`, `domain_edit.php`.
- **Legacy behaviors mirrored**:
  - Reseller ownership: setting/clearing `parent_client_id` swaps `sys_userid`/`sys_groupid` to the reseller's `sys_user.userid`/`default_group`, or back to 1/1 (`client_edit.php` lines 339, 510–531).
  - Reseller definition `limit_client > 0 OR = -1` (`client_list`/`reseller_list` queries, `reseller.tform.php` custom `limit_client` validator).
  - Domain module: new `domain` rows get `sys_groupid` of the owning client group and `sys_perm_group='ru'` (`domain_edit.php::onAfterInsert`).
  - Template merging semantics in `ClientTemplateService::applyClientTemplates()` port `client_templates.inc.php`: numeric limits summed, `-1` = unlimited wins, CHECKBOXARRAY/MULTIPLE union with separator, CHECKBOX picks the less-limited value, reseller `limit_client` adjustments.
  - `template_additional` bookkeeping formats (`assigned_id:template_id`, legacy `/id/` strings) in `updateClientTemplates()`/`parseLegacyTemplateAssignments()`.
  - Unique username on `client` (legacy CUSTOM `username_unique`), unique `domain`, unique `circle_name` (implementation-added), unique `customer_no` is legacy-only (not implemented — deviation).
- **Legacy behaviors NOT mirrored** (honest deviations; none marked as agreed in any spec):
  - `client_edit.php::onAfterInsert` creates a `sys_group` (datalogged) and a `sys_user` row for the new client; the REST create does neither, so REST-created clients own no group and cannot own resources or log in.
  - `onAfterUpdate` syncs `username`/`password`/`language` to `sys_user` and renames the `sys_group`; not implemented.
  - `client_del.php::onBeforeDelete` deletes the client's `sys_group`/`sys_user` and datalog-deletes all dependent records (mail, web, dns, …) by `sys_groupid`; REST delete removes only the `client` row.
  - Passwords: legacy stores CRYPT hashes (`'encryption' => 'CRYPT'`); the REST API writes the request password to `client.password` unhashed (and it lands in the datalog payload).
  - Username regex `/^[\w\.\-\_]{0,64}$/` and 64-char legacy column widths (`contact_name`, `company_name` are `varchar(64)`) vs. `max:255` rules.
  - Domain name regex/IDN/lowercase filters (`domain.tform.php`).
  - `client_template_edit.php::onAfterUpdate` re-applies changed templates to every assigned client; REST template update does not.
  - Welcome-mail templates, `customer_no` auto-generation from reseller templates: out of scope, not implemented.
- **Tables written (via datalog through `BaseModel::save()`/`delete()` unless noted)**:
  - `client` — i/u/d (clients, resellers, and `u` when `applyClientTemplates()`/master-template assignment updates limits)
  - `client_template` — i/u/d (note: legacy sets `db_history=no` for this form, so the REST API datalogs where legacy does not — harmless surplus)
  - `client_circle` — i/u/d (legacy `db_history=yes`)
  - `domain` — i/u/d (legacy `db_history=yes`)
  - `client_template_assigned` — **written directly, no datalog**: pivot `attach()`/`detach()` and query-builder deletes bypass `BaseModel` (no model exists for this table). Legacy also writes this table without datalog, so behavior matches legacy but not Constitution Principle II's letter.
- **System fields handling**: creates default `sys_userid`/`sys_groupid` from the authenticated sys user (fallback 1/1), `sys_perm_user='riud'`, `sys_perm_group='riud'` (`'ru'` for domains), `sys_perm_other=''`; updates strip `sys_*` from input. `server_id` is not applicable to these tables (datalog entries carry `server_id=0`).
- **Intentional deviations from legacy**: only the stricter circle validation (US6.5) and datalogging `client_template` look deliberate; the remainder listed above are gaps, not decisions.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST expose paginated list endpoints for clients, resellers, templates, circles and domains with `limit`/`offset`/`sort`/`order` query parameters.
- **FR-002**: System MUST create clients only when `company_name`, `contact_name`, `email`, `username` (unique in `client`) and `password` (min 8 chars) are present; other fields per `Client::$rules`.
- **FR-003**: System MUST resolve `parent_client_id` to the reseller's `sys_user` and set `sys_userid`/`sys_groupid` accordingly on create and re-parenting, rejecting non-resellers and missing sys users with 400.
- **FR-004**: System MUST reset ownership to `sys_userid=1`/`sys_groupid=1`/`parent_client_id=0` when a client's reseller link is removed.
- **FR-005**: System MUST journal every client/reseller/template/circle/domain write to `sys_datalog` with action `i`/`u`/`d` via `BaseModel` (`DatalogService::log()`), recording `{new, old}` diffs on update and full payloads on insert/delete.
- **FR-006**: System MUST restrict `/resellers` reads and writes to clients satisfying `limit_client > 0 OR limit_client = -1` (model global scope) and reject payloads that would violate it with 400.
- **FR-007**: System MUST require the full reseller limit profile (address fields, `template_master`, `limit_*` including PHP and SMTP settings) when creating a reseller.
- **FR-008**: System MUST block reseller deletion with 409 while clients reference it via `parent_client_id`.
- **FR-009**: System MUST validate `template_name` (required) and `template_type` in `{m, a}` for client templates, returning 422 with an `errors` map on failure.
- **FR-010**: System MUST block deletion of templates that are in use (409). *(Shipped check is broken — see Gaps.)*
- **FR-011**: System MUST store master-template assignments in `client.template_master` and additional assignments in `client_template_assigned`, rejecting duplicates with 409 and unknown templates with 422/400.
- **FR-012**: System MUST recompute the client's effective limits after every assignment change using the legacy merging rules (sum, `-1` wins, less-limited checkbox, union of list fields, reseller `limit_client` adjustment).
- **FR-013**: System MUST report a client's assignments as `{client_id, client_template_id, is_master, template}` items, covering the master template and each pivot row.
- **FR-014**: System MUST create `domain` rows owned by the target client's `sys_group` with `sys_perm_group='ru'`, enforcing `client_id` existence and domain uniqueness.
- **FR-015**: System MUST support filtering the domain list by `client_id` resolved through `sys_group.client_id → sys_groupid`, returning an empty result for clients without groups.
- **FR-016**: System MUST validate circle payloads (`circle_name` ≤64 unique, `client_ids` all existing, `active` in `{y,n}`) with 409 for duplicate names and 400 for invalid id lists.
- **FR-017**: System MUST strip `sys_userid`, `sys_groupid`, `sys_perm_*` from client/reseller/template update payloads and never accept caller-supplied permission escalation.
- **FR-018**: System MUST return 404 with an error body for every unknown resource id on show/update/delete. *(Two shipped paths 500 instead — see Gaps.)*
- **FR-019**: System MUST require a valid `X-API-Key` (via `api.auth` middleware) on every endpoint, returning 401 otherwise.
- **FR-020**: System MUST return write status codes per the OpenAPI contract — 201 create / 200 update / 204 delete. *(Shipped: eight write paths return 202 — the constitution's documented "known deviation" for client-era controllers.)*
- **FR-021**: System MUST hide `password` from all client/reseller responses (`$hidden`).
- **FR-022**: System MUST handle `y`/`n` flags via casts (`ClientCircle.active` uses `YesNoBoolean`) and validation (`in:y,n` for `locked`, `canceled`, `can_use_api`, circle `active`).

### Key Entities

- **Client**: customer/reseller master record — table `client`, schema `api/components/schemas/Client.yaml`, model `app/Models/Client.php` (extends `BaseModel`; pk `client_id`; relations `masterTemplate`, `addonTemplates`, `domains`).
- **ClientReseller**: a `Client` with `limit_client > 0 OR = -1` — table `client` (same), schema `api/components/schemas/ClientReseller.yaml`, model `app/Models/ClientReseller.php` (extends `Client`, global `reseller` scope, `clients()` hasMany via `parent_client_id`).
- **ClientTemplate**: reusable limit template (`template_type` `m`/`a`) — table `client_template`, schema `api/components/schemas/ClientTemplate.yaml`, model `app/Models/ClientTemplate.php` (extensive integer casts and defaults).
- **ClientTemplateAssigned**: pivot client ↔ additional template — table `client_template_assigned` (pk `assigned_template_id`), schema `api/components/schemas/ClientTemplateAssigned.yaml`, **model missing** (`App\Models\ClientTemplateAssigned` is imported and used but no class exists; pivot accessed via `Client::addonTemplates()` belongsToMany).
- **ClientCircle**: named client grouping (`client_ids` CSV) — table `client_circle`, schema `api/components/schemas/ClientCircle.yaml`, model `app/Models/ClientCircle.php`.
- **ClientDomain**: domain-module entry — table `domain`, schema `api/components/schemas/ClientDomain.yaml`, model `app/Models/ClientDomain.php` (ownership via `sys_groupid`; the table has no `client_id` column).
- **Supporting**: `SysUser` (`sys_user`) and `SysGroup` (`sys_group`, `client_id` link) — used read-only to resolve reseller ownership and domain groups; `app/Models/SysUser.php`, `app/Models/SysGroup.php`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: `GET /api/v1/clients` with a valid key returns 200 and a body containing `items`, `total`, `limit`, `offset` (asserted by `ClientApiTest::testClientListing`).
- **SC-002**: `POST /api/v1/clients` with the five required fields produces a `sys_datalog` row with `dbtable='client'`, `action='i'` (asserted by `testClientCreation`; shipped status 202 is asserted as-is — aligning to the contract's 201 requires updating this assertion).
- **SC-003**: `PUT /api/v1/clients/{id}` and `DELETE /api/v1/clients/{id}` produce `sys_datalog` rows with `dbidx='client_id:{id}'` and actions `u`/`d` respectively (asserted by `testClientUpdate`/`testClientDeletion`).
- **SC-004**: All 24 endpoints in the table above are registered in `routes/web.php` and render in Swagger UI (`/api/documentation`) from `api/modules/client/*.yaml`.
- **SC-005**: Every write to `client`, `client_template`, `client_circle`, `domain` flows through `BaseModel::save()`/`delete()` — zero direct `DB::table()->insert/update/delete` calls against those tables in the module's controllers/service (the `client_template_assigned` pivot is the sole, documented exception).
- **SC-006**: Reseller ownership behavior matches legacy for the three documented cases (assign parent, change parent, remove parent) per `client_edit.php`.
- **SC-007**: `vendor/bin/phpunit` passes for `tests/ClientApiTest.php` against a populated `dbispconfig` database.

## Assumptions

- The OpenAPI YAML under `api/modules/client/` is the contract of record (Principle I) even where the shipped implementation predates and deviates from it; this spec documents shipped behavior against that contract rather than redefining either.
- The shipped 202 responses are treated as the constitution's documented "known deviation" for client-era controllers, pending alignment — not as the desired contract.
- The list-response shape standard is the constitution's `{items,total,limit,offset}`; the module YAML's `{data, pagination}` (page-based `Pagination.yaml`) predates that standardization. Which side gets amended is an open decision recorded here, not resolved.
- Auth is the existing `X-API-Key` middleware (`api.auth`); no per-endpoint permission model beyond ISPConfig's `sys_perm_*` defaults is assumed, and the API does not enforce `sys_perm_*`-based row visibility (it reads as admin).
- A populated `dbispconfig` database with at least the admin `sys_user`/`sys_group` (id 1) is available; `ClientApiTest` update/delete tests skip when client id 1 is absent.
- Legacy behavior was verified against the `source_code/` tree currently vendored in the repo (ISPConfig 3.2.x interface).
- REST-created clients are assumed to get their `sys_user`/`sys_group` provisioned by some other channel (legacy UI or manual), since the API does not create them — flagged as a parity gap, not silently accepted.
