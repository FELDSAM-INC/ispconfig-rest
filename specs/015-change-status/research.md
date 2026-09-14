# Research: Change Status for API Writes

**Feature**: 015-change-status | **Date**: 2026-09-14

Sources: this repository at `main` 06dc558 (DatalogService, IspContext, DataLog model, Monitor
DataLogController, HandlesListQuery, AuthScope, bootstrap/app.php, tests/Support schemas); ISPConfig
3.3.1p1 installed on isp-test.feldhost.cz, read-only (`server/lib/classes/modules.inc.php`,
`server/server.php`, `server/lib/classes/cron.d/200-logfiles.inc.php`,
`interface/lib/classes/db_mysql.inc.php`); live `dbispconfig` schema, read-only.

## R1 — Change set identity

- **Decision**: The change set id is the existing `sys_datalog.session_id`. `IspContext::sessionId()`
  already generates one 32-hex-character id per request and `DatalogService::log()` writes it on every
  entry, including cascades.
- **Rationale**: Zero schema change, same grouping semantics as legacy PHP session ids, and entries
  written before the feature ships are already grouped.
- **Alternatives considered**: a new API-owned change-set table (duplicates the journal, needs writes
  inside every transaction); encoding the first `datalog_id` into the id for faster lookup (changes the
  meaning of `session_id` for ISPConfig tooling and the spec's assumption); an index on `session_id`
  (forbidden, R7).

## R2 — Processed state

- **Decision**: For an entry with target `server_id = s`, the responsible servers are `s` (if active) and
  every active server with `mirror_server_id = s`; for `s = 0`, every active server. The entry is
  processed when `datalog_id <= min(updated)` over that set. An empty set means `stalled`.
- **Rationale**: Mirrors legacy exactly:
  - `modules.inc.php::processDatalog()` selects `datalog_id > last_datalog_id AND (server_id = own OR
    server_id = mirror_server_id OR server_id = 0)` and sets `server.updated` to each processed
    `datalog_id` (lines 105-110, 199-239).
  - `server.php:76` starts from `max(master updated, local updated)`.
  - `db_mysql.inc.php::datalogStatus()` counts pending entries with
    `(server.server_id = sys_datalog.server_id OR sys_datalog.server_id = 0) AND datalog_id >
    server.updated AND server.active = 1`.
- **Alternatives considered**: per-entry comparison with only the target server's watermark (wrong for
  `server_id = 0` and mirrors); treating inactive servers as pending forever (legacy hides them; the spec
  adds `stalled`).
- **Edge**: an entry whose target server row was deleted has no responsible server → `stalled`.

## R3 — Failure detection

- **Decision**: `failed` = processed and `sys_datalog.error` is non-empty. The `status` column is
  ignored.
- **Rationale**: In 3.3.1p1 `sys_datalog.status` is never updated (all 81 live rows are `ok`);
  processing errors are written by `server/lib/classes/db_mysql.inc.php::datalogError()` from the
  apache2, nginx and bind plugins. The error can be written before the watermark advances, so an entry
  with an error stays `pending` until processed.
- **Alternatives considered**: reading `status` (always `ok`, useless); surfacing the error while still
  pending (the spec requires `failed` only once processed).

## R4 — Emitting `X-Change-Set-Id`

- **Decision**:
  - `IspContext` gains a request-scoped journal counter: `recordJournalEntry()` and
    `journalEntryCount()`.
  - `DatalogService::log()` increments it after each successful insert.
  - A new response middleware `App\Http\Middleware\AttachChangeSetId` (alias `change.set`) sets the header
    when the response status is 2xx and the counter is above 0.
  - It is applied to the whole authenticated group in `routes/api.php` (`middleware(['api.key',
    'change.set'])`) and placed after `ApiKeyAuth` in the middleware priority list.
- **Rationale**: One place covers all 148 write operations, including cascades, resync and future writes.
  No-change updates (DatalogService suppresses the row), validation failures (non-2xx) and writes to
  API-owned tables (no journal) naturally get no header. `IspContext` is already the request-scoped
  identity holder that DatalogService uses.
- **Alternatives considered**: setting the header in each controller (148 sites, easy to forget); a DB
  query listener on `sys_datalog` inserts (couples to SQL text); computing it in `ApiKeyAuth` after
  `$next()` (mixes auth with response decoration).
- **Note**: there is no `config/cors.php`, so browsers cannot read the header cross-origin unless it is
  added to exposed headers. The first consumer (WHMCS) calls the API server-side, so this is a follow-up,
  not part of this feature.

## R5 — Visibility

- **Decision**:
  - Non-admin keys: `sys_datalog.user = IspContext::username()` for the list and change-set lookups.
  - Admin keys: no restriction.
  - Record view (`table` + `record_id`): resolve the table through a fixed map of API-exposed tables to
    primary keys, load the record with `AuthScope::applyReadPredicate('r')`, and if it is readable list
    entries with `dbtable = table AND dbidx = "<pk>:<id>"` from any writer.
  - Tables without sys fields (`sys_ini`, `client_template_assigned`) are readable only by admin keys.
  - A missing record gives 404, and a table outside the map with `record_id` gives 400.
