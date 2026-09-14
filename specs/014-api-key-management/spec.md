# Feature Specification: API Key Management over HTTP

**Feature Branch**: `014-api-key-management`  
**Created**: 2026-09-14  
**Status**: Draft  
**Module**: system (plus a cross-cutting caller identity endpoint)  
**Input**: User description: "HTTP API key management for admin integrations: create client-scoped or admin API keys, list, revoke and inspect keys, and identify the calling key"

## Context

API keys can currently only be minted on the ISPConfig host with `php artisan api:key:create`
(or `ispconfig-rest key:create`), optionally bound to a client with `--client-id` (feature 011
FR-019). There is no way to list keys, revoke one, or create one remotely; revocation today means
editing the `api_keys` table by hand.

Billing and provisioning systems (the first consumer is a WHMCS module) must create an ISPConfig
client and hand that client's automation a **client-scoped** key, without shell access to the
ISPConfig host. Client-scoped keys are the isolation boundary built by features 011/012: a key bound
to a client sees and mutates only that client's rows and is capped by its limits. This feature makes
that boundary usable by remote integrations.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Mint a client-scoped key remotely (Priority: P1)

A provisioning system holds an admin key. When a customer orders hosting it creates the ISPConfig
client (`POST /clients`) and then requests a key bound to that client
(`POST /system/api-keys` with `name` and `client_id`). The response contains the plaintext key once;
the provisioning system stores it and uses it for every later request made on the customer's behalf.

**Why this priority**: Without it, a remote integration either needs shell access to the ISPConfig
host or must serve customers with the admin key, which defeats the client isolation of features
011/012. This alone is a viable MVP for provisioning.

**Independent Test**: Seed client A (with its `sys_group`/`sys_user` pair) and client B. With an admin
key, `POST /system/api-keys` `{"name": "whmcs service 17", "client_id": A}` → 201 with the plaintext
key and metadata. Using the returned key, `GET /mail/domains` returns only A's domains and
`GET /servers` returns 403. With a client key, `POST /system/api-keys` → 403. No `sys_datalog` row is
written.

**Acceptance Scenarios**:

1. **Given** an admin key and an existing client with a control-panel user, **When** it creates a key
   with `name` and `client_id`, **Then** 201 returns the key's metadata plus the plaintext key, and the
   key authenticates with that client's identity and scope (same identity resolution as the CLI
   `--client-id`).
2. **Given** an admin key, **When** it creates a key without `client_id`, **Then** 201 returns an
   admin-scoped key bound to the ISPConfig admin identity.
3. **Given** a `client_id` that does not exist, or a client without a control-panel user, **When** a key
   is created, **Then** 422 problem+json names the `client_id` field and no key is stored.
4. **Given** a client or reseller key, **When** it calls any `/system/api-keys` endpoint, **Then** 403
   (admin-only module gate of feature 011).
5. **Given** a key was created, **When** any later request reads it, **Then** the plaintext and the stored
   hash are never returned.

---

### User Story 2 - List, inspect and revoke keys (Priority: P2)

An operator or provisioning system lists existing keys (optionally filtered by client or state),
inspects one, renames it, revokes it (deactivates) or deletes it. When a customer's service is
terminated, the provisioning system revokes the customer's key; when the ISPConfig client is deleted
through the API, its keys are revoked automatically.

**Why this priority**: Keys must be revocable for a safe lifecycle (termination, leaked key, staff
change). It builds on US1 but is independently testable with keys minted by the CLI.

**Independent Test**: Mint three keys (admin, client A, client B). `GET /system/api-keys?client_id=A`
returns only A's key with `meta.total = 1`. `PUT /system/api-keys/{id}` `{"active": false}` → 200; the
revoked key then gets 401 on `GET /ping`; `{"active": true}` restores access. `DELETE` → 204 and the key
no longer authenticates. `DELETE /clients/{A}` → A's keys become inactive.

**Acceptance Scenarios**:

1. **Given** keys exist, **When** an admin key lists them, **Then** 200 returns `{data, meta}` with
   `limit`/`offset`/`sort`/`order` and optional filters `client_id`, `active` and `name`; unknown query
   parameters return 400.
2. **Given** a key id, **When** it is shown, **Then** 200 returns id, name, scope (`admin`, `reseller` or
   `client`), bound `client_id` (null for admin keys), active flag, created time and last-used time.
3. **Given** an active key, **When** it is updated with `active: false`, **Then** 200 and the next request
   authenticated with it returns 401 with the standard invalid-key problem body.
