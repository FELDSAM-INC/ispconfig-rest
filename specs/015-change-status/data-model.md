# Data Model: Change Status for API Writes

**Feature**: 015-change-status | **Date**: 2026-09-14

The feature is a read model. It introduces no table, column, index or migration (constitution:
no schema changes against ISPConfig tables). All entities below are derived at request time from
`sys_datalog` and `server`, plus one piece of request-scoped runtime state on `IspContext`.

## Source tables (read-only)

### `sys_datalog` (ISPConfig journal; model `App\Models\DataLog`, existing, read-only)

| Column | Type (3.3.1p1) | Used as |
|--------|----------------|---------|
| `datalog_id` | int unsigned PK | `Change.id`; compared with server watermarks |
| `server_id` | int unsigned | target server; `0` = every server |
| `dbtable` | varchar(255) | `Change.table` |
| `dbidx` | varchar(255) `"<pk>:<value>"` | `Change.record_id` (value part) and record filter |
| `action` | char(1) `i/u/d` | `Change.action` (`create/update/delete`) |
| `tstamp` | int (unix time) | `Change.created_at`; `since` filter |
| `user` | varchar(255) | non-admin visibility (writer username); never returned |
| `data` | longtext | never read by this feature |
| `status` | set, always `ok` in 3.3 | ignored (ISPConfig never updates it) |
| `error` | longtext | `Change.error`; drives `failed` |
| `session_id` | varchar(64) | `Change.change_set_id` / `ChangeSet.id` |

Indexes on 3.3.1p1: `PRIMARY(datalog_id)`, `server_id(server_id, status)`. No index on
`session_id`, `user`, `dbtable` or `dbidx` (see research R7).

### `server` (model `App\Models\Server`, existing)

| Column | Used as |
|--------|---------|
| `server_id` | server identity |
| `active` | only active servers are responsible (legacy `datalogStatus()`) |
| `mirror_server_id` | a mirror processes its master's entries (`modules.inc.php::processDatalog()`) |
| `updated` | watermark: id of the last journal entry the server processed |

## Derived entities

### ResponsibleServers (per target server id)

Computed once per request from all `server` rows (a handful of rows).

- For `server_id = s > 0`: `R(s) = {s if active} ∪ {m | m.mirror_server_id = s AND m.active}`.
- For `server_id = 0`: `R(0) = {every active server}`.
- `T(s) = min(updated over R(s))` — the entry is processed when `datalog_id <= T(s)`.
- `R(s) = ∅` (server inactive without active mirror, server deleted, or no active server) → the entry is
  stalled.

### Change (schema `api/components/schemas/Change.yaml`)

| Field | Derivation |
|-------|-----------|
| `id` | `datalog_id` |
| `change_set_id` | `session_id` |
| `table` | `dbtable` |
| `record_id` | integer after the first `:` in `dbidx`; `null` if not an integer |
| `action` | `i → create`, `u → update`, `d → delete` |
| `status` | see state rules below |
| `error` | `error` verbatim, only when `status = failed` |
| `created_at` | `tstamp` as ISO 8601 |

**Status rules (FR-003/FR-004)**, evaluated in order:

1. `R(server_id) = ∅` → `stalled`
2. `datalog_id > T(server_id)` → `pending`
3. `error` is not null and not empty → `failed`
4. otherwise → `applied`

**State transitions**

```text
pending ──(all responsible watermarks pass)──▶ applied | failed
pending ──(responsible servers deactivated)──▶ stalled
stalled ──(a responsible server reactivated)──▶ pending
applied | failed ──(ISPConfig log cleanup purges the row)──▶ not found (404)
```

### ChangeSet (schema `api/components/schemas/ChangeSet.yaml`)

- `id` = the shared `session_id`; `entries` = visible entries with that `session_id`, oldest first.
- `created_at` = earliest entry's `tstamp`.
- `status` (FR-005): `pending` if any entry pending → else `stalled` if any stalled → else `failed` if any
  failed → else `applied`.
- Not found (404): no visible entry with that id, or id longer than 64 characters / outside
  `[A-Za-z0-9,-]`.

## Visibility rules (FR-006/FR-008/FR-009)

| Key scope | Default list and change set lookup | Record view (`table` + `record_id`) |
|-----------|-----------------------------------|-------------------------------------|
| admin (`AuthScope::isAdmin`) | all entries | all entries of the record; the record need not exist |
| client / reseller | `sys_datalog.user = IspContext::username()` | entries of the record from any writer, only if the record exists and passes `AuthScope::applyReadPredicate('r')`; otherwise 404 |

Record view table map (tables exposed as API resources with sys fields; `web_domain` covers vhosts and
child domains):

`client`, `client_circle`, `domain`, `client_template`, `cron`, `directive_snippets`, `dns_rr`, `dns_slave`,
`dns_soa`, `dns_ssl_ca`, `dns_template`, `ftp_user`, `mail_access`, `mail_content_filter`, `mail_domain`,
`mail_forwarding`, `mail_get`, `mail_relay_domain`, `mail_relay_recipient`, `mail_transport`, `mail_user`,
`mail_user_filter`, `server`, `firewall`, `server_ip`, `server_ip_map`, `server_php`, `shell_user`,
`spamfilter_policy`, `spamfilter_users`, `spamfilter_wblist`, `web_database`, `web_database_user`,
`webdav_user`, `web_domain`, `web_folder`, `web_folder_user`, plus `client_template_assigned` and `sys_ini`
(no sys fields — readable only by admin keys). Any other table with `record_id` → 400.

Fields never returned to any key: `data`, `user`, `server_id`, `status` column, raw `dbidx`.

## Request-scoped runtime state

### `IspContext` journal counter (new)

- `recordJournalEntry(): void` — called by `DatalogService::log()` after each successful insert.
- `journalEntryCount(): int` — read by the response middleware.
- Lifetime: one request (scoped singleton); `sessionId()` already provides the change set id.

The `X-Change-Set-Id` header is set when the response status is 2xx and `journalEntryCount() > 0`
(FR-001).
