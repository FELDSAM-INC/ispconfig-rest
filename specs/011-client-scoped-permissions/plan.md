# Implementation Plan: Client-Scoped Permissions

**Branch**: `011-client-scoped-permissions` | **Date**: 2026-07-04 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/011-client-scoped-permissions/spec.md`

**Status**: Draft — spec-only stage; this plan describes FUTURE code. No app code changes yet.

## Summary

Activate ISPConfig's legacy riud row-permission model for non-admin API keys. One request-scoped **AuthScope** value object (key → `sys_userid`, expanded group ids from the `sys_user.groups` CSV, `isAdmin`, lazy `isReseller`) feeds three enforcement points: (1) a **read predicate** (the `getAuthSQL('r')` triplet) applied centrally in `HandlesListQuery::listQuery()` and in `BaseModel::resolveRouteBindingQuery()` so lists filter silently and unauthorized ids 404 through implicit binding; (2) a **write gate** inside `BaseModel::save()/delete()` — in-memory `checkPerm` for `u`/`d` throwing a 403-mapped exception, plus forced `sys_userid`/`sys_groupid` stamping on non-admin inserts; (3) **module gates** as route middleware (`scope.admin` on server/system/monitor/resellers, `scope.client-module` = admin-or-reseller on `/clients/**`, plus targeted admin gates on the mail/dns admin-menu-only write surfaces). P3 adds a `ClientLimitService` mirroring `checkClientLimit`/`checkResellerLimit` on create paths (independently deliverable; pre-authorized for deferral to feature 012). Zero endpoint changes; the only candidate contract diff is the additive 403 declaration on 65 flagged operations (pending sign-off, see spec API Contract).

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)
**Primary Dependencies**: laravel/framework ^12, Eloquent ORM; dev: phpunit ^11, mockery, faker
**Storage**: MySQL — ISPConfig's `dbispconfig` database (schema owned by ISPConfig; never migrated by this project; all writes via `sys_datalog`). New reads: `sys_user` (groups CSV, typ), `sys_group` (client_id), `client` (limit_* — P3 and reseller detection). No new tables, no `api_keys` columns.
**Testing**: PHPUnit (`vendor/bin/phpunit`), feature tests in `tests/Feature/` — REQUIRED per constitution v2. This feature's tests are authorization matrices (admin / owner / other-client / reseller keys) per module, on the established SQLite in-memory `tests/Support/*Schema.php` pattern.
**Target Platform**: Linux server alongside an ISPConfig installation
**Project Type**: Contract-first REST API (monolith)
**Performance Goals**: AuthScope resolution adds ≤ 1 `sys_user` read per non-admin request (cached in the request-scoped object); the read predicate is an indexed-column OR that legacy runs on every interface query — no new scale risk.
**Constraints**: no endpoint/shape changes (spec FR-024); admin-key behavior byte-identical (FR-004, 564-test regression bar); datalog machinery must stay unscoped (FR-010); behavioral parity citations per spec Parity section.
**Scale/Scope**: 0 new endpoints; ~6 new PHP files, ~5 modified core files (`ApiKeyAuth`, `IspContext`, `BaseModel`, `HandlesListQuery`, `CreateApiKey`), 4 route files gaining middleware groups, 1 new test-support fixture file + per-module authorization test files (7 modules).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: PASS — no new endpoints; all activated status codes (200 filtered lists, 403, 404) are already declared except the 65 operations flagged in spec.md (API Contract section). The additive `403: $ref Forbidden` amendment is gated on owner sign-off **before** module-gate middleware ships for those routes; if declined, that sub-decision is re-opened rather than shipping undeclared codes silently.
- [x] **Datalog-only writes (II)**: PASS — feature adds **no** write paths. The one write-adjacent change is inside `BaseModel::save()/delete()` (deny-before-write + sys-field forcing), which strengthens Principle II. `add_group_to_user` CSV append already exists in `ClientService` (documented legacy direct-UPDATE exception from feature 001).
- [x] **Legacy parity (III)**: PASS — the authorization model is reverse-engineered with file:line citations (spec Parity section): `getAuthSQL` tform_base.inc.php:1742-1769, `checkPerm` tform.inc.php:68-108, insert stamping tform_base.inc.php:1548-1561, delete gate tform_actions.inc.php:328-332, list scoping listform_actions.inc.php:242-247, module CSV auth.inc.php:176-211, limits tform.inc.php:183-250. Deviations (403 vs silent no-op; hardened admin-menu-only writes; no mailuser bypass; route-prefix module gates) are declared in the spec.
- [x] **Route discipline (IV)**: PASS — no new routes; existing module route files gain `Route::middleware(...)->group()` wrappers, ordering untouched. Middleware aliases registered in `bootstrap/app.php` beside `api.key`.
- [x] **HTTP contract (V)**: PASS — all denials are RFC 9457 problem+json via existing `App\Support\Problem`/exception rendering; list envelope untouched (`meta.total` counts visible rows only).
- [x] **No schema changes**: PASS — no migrations against ISPConfig tables; no `api_keys` migration needed (identity columns already exist).

## Project Structure

### Documentation (this feature)

```text
specs/011-client-scoped-permissions/
├── spec.md              # Feature spec (done)
├── plan.md              # This file
└── tasks.md             # Task list
```

(No separate research.md/data-model.md — legacy research is consolidated in spec.md's Parity section with file:line citations; no new entities/contracts exist to model.)

### Source Code (repository root)

```text
api/
└── modules/{server,system,monitor,mail}/*.yaml   # ONLY if the additive-403 amendment is signed off:
                                                  # append the shared Forbidden ref to the 65 flagged ops
                                                  # (response-map-only diff; no paths/params/shapes)

app/
├── Support/
│   ├── AuthScope.php                # FUTURE — immutable value object: sysUserId, sysGroupId, groupIds[],
│   │                                #   isAdmin, isReseller() (lazy client.limit_client query),
│   │                                #   readPredicate()/writePredicate() helpers, allows(array $row, string $perm)
│   └── IspContext.php               # MODIFIED — carries the resolved AuthScope (authScope() accessor;
│                                    #   default admin 1/1 outside HTTP, preserving CLI/test writes)
├── Http/
│   ├── Middleware/
│   │   ├── ApiKeyAuth.php           # MODIFIED — after actAs(): resolve AuthScope (one sys_user read for
│   │   │                            #   non-admin ids; userid 1 short-circuits to admin) and park it in IspContext
│   │   ├── RequireAdmin.php         # FUTURE — alias 'scope.admin': 403 problem+json unless AuthScope->isAdmin
│   │   └── RequireAdminOrReseller.php # FUTURE — alias 'scope.reseller': 403 unless admin || isReseller
│   └── Concerns/HandlesListQuery.php # MODIFIED — listQuery() applies the read scope when the builder's
│                                    #   model is a sys-fielded BaseModel and the AuthScope is non-admin
├── Models/
│   ├── BaseModel.php                # MODIFIED — (a) scopeReadable(): getAuthSQL('r') triplet;
│   │                                #   (b) resolveRouteBindingQuery() override → readable() ⇒ scoped 404s;
│   │                                #   (c) save(): non-admin update without 'u' → 403 exception BEFORE write;
│   │                                #   (d) delete(): non-admin without 'd' → 403 exception;
│   │                                #   (e) applySysFieldDefaults(): FORCE identity pair for non-admin
│   └── (no new models — SysUser/SysGroup exist)
├── Services/
│   └── ClientLimitService.php       # FUTURE (P3) — checkClientLimit/checkResellerLimit parity:
│                                    #   limit lookup via sys_group.client_id, count via the 'u' predicate
│                                    #   + per-resource type filters (map from spec FR-020)
└── Console/Commands/CreateApiKey.php # MODIFIED — --client-id option (exclusive with --sys-userid/--sys-groupid)

bootstrap/app.php                    # MODIFIED — register 'scope.admin'/'scope.reseller' aliases;
                                     #   map the authorization exception → 403 problem+json

routes/
├── api.php                          # MODIFIED — wrap the server/system/monitor requires in
│                                    #   Route::middleware('scope.admin')->group(...)
└── api/
    ├── client.php                   # MODIFIED — 'scope.admin' on resellers/* routes;
    │                                #   'scope.reseller' on the remaining client-module routes
    ├── mail.php                     # MODIFIED — 'scope.admin' on relay-domains/relay-recipients/
    │                                #   content-filters/spamfilter-config + write ops of spamfilter
    │                                #   policies/users; limit-gate hooks on transports/access-rules/wblist (P2/P3)
    └── dns.php                      # MODIFIED — 'scope.admin' on dns/templates write ops (reads stay row-scoped)

tests/
├── Support/
│   ├── TenantFixtures.php           # FUTURE — trait: seeds sys_user/sys_group/client rows for
│   │                                #   admin, client A, client B, reseller R (groups CSV = own + A's group),
│   │                                #   mints real ApiKeys (ApiKey::mint) and returns per-identity headers;
│   │                                #   owns() helpers to stamp rows per tenant
│   └── TenantSchema.php             # FUTURE — guarded Schema::create for sys_user/sys_group/client columns
│                                    #   used by scoping (client table: limit_* subset needed by tests)
└── Feature/
    ├── AuthScopeTest.php            # FUTURE — unit-ish: predicate/allows()/group expansion/admin bypass
    ├── ScopedBindingTest.php        # FUTURE — binding 404s, nested parent scoping
    ├── ModuleGateTest.php           # FUTURE — 403 matrix across /servers,/system,/monitor,/resellers,/clients
    ├── Scoping{Mail,Dns,Sites,Client}ModuleTest.php  # FUTURE — per-module 4-key matrices (SC-001…SC-006)
    ├── ClientLimitTest.php          # FUTURE (P3) — -1/0/n/reseller-cap matrix (SC-007)
    └── CreateApiKeyClientIdTest.php # FUTURE — --client-id resolution (SC-008)
```

**Structure Decision**: Enforcement is centralized in the four files every request already flows through (`ApiKeyAuth` → `IspContext`/`AuthScope`; `HandlesListQuery` for all list envelopes; `BaseModel` for bindings and writes), so the ~50 controllers need **no** edits — per-module work reduces to route-middleware wrappers and tests. This mirrors how legacy centralizes the same logic (every form/list flows through tform/listform). Alternatives rejected: per-controller policies (50-file diff, drift risk), a global Eloquent scope via `booted()` (would silently scope service-internal queries — e.g. `ClientService` reseller lookups and cross-tenant uniqueness checks — violating FR-010; explicit application at the HTTP entry points keeps internal reads global by default).

## Design

### AuthScope resolution (auth time)

`ApiKeyAuth` already calls `IspContext::actAs()`. It additionally resolves an `AuthScope`:

- `sys_userid == 1` → admin scope immediately (no query; dev key and default admin keys keep today's zero-overhead path).
- Otherwise one `sys_user` read: `typ`, `groups`, `default_group`. `typ === 'admin'` ⇒ admin scope. Else `groupIds = array_filter(int-cast explode(',', groups)) ∪ {key.sys_groupid}` (legacy predicate parity: tform_base.inc.php:1758-1763; missing-row behavior per spec FR-005 clarification).
- `isReseller` resolved lazily (only the client-module gate needs it): `client.limit_client != 0` via `sys_user.client_id` (auth.inc.php:69-80).
- Stored on `IspContext` (request-scoped singleton); outside HTTP the default scope is admin 1/1 (FR-025 — CLI/test datalog writes keep working).

### Read scoping (P1)

One predicate builder on `AuthScope` produces the legacy triplet for a perm letter (`LIKE '%r%'` containment, per tform_base.inc.php:1755-1764). Applied at exactly two places:

1. `HandlesListQuery::listQuery()` — before filters/count, when the builder's model is a `BaseModel` with sys fields and scope is non-admin. Covers every list endpoint in the client-accessible modules (controllers not using the trait are either admin-gated — Monitor/ServerConfig/SystemConfig/Resync/SpamfilterConfig — or nested under a scoped parent binding: MailUser* sub-resources, WebDomainSsl, ClientTemplateAssignment).
2. `BaseModel::resolveRouteBindingQuery()` — the implicit-binding path (`show(MailDomain $mailDomain)` etc., used by all CRUD controllers, e.g. MailDomainController:55-99). Unauthorized ids yield `ModelNotFoundException` ⇒ existing 404 problem+json rendering. Nested bindings inherit this automatically because the parent model binds through the same override (FR-009).

A `scopeReadable()` is also exposed for controllers that build ad-hoc queries; the per-module verification tasks grep for `::query()` uses on scoped models outside these two paths.

### Write enforcement (P2)

- **checkPerm helper**: `AuthScope::allows(array $rowAttributes, string $perm): bool` evaluates the triplet in memory against the resolved model's raw attributes — semantics identical to re-selecting with `getAuthSQL($perm)` (tform.inc.php:81-86) since the row is already loaded.
- **Gate placement**: `BaseModel::save()` (when `exists`) requires `u`, `BaseModel::delete()` requires `d`, for non-admin scopes on sys-fielded models — throwing `AuthorizationException` (mapped to 403 problem+json in `bootstrap/app.php`). Placing the gate in BaseModel (not controllers) guarantees no write path — controller, service, cascade — can bypass it, and needs zero controller edits. Rows the key cannot even read never reach save/delete (binding 404s first), producing the spec's 404-vs-403 split.
- **Sys-field forcing**: `applySysFieldDefaults()` splits behavior — admin: today's defaults-if-absent (services may pre-set ownership, e.g. `ClientService` reseller stamping); non-admin: `sys_userid`/`sys_groupid` **overwritten** with the scope identity, perm letters keep defaults-if-absent (preserves `spamfilter_policy`'s `perm_other='r'` preset). Request payloads never carry `sys_*` into models anyway (not in `$fillable`), so this is defense-in-depth (FR-012).
- **Module gates**: two tiny middleware (403 via `Problem::response`) — `scope.admin` and `scope.reseller` (admin-or-reseller) — wrapped around route groups per the spec matrix (FR-013…FR-017). Targeted admin-only write clusters inside `mail.php`/`dns.php` get `scope.admin` on the specific route registrations (read routes stay open/row-scoped).
- **Reseller client creation**: `ClientService::create` derives `parent_client_id` from the acting scope when non-admin (client_edit.php:349-362) — the existing group-append (`addGroupToUser`) then targets the reseller.
- **CLI**: `CreateApiKey` gains `--client-id` (resolution per FR-019).

### Client limits (P3 — independently deliverable)

`ClientLimitService::checkCreate(string $limitName, BaseModel $prototype, ?string $typeWhere)`:

1. Resolve acting client: `sys_group.client_id` for the scope's `sys_groupid` → `client` row; absent ⇒ allow (auth.inc.php:139-141).
2. `limit == -1` ⇒ allow; else count rows of the model's table matching the scope's `u` predicate + `$typeWhere`; `count >= limit` ⇒ 403 "limit reached" (tform.inc.php:183-209).
3. Reseller pass when `parent_client_id > 0` (tform.inc.php:211-250): parent's limit vs. count over the reseller's groups ∪ userid.

Invocation point: store() paths of the mapped resources (spec FR-020 table) — the only per-controller touch in the feature; hence the deferral option. **Recommendation**: implement P1+P2 first; ship P3 as feature 012 unless the store()-hook wiring proves trivial after Foundational — the AuthScope predicate work it depends on is finished either way.

### Test strategy

Per-module authorization matrices on the existing SQLite/Schema-helper pattern, with `TenantFixtures` seeding four identities and real hashed keys (not the admin dev key):

| Key | Bound to | Expects |
|---|---|---|
| admin | 1/1 (dev-key path and a minted admin key) | current behavior, everything visible/writable |
| client A | userA/groupA (`groups = "groupA"`) | own rows only; forced stamping; 403 on gated modules |
| client B | userB/groupB | none of A's rows: absent from lists, 404 on show/update/delete |
| reseller R | userR/groupR (`groups = "groupR,groupA"`, `client.limit_client = -1`) | sees A's rows; passes `/clients/**` gate; 403 on `/resellers/**`, `/servers/**` |

Each client-accessible module gets one `Scoping<Module>ModuleTest` running the same matrix over its resources (SC-001…SC-006); `ModuleGateTest` sweeps ≥ 1 operation per admin-only YAML file (SC-003); the full pre-existing suite is the admin regression bar (SC-005).

## Legacy Research (Phase 0 focus)

Complete — consolidated in spec.md's Parity section with file:line citations (getAuthSQL/checkPerm/insert-stamping/delete-gate/list-scoping/module-CSV/limits/reseller-groups/remote-API comparison/datalog-list evidence). No open legacy questions besides the two NEEDS CLARIFICATION items (missing `sys_user` row behavior; additive-403 contract amendment sign-off).

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Direct reads of `sys_user`/`sys_group`/`client` (query builder, read-only) in AuthScope/limit resolution | The scope must be resolved before any model query runs, and these are system tables the API never writes | Full Eloquent models + relations add nothing for three indexed single-row reads; legacy reads the same rows the same way at login (login/index.php:326) |
| 403-throwing logic inside `BaseModel::save()/delete()` | Only chokepoint every write path shares; controller-level checks are bypassable by services/cascades | Laravel policies per model = ~50 files with duplicated predicate logic and no coverage of service-initiated writes |
