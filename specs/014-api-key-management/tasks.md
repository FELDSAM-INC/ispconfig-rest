---
description: "Task list for feature 014 — API Key Management over HTTP"
---

# Tasks: API Key Management over HTTP

**Input**: Design documents from `/specs/014-api-key-management/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/ (`api-keys.yaml`, `me.yaml`, `schemas.yaml`), quickstart.md

**Tests**: REQUIRED (constitution v2) — every endpoint ships with feature tests (success, validation 422,
auth 401/403, 404/409, plaintext non-disclosure). Write each story's tests first and confirm they fail.
Run with `php artisan test` (PHP ≥ 8.3; this workstation has 8.1 — use the `php:8.3-cli` container from
quickstart.md).

**Organization**: Grouped by user story — US1 (P1) mint client-scoped key, US2 (P2) list/inspect/revoke +
client-delete cascade, US3 (P3) `GET /me`, US4 (P3) CLI parity. Contracts are authored in `api/` before any
PHP (Principle I). No ISPConfig table is written and no migration is added (`api_keys` is API-owned).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: US1 / US2 / US3 / US4 (Setup, Foundational and Polish tasks carry no story label)
- Every task names exact file paths.

## Path Conventions (this feature)

| Artifact | Path |
|----------|------|
| Endpoint contracts | `api/modules/system/api-keys.yaml`, `api/modules/me/me.yaml` (+ `_index.yaml` of each module) |
| Schemas | `api/components/schemas/ApiKey*.yaml` (+ `api/components/schemas/_index.yaml`) |
| Contract root | `api/openapi.yaml` (`components.schemas` and `paths`) |
| Service | `app/Services/ApiKeyService.php` |
| Model (API-owned, plain Eloquent) | `app/Models/ApiKey.php` |
| Controllers | `app/Http/Controllers/Api/V1/ApiKeyController.php`, `app/Http/Controllers/Api/V1/MeController.php` |
| Form Requests | `app/Http/Requests/StoreApiKeyRequest.php`, `app/Http/Requests/UpdateApiKeyRequest.php` |
| Routes | `routes/api/system.php` (inside `scope.admin`), `routes/api/me.php` (new, outside `scope.admin`), `routes/api.php` |
| Commands | `app/Console/Commands/CreateApiKey.php`, `ListApiKeys.php`, `RevokeApiKey.php`; manager `bin/ispconfig-rest` |
| Tests (REQUIRED) | `tests/Feature/ApiKeyManagementApiTest.php`, `MeApiTest.php`, `ApiKeyCommandsTest.php`, `ClientDeleteRevokesKeysTest.php`, `ModuleGateTest.php` |

---

## Phase 1: Setup (contracts first)

**Purpose**: The OpenAPI contract for all six operations exists and renders before any PHP is written.

- [x] T001 [P] Author `api/components/schemas/ApiKey.yaml` from the `ApiKey` block of `specs/014-api-key-management/contracts/schemas.yaml` (`x-db-table: api_keys`, no `key_hash`, `scope` enum incl. `unbound`)
- [x] T002 [P] Author `api/components/schemas/ApiKeyCreate.yaml` from the `ApiKeyCreate` block of `specs/014-api-key-management/contracts/schemas.yaml`
- [x] T003 [P] Author `api/components/schemas/ApiKeyUpdate.yaml` from the `ApiKeyUpdate` block of `specs/014-api-key-management/contracts/schemas.yaml`
- [x] T004 [P] Author `api/components/schemas/ApiKeyCreated.yaml` (`allOf` `./ApiKey.yaml` + one-time `key`) from `specs/014-api-key-management/contracts/schemas.yaml`
- [x] T005 [P] Author `api/components/schemas/ApiKeyIdentity.yaml` from the `ApiKeyIdentity` block of `specs/014-api-key-management/contracts/schemas.yaml`
- [x] T006 Register `ApiKey`, `ApiKeyCreate`, `ApiKeyUpdate`, `ApiKeyCreated`, `ApiKeyIdentity` in `api/components/schemas/_index.yaml` and under `components.schemas` in `api/openapi.yaml`
- [x] T007 [P] Author `api/modules/system/api-keys.yaml` from `specs/014-api-key-management/contracts/api-keys.yaml` and add an `api-keys` entry to `api/modules/system/_index.yaml`
- [x] T008 [P] Create the `me` module: `api/modules/me/_index.yaml` (entry `me` → `./me.yaml`, comment that spec 016 adds `servers.yaml`) and `api/modules/me/me.yaml` from `specs/014-api-key-management/contracts/me.yaml`
- [x] T009 Register paths `/system/api-keys`, `/system/api-keys/{id}` (after the other `/system/*` entries) and `/me` in `api/openapi.yaml` `paths` using JSON-pointer refs (`./modules/system/api-keys.yaml#/~1system~1api-keys`, `…#/~1system~1api-keys~1{id}`, `./modules/me/me.yaml#/~1me`)
- [x] T010 Verify the contract parses and renders: load `GET /api/spec` and `/api/documentation` (see `specs/014-api-key-management/quickstart.md` §1) and confirm the API Keys and Me operations appear with every documented response code

**Checkpoint**: Contract complete — PHP work may start.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Shared identity resolution and key presentation used by the HTTP endpoints and the CLI.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [x] T011 Create `app/Services/ApiKeyService.php` with: `resolveClientGroupId(int $clientId): ?int` and `resolveClientIdentity(int $clientId): ?array` (moved verbatim from `app/Console/Commands/CreateApiKey.php`: `sys_group.client_id` → `groupid`, `sys_user.default_group` → `userid`, returns `[userid, groupid]` or `null`); `mint(string $name, ?int $clientId): array` (admin `1/1` without client, wraps `ApiKey::mint()` in `DB::transaction`, returns `[ApiKey, plaintext]`); `present(iterable $keys): array` resolving `scope` (`admin` | `reseller` | `client` | `unbound`) and `client_id` for a whole page with one batched `sys_user` `whereIn` read and one batched `client` `whereIn` read (rules in `specs/014-api-key-management/data-model.md` "Derived fields")
- [x] T012 Refactor `app/Console/Commands/CreateApiKey.php` to delegate client identity resolution to `ApiKeyService` while keeping both existing error messages ("Client N not found …" / "… has no control-panel user …") and exit codes
- [x] T013 Run the regression guards for the refactor: `php artisan test --filter='CreateApiKeyClientIdTest|ApiKeyAuthTest'` (`tests/Feature/CreateApiKeyClientIdTest.php`, `tests/Feature/ApiKeyAuthTest.php`) — must pass unchanged

**Checkpoint**: Foundation ready — US1, US3 and US4 can start in parallel; US2 follows US1 (shared controller, routes and test file).

---

## Phase 3: User Story 1 - Mint a client-scoped key remotely (Priority: P1) 🎯 MVP

**Goal**: An admin key creates an admin or client-bound key with `POST /system/api-keys`; the plaintext is
returned once and works with the bound client's scope.

**Independent Test**: Admin key → `POST /system/api-keys {"name":"whmcs service 17","client_id":A}` → 201 with
`key`; that key lists only A's mail domains and gets 403 on `GET /servers`; client/reseller keys get 403 on
`POST /system/api-keys`; no `sys_datalog` row is written.

### Tests for User Story 1 (REQUIRED) ⚠️

> Write these tests first and confirm they fail before implementation.

- [x] T014 [P] [US1] Create `tests/Feature/ApiKeyManagementApiTest.php` (TenantSchema + TenantFixtures) with create cases: client-bound key → 201 with `key`, `scope: client`, `client_id`, no `key_hash`; returned key authenticates with A's scope (`GET /api/v1/mail/domains` only A's rows, `GET /api/v1/servers` 403); no `client_id` → `scope: admin`; reseller `client_id` → `scope: reseller`; 422 matrix (missing name, name > 255, `client_id` 0 / negative / string, unknown client, client without control-panel user, each prohibited field `key`, `key_hash`, `sys_userid`, `sys_groupid`, `id`, `scope`); 403 for client and reseller keys; zero `sys_datalog` rows after create
- [x] T015 [P] [US1] Add `['POST', '/api/v1/system/api-keys']` to `adminOnlyOperations()` in `tests/Feature/ModuleGateTest.php`

### Implementation for User Story 1

- [x] T016 [P] [US1] Create `app/Http/Requests/StoreApiKeyRequest.php`: `name` required string 1–255; `client_id` sometimes integer min 1; `prohibited` for `key`, `key_hash`, `sys_userid`, `sys_groupid`, `id`, `scope`; after validation resolve `client_id` through `ApiKeyService::resolveClientIdentity()` and add a 422 `errors.client_id` message when it returns `null`
- [x] T017 [US1] Create `app/Http/Controllers/Api/V1/ApiKeyController.php` with a thin `store(StoreApiKeyRequest)` → `ApiKeyService::mint()` → 201 JSON = `ApiKeyService::present([$key])[0]` plus `key` (the plaintext appears only here)
- [x] T018 [US1] Register `Route::post('system/api-keys', [ApiKeyController::class, 'store'])` in `routes/api/system.php` with a `// API Keys — api/modules/system/api-keys.yaml` comment, placed so static `system/api-keys` routes precede the `{apiKey}` routes added in US2
- [x] T019 [US1] Run `php artisan test --filter='ApiKeyManagementApiTest|ModuleGateTest|CreateApiKeyClientIdTest'` (`tests/Feature/ApiKeyManagementApiTest.php`, `tests/Feature/ModuleGateTest.php`) until green

**Checkpoint**: WHMCS provisioning can obtain client-scoped keys over HTTP (SC-001).

---

## Phase 4: User Story 2 - List, inspect and revoke keys (Priority: P2)

**Goal**: Admin keys list (filters, sort, pagination), show, rename, deactivate/re-activate and delete keys;
self-revocation is refused; deleting a client through the API deactivates its keys.

**Independent Test**: Mint admin, client A and client B keys; `GET /system/api-keys?client_id=A` → only A's
key (`meta.total = 1`); `PUT {active:false}` → next request with that key 401; `{active:true}` restores;
`DELETE` → 204 then 401; `DELETE /clients/{A}` → A's keys inactive.

### Tests for User Story 2 (REQUIRED) ⚠️

- [x] T020 [P] [US2] Extend `tests/Feature/ApiKeyManagementApiTest.php`: list envelope `{data, meta}` with `limit`/`offset`; sort whitelist (`id`, `name`, `active`, `created_at`, `last_used_at`) and 400 for other sort values or unknown params; filters `client_id` (client and reseller ids, no match → empty, non-integer → 400), `active` (true/false), `name` (`*` wildcard and exact match); show fields incl. `scope: unbound` for a key whose `sys_user` row was deleted; rename → 200; `active:false` → `GET /api/v1/ping` with that key 401 with the standard invalid-key body; `active:true` → 200 again; different `client_id` → 422, same `client_id` → 200; prohibited fields → 422; deactivating or deleting the key that authenticates the request → 409 (renaming or re-activating it → 200); `DELETE` → 204 then 401; unknown id → 404 on show/update/delete; plaintext and `key_hash` absent from every list/show/update body (SC-003)
- [x] T021 [P] [US2] Create `tests/Feature/ClientDeleteRevokesKeysTest.php`: client A with one CLI-minted (`ApiKey::mint` with A's identity) and one HTTP-minted key → `DELETE /api/v1/clients/{A}` → both inactive; client B's key and the admin key stay active (SC-005)
- [x] T022 [P] [US2] Add `['GET', '/api/v1/system/api-keys']`, `['GET', '/api/v1/system/api-keys/1']`, `['PUT', '/api/v1/system/api-keys/1']`, `['DELETE', '/api/v1/system/api-keys/1']` to `adminOnlyOperations()` in `tests/Feature/ModuleGateTest.php`

### Implementation for User Story 2

- [x] T023 [P] [US2] Create `app/Http/Requests/UpdateApiKeyRequest.php`: `name` sometimes filled string max 255; `active` sometimes boolean; `client_id` sometimes nullable integer, after validation compared with the key's current binding from `ApiKeyService::present()` → 422 `errors.client_id` on mismatch; same `prohibited` list as create
- [x] T024 [P] [US2] Add `deactivateForClientIdentities(array $userIds, int $groupId): int` to `app/Models/ApiKey.php`: `UPDATE api_keys SET active = 0 WHERE active = 1 AND (sys_userid IN (…) OR sys_groupid = :group)`, group clause only when `$groupId > 1`, no-op for an empty identity set
- [x] T025 [US2] Add `index`, `show`, `update`, `destroy` to `app/Http/Controllers/Api/V1/ApiKeyController.php`: `index` uses `HandlesListQuery::listQuery()` on `ApiKey::query()` with sortable `[id, name, active, created_at, last_used_at]`, default `id`, filters `['active' => 'boolean', 'name' => 'wildcard']` (confirm the `boolean` filter writes 1/0 for the plain boolean cast of `active`), extra `['client_id']` mapped to `whereIn('sys_userid', <sys_user.userid of that client>)` (non-integer → 400), page mapped through `ApiKeyService::present()`; `show` → `present()`; `update` throws `ConflictHttpException` (409) when `active` becomes false and `$request->attributes->get('api_key_id') === $apiKey->id`; `destroy` throws 409 for the calling key, otherwise deletes → 204
- [x] T026 [US2] Register in `routes/api/system.php`, after `POST system/api-keys` and before any parameterized route: `GET system/api-keys` (index), then `GET`/`PUT`/`DELETE system/api-keys/{apiKey}` with `->whereNumber('apiKey')` (implicit binding to `ApiKey`, 404 via the existing handler)
- [x] T027 [US2] Modify `app/Services/ClientService.php` `deleteClient()`: read the client's `sys_user.userid` list and `sys_group.groupid` before the `sys_user`/`sys_group` rows are deleted, then call `ApiKey::deactivateForClientIdentities()`; confirm it runs inside the `DB::transaction` of `app/Http/Controllers/Api/V1/ClientController.php` `destroy` so a failed delete rolls it back (FR-010)
- [x] T028 [US2] Run `php artisan test --filter='ApiKeyManagementApiTest|ClientDeleteRevokesKeysTest|ModuleGateTest|ClientApiTest'` (`tests/Feature/ClientApiTest.php` guards the delete cascade) until green

**Checkpoint**: Full key lifecycle over HTTP; revoked/deleted keys fail on the next request (SC-002).

---

## Phase 5: User Story 3 - Identify the calling key (Priority: P3)

**Goal**: Any valid key calls `GET /me` and receives its id, name, scope, `client_id`, `sys_userid` and
`sys_groupid`.

**Independent Test**: `GET /me` with the admin key → `scope: admin`; with client A's key → `scope: client`,
`client_id = A`; with a revoked key → 401.

### Tests for User Story 3 (REQUIRED) ⚠️

- [x] T029 [P] [US3] Create `tests/Feature/MeApiTest.php`: admin, reseller and client A identities (`key_id`, `name`, `scope`, `client_id`, `sys_userid`, `sys_groupid`); dev key (`config('api.dev_key')` in the testing environment) → `key_id: null`, `name: "development key"`, `scope: admin`; client key is not admin-gated (200); revoked key and missing key → 401; no `key`/`key_hash` in the body

### Implementation for User Story 3

- [x] T030 [P] [US3] Add `identity(Request $request): array` to `app/Services/ApiKeyService.php`: built from `IspContext` auth scope and request attribute `api_key_id` (name from the bound `ApiKey` row; reseller via `AuthScope::isReseller()`); dev key (no `api_key_id`) → `key_id: null`, `name: "development key"`
- [x] T031 [US3] Create `app/Http/Controllers/Api/V1/MeController.php` with a thin `show(Request)` returning `ApiKeyService::identity()`
- [x] T032 [P] [US3] Create `routes/api/me.php` (`Route::get('me', [MeController::class, 'show'])`, header comment: module owned by spec 014, spec 016 appends `GET me/servers`) and add `require __DIR__.'/api/me.php';` to `routes/api.php` inside the `api.key` group but outside every `scope.admin` group
- [x] T033 [US3] Run `php artisan test --filter='MeApiTest|ModuleGateTest'` (`tests/Feature/MeApiTest.php`) until green

**Checkpoint**: Integrations can verify stored keys (WHMCS "test connection").

---

## Phase 6: User Story 4 - CLI parity for operators (Priority: P3)

**Goal**: `api:key:list` and `api:key:revoke` (plus `ispconfig-rest key:list` / `key:revoke`) give operators
a recovery path without secrets in output.

**Independent Test**: Mint two keys with the CLI; `api:key:list` prints both without secrets;
`api:key:revoke {id}` deactivates one and it then gets 401.

### Tests for User Story 4 (REQUIRED) ⚠️

- [x] T034 [P] [US4] Create `tests/Feature/ApiKeyCommandsTest.php`: `api:key:list` prints id, name, scope, client id, active, last used and never the plaintext or hash; `--client-id` filter; `api:key:revoke {id}` → key inactive and next request 401; unknown id → non-zero exit; already inactive → notice with exit 0

### Implementation for User Story 4

- [x] T035 [P] [US4] Create `app/Console/Commands/ListApiKeys.php` (`api:key:list {--client-id=}`) rendering a table through `ApiKeyService::present()`
- [x] T036 [P] [US4] Create `app/Console/Commands/RevokeApiKey.php` (`api:key:revoke {id}`): sets `active = false`; unknown id → error, exit 1; already inactive → notice, exit 0
- [x] T037 [P] [US4] Add `cmd_key_list` / `cmd_key_revoke` (`need_root`, `run_as "$PHP_BIN" artisan api:key:list|api:key:revoke`), `key:list` / `key:revoke` case entries and header help lines next to `key:create` in `bin/ispconfig-rest`
- [x] T038 [US4] Run `php artisan test --filter=ApiKeyCommandsTest` (`tests/Feature/ApiKeyCommandsTest.php`) until green

**Checkpoint**: All four user stories independently functional.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [x] T039 [P] Document key management in `README.md`: HTTP create/list/revoke/delete under `/system/api-keys`, `GET /me`, and `ispconfig-rest key:list` / `key:revoke` in "Managing the installation"
- [x] T040 [P] Security review for FR-003: confirm no plaintext or `key_hash` in logs, exception rendering or command output (`app/Models/ApiKey.php` `$hidden`, `app/Http/Controllers/Api/V1/ApiKeyController.php`, `app/Console/Commands/ListApiKeys.php`)
- [x] T041 Review route ordering and gates in `routes/api/system.php` and `routes/api.php` (static before `{apiKey}`, `me.php` outside `scope.admin`) against constitution Principle IV
- [x] T042 Run the full suite in PHP 8.3 (`docker run … php:8.3-cli … php artisan test`, `specs/014-api-key-management/quickstart.md` §1) — all tests green, including `tests/Feature/ApiKeyAuthTest.php` and `tests/Feature/CreateApiKeyClientIdTest.php`
- [x] T043 Verify Swagger UI "Try it out" for all six operations and every documented status code (SC-004) using `api/modules/system/api-keys.yaml` and `api/modules/me/me.yaml`
- [ ] T044 After deployment to isp-test, run the manual end-to-end check and cleanup from `specs/014-api-key-management/quickstart.md` §2–§3 (shared server: remove QA keys, do not delete existing clients)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: no dependencies. T006 follows T001–T005; T009 follows T006–T008 (same `api/openapi.yaml`); T010 last.
- **Foundational (Phase 2)**: depends on Setup; T012 depends on T011; T013 after T012. Blocks all stories.
- **US1 (Phase 3)**: after Foundational.
- **US2 (Phase 4)**: after US1 (extends `ApiKeyController.php`, `routes/api/system.php`, `ApiKeyManagementApiTest.php`, `ModuleGateTest.php` created/edited in US1).
- **US3 (Phase 5)**: after Foundational; independent of US1/US2.
- **US4 (Phase 6)**: after Foundational; independent of US1–US3.
- **Polish (Phase 7)**: after the desired stories are complete.

### Within Each User Story

- Tests first (must fail) → Form Request / model helper → service method → controller → routes → test run.
- Static `system/api-keys` routes before `system/api-keys/{apiKey}` (T018 → T026).
- T027 (client delete cascade) depends on T024 (model helper).
- T031 (controller) depends on T030 (service method).

### Parallel Opportunities

- Setup: T001–T005, T007, T008 in parallel.
- US1: T014, T015, T016 in parallel.
- US2: T020, T021, T022, T023, T024 in parallel.
- After Foundational, US3 (T029–T033) and US4 (T034–T038) can run alongside US1/US2 — they touch different files (US3 adds `identity()` to `ApiKeyService.php`; coordinate if US4 work edits the same file, which it does not).

## Parallel Example: User Story 2

```bash
# Tests together:
Task: "Extend tests/Feature/ApiKeyManagementApiTest.php with list/show/update/delete/409/404 cases"
Task: "Create tests/Feature/ClientDeleteRevokesKeysTest.php"
Task: "Add GET/PUT/DELETE /system/api-keys rows to tests/Feature/ModuleGateTest.php"

# Independent implementation files together:
Task: "Create app/Http/Requests/UpdateApiKeyRequest.php"
Task: "Add deactivateForClientIdentities() to app/Models/ApiKey.php"
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 1 (contracts) → Phase 2 (ApiKeyService + CLI refactor)
2. Phase 3 (US1) → **validate**: an admin key mints a client key that is correctly scoped
3. This alone unblocks WHMCS provisioning (create client, mint key)

### Incremental Delivery

1. US1 → minting (MVP)
2. US2 → revocation lifecycle and client-delete cascade (needed before production use: termination and leaked keys)
3. US3 → `GET /me` (WHMCS "test connection", spec 016 builds `GET /me/servers` on this module)
4. US4 → CLI list/revoke recovery path
5. Polish → docs, security review, full suite, Swagger, isp-test check

---

## Cross-feature Notes

- **014 owns the `me` module layout**: `api/modules/me/_index.yaml`, `api/modules/me/me.yaml` and
  `routes/api/me.php` (T008, T032). Spec 016 extends these files with `api/modules/me/servers.yaml` and
  `GET me/servers`; if 016 is implemented first it creates the same layout and 014 adds `me.yaml` to it.
- **Spec 015 (`X-Change-Set-Id`)**: 015 adds the header to responses of writes that journal to
  `sys_datalog`. `/system/api-keys` writes only the API-owned `api_keys` table, so these operations get
  no header and their contract operations need no `ChangeSetId` reference.
- **CLAUDE.md**: the SPECKIT block is edited on every plan branch; resolve the trivial conflict on merge.

## Notes

- [P] tasks = different files, no dependencies on incomplete tasks.
- No legacy ISPConfig source applies (ISPConfig `remote_user` is a different credential system — spec
  Parity section); parity-relevant logic is the feature 011 client identity resolution reused in T011.
- Commit after each task or logical group; stop at any checkpoint to validate a story independently.
