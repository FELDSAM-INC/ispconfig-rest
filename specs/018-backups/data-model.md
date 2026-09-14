# Data Model: Website & Database Backups (018)

No ISPConfig table schema changes. No API-owned tables are added (research R5).

## WebBackup — `web_backup` (read-only)

Model `app/Models/WebBackup.php` extends `BaseModel` (read-only: the API never saves or deletes rows;
servers create and remove them). No `sys_*` columns — visibility comes from the parent website binding.

| Column | Type (live) | API field | Notes |
|--------|-------------|-----------|-------|
| `backup_id` | int unsigned PK | `id` | |
| `server_id` | int unsigned | `server_id` | server holding the file |
| `parent_domain_id` | int unsigned | `parent_domain_id` | website (`web_domain.domain_id`) |
| `backup_type` | enum `web,mysql,mongodb` | `backup_type` | |
| `backup_mode` | varchar(64) | `backup_mode` | e.g. `rootgz`, `userzip`, `borg` |
| `backup_format` | varchar(64) | `backup_format` | derived (R7) |
| `tstamp` | int unsigned | `created_at` | ISO 8601 date-time |
| `filename` | varchar(255) | `filename` | borg: derived extension appended (R7) |
| `filesize` | varchar(20) | `filesize` | integer bytes or `null` |
| `backup_password` | varchar(255) | — | never exposed; drives `encrypted` |

Derived fields: `database_name` (nullable), `filesize_approximate`, `job` (`manual`/`auto`),
`encrypted`, `download_available` (R7).

**Visibility rule**: `parent_domain_id = W AND server_id IN (W.server_id ∪ distinct web_database.server_id
of W)` (R6).

## BackupJob — `sys_remoteaction` (insert + read)

Model `app/Models/RemoteAction.php` extends `Illuminate\Database\Eloquent\Model` (not `BaseModel`:
remote actions must not be datalogged — Principle II exception, plan Complexity Tracking). Rows are
inserted only by `RemoteActionService`.

| Column | Type (live) | API field | Notes |
|--------|-------------|-----------|-------|
| `action_id` | int unsigned PK | `id` | |
| `server_id` | int unsigned | `server_id` | processing server |
| `tstamp` | int | `created_at` | set to `time()` on insert |
| `action_type` | varchar(64) | `action` (+ `backup_type`) | `backup_web_files` → `backup`/`web`; `backup_database` → `backup`/`mysql`; `backup_restore` → `restore`; `backup_download` → `download`; `backup_delete` → `delete` |
| `action_param` | longtext | `backup_id` (restore/download/delete) | website id for backup jobs (not exposed separately) |
| `action_state` | enum `pending,ok,warning,error` | `state` | any other value → `error` (R4) |
| `response` | longtext | — | inserted as `''`; not exposed |

Derived: `backup_type` for restore/download/delete comes from the referenced `web_backup` row
(`null` if gone); `download` object for `download` jobs in state `ok` whose backup still exists (R13).

**Attribution rule**: R5.

### State transitions

```text
pending ──(server.sh processes the action)──► ok | warning | error | '' (exposed as error)
```

A job is never re-queued or updated by the API. Duplicate prevention applies only while `pending` (R3).

## BackupSettings — `web_domain` backup columns (read + datalog update)

Existing model `app/Models/WebDomain.php`. Written with `forceFill()->save()` (datalog `u`).

| Column | API field | Validation (update) |
|--------|-----------|---------------------|
| `backup_interval` | `backup_interval` | `none, daily, weekly, monthly` |
| `backup_copies` | `backup_copies` | one of `1–10, 15, 20, 30` (same rule on `POST`/`PUT /sites/web-domains`, FR-016, owner decision 2026-09-14) |
| `backup_excludes` | `backup_excludes` | nullable, max 255, regex `@^(?!.*\.\.)[-a-zA-Z0-9_/.~,*]*$@` |
| `backup_format_web` | `backup_format_web` | `default, zip, zip_bzip2, tar_gzip, tar_bzip2, tar_xz, tar_7z_lzma2, tar_7z_lzma, tar_7z_ppmd, tar_7z_bzip2` |
| `backup_format_db` | `backup_format_db` | `zip, zip_bzip2, gzip, bzip2, xz, 7z_lzma2, 7z_lzma, 7z_ppmd, 7z_bzip2` |
| `backup_encrypt` | `backup_encrypt` | boolean (`y/n`); `true` requires a stored or supplied password |
| `backup_password` | `backup_password` (write-only) | string, max 255, stored as plain text (legacy) |

Read-only fields: `backup_password_set` (boolean), `backups_available` (website server has
`backup_dir`, R9), `missing_utils` (array of strings or `null`, R10).

## Supporting reads

- `server.config` INI blob, section `[server]`, key `backup_dir` (R9) — via `ServerConfigService`.
- `monitor_data` newest row `type = 'backup_utils'` for the website's server (R10).
- `client.limit_backup` of the key's client (R8).
- `web_database.server_id` of the website's databases (R2, R6).
