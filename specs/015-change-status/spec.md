# Feature Specification: Change Status for API Writes

**Feature Branch**: `015-change-status`  
**Created**: 2026-09-14  
**Status**: Draft  
**Module**: cross-cutting (read model over the `sys_datalog` journal; write responses of all modules)  
**Input**: User description: "Let client-scoped (and admin) API keys find out whether their writes have been applied by ISPConfig, and see failures. Writes are journaled to sys_datalog and applied asynchronously by server daemons; /monitor/data-logs is admin-only (spec 011), so a client-scoped key cannot show change pending / applied / failed today."

## Context

Every write in this API is journaled to `sys_datalog` and applied later by ISPConfig's server daemons
(typically within a minute). A successful response confirms the journal entry, not the applied change
(constitution Principle II). The only way to observe processing today is `GET /monitor/data-logs`,
which is admin-only because the journal has no permission columns and its payloads contain other
tenants' data (spec 011 FR-013/FR-014).

A customer-facing control panel (the first consumer is a WHMCS module that serves each customer with a
client-scoped key) must show "being applied", "done" or "failed" for the customer's own changes, and
must not claim a change is live before ISPConfig has applied it. Legacy ISPConfig gives every logged-in
user, clients included, exactly this: a pending-changes indicator scoped to the user's own writes
(`db_mysql.inc.php::datalogStatus()`, polled by `web/datalogstatus.php`) and, on some edit forms, the
last processing error of the record.

This feature adds a scoped, payload-free status view of the journal and a change reference on write
responses. The admin monitor endpoints stay unchanged.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Track one write until it is applied (Priority: P1)

A control panel creates a website with the customer's client-scoped key. The 201 response carries a
change set id in the `X-Change-Set-Id` header. The panel shows "being applied" and polls
`GET /changes/{change_set_id}` until the status becomes `applied` (show "done") or `failed` (show the
error), then refreshes the site.

**Why this priority**: This is the minimum a customer panel needs to be truthful about asynchronous
writes, and it works for any resource without per-resource logic. Viable MVP on its own.

**Independent Test**: Seed server 1 with `server.updated = N`. With client A's key `POST /mail/domains`
→ 201 with `X-Change-Set-Id`. `GET /changes/{id}` → `pending` with one entry (`table = mail_domain`,
action `create`). Set `server.updated` to the entry id → `applied`. Repeat with `sys_datalog.error`
set on the entry → `failed` with the error text. With client B's key → 404. No `sys_datalog` row is
written by the status calls.

**Acceptance Scenarios**:

1. **Given** a write request that journals one or more entries, **When** it succeeds, **Then** the
   response includes `X-Change-Set-Id` identifying all entries written by that request (for example a
   delete with cascades).
2. **Given** a write request that changes nothing (no journal entry), **When** it succeeds, **Then** no
   `X-Change-Set-Id` header is returned and the consumer can treat the change as applied.
3. **Given** a change set whose entries are not yet processed by every responsible server, **When** its
   status is requested, **Then** 200 with status `pending` and each entry's own status.
4. **Given** all entries processed and none carrying an error, **When** status is requested, **Then**
   `applied`.
5. **Given** a processed entry with an error recorded by ISPConfig, **When** status is requested,
   **Then** that entry is `failed` with the error text, and the set is `failed` once nothing is pending.
6. **Given** a change set written by another identity that the key may not see, or an unknown id,
   **When** status is requested, **Then** 404 problem+json.
7. **Given** a change set with more entries than one page (for example an admin resync), **When** its
   status is requested with `limit`/`offset`, **Then** the set status and per-status entry counts cover all
   visible entries, while `entries` holds only the requested page and `meta` reports `total`, `limit` and
   `offset` (owner decision 2026-09-14).

---

### User Story 2 - Show the customer's pending and failed changes (Priority: P2)

