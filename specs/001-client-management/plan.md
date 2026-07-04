# Implementation Plan: Client Management

**Branch**: `001-client-management` (no such branch — feature shipped on `main` before spec-kit adoption; this plan documents the built system) | **Date**: 2026-07-04 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/001-client-management/spec.md`

## Summary

Full CRUD over ISPConfig's client hierarchy — clients, resellers (scoped `client` rows), client templates, template assignments (master field + `client_template_assigned` pivot with limit recomputation), client circles, and domain-module domains — as six controllers under `App\Http\Controllers\Api\V1`, five `BaseModel` models, one service (`ClientTemplateService`, a port of legacy `client_templates.inc.php`), and 24 routes inside the `api.auth` group. This is the oldest module in the codebase: it established the datalog-write pattern but predates the spec-first status-code and pagination-shape conventions, and it ships four known defects (documented in tasks.md → Gaps).

## Technical Context

**Language/Version**: PHP ^7.3|^8.0 (Laravel Lumen 8.3)
**Primary Dependencies**: laravel/lumen-framework, Eloquent ORM; dev: phpunit ^9.5, mockery, fakerphp
**Storage**: MySQL — ISPConfig's `dbispconfig` database (schema owned by ISPConfig; never migrated by this project; all writes via `sys_datalog`). Tables touched: `client`, `client_template`, `client_template_assigned`, `client_circle`, `domain`; read-only: `sys_user`, `sys_group`, `sys_datalog`.
**Testing**: PHPUnit (`vendor/bin/phpunit`) — `tests/ClientApiTest.php` exists (the project's only real feature test; covers the clients resource only, asserts the shipped 202s)
**Target Platform**: Linux server alongside an ISPConfig installation
**Project Type**: Contract-first REST API (monolith) — though this module was in practice built code-first; the YAML was authored around it
**Performance Goals**: N/A (CRUD volumes; no special goals)
**Constraints**: async write semantics via `sys_datalog` (spec status codes: 201 create / 200 update / 204 delete — **not met**, see gate V); behavioral parity with legacy ISPConfig (`source_code/interface/web/client/`) — partial, see Legacy Research
**Scale/Scope**: 6 resources, 24 endpoints, 5 models + 1 missing pivot model, 1 service (583 lines), 1 test class (4 tests)

## Constitution Check

*GATE: retroactive assessment of the shipped implementation (constitution v1.0.1).*

- [x] **Spec-first (I)** — *PARTIAL*: all endpoints exist in `api/modules/client/*.yaml` with `_index.yaml`, registered in `api/openapi.yaml`, bodies referencing `api/components/schemas/Client*.yaml`. But the implementation does not mirror the YAML verbatim: list shapes (`{items,total,limit,offset}` or ad-hoc `{data,pagination}` vs. declared `{data, pagination}` with page-based `Pagination.yaml`), undeclared `filter[]`/`search` params instead of the declared per-field filters (clients, templates), default `limit` 25/15 vs. declared 20, `GET /clients/{id}` returns an undeclared `template_assignments` field, and `domains.yaml` itself is inconsistent (`{domainId}` path segment vs. `domain_id` parameter).
- [x] **Datalog-only writes (II)** — *PARTIAL*: `Client`, `ClientReseller`, `ClientTemplate`, `ClientCircle`, `ClientDomain` all extend `App\Models\BaseModel`; every save/delete on those tables datalogs via `DatalogService` (verified in `app/Models/BaseModel.php` save/delete overrides). **Violation**: `client_template_assigned` has no model at all — `ClientTemplateService` and `ClientTemplateAssignmentController` import the nonexistent `App\Models\ClientTemplateAssigned`, and actual pivot writes go through `belongsToMany::attach()/detach()` plus query-builder mass deletes, bypassing datalog entirely. (Mitigating context: legacy ISPConfig also does not datalog this table, and `client_template.tform.php` sets `db_history=no` — so runtime behavior matches legacy; the constitution's rule is still formally violated.)
- [x] **Legacy parity (III)** — *PARTIAL*: `source_code/interface/web/client/` was demonstrably consulted (controller comments cite `client_edit.php`; `ClientDomainController` reproduces the `sys_perm_group='ru'` onAfterInsert fixup; `ClientTemplateService` ports `client_templates.inc.php`). Major unported side effects: no `sys_group`/`sys_user` creation on client insert, no sys_user credential sync on update, no cascade delete (`client_del.php`), no CRYPT password hashing, no template re-application on template update. Details in spec → ISPConfig Parity and Legacy Research below.
- [x] **Route discipline (IV)** — *PASS*: all 24 routes registered in `routes/web.php` (lines 34–73) inside the `api.auth`-middleware group with `API_PREFIX`; literal-segment routes (`clients/domains`, `clients/templates`, `clients/circles`) and the parameterized `clients/{client_id}/templates` precede the general `clients/{id}` block; the file's own comments call out the ordering. Controllers use `index/show/store/update/destroy`; reusable logic lives in `App\Services\ClientTemplateService`.
- [ ] **HTTP contract (V)** — *FAIL*: eight write paths return **202 Accepted** where the YAML declares 201/200/204: `ClientController` store/update/destroy, `ClientDomainController` store/update/destroy, `ClientResellerController` store/update, `ClientCircleController` update, `ClientTemplateController` update. This is exactly the constitution's documented **known deviation** ("the five client-era controllers … still return 202 Accepted; they predate the spec-first rule and are pending alignment — do not copy that pattern into new code", Principle V, v1.0.1 amendment note). `ClientTemplateAssignmentController` is fully compliant (201/204); circle/template/reseller creates-deletes are compliant where noted in the spec's endpoint table. Additional Principle V misses: pagination shape not `{items,total,limit,offset}` on circles/resellers; several error bodies are `{"error": ...}` without `message`; two 404 paths return 500 due to bugs. Errors otherwise use 400/401/404/409/422/500 with Lumen `$this->validate()` (422) and DB transactions + rollback + contextual `\Log::error` on multi-step writes.
- [x] **No schema changes** — *PASS*: no migrations exist for any ISPConfig table (`database/` holds only factories/seeders scaffolding).

**Gate summary**: IV and "No schema changes" pass; I, II, III partial; V fails on the documented known deviation plus shape/error details. Remediation items are enumerated as unchecked tasks in `tasks.md → Gaps` rather than fixed retroactively here.

## Project Structure

### Documentation (this feature)

```text
specs/001-client-management/
├── spec.md              # Reverse-engineered feature spec
├── plan.md              # This file
└── tasks.md             # Completed-work inventory + Gaps backlog
```

(No research.md / data-model.md / contracts/ — brownfield migration; the API contract lives in `api/modules/client/` and the data model in ISPConfig-DB-Structure.txt.)

### Source Code (repository root) — actual files of this feature

```text
api/
├── openapi.yaml                                    # registers all /clients*, /resellers paths (lines ~168+)
├── modules/client/
│   ├── _index.yaml                                 # module index referencing the six resource files
│   ├── clients.yaml                                # /clients, /clients/{id}
│   ├── resellers.yaml                              # /resellers, /resellers/{id}
│   ├── templates.yaml                              # /clients/templates, /clients/templates/{template_id}
│   ├── template_assignments.yaml                   # /clients/{client_id}/templates[/{template_id}]
│   ├── circles.yaml                                # /clients/circles, /clients/circles/{id}
│   └── domains.yaml                                # /clients/domains, /clients/domains/{domainId}
└── components/
    ├── schemas/Client.yaml, ClientReseller.yaml, ClientTemplate.yaml,
    │           ClientTemplateAssigned.yaml, ClientCircle.yaml, ClientDomain.yaml,
    │           Pagination.yaml, Error.yaml          # shared
    ├── parameters/limit.yaml, offset.yaml, sort.yaml, order.yaml   # reused
    └── responses/BadRequest.yaml, Unauthorized.yaml, Forbidden.yaml,
                NotFound.yaml, Conflict.yaml, UnprocessableEntity.yaml,
                InternalServerError.yaml             # reused

app/
├── Http/Controllers/Api/V1/
│   ├── ClientController.php                        # clients CRUD + reseller-ownership logic
│   ├── ClientResellerController.php                # resellers CRUD (scoped model)
│   ├── ClientTemplateController.php                # template catalog CRUD
│   ├── ClientTemplateAssignmentController.php      # assignment index/show/store/destroy
│   ├── ClientCircleController.php                  # circles CRUD
│   └── ClientDomainController.php                  # domain-module CRUD
├── Http/Controllers/Controller.php                 # getCurrentUserId()/getCurrentGroupId() helpers
├── Models/
│   ├── BaseModel.php                               # datalog-routing save()/delete() (shared)
│   ├── Client.php                                  # table client, pk client_id
│   ├── ClientReseller.php                          # extends Client, global 'reseller' scope
│   ├── ClientTemplate.php                          # table client_template, pk template_id
│   ├── ClientCircle.php                            # table client_circle, pk circle_id, YesNoBoolean cast
│   ├── ClientDomain.php                            # table domain, pk domain_id
│   ├── SysUser.php / SysGroup.php                  # read-only ownership lookups
│   └── (MISSING: ClientTemplateAssigned.php — referenced but never created)
├── Services/
│   ├── ClientTemplateService.php                   # assignment CRUD + legacy limit-merge port
│   └── DatalogService.php                          # sys_datalog writer (shared)
└── Casts/YesNoBoolean.php                          # shared y/n cast

routes/web.php                                      # lines 34–73: domains → templates → assignments → circles → clients → resellers

tests/ClientApiTest.php                             # list/create/update/delete of clients (asserts 202 + datalog rows)
```

**Structure Decision**: single flat module — no submodule namespace (unlike `Monitor/`). Route block ordering inside the `api.auth` group is load-bearing: the four literal-prefixed groups and the `{client_id}/templates` assignment routes must stay above `clients/{id}`; `resellers` is an independent top-level prefix and can sit anywhere in the group.

## Legacy Research (Phase 0 focus)

What `source_code/interface/web/client/` does, and what the implementation took from it:

- **Form definitions (`form/*.tform.php`)**:
  - `client.tform.php`: `contact_name` NOTEMPTY; `username` NOTEMPTY + UNIQUE (custom `username_unique`/`username_collision`) + regex `/^[\w\.\-\_]{0,64}$/`; `password` CRYPT + strength check; `email` ISEMAILADDRESS + NOTEMPTY; `customer_no` UNIQUE (allowempty); `language` NOTEMPTY; many ISINT limit validators; `db_history=yes` (datalogged). → Ported: required/unique/email/min-length rules in `Client::$rules` + controller overrides. Not ported: username regex + 64-char widths, customer_no uniqueness, CRYPT hashing, TRIM/STRIPTAGS filters.
  - `reseller.tform.php`: same table (`client`), `db_history=yes`, custom `limit_client` validator. → Ported as the `ClientReseller` scope + explicit 400 check.
  - `client_template.tform.php`: `template_name` NOTEMPTY, ISINT limits, **`db_history=no`** (legacy does not datalog template writes; the REST API does — surplus, harmless). → Ported: required name, `m`/`a` type, integer limits with model defaults matching legacy column defaults.
  - `client_circle.tform.php`: `db_history=yes`; **no NOTEMPTY validators** (name/ids optional in legacy); `client_ids` is a CHECKBOXARRAY joined with `,`. → REST is stricter (all three required) — documented deviation.
  - `domain.tform.php`: `domain` NOTEMPTY + UNIQUE + regex `/^[\w\.\-]{1,255}\.[a-zA-Z0-9\-]{2,63}$/`, IDN/TOLOWER filters, `db_history=yes`. → Ported: required + unique. Not ported: regex, IDN, lowercasing.
- **Actions / side effects**:
  - `client_edit.php::onAfterInsert`: datalogInsert `sys_group` (name=username, client_id) + raw INSERT `sys_user` (default_group = new group); parent reseller path picks `sys_user.userid`/`default_group` via `sys_group.client_id = parent_client_id`; customer-no template generation; welcome message. → Ported: **only** the parent-reseller ownership resolution (`ClientController::store`, same query via `SysUser::whereHas('defaultGroup')`). sys_group/sys_user creation NOT ported.
  - `client_edit.php::onAfterUpdate`: sys_user username/password/language sync, `sys_group` rename (datalogUpdate), reseller re-assignment (`UPDATE client SET sys_userid=?, sys_groupid=? …` or reset to 1/1/0), `update_client_templates`. → Ported: reseller re-assignment + reset (in `ClientController::update`), template bookkeeping (in the service, though unused by the update endpoint). Sys_user/sys_group sync NOT ported.
  - `client_del.php`: onBeforeDelete removes `sys_group`/`sys_user` rows and datalog-deletes every dependent record by `sys_groupid` (mail, web, dns, …). → NOT ported; REST deletes just the `client` row.
  - `domain_edit.php::onAfterInsert`: `UPDATE domain SET sys_groupid = <client group>, sys_perm_group = 'ru'`. → Fully ported (set before insert in `ClientDomainController::store`).
  - `client_template_edit.php::onAfterUpdate`: re-applies templates (`apply_client_templates`) to every client using the changed template. → The merge algorithm is ported (`ClientTemplateService::applyClientTemplates`) and runs on assignment changes, but the template-update trigger is NOT wired.
  - `client_template_del.php::onBeforeDelete`: refuses deletion when `client_template_assigned` rows exist OR `client.template_master`/`template_additional` reference the template. → Intended as the 409 check; shipped via a broken `ClientTemplate::clients()` relation (`client.template_id` column does not exist).
- **Permission checks**: legacy enforces per-record `sys_perm_*`/AUTHSQL and per-reseller client limits (`limit_client` count checks in `client_edit.php`). → NOT ported; the API operates as admin behind `X-API-Key` and does not enforce the reseller's own client quota.
- **List definitions**: legacy list filters (searchable fields) informed the YAML's declared query filters; only circles/resellers implement them as declared.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Pivot writes to `client_template_assigned` bypass datalog (Principle II) | Mirrors legacy exactly — ISPConfig itself never datalogs this table (`db_history` n/a for the pivot; interface writes it raw), so journaling it could confuse ISPConfig-side consumers of `sys_datalog` | A `ClientTemplateAssigned extends BaseModel` model was clearly intended (it is imported in two files) but never written; adding it must decide whether datalogging this table is safe — deferred to Gaps |
| Direct reads of `sys_user`/`sys_group` in controllers | Needed to resolve reseller ownership and client groups, same queries legacy runs | A service would be cleaner but the constitution allows model reads; noted as cleanup, not a violation |