4. **Given** a key, **When** it is updated with a new `name`, **Then** 200 and only the label changes; the
   key's binding (`client_id`/identity) cannot be changed after creation (422 if sent with a different
   value).
5. **Given** the key used to authenticate the current request, **When** that same request tries to
   deactivate or delete it, **Then** 409 problem+json and nothing changes.
6. **Given** a client with keys, **When** the client is deleted through `DELETE /clients/{id}`, **Then** all
   keys bound to that client are deactivated in the same operation.
7. **Given** an unknown key id, **When** it is shown, updated or deleted, **Then** 404 problem+json.

---

### User Story 3 - Identify the calling key (Priority: P3)

An integration verifies a stored key before using it (for example a "test connection" button, or
checking that a saved customer key still belongs to the expected client). It calls `GET /me` with the
key and receives the key's identity and scope.

**Why this priority**: Improves diagnostics and lets consumers detect mis-bound or revoked keys early,
but provisioning works without it.

**Independent Test**: `GET /me` with an admin key → 200 with scope `admin`; with client A's key → 200 with
scope `client` and `client_id = A`; with a revoked key → 401.

**Acceptance Scenarios**:

1. **Given** any valid key, **When** it calls `GET /me`, **Then** 200 returns key id, name, scope,
   `client_id` and the ISPConfig user and group it acts as.
2. **Given** the local development key, **When** it calls `GET /me`, **Then** 200 returns scope `admin`
   with a null key id and a name identifying it as the development key.

---

### User Story 4 - CLI parity for operators (Priority: P3)

An operator on the ISPConfig host lists and revokes keys with `ispconfig-rest key:list` and
`ispconfig-rest key:revoke ID` (backed by artisan commands), as a recovery path when no admin key is
available remotely.

**Why this priority**: Recovery and convenience; the HTTP endpoints cover integrations.

**Independent Test**: Mint two keys with the CLI; `key:list` prints both without secrets;
`key:revoke {id}` deactivates one and it then gets 401.

**Acceptance Scenarios**:

1. **Given** keys exist, **When** `key:list` runs, **Then** it prints id, name, scope, client id, active and
   last used, never the hash.
2. **Given** an active key id, **When** `key:revoke` runs, **Then** the key is deactivated and the command
   reports it; an unknown id exits non-zero.

### Edge Cases

- Missing or invalid `X-API-Key` on any endpoint → 401 (existing behavior).
- `client_id` that is zero, negative or not an integer → 422.
- A reseller's `client_id` (a client row with `limit_client > 0`) → the key is bound to the reseller's
  identity and resolves to reseller scope (feature 011); this is allowed because it is a client row.
- A key whose bound ISPConfig user was deleted outside the API (legacy panel) → it keeps failing closed
  with 401 (feature 011 FR-005); list/show report it as bound to a missing identity and it can be
  revoked or deleted.
- `name` empty or longer than 255 characters → 422. Duplicate names are allowed.
- Request body containing `key`, `key_hash`, `sys_userid` or `sys_groupid` → the fields are rejected
  with 422 (the key and identity are never client-supplied).
- Sorting by an unsupported column → 400 (shared list conventions).
- Concurrent revocation of a key that is being used → requests after the revocation commits return 401.

## API Contract *(mandatory)*

- **Spec file(s)**: `api/modules/system/api-keys.yaml` (new — to be authored first);
  `api/modules/system/me.yaml` or a root-level path file for `GET /me` (new).
- **Shared schemas**: `api/components/schemas/ApiKey.yaml` (new), `ApiKeyCreate.yaml` (new),
  `ApiKeyUpdate.yaml` (new), `ApiKeyCreated.yaml` (new — metadata plus one-time `key`),
  `ApiKeyIdentity.yaml` (new, for `GET /me`). Shared list parameters and problem responses are reused.
- **Endpoints**:

| Method | Path | Purpose | Success code |
|--------|------|---------|--------------|
| GET | `/api/v1/system/api-keys` | List keys (paginated `{data, meta}`; filters `client_id`, `active`, `name`) | 200 |
| GET | `/api/v1/system/api-keys/{id}` | Show key metadata | 200 |
| POST | `/api/v1/system/api-keys` | Create admin or client-bound key; response includes plaintext once | 201 |
| PUT | `/api/v1/system/api-keys/{id}` | Rename or activate/deactivate | 200 |
| DELETE | `/api/v1/system/api-keys/{id}` | Delete key permanently | 204 |
| GET | `/api/v1/me` | Identity and scope of the calling key (any valid key) | 200 |

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference**: none — ISPConfig's own remote API users (`remote_user`, `admin/remote_user_edit.php`)
  are a different credential system and are not mirrored. Identity resolution for `client_id` mirrors
  this project's CLI (`sys_group.client_id` → `sys_user.default_group`, feature 011 FR-019).