The panel dashboard shows "3 changes are being applied" and lists recent failures, like the legacy
top-bar indicator. It calls `GET /changes?status=pending` (and `status=failed`) with the customer's key
and gets only changes written by that customer's identity.

**Why this priority**: Covers changes the panel did not track (other sessions, other integrations using
the same identity) and gives a global indicator, but tracking individual writes (US1) comes first.

**Independent Test**: Client A writes two entries, client B one, admin one. With A's key
`GET /changes?status=pending` → exactly A's two entries, `meta.total = 2`. Admin key → all four.
`status=failed` returns only entries with an error that are processed.

**Acceptance Scenarios**:

1. **Given** journal entries from several identities, **When** a non-admin key lists changes, **Then**
   only entries written by that key's ISPConfig identity are returned (legacy `datalogStatus()` scope),
   newest first, in the `{data, meta}` envelope.
2. **Given** an admin key, **When** it lists changes, **Then** entries of all identities are returned.
3. **Given** filters `status`, `table`, `change_set_id` and `since`, **When** they are combined,
   **Then** only matching entries are returned; unknown parameters return 400.

---

### User Story 3 - Status of changes to one record (Priority: P3)

The panel shows a "being updated" badge on a website that was changed by someone else (for example by
the provider's admin integration). It calls `GET /changes?table=web_domain&record_id=12` with the
customer's key and sees pending or failed changes to that record from any writer, provided the key can
read the record.

**Why this priority**: Useful when several writers touch the same records, but the panel's own writes
are covered by US1/US2.

**Independent Test**: Admin updates client A's web domain 12 (entry pending). With A's key
`GET /changes?table=web_domain&record_id=12` → the admin's entry is included without writer details.
With B's key → 404. Deleted records are not reachable through this view.

**Acceptance Scenarios**:

1. **Given** a record the key can read, **When** it lists changes filtered by `table` and `record_id`,
   **Then** entries for that record from any writer are returned.
2. **Given** a record the key cannot read or that does not exist, **When** it uses the record filter,
   **Then** 404 problem+json (feature 011 row semantics).
3. **Given** `record_id` without `table`, or a table not exposed by this API, **When** listing, **Then**
   400.

### Edge Cases

- Missing or invalid `X-API-Key` on status endpoints → 401.
- Journal entry with `server_id = 0` (for example client or DNS template rows) → processed by every
  active server; `applied` only when all active servers have passed it.
- Target server with active mirror servers → the entry is processed when the target server and its
  active mirrors have passed it (legacy `modules.inc.php::processDatalog()` mirror handling).
- Target server inactive and no active mirror (or no active server at all for `server_id = 0`) → the
  entry can never be processed; status `stalled` instead of an endless `pending`.
- Several requests by the same identity share nothing; each request has its own change set id.
- Different keys bound to the same client identity see each other's changes (same writer identity).
- Processed entries purged by ISPConfig's log cleanup (`cron.d/200-logfiles.inc.php`) → the change set
  is no longer found (404); only processed entries are ever purged.
- A change set id longer than 64 characters → 404.
- A change set with thousands of entries (admin resync) → status computed over all entries, entries
  returned one page at a time.
- Writes to API-owned tables only (for example API keys, feature 014) → no journal entry, no header.
- Error text recorded by ISPConfig may be multi-line output of a configuration test; it is returned
  verbatim.

## API Contract *(mandatory)*

- **Spec file(s)**: `api/modules/changes/changes.yaml` and `api/modules/changes/_index.yaml` (new — to
  be authored first), registered in `api/openapi.yaml`.
- **Shared components**: `api/components/schemas/Change.yaml` (new), `ChangeSet.yaml` (new),
  `api/components/headers/ChangeSetId.yaml` (new) referenced from every 200/201/204 response of every
  write operation (POST/PUT/DELETE) in all module files.
- **Endpoints**:

| Method | Path | Purpose | Success code |
|--------|------|---------|--------------|
| GET | `/api/v1/changes` | List journal entries visible to the key (`{data, meta}`; filters `status`, `table`, `record_id`, `change_set_id`, `since`) | 200 |
| GET | `/api/v1/changes/{change_set_id}` | Aggregate status of one change set over all entries, with its entries paginated (`limit`/`offset`, `meta`) | 200 |
| (all writes) | every POST/PUT/DELETE | Response header `X-Change-Set-Id` when at least one journal entry was written | 200/201/204 |

- **Change entry fields**: `id` (journal id), `change_set_id`, `table`, `record_id`, `action`
  (`create` / `update` / `delete`), `status` (`pending` / `applied` / `failed` / `stalled`), `error`
  (present only when `failed`), `created_at` (ISO 8601).
- **Change set fields**: `id`, `status` and `entry_counts` (per status, over all visible entries), `created_at`
  (earliest entry), `entries[]` (one page, oldest first) and `meta` (`total`, `limit`, `offset`).

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference**:
  - `interface/lib/classes/db_mysql.inc.php::datalogStatus()` and `interface/web/datalogstatus.php`:
    pending entries of the logged-in user (`sys_datalog.user = username`), processed-ness by the
    per-server watermark (`datalog_id > server.updated`), `server_id = 0` counted for every server,
    only `server.active = 1` servers; shown to every user including clients.
  - `interface/web/sites/web_vhost_domain_edit.php:754-776`, `interface/web/dns/dns_soa_edit.php:226`:
    the record's latest processed entry's `sys_datalog.error` is shown to the user editing the record.
  - `server/lib/classes/db_mysql.inc.php::datalogError()`: server plugins (apache2, nginx, bind) write
    processing errors to `sys_datalog.error`; `sys_datalog.status` is never updated (always `ok`).
  - `server/lib/classes/modules.inc.php::processDatalog()`: a server processes entries for its own id,
    its `mirror_server_id`, and `server_id = 0`, then advances `server.updated`.
  - `server/lib/classes/cron.d/200-logfiles.inc.php:300`: old entries below the watermark are purged.
- **Legacy behaviors to mirror**: user-scoped visibility, watermark-based processed state, active-server
  filter, error surfaced to the record's user.
- **Tables written (via datalog only)**: none. The feature only reads `sys_datalog` and `server`; the
  write-response header reuses the request's existing journal `session_id`.
- **System fields handling**: not applicable (read-only).
- **Intentional deviations from legacy**:
  - Status is reported per entry and per change set instead of an aggregated count per table/action.
  - The legacy table ignore list (`aps_instances`, `aps_instances_settings`, `mail_access`,
    `mail_content_filter`) is not applied, so a change set is never reported applied while an entry is
    pending.
  - `stalled` is added for entries whose responsible servers are all inactive (legacy hides them).
  - The record view (US3) shows other writers' entries for readable records; legacy only shows the
    latest error on the edit form.
  - Journal payloads (`data`) and writer usernames are never exposed to non-admin keys; `/monitor/*`
    stays admin-only (spec 011 FR-014 unchanged).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Every successful write request that journals at least one entry MUST return the header
  `X-Change-Set-Id` whose value identifies all entries written by that request; requests that journal
  nothing MUST NOT return it.
- **FR-002**: `GET /changes/{change_set_id}` MUST return the change set's status and per-status entry counts
  computed over all its visible entries, its earliest creation time, and its entries paginated with the
  shared `limit`/`offset` parameters and a `meta` object (`total`, `limit`, `offset`); unknown query
  parameters return 400. It MUST return 404 when the set does not exist or is not visible to the key
  (owner decision 2026-09-14: status over all entries, entries paginated).
- **FR-003**: An entry MUST be considered processed when every responsible server has a watermark at or
  above the entry id. Responsible servers are the active target server and its active mirror servers,
  or all active servers when the entry's `server_id` is 0.
- **FR-004**: An entry's status MUST be `pending` while not processed, `failed` when processed and
  ISPConfig recorded an error, `stalled` when it has no active responsible server, and `applied`
  otherwise. The error text MUST be returned only for `failed` entries.
- **FR-005**: A change set's status MUST be `pending` if any entry is pending, otherwise `stalled` if any
  entry is stalled, otherwise `failed` if any entry failed, otherwise `applied`.
- **FR-006**: `GET /changes` MUST return, for non-admin keys, only entries written by the key's ISPConfig
  identity, and for admin keys all entries; newest first; shared `limit`/`offset`/`order` parameters;
  unknown query parameters return 400.
- **FR-007**: `GET /changes` MUST support filters `status`, `table`, `change_set_id`, `since` (entries
  created at or after an ISO 8601 time) and `record_id` (requires `table`).
- **FR-008**: When `table` and `record_id` are given, entries for that record from any writer MUST be
  included if the key can read the record under feature 011 rules; otherwise the request MUST return
  404. `record_id` is only accepted for tables exposed as resources by this API.
- **FR-009**: Status responses MUST NOT include journal payloads, writer usernames or session details
  other than the change set id.
- **FR-010**: The status endpoints MUST be available to every valid key (not behind the admin module
  gate); `/monitor/data-logs` behavior MUST remain unchanged.
- **FR-011**: Status endpoints MUST be read-only and MUST NOT write journal entries.
- **FR-012**: The contract MUST be authored first, including the header on all write responses, and every
  endpoint and the header MUST be covered by feature tests for success, 400, 401 and 404 cases and for
  cross-tenant isolation.

### Key Entities

- **Change**: one journal entry as seen by a consumer — table `sys_datalog` (read-only), schema
  `api/components/schemas/Change.yaml`, model `app/Models/DataLog.php` (existing).
- **Change Set**: all entries written by one API request, grouped by `sys_datalog.session_id` — schema
  `api/components/schemas/ChangeSet.yaml`.
- **Server watermark**: `server.updated` (last processed journal id), `server.active`,
  `server.mirror_server_id` — read to derive processed state.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: After ISPConfig processes a change, the next status request reports it as applied or failed
  (no additional delay introduced by the API).
- **SC-002**: In automated tests, a non-admin key never receives another identity's change entries,
  payloads or usernames outside the readable-record view (0 leaks across all isolation cases).
- **SC-003**: 100% of processed entries with an error recorded by ISPConfig are reported as failed with
  that error text.
- **SC-004**: Every write operation in the contract documents `X-Change-Set-Id`; tests confirm the header
  on create, update and cascading delete, and its absence on no-change updates.
- **SC-005**: A status request answers within 1 second on a typical ISPConfig host, including change sets
  with thousands of entries (one page of entries per response).

## Assumptions

- Consumers poll; push notifications or webhooks are out of scope.
- ISPConfig 3.3 never updates `sys_datalog.status`; processed state comes from `server.updated` and
  failures from `sys_datalog.error` (verified on 3.3.1p1).
- The journal `session_id` already groups all entries of one API request (request-scoped identity
  context), so no new table or column is needed.
- Consumers keep change set ids only while tracking a change; a 404 after an entry was previously seen
  as processed means it was purged.
- Non-admin visibility is by writer identity (`sys_datalog.user`), as in legacy; keys sharing a client
  identity share visibility.
- Entries written by the legacy ISPConfig panel (PHP session ids) are visible through the list endpoint
  like any other entry of the same identity, but carry no API response header.
- Owner decisions (2026-09-14): non-admin visibility stays own writes plus the readable-record view
  (FR-004..FR-008); the header name stays `X-Change-Set-Id`, consistent with `X-API-Key`; every write
  operation in the contract documents the header.
- Browser access to `X-Change-Set-Id` (CORS exposed headers) is deferred; the first consumers (WHMCS)
  call the API server-side (owner decision 2026-09-14).
- Reseller keys see only changes written under their own username, as in legacy; changes to their
  clients' records remain available through the readable-record view (owner decision 2026-09-14).
