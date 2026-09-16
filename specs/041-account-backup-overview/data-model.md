# Data Model: Account-Wide Backup Overview

Read-only feature. No table is created, altered or written; the entities below are response shapes folded
from existing ISPConfig rows.

## Source tables (all read-only)

| Table | Columns used | Purpose |
|---|---|---|
| `web_domain` | `domain_id`, `domain`, `type`, `server_id`, `document_root`, `system_user`, `system_group`, `backup_format_web`, `backup_format_db`, `backup_encrypt`, `backup_password`, `sys_*` | the account's websites (scoped read predicate, `type = 'vhost'`) and the fields `backupRepresentation()` needs |
| `web_backup` | `backup_id`, `server_id`, `parent_domain_id`, `backup_type`, `backup_mode`, `backup_format`, `tstamp`, `filename`, `filesize`, `backup_password` | the backups themselves |
| `web_database` | `parent_domain_id`, `server_id` | database servers of the page's websites (R4) |
| `server` | `server_id`, `config` | `backup_dir` → `backups_available` (R6) |
| `client` | `client_id`, `limit_backup` | the plan gate (R5) |

## Response entities

### AccountBackupOverview (one per website)

| Field | Type | Source | Notes |
|---|---|---|---|
| `web_domain_id` | integer | `web_domain.domain_id` | |
| `domain` | string | `web_domain.domain` | page ordered by this, ascending |
| `server_id` | integer | `web_domain.server_id` | the website's own server only |
| `backups_available` | boolean | `WebBackupService::backupsAvailable(server_id)` | resolved once per distinct server of the page |
| `total` | integer | count of visible `web_backup` rows of the website | 0 when none |
| `latest` | array of `WebBackup` | newest visible row per `backup_type` | `[]` when none; newest first |

### `latest` entries (one per backup type present)

Each entry is the shipped **`WebBackup`** schema, produced by `WebBackupService::backupRepresentation()` and
returned unchanged — the overview defines no representation of its own, so every value is byte-identical to
the corresponding field of `GET /sites/web-domains/{id}/backups` (SC-003).

| Field | Type | Notes |
|---|---|---|
| `id` | integer | `backup_id` |
| `server_id` | integer | server storing the backup |
| `parent_domain_id` | integer | the website |
| `backup_type` | string | `web`, `mysql`, `mongodb` |
| `database_name` | string\|null | parsed from the filename for database backups |
| `backup_mode` | string | server backup mode (`rootgz`, `userzip`, `borg`, …) |
| `backup_format` | string\|null | resolved format, including the borg/repos rules of spec 018 |
| `filename` | string | archive file name |
| `filesize` | integer\|null | bytes; null when the server recorded none |
| `filesize_approximate` | boolean | true for borg repositories |
| `created_at` | string (date-time) | API timezone, from `tstamp` |
| `job` | string | `manual` or `auto`, from the filename prefix as legacy does |
| `encrypted` | boolean | |
| `download_available` | boolean | false when the backup's server differs from the website's |

### Meta

Standard list meta: `total` (the account's vhost websites), `limit` (default 25, max 100), `offset`.

## Query plan (FR-008, research R3)

For one request, regardless of the number of websites:

1. `count` of the account's vhost websites (meta.total).
2. One page of websites (scoped read predicate + `type = 'vhost'`, ordered by `domain`, `limit`/`offset`).
3. One grouped `web_database` query for the database server ids of those websites.
4. One `web_backup` query for all website ids of the page, ordered newest first.
5. One `server` query for the distinct server ids of the page (`backup_dir`).
6. One `client` read for the plan gate (before anything else; refuses early).
7. One `sys_remoteaction` query for backups with a pending download action, so each entry's `download.state`
   can report `preparing` (added by spec 042; one query per page, never per backup).

Newest-per-type and per-website totals are folded in PHP from the single result of step 4.

## Refusals

| Situation | Status | Problem type | Member |
|---|---|---|---|
| client/reseller key whose client lacks `limit_backup` | 403 | `feature-not-allowed` | `feature: limit_backup` |
| admin key without `client_id` | 422 | `validation-failed` | `client_id` |
| unknown or foreign client id | 404 | `about:blank` | — |
| unknown query parameter | 400 | `about:blank` | — |
| non-numeric or non-positive `client_id` | 422 | `validation-failed` | `client_id` |