- **Rationale**: Legacy `datalogStatus()` scopes by username; `DatalogService` writes the same
  `IspContext::username()`, so API and legacy panel writes of one identity are both visible (owner
  decision 2026-09-14). The record check reuses spec 011's single predicate source.
- **Alternatives considered**: all entries of every readable record in the general list (needs a
  per-table permission join over the journal; rejected by the owner); scoping by `sys_groupid` (the
  journal has no permission columns).

## R6 — Status filter with correct pagination

- **Decision**: `ChangeStatusResolver` builds SQL predicates from the server rows:
  - `pending`: `OR (server_id = s AND datalog_id > T(s))` for each non-stalled target `s`, including 0;
  - `stalled`: `server_id NOT IN (non-stalled targets)`;
  - `failed`: not pending, not stalled, and `error <> ''`;
  - `applied`: not pending, not stalled, and `error IS NULL OR error = ''`.
  The same resolver computes each returned entry's status in PHP.
- **Rationale**: `meta.total` and paging stay correct when filtering by status. The server table holds a
  handful of rows, so the predicate stays small.
- **Alternatives considered**: filtering in PHP after paging (wrong totals); a SQL join to `server` with
  aggregates (mirror handling becomes a correlated subquery per row).

## R7 — Performance without new indexes

- **Decision**: No index is added. Change set lookups scan `sys_datalog` by `session_id`, lists scan by
  `user`, and the record view scans by `dbtable`/`dbidx`; all are bounded by the journal's size.
- **Rationale**:
  - The constitution forbids schema changes to ISPConfig tables.
  - ISPConfig keeps the journal small: `200-logfiles.inc.php:300` deletes processed entries older than the
    retention per server, and only `server_id = 0` and unprocessed entries are kept (81 rows on the test
    server).
  - A full scan of tens of thousands of short rows takes milliseconds in MySQL, well inside SC-005 (a set of
    up to 50 entries within 1 second).
  - Status computation is one extra query for the server table.
- **Alternatives considered**: an index on `session_id` (schema change, overwritten by ISPConfig
  updates); caching status (stale by design, the watermark changes every minute).
- **Risk**: very large sets (admin resync writes thousands of entries with one id) return all entries;
  acceptable for admin-only tooling and noted in the contract description of the change set response.

## R8 — Contract mechanics

- **Decision**:
  - New module `api/modules/changes/` (`_index.yaml`, `changes.yaml`), schemas `Change.yaml` and
    `ChangeSet.yaml`.
  - New components section `headers` with `ChangeSetId.yaml`, referenced from all 149 inline 2xx responses
    of the 148 write operations in 54 files, added by text edit.
  - Unit test `ChangeSetHeaderContractTest` (symfony/yaml) enforces the reference on every write.
- **Rationale**: Owner decision (header on every write). All 2xx write responses are inline, so no `$ref`
  sibling problem. The lint test keeps future features compliant.
- **Alternatives considered**: documenting the header once in the API description only (not
  machine-readable, rejected by the owner); a YAML dump rewrite (destroys comments and ordering).

## R9 — List parameters

- **Decision**:
  - `limit`/`offset` are shared.
  - `order` accepts `asc`/`desc` and defaults to `desc` (by `datalog_id`); there is no `sort` parameter, and
    sending `sort` gives 400.
  - Filters: `status` (enum), `table` (exact `dbtable`), `change_set_id` (exact `session_id`), `since`
    (ISO 8601, compared as `tstamp >=`), and `record_id` (integer ≥ 1, requires `table`).
  - Unknown parameters give 400 through `HandlesListQuery`.
- **Rationale**: The spec asks for newest first and these filters only; `HandlesListQuery` already
  enforces unknown-parameter rejection and pagination bounds.
- **Alternatives considered**: reusing `/monitor/data-logs` parameters (`start_date` unix timestamps,
  `dbtable`), which would leak admin-view naming into the customer view.

## R10 — Legacy deviations kept

- **Decision**: No table ignore list; add `stalled`; per-entry status instead of per-table counts; record
  view for readable records; no payloads or usernames for any key.
- **Rationale**: Recorded in the spec's Intentional deviations; each avoids reporting `applied` while work
  is outstanding or leaking tenant data.

## R11 — Legacy panel entries

- **Decision**: Entries written by the ISPConfig panel (26-character PHP session ids) are listed like
  API entries of the same identity and can be looked up by their session id; the path parameter accepts
  `[A-Za-z0-9,-]{1,64}`.
- **Rationale**: Live data has both 26- and 32-character ids; PHP session ids use that character set.
