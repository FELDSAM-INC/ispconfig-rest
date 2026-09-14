# Research: API Key Management over HTTP

**Feature**: 014-api-key-management | **Date**: 2026-09-14

The Technical Context has no open NEEDS CLARIFICATION items; the decisions below settle the design
choices the spec leaves to planning. Each was checked against the current code on branch `main`
(`ApiKeyAuth`, `AuthScope`, `IspContext`, `HandlesListQuery`, `CreateApiKey`, `ClientService`,
`routes/api.php`, `routes/api/system.php`, `api/openapi.yaml`).

## R1 — Where key management lives

- **Decision**: `/system/api-keys` in the existing `system` module (`routes/api/system.php`,
  `api/modules/system/api-keys.yaml`).
- **Rationale**: `routes/api.php` already wraps `system.php` in `scope.admin`, so FR-004 (403 for
  client/reseller keys before any query) comes for free and is covered by the `ModuleGateTest` pattern.
- **Alternatives considered**: a new top-level `/api-keys` module (needs its own admin gate wiring and a
  new tag with no gain); `/auth/keys` (mixes admin CRUD with caller-identity concerns).

## R2 — Caller identity endpoint and the `me` module

- **Decision**: `GET /me` in a new `me` module: `api/modules/me/_index.yaml`, `api/modules/me/me.yaml`,
  `routes/api/me.php` required from `routes/api.php` inside `api.key` but outside `scope.admin`,
  `App\Http\Controllers\Api\V1\MeController`. Spec 016 adds `api/modules/me/servers.yaml` and one route
  (`GET me/servers`) to the same files.
- **Rationale**: every valid key must reach it; one module file per concern matches the project's
  "one route file per module" rule and avoids 014/016 editing unrelated files.
- **Alternatives considered**: `api/modules/system/me.yaml` (system is admin-only — wrong gate);
  a root path file (breaks the per-module layout).

## R3 — Scope values in key representations

- **Decision**: derive `scope` per key as `admin` | `reseller` | `client` | `unbound` and `client_id`
  from `sys_user`/`client`, batched per page (one `whereIn` read per table) in
  `ApiKeyService::present()`.
- **Rationale**: `api_keys` stores only `sys_userid`/`sys_groupid`; the same derivation that
  `ApiKeyAuth::resolveScope` and `AuthScope::isReseller()` perform must be reported without per-row
  queries. `unbound` makes keys whose ISPConfig user was deleted in the legacy panel visible and
  revocable (spec edge case).
- **Alternatives considered**: storing `client_id`/`scope` columns on `api_keys` (a migration and stale
  data when clients change in the legacy panel); omitting scope (consumers could not tell what a key is).

## R4 — `client_id` resolution shared by CLI and HTTP

- **Decision**: move `CreateApiKey::resolveClientIdentity()` into `ApiKeyService::resolveClientIdentity()`
  returning `[userid, groupid]` or `null`; the command keeps its two distinct error messages by checking
  the group and user steps through the service; HTTP returns one 422 on `client_id`.
- **Rationale**: FR-002 requires the HTTP binding to match the CLI exactly; one implementation removes
  drift risk. `CreateApiKeyClientIdTest` guards the refactor.
- **Alternatives considered**: duplicating the lookup in the controller (two sources of truth).

## R5 — Self-revocation guard (FR-009)

- **Decision**: compare the bound key id with request attribute `api_key_id` (already set by
  `ApiKeyAuth`) on `PUT` with `active: false` and on `DELETE`; 409 problem+json.
- **Rationale**: zero new plumbing; the dev key sets no `api_key_id` and has no row, so it can never
  match.
- **Alternatives considered**: blocking deactivation of the last admin key (not required; CLI remains
  the recovery path and would add a count query per request).

## R6 — Client deletion cascade (FR-010)

- **Decision**: in `ClientService::deleteClient`, capture `sys_user.userid` of the client before step 2
  deletes them; afterwards deactivate keys where `sys_userid IN (userids)` or `sys_groupid = groupid`
  (only when `groupid > 1`), inside the existing transaction of `ClientController::destroy`.
- **Rationale**: CLI `--sys-userid/--sys-groupid` can bind keys to either half of the identity; matching
  both covers all client-bound keys while never touching the admin group 1. Deactivation (not deletion)
  keeps an audit trail and matches the spec wording (owner decision 2026-09-14).
- **Alternatives considered**: deleting the keys (loses `last_used_at` history); relying on fail-closed
  401 only (spec SC-005 requires zero active keys).

## R7 — Rejected body fields

- **Decision**: Laravel `prohibited` rule for `key`, `key_hash`, `sys_userid`, `sys_groupid`, `id`,
  `scope` in both Form Requests (422 with `errors` map).
- **Rationale**: spec edge case requires rejection rather than silent ignore; `prohibited` yields the
  standard validation problem body.
- **Alternatives considered**: ignoring unknown fields (the project default elsewhere) — rejected because
  these specific fields signal a caller trying to set identity or secrets.

## R8 — `name` filter semantics

- **Decision**: `name` uses the shared `wildcard` filter type (`*` → `LIKE` with `%`/`_` escaped, exact
  match without `*`); `active` uses the shared `boolean` filter type; sort whitelist
  `id, name, active, created_at, last_used_at`, default `id`.
- **Rationale**: same filter behaviour as every other list endpoint (owner decision 2026-09-14).
- **Alternatives considered**: case-insensitive substring match as an `extra` list parameter (rejected by the
  owner: it would differ from the project convention).

## R9 — Verification environment

- **Decision**: run the suite with PHP ≥ 8.3 (`php artisan test`, SQLite in-memory); on this workstation
  PHP is 8.1, so verification uses a PHP 8.3 container or the test server `isp-test.feldhost.cz`
  (`/opt/ispconfig-rest`, PHP 8.3) after deployment — see quickstart.md.
- **Rationale**: composer requires `php ^8.3`; results on 8.1 would not be representative.
- **Alternatives considered**: none.
