# Implementation Plan: API Key Management over HTTP

**Branch**: `014-api-key-management` | **Date**: 2026-09-14 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/014-api-key-management/spec.md`

**Note**: This template is filled in by the `/speckit-plan` command. See `.specify/templates/plan-template.md` for the execution workflow.

## Summary

Expose the existing CLI-only API key lifecycle over HTTP so remote integrations (first consumer: the
WHMCS ISPConfig module) can mint client-scoped keys after `POST /clients`, list/inspect/rename/
revoke/delete keys, and let any key identify itself. Admin-only CRUD lives under `/system/api-keys`
(inherits the `scope.admin` gate of feature 011); `GET /me` is a new `me` module open to every valid
key. Identity resolution for `client_id` is extracted from `CreateApiKey` into `ApiKeyService` and
shared by CLI and HTTP. Deleting a client through the API deactivates its keys inside the existing
delete transaction. CLI parity adds `api:key:list` / `api:key:revoke` and manager wrappers. No
ISPConfig table is written; the only writes hit the API-owned `api_keys` table; no migration.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)  
**Primary Dependencies**: laravel/framework ^12, Eloquent ORM; dev: phpunit ^11, mockery, faker  
**Storage**: MySQL `dbispconfig`. Writes: API-owned `api_keys` only (insert, update `name`/`active`, delete; `last_used_at` already touched by `ApiKeyAuth`). Reads: `sys_user` (typ, client_id, default_group), `sys_group` (client_id), `client` (limit_client for reseller detection). No new tables or columns.  
**Testing**: PHPUnit feature tests (`php artisan test`, SQLite in-memory, `tests/Support/TenantFixtures` four-identity matrix) — REQUIRED per constitution v2  
**Target Platform**: Linux server alongside an ISPConfig installation (dedicated php-fpm vhost, default port 8090)  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: list endpoint uses a constant number of queries per page (page + count + one batched `sys_user` read + one batched `client` read), independent of page size; auth path unchanged  
**Constraints**: plaintext key only in the 201 create body, never in lists, logs, CLI list output or `/me`; admin-only management (403 before any query for client/reseller keys); key binding immutable; self-revocation 409; `/me` path file shared with spec 016 (`GET /me/servers`)  
**Scale/Scope**: 6 operations (5 `/system/api-keys`, 1 `/me`); ~9 new PHP files (service, 2 controllers, 2 form requests, 2 commands, route file, plus tests), 6 modified (`CreateApiKey`, `ApiKey`, `ClientService`, `routes/api.php`, `routes/api/system.php`, `bin/ispconfig-rest`), 8 new/changed contract files, 4 new + 1 extended test files

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: PASS — contracts drafted in `contracts/` are authored into `api/modules/system/api-keys.yaml`, `api/modules/me/me.yaml` and `api/components/schemas/ApiKey*.yaml` **before** any PHP; bodies reference shared schemas/parameters/responses; `api/openapi.yaml` registers the paths and schemas; `_index.yaml` files updated.
- [x] **Datalog-only writes (II)**: PASS — no ISPConfig table is written. `api_keys` is the API-owned table already exempt under Code Boundaries (see `app/Models/ApiKey.php` docblock); `ApiKey` stays a plain Eloquent model (not `BaseModel`). FR-010 adds an `api_keys` UPDATE inside `ClientService::deleteClient` — API-owned data, same transaction, no datalog.
- [x] **Legacy parity (III)**: PASS — no legacy equivalent (ISPConfig `remote_user` is a different credential system, deliberately not mirrored — spec Parity section). The only parity-relevant logic, `client_id` → identity, reuses the feature 011 FR-019 resolution verbatim (moved, not rewritten).
- [x] **Route discipline (IV)**: PASS — routes in `routes/api/system.php` (already inside `scope.admin` in `routes/api.php`) with `system/api-keys` before `system/api-keys/{apiKey}` (`whereNumber`); new `routes/api/me.php` required from `routes/api.php` inside `api.key` but outside every `scope.admin` group. Flow: `api.key` → `scope.admin` → thin controller → Form Request → `ApiKeyService`.
- [x] **HTTP contract (V)**: PASS — list `{data, meta}` via `HandlesListQuery` (400 on unknown params/sort), problem+json via `App\Support\Problem`, 201 create / 200 show-update / 204 delete, 401/403/404/409/422; `X-API-Key` auth; keys hashed at rest (SHA-256, unchanged).
- [x] **No schema changes**: PASS — no migration; existing `api_keys` columns (`id`, `name`, `key_hash`, `sys_userid`, `sys_groupid`, `active`, `last_used_at`, timestamps) cover every requirement.
- [x] **Testing (REQUIRED)**: PASS (planned) — feature tests per endpoint for success, 400/401/403/404/409/422, plaintext non-disclosure, CLI commands and the client-delete cascade.

**Post-design re-check (after Phase 1)**: all gates still PASS; no Complexity Tracking entries.

## Project Structure

### Documentation (this feature)

```text
specs/014-api-key-management/
├── plan.md              # This file
├── research.md          # Phase 0 decisions
├── data-model.md        # Phase 1 entities, representations, state
├── quickstart.md        # Phase 1 verification guide
├── contracts/           # Phase 1 OpenAPI drafts (authored into api/ first during implementation)
│   ├── api-keys.yaml    # → api/modules/system/api-keys.yaml
│   ├── me.yaml          # → api/modules/me/me.yaml
│   └── schemas.yaml     # → api/components/schemas/ApiKey*.yaml (one file per schema)
├── checklists/requirements.md
└── tasks.md             # Phase 2 output (/speckit-tasks — NOT created here)
```

### Source Code (repository root)

```text
api/
├── openapi.yaml                                  # + paths /system/api-keys, /system/api-keys/{id}, /me; + schemas ApiKey, ApiKeyCreate, ApiKeyUpdate, ApiKeyCreated, ApiKeyIdentity
├── modules/system/
│   ├── _index.yaml                               # + api-keys entry
│   └── api-keys.yaml                             # NEW — list/create, show/update/delete
├── modules/me/
│   ├── _index.yaml                               # NEW — me module index (016 adds servers.yaml here)
│   └── me.yaml                                   # NEW — GET /me
└── components/schemas/
    ├── ApiKey.yaml                               # NEW — metadata representation
    ├── ApiKeyCreate.yaml                         # NEW — POST body
    ├── ApiKeyUpdate.yaml                         # NEW — PUT body
    ├── ApiKeyCreated.yaml                        # NEW — ApiKey + one-time plaintext `key`
    └── ApiKeyIdentity.yaml                       # NEW — GET /me body

