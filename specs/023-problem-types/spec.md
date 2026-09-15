# Feature Specification: Machine-Readable Problem Types

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-15  
**Status**: Draft  
**Module**: cross-cutting (client / dns / mail / sites / backups)  
**Input**: User description: "Machine-readable problem types: problem+json responses use generic `type` today; the module matches `detail` texts to recognise account locked (019 guard, 018 backups while locked), limit reached (012 count and quota limits), server not assigned (016), not allowed by plan (020). Introduce stable `type` URIs plus extension members where useful (`limit`: name, used, max; `field`) without changing status codes or detail texts; contract components for each type; tests; README section. Backwards compatible."

## Context

Every error of this API is an RFC 9457 problem with `type: about:blank`. Integrations that need to react to a specific
refusal — the WHMCS ISPConfig module shows "your hosting is suspended", "upgrade your plan" or "this option is not
in your plan" — have to compare the English `detail` text, which breaks as soon as a text is reworded and cannot
tell a count limit from a disk quota. RFC 9457 designates the `type` URI and extension members for exactly this.

This feature gives the refusals that consumers act on a stable `type` URI and structured extension members, and
marks field-level validation errors of the same kinds, while status codes, titles and detail texts stay unchanged.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Recognise a suspended account without parsing text (Priority: P1)

The panel tries to enable a website or start a backup for a customer whose account an administrator locked. It
receives 403 with `type` ending in `#account-locked` and shows the suspended notice, whatever the detail text says.

**Why this priority**: the lock message is the one the module needs on every write screen; matching its text is
the most fragile heuristic in the module today.

**Independent Test**: lock a client and send the refused writes of spec 019 (re-enable, add) and spec 018
(start, restore, delete backup, change settings) with the client key; assert `type` and unchanged `detail`.

**Acceptance Scenarios**:

1. **Given** a locked client, **When** its key re-enables or adds a service, **Then** 403 with
   `type = <base>#account-locked`, `title = Forbidden` and the existing detail text.
2. **Given** a locked client, **When** its key starts, restores or deletes a backup or changes backup settings,
   **Then** 403 with `type = <base>#account-locked` and the existing backup detail text.
3. **Given** an admin key, **When** it performs the same writes, **Then** no problem is returned (unchanged).

---

### User Story 2 - Tell the customer which limit was reached (Priority: P1)

A customer at the website limit of the plan tries to add one more; another exceeds the plan's disk quota with a new
mailbox. The panel receives `#limit-reached` or `#quota-exceeded` with a `limit` object naming the limit column,
whether the client's or the reseller's cap applied, the maximum and the current usage, and offers an upgrade.

**Why this priority**: count limits and quota sums share one detail text today; an upgrade offer needs to know which
limit and how far over it the request is.

**Independent Test**: seed clients at count and quota caps (client and reseller scope) and assert the extension
members.

**Acceptance Scenarios**:

1. **Given** `limit_web_domain = 1` and one website, **When** the client key creates another, **Then** 403 with
   `type = <base>#limit-reached` and `limit = {name: limit_web_domain, scope: client, max: 1, used: 1}`.
2. **Given** a reseller cap reached while the client is unlimited, **Then** `limit.scope = reseller` and the
   `Reseller:` detail text is unchanged.
3. **Given** `limit_mailquota = 1000` MB and 900 MB used, **When** a 200 MB mailbox is created, **Then** 403 with
   `type = <base>#quota-exceeded` and `limit = {name: limit_mailquota, scope: client, unit: MB, max: 1000, used: 900,
   requested: 200}`; an unlimited quota request under a finite cap gives `requested = null`.

---

### User Story 3 - Hide options the plan does not include (Priority: P2)

The panel sends a website update that enables Let's Encrypt for a customer whose plan does not include it, or uses
a server the account is not assigned to. The 422 response keeps its `errors` map and adds `error_types`, mapping each
affected field to `#feature-not-allowed` or `#server-not-assigned`, so the panel can show "not included in your
plan" next to the right field. Feature gates that refuse a whole request (backups disabled, SSL upload without the
SSL option, mail routing without its limit) answer 403 `#feature-not-allowed` with the `feature` limit column.

**Why this priority**: spec 021 already lets panels hide such options up front; typed errors are the fallback.

**Independent Test**: repeat the spec 016, 020 and gate refusals with client keys and assert `error_types`,
`feature` and unchanged messages.

**Acceptance Scenarios**:

1. **Given** a plan without Let's Encrypt, **When** a client key sets `ssl_letsencrypt = true`, **Then** 422 with
   `type = <base>#validation-failed`, the unchanged `errors.ssl_letsencrypt` message and
   `error_types.ssl_letsencrypt = <base>#feature-not-allowed`.
2. **Given** a client key sending a server that is not assigned to the account (or no server of the service is
   assigned), **Then** `error_types.server_id = <base>#server-not-assigned`.
3. **Given** a 422 with only ordinary validation errors, **Then** `type = <base>#validation-failed` and no
   `error_types` member.
4. **Given** backups are not enabled for the account, **When** a client key calls a backup endpoint or sends
   `backup_*` fields, **Then** 403 `#feature-not-allowed` with `feature = limit_backup`.
5. **Given** a plan without SSL, **When** a client key uploads a certificate, **Then** 403 `#feature-not-allowed` with
   `feature = limit_ssl`; renewal without Let's Encrypt gives `feature = limit_ssl_letsencrypt`.