- **Legacy behaviors to mirror**: none beyond the existing AuthScope resolution (feature 011).
- **Tables written (via datalog only)**: none. Only the API-owned `api_keys` table is written; it is
  exempt from the datalog rule (constitution Code Boundaries). No ISPConfig table is read-modified.
- **System fields handling**: not applicable; the key stores the resolved `sys_userid`/`sys_groupid` it
  acts as, never supplied by the caller.
- **Intentional deviations from legacy**: client deletion through the API additionally deactivates the
  client's API keys (no legacy equivalent; API-owned data only).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST let admin keys create an API key with a `name` and an optional `client_id`;
  without `client_id` the key is bound to the ISPConfig admin identity.
- **FR-002**: System MUST resolve `client_id` to the client's control-panel identity exactly as the CLI
  `--client-id` does, and MUST reject unknown clients or clients without a control-panel user with 422.
- **FR-003**: System MUST return the plaintext key only in the create response and MUST store only its
  hash; no other response, log line or command output may contain the plaintext or the hash.
- **FR-004**: System MUST restrict all `/system/api-keys` endpoints to admin-scoped keys; client and
  reseller keys receive 403 before any query runs.
- **FR-005**: System MUST list keys with the shared `{data, meta}` envelope, `limit`/`offset`/`sort`/
  `order`, and filters `client_id`, `active` and `name` (`*` wildcard match like other modules, exact match without `*`; owner decision
  2026-09-14); unknown parameters return 400.
- **FR-006**: Key representations MUST include id, name, scope (`admin`, `reseller`, `client`, or
  `unbound` when the bound identity no longer exists), `client_id`, active flag, created time and
  last-used time.
- **FR-007**: System MUST allow renaming and activating/deactivating a key; the key's identity binding
  MUST be immutable after creation.
- **FR-008**: A deactivated or deleted key MUST fail authentication with the existing 401 invalid-key
  problem body on the next request.
- **FR-009**: System MUST refuse (409) deactivating or deleting the key that authenticates the request.
- **FR-010**: Deleting a client through the API MUST deactivate every key bound to that client within the
  same operation.
- **FR-011**: `GET /me` MUST return the calling key's id, name, scope, `client_id`, and acted-as ISPConfig
  user and group, for any valid key including the local development key.
- **FR-012**: The CLI MUST offer `api:key:list` and `api:key:revoke {id}` (and the `ispconfig-rest`
  manager wrappers `key:list`, `key:revoke`) with output that never includes secrets.
- **FR-013**: Every endpoint MUST be defined in the OpenAPI contract before implementation and covered by
  feature tests for success, validation failure, 401 and 403 cases.

### Key Entities

- **API Key**: a credential for this API, bound to one ISPConfig identity (admin, reseller or client) —
  table `api_keys` (API-owned), schema `api/components/schemas/ApiKey.yaml`, model `app/Models/ApiKey.php`.
- **Caller Identity**: the resolved identity and scope of the key making a request — schema
  `api/components/schemas/ApiKeyIdentity.yaml`, derived from the existing AuthScope.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A provisioning system with an admin key can create a client and obtain a working
  client-scoped key in two API calls, with no access to the ISPConfig host.
- **SC-002**: 100% of requests made with a key after it is deactivated or deleted are rejected.
- **SC-003**: The plaintext key appears in exactly one response (creation); automated tests confirm no list,
  show, update, identity or CLI output contains the plaintext or the hash.
- **SC-004**: All endpoints listed in the API Contract render in Swagger UI and behave as documented,
  including 400/401/403/404/409/422 cases.
- **SC-005**: Deleting a client through the API leaves zero active keys bound to that client.

## Assumptions

- Key management is admin-only; resellers minting keys for their own clients is out of scope (possible
  follow-up feature).
- Keys have no expiry date; rotation is done by creating a new key and revoking the old one.
- Keys can only be bound to an admin identity or to a client row by `client_id`; binding to arbitrary
  `sys_userid`/`sys_groupid` pairs stays a CLI-only capability.
- No separate audit log is introduced; `created_at` and `last_used_at` are the available trail.
- The existing `api_keys` table is sufficient; if a column is needed (for example to record the key that
  created another key), it is added by an API-owned migration.
- Clients deleted outside the API (legacy panel) do not trigger key deactivation; their keys already fail
  closed.