app/
├── Http/Controllers/Api/V1/ApiKeyController.php  # NEW — index/show/store/update/destroy
├── Http/Controllers/Api/V1/MeController.php      # NEW — show
├── Http/Requests/StoreApiKeyRequest.php          # NEW
├── Http/Requests/UpdateApiKeyRequest.php         # NEW
├── Services/ApiKeyService.php                    # NEW — identity resolution, minting, presentation, client cascade
├── Models/ApiKey.php                             # MODIFIED — deactivateForClientIdentities() helper, casts unchanged
├── Services/ClientService.php                    # MODIFIED — deleteClient() deactivates client keys (FR-010)
├── Console/Commands/CreateApiKey.php             # MODIFIED — delegates client identity resolution to ApiKeyService
├── Console/Commands/ListApiKeys.php              # NEW — api:key:list
└── Console/Commands/RevokeApiKey.php             # NEW — api:key:revoke {id}

routes/api.php                                    # MODIFIED — require routes/api/me.php (inside api.key, outside scope.admin)
routes/api/system.php                             # MODIFIED — api-keys routes (static before {apiKey})
routes/api/me.php                                 # NEW — GET me (016 appends GET me/servers)
bin/ispconfig-rest                                # MODIFIED — key:list, key:revoke wrappers + help lines
README.md                                         # MODIFIED — "Managing keys" docs (HTTP + CLI)