6. **Given** `limit_mailrouting = 0`, **When** a client key creates a mail transport, **Then** 403
   `#feature-not-allowed` with `feature = limit_mailrouting`.

### Edge Cases

- All other problems (401, 404, 409, admin-only 403, row permission 403, 500) keep `type = about:blank`.
- Identity field errors ("cannot be changed by this account") and child-type wildcard errors are not typed: they
  are not plan features.
- `error_types` lists only fields that also appear in `errors`.
- A request refused by both an ordinary rule and a typed rule on different fields lists only the typed fields.
- The type URIs are identifiers; consumers must compare them as exact strings (they also resolve to documentation).
- Status codes, `title` and `detail` values of every existing response stay byte-identical.

## API Contract *(mandatory)*

- **Spec file(s)**: shared components only — `api/components/schemas/Problem.yaml` (type documentation),
  `api/components/schemas/ValidationProblem.yaml` (`error_types`), new `api/components/schemas/ForbiddenProblem.yaml`
  (`limit`, `feature`), new `api/components/schemas/ProblemLimit.yaml`, `api/components/responses/Forbidden.yaml`
  and `UnprocessableEntity.yaml` (schemas and examples). No path changes.
- **Documentation**: `docs/problems.md` (one section per type — the URI fragments), README "Problem types" section.
- **Endpoints**: every endpoint that already returns the refusals above; no new endpoints.

| Type (fragment) | Status | Emitted by |
|-----------------|--------|------------|
| `account-locked` | 403 | locked-client write guard (019), backups of a locked client (018 FR-017) |
| `limit-reached` | 403 | client and reseller count limits (012) |
| `quota-exceeded` | 403 | client and reseller quota sums (012) |
| `feature-not-allowed` | 403 / field | limit gates (`scope.limit`, backups), certificate operations (020 FR-008); field errors of plan flags, PHP modes/versions and administrator-only website settings (020) |
| `server-not-assigned` | field | server assignment (016) |
| `validation-failed` | 422 | every validation failure |

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference**: legacy ISPConfig reports these refusals as translated HTML error strings
  (`tform.inc.php` checkClientLimit/checkResellerLimit, `client_edit.php` lock, `web_vhost_domain_edit.php`); it has no
  machine-readable equivalent.
- **Legacy behaviors to mirror**: none changed — the checks themselves are untouched.
- **Tables written (via datalog only)**: none.
- **System fields handling**: not applicable.
- **Intentional deviations from legacy**: response metadata only (API addition).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Problem type URIs MUST be `https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#<name>`
  with the names in the API Contract table, and each name MUST have a section in `docs/problems.md`.
- **FR-002**: Locked-account refusals MUST use `account-locked`.
- **FR-003**: Count limit refusals MUST use `limit-reached` with `limit = {name, scope, max, used}` where `name` is the
  client limit column, `scope` is `client` or `reseller`, and `used` the counted rows.
- **FR-004**: Quota sum refusals MUST use `quota-exceeded` with `limit = {name, scope, unit: "MB", max, used,
  requested}`; `requested` is null when an unlimited quota was requested.
- **FR-005**: Feature gate refusals (limit gate middleware, backups not enabled — endpoint gate and `backup_*`
  fields —, certificate upload/delete without SSL, renewal without Let's Encrypt) MUST use `feature-not-allowed` with
  `feature` = the client limit column.
- **FR-006**: Every 422 validation problem MUST use `validation-failed`; when field errors come from server
  assignment (016) or website plan permissions (020 plan flags, suEXEC, error documents, directive snippets, wildcard
  under the plan, PHP mode and version, administrator-only settings), the problem MUST add
  `error_types = {field: type URI}` for exactly those fields.
- **FR-007**: Status codes, `title` and `detail` of all responses MUST stay unchanged; all other problems keep
  `about:blank`.
- **FR-008**: The OpenAPI components MUST document the types and extension members; README MUST describe them.
- **FR-009**: Feature tests MUST assert type and extension members for every emitter listed in the contract table and
  that ordinary 422s have no `error_types`.

### Key Entities

- **Problem type**: stable identifier of a refusal kind — `App\Support\ProblemType`, documented in `docs/problems.md`.
- **Limit extension**: `api/components/schemas/ProblemLimit.yaml`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A consumer can distinguish all five refusal kinds by comparing `type` only (no text matching), verified by
  tests covering every emitter.
- **SC-002**: 100% of existing tests asserting status, title or detail pass unchanged.
- **SC-003**: Limit refusals report the exact maximum and usage the check used (tests with known counts and quotas).
- **SC-004**: Every documented type URI fragment has a matching section in `docs/problems.md`.

## Assumptions

- Owner-delegated decision 2026-09-15: type URIs point at `docs/problems.md` on GitHub `main` — stable identifiers
  that also resolve to human documentation (RFC 9457 §3.1.1).
- Owner-delegated decision 2026-09-15: 422 responses keep a single `type` (`validation-failed`) and carry per-field
  types in an `error_types` extension member, because one request can fail several fields for different reasons.
- Owner-delegated decision 2026-09-15: only refusals consumers act on are typed now; other problems stay `about:blank`
  and can be typed later without breaking compatibility.
- Owner-delegated decision 2026-09-15: `limit.name`/`feature` expose ISPConfig client limit column names (already part
  of the client contract); no usage of other tenants is revealed (`used` counts only what the check counted for the
  caller's own account or its reseller).