tests/Feature/ApiKeyManagementApiTest.php         # NEW — CRUD, filters, validation, gates, plaintext non-disclosure, 409
tests/Feature/MeApiTest.php                       # NEW — admin/reseller/client/dev key identities, revoked key 401
tests/Feature/ApiKeyCommandsTest.php              # NEW — api:key:list / api:key:revoke
tests/Feature/ClientDeleteRevokesKeysTest.php     # NEW — FR-010 cascade
tests/Feature/ModuleGateTest.php                  # MODIFIED — add /system/api-keys operations to the admin-only matrix
```

**Structure Decision**: Key management is an admin surface, so it joins the existing `system` module
and inherits `scope.admin` without new middleware. `GET /me` must be reachable by client keys, so it
gets its own `me` module and route file outside the admin group; spec 016 extends the same files. All
non-HTTP logic (identity resolution, batched scope presentation, cascade) sits in `ApiKeyService` so
the CLI commands and both controllers share it and controllers stay thin.

## Design

### Endpoints and behaviour

| Operation | Controller | Notes |
|-----------|-----------|-------|
| `GET /system/api-keys` | `ApiKeyController@index` | `HandlesListQuery` with `sortable: [id, name, active, created_at, last_used_at]`, default `id`; filters `active` (boolean); `client_id` and `name` consumed as `extra` params: `client_id` → `sys_user.userid` set of that client (`whereIn sys_userid`, empty set → no rows, invalid value → 400); `name` → case-insensitive substring `LIKE %…%` with `%`/`_` escaped. Page mapped through `ApiKeyService::present()`. |
| `GET /system/api-keys/{id}` | `@show` | implicit binding (`{apiKey}` `whereNumber`), 404 problem via existing handler |
| `POST /system/api-keys` | `@store` | `StoreApiKeyRequest`; `ApiKeyService::mint(name, ?clientId)` in a transaction; 201 `ApiKeyCreated` |
| `PUT /system/api-keys/{id}` | `@update` | `UpdateApiKeyRequest`; rename and/or `active`; 409 when deactivating the calling key; 422 when `client_id` differs from current binding |
| `DELETE /system/api-keys/{id}` | `@destroy` | 409 when deleting the calling key; 204 |
| `GET /me` | `MeController@show` | `ApiKeyService::identity(request)` from `IspContext::authScope()` + request attribute `api_key_id` |

### Identity and scope

- `ApiKeyService::resolveClientIdentity(int $clientId): ?array` — moved from `CreateApiKey`
  (`sys_group.client_id` → `groupid`, `sys_user.default_group` → `userid`); CLI keeps its messages,
  HTTP maps `null` to 422 `errors.client_id` (unknown client, or client without control-panel user).
- Without `client_id` the key is minted as admin `1/1`, matching the CLI default.
- `present(iterable<ApiKey>)` resolves `scope`/`client_id` for a whole page with one `sys_user`
  `whereIn` read and one `client` `whereIn` read: `sys_userid = 1` or `typ = 'admin'` → `admin`
  (`client_id` null); missing `sys_user` row → `unbound` (`client_id` null); `client.limit_client != 0`
  → `reseller`; otherwise `client`. Rules mirror `AuthScope` / `ApiKeyAuth::resolveScope`.
- `/me` uses the request's already-resolved `AuthScope` (no extra query except the lazy
  `isReseller()` read for non-admin keys); the dev key reports `key_id: null`, `name: "development key"`.

### Revocation rules

- FR-008 needs no new code: `ApiKeyAuth` already filters `active = true` and rejects deleted rows.
- FR-009: `ApiKeyAuth` already sets request attribute `api_key_id`; update (`active: false`) and destroy
  compare it with the bound key and throw `ConflictHttpException` (409). Renaming or re-activating the
  calling key is allowed. The dev key has no row and cannot hit this path.
- FR-010: in `ClientService::deleteClient`, read the client's `sys_user.userid` list before step 2 drops
  those rows, then call `ApiKey::deactivateForClientIdentities($userIds, $groupId)` — one
  `UPDATE api_keys SET active = 0 WHERE active = 1 AND (sys_userid IN (…) OR sys_groupid = :group)`
  (group clause only when `groupid > 1`). It runs inside the `DB::transaction` already wrapping
  `ClientController::destroy`, so a failed delete rolls the deactivation back.

### Validation

- `StoreApiKeyRequest`: `name` required string 1–255; `client_id` sometimes integer min 1;
  `key`, `key_hash`, `sys_userid`, `sys_groupid`, `id`, `scope` → `prohibited` (422).
- `UpdateApiKeyRequest`: `name` sometimes filled string max 255; `active` sometimes boolean;
  `client_id` sometimes nullable integer, compared after validation with the key's current binding
  (`present()` value) → 422 on mismatch; same `prohibited` list.

### CLI parity

- `api:key:list {--client-id=}` — table: id, name, scope, client id, active, last used (never hash).
- `api:key:revoke {id}` — sets `active = false`; unknown id → error, exit 1; already inactive → notice,
  exit 0.
- `bin/ispconfig-rest`: `key:list` / `key:revoke` wrappers (`need_root`, `run_as … artisan …`) and help
  lines next to `key:create`.

### Test strategy

- `ApiKeyManagementApiTest` (TenantSchema + TenantFixtures): create admin/client/reseller-bound keys
  and use the returned plaintext against a scoped endpoint; 422 matrix (missing name, unknown client,
  client without user, prohibited fields, binding change); 403 for client and reseller keys on all five
  operations; list filters/sort/pagination/400; plaintext and hash absent from list/show/update bodies;
  deactivate → 401 on `/ping`, re-activate → 200; delete → 204 then 401; self-deactivate/self-delete
  409 using the fixture's minted admin key; unknown id 404.
- `MeApiTest`: admin, reseller, client A, dev key identities; revoked key 401.
- `ApiKeyCommandsTest`: list output without secrets, `--client-id` filter, revoke success/unknown id.
- `ClientDeleteRevokesKeysTest`: client A with two keys (CLI-minted and HTTP-minted) → `DELETE /clients/{A}`
  → both inactive, client B's key untouched, admin key untouched.
- `ModuleGateTest`: add `GET`/`POST /system/api-keys` rows to the admin-only matrix.
- Regression: existing `ApiKeyAuthTest`, `CreateApiKeyClientIdTest` (unchanged behaviour after the
  service extraction) and the full suite.

## Legacy Research (Phase 0 focus)

- No `source_code/interface/web/` form or list backs API keys; ISPConfig's `admin/remote_user_*` manages
  SOAP remote users with separate credentials and function-level permissions — explicitly out of scope.
- Parity-relevant behaviour is internal to this project: feature 011 FR-005 (fail closed when the bound
  `sys_user` disappears), FR-019 (client identity resolution) and the `ClientService::deleteClient`
  cascade order (feature 001), all reviewed in the current code.

## Complexity Tracking

No constitution violations; nothing to justify.
