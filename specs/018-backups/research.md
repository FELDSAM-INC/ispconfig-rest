# Research: Website & Database Backups (018)

Legacy behaviour was verified read-only against the live ISPConfig 3.3.1p1 installation on
`isp-test.feldhost.cz` (`/usr/local/ispconfig`, `dbispconfig`), because `source_code/` is not part of
this checkout. File references below use the same relative layout as `source_code/`.

## R1 — Remote actions are inserted directly into `sys_remoteaction`

- **Decision**: Backup, restore, download and delete are queued by inserting rows into
  `sys_remoteaction` with a direct insert (no datalog), byte-compatible with legacy:
  `server_id`, `tstamp = UNIX_TIMESTAMP()` (PHP `time()`), `action_type`, `action_param` (the id as a
  string), `action_state = 'pending'`, `response = ''`.
- **Rationale**: `interface/lib/classes/plugin_backuplist.inc.php` (`makeBackup()` and the
  `backup_action` branches of `onShow()`) inserts exactly these rows with plain SQL. Server daemons read
  actions only from this table: `server/lib/classes/modules.inc.php::processActions()` selects
  `sys_remoteaction WHERE server_id = <local> AND action_id > <maxid>` and raises the plugin action; the
  datalog has no mechanism to trigger an action. Principle II exception, justified in plan.md
  Complexity Tracking.
- **Alternatives considered**: datalog entry on `web_domain` (no server plugin reacts to it for
  backups); a new API-owned queue table (servers would never read it).

## R2 — Exact action rows per operation

| Operation | `action_type` | `action_param` | `server_id` |
|-----------|---------------|----------------|-------------|
| Back up website files | `backup_web_files` | website `domain_id` | website `server_id` |
| Back up databases | `backup_database` | website `domain_id` | one row per `DISTINCT web_database.server_id WHERE parent_domain_id = W` |
| Restore | `backup_restore` | `backup_id` | `web_backup.server_id` if `> 0`, else website `server_id` |
| Download | `backup_download` | `backup_id` | same as restore |
| Delete | `backup_delete` | `backup_id` | same as restore |

Source: `plugin_backuplist.inc.php` (`makeBackup()`, `onShow()` action branches);
`server/plugins-available/backup_plugin.inc.php::onLoad()` registers exactly these five action names
(mail variants are out of scope).

## R3 — Pending de-duplication and races

- **Decision**: Inside `DB::transaction`, lock the website row (`web_domain ... FOR UPDATE` via
  `lockForUpdate()`), then count `sys_remoteaction WHERE action_state = 'pending' AND action_type = ?
  AND action_param = ?`; any match → 409 problem+json, nothing inserted. `backup_database` checks once per
  (type, website) like legacy, then inserts one row per database server.
- **Rationale**: legacy performs the same count-then-insert without locking. The website row lock
  serialises concurrent API requests for the same website (all backup actions of a website hang off it)
  without a new table; on SQLite (tests) the lock is a no-op and tests stay deterministic. Requests from
  the legacy panel are not serialised — same as legacy itself.
- **Alternatives considered**: `GET_LOCK()` named locks (MySQL-only, leaks on connection reuse);
  Laravel cache locks (the `file` cache store breaks with more than one API host); unique index on
  `sys_remoteaction` (schema change to an ISPConfig table — forbidden).

## R4 — Job state mapping

- **Decision**: expose `action_state` as `state`: `pending`, `ok`, `warning`, `error`; any other stored value
  (in practice the empty string) is exposed as `error`.
- **Rationale**: `processActions()` stores whatever `raiseAction()` returns.
  `backup_plugin::backup_action()` returns `'ok'`, `'error'`, or `null` (bare `return;`) when the server has
  no `backup_dir`; with the ISPConfig default `sql_mode = NO_ENGINE_SUBSTITUTION` (verified on isp-test)
  MySQL stores the invalid enum value as `''`. `make_backup_callback()` always returns `'ok'`, even when no
  backup was produced (spec FR-013). No backup handler returns `warning`, but the enum allows it.
- **Alternatives considered**: a `stalled`/`unknown` state (not defined by legacy; consumers only need
  "finished with a problem").

## R5 — Attributing jobs to a website without a new table

- **Decision**: a job belongs to website W when
  `(action_type IN ('backup_web_files','backup_database') AND action_param = W)` or
  `(action_type IN ('backup_restore','backup_download','backup_delete') AND action_param IN
  (SELECT backup_id FROM web_backup WHERE parent_domain_id = W))`. `action_param` is longtext; ids are
  compared as strings.
- **Rationale**: every spec flow stays attributable — restore and download keep their backup row; the
  delete endpoint returns 204 (no job to poll), and consumers see the deletion in the backup list. A
  completed delete job disappears from the job list together with its backup row; documented. This also applies to
  delete jobs queued from the legacy ISPConfig panel, which cannot be matched to their website afterwards —
  accepted (owner decision 2026-09-14).
- **Alternatives considered**: an API-owned `backup_jobs` mapping table. Rejected: `ispconfig-rest update`
  runs `artisan migrate` with the runtime DB user, which has no `CREATE` right (the installer creates
  `api_keys` with a privileged login only once), so a new table breaks updates of existing installations.
  Storing metadata in `sys_remoteaction.response` was rejected because legacy writes `''` there and spec
  FR-015 requires legacy-identical rows.

## R6 — Backup visibility and list order

- **Decision**: list `web_backup WHERE parent_domain_id = W AND server_id IN (website server_id ∪ DISTINCT
  web_database.server_id of W)`, default order `tstamp DESC, backup_type ASC`; public sort names
  `created_at` (→ `tstamp`) and `id` (→ `backup_id`); filters `type` (→ `backup_type`) and `job`
  (`manual` → `filename LIKE 'manual-%'`, `auto` → `NOT LIKE`).
- **Rationale**: identical to `plugin_backuplist::onShow()`. `web_backup` has no `sys_*` fields; visibility
  derives from the website binding (read predicate, spec 011).
- **Implementation note**: `HandlesListQuery::listQuery()` gains two optional, backward-compatible named
  arguments — `defaultOrder` (default `'asc'`) and `sortAliases` (public name → column, default `[]`) —
  because this is the first list whose natural order is newest first and whose public field names differ
  from the columns. Existing callers are unaffected; covered by a dedicated test.

## R7 — Derived backup fields

- `backup_format`: for `backup_mode = 'borg'` use the website's `backup_format_db` / `backup_format_web`
  (falling back to `getDefaultBackupFormat('rootgz', 'mysql')` = `gzip`, or
  `getDefaultBackupFormat($mode, 'web')` = `zip` for `userzip`, else `tar_gzip`, when empty or `default`);
  otherwise the stored value, or the same defaults when empty (old ISPConfig backups).
- `filename`: borg archives get the extension from `getBackupDbExtension()` / `getBackupWebExtension()`
  (ported verbatim: `.sql.gz`, `.sql.bz2`, `.sql.xz`, `.zip`, `.rar`, `.sql.7z`, `.tar.gz`, `.tar.bz2`,
  `.tar.xz`, `.tar.7z`).
- `encrypted`: borg → website `backup_encrypt = 'y'` and non-empty `backup_password`; otherwise non-empty
  `web_backup.backup_password`. The password is never returned.
- `job`: `manual` when the filename starts with `manual-`, else `auto`.
- `download_available`: `web_backup.server_id == website server_id`.
- `filesize`: integer bytes (`web_backup.filesize` is varchar), `null` when empty; `filesize_approximate`
  true for borg (legacy tooltip "Final download size may vary").
- `database_name`: for `mysql`/`mongodb` rows, captured from the filename with
  `^(manual-)?db_(?<db>.+)_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}` (the pattern `backup.inc.php::downloadBackup()`
  uses), `null` when it does not match.

Source: `plugin_backuplist.inc.php` (record loop and the three static helpers).

## R8 — Permissions, gates and response order

- **Decision**, evaluated in this order:
  1. website not readable or missing → 404 (existing route binding with the read predicate);
  2. website `type != 'vhost'` → 404;
  3. non-admin key and the key's client row missing or `limit_backup != 'y'` → 403;
  4. restore, delete, on-demand backup and settings update require `u` on the website
     (`AuthScope::allows($website->getAttributes(), 'u')`) → 403; list, show, jobs, settings read and
     download need only `r` (already guaranteed by step 1);
  5. request validation → 422 (including download of a backup on another server, and a database backup
     for a website without databases);
  6. backups not configured on a target server → 409;
  7. pending duplicate → 409.
- **Rationale**: `web_vhost_domain.tform.php` offers the Backup tab only for `vhostdomain_type == 'domain'`
  and, for non-admins, only when the client row joined through the user's default group has
  `limit_backup = 'y'` — a missing client row also hides the tab. `plugin_backuplist::onShow()` checks
  `getAuthSQL('u')`, or `'r'` for download.
- **Deviation noted**: the "no client row" case differs from `ClientLimitService::resourceEnabled()`, which
  treats keys without a client row as unlimited for numeric limits; the backup gate follows the tform.
- Steps 2–3 run in a route middleware (`scope.backup`, class `RequireBackupAccess`) applied to the backup
  route group, after route-model binding, so step 1's 404 still wins (verified by a test alongside
  `AuthBeforeBindingTest`).

## R9 — "Backups available" check

- **Decision**: a server can process backup actions when its `server.config` INI `[server] backup_dir` is
  non-empty (`ServerConfigService::getSection($server, 'server')`). Settings report `backups_available` for
  the website's server. Every action checks each target server; if any lacks `backup_dir`, 409 and nothing
  is queued.
- **Rationale**: `backup_plugin::backup_action()` returns early without doing anything when `backup_dir` is
  empty (and the job ends with state `''`, R4). Checking up front avoids silently useless jobs (spec
  FR-012, intentional deviation already in the spec). isp-test has `backup_dir=/var/backup`,
  `backup_mode=rootgz`.

## R10 — Missing compression utilities

- **Decision**: settings expose read-only `missing_utils` from the newest `monitor_data` row of type
  `backup_utils` for the website's server (`unserialize(data)['missing_utils']`), `null` when there is no
  row.
- **Rationale**: `web_vhost_domain.tform.php` shows this list so users do not pick unusable formats. The
  legacy local-server branch (probing binaries on the panel host) is not reproduced — the API cannot probe
  web servers and the collector covers every server.

## R11 — Settings validation and write path

- **Decision**: `PUT /sites/web-domains/{id}/backup-settings` accepts `backup_interval`
  (`none|daily|weekly|monthly`), `backup_copies` (`1..10, 15, 20, 30`), `backup_excludes` (regex
  `@^(?!.*\.\.)[-a-zA-Z0-9_/.~,*]*$@`, max 255), `backup_format_web`
  (`default, zip, zip_bzip2, tar_gzip, tar_bzip2, tar_xz, tar_7z_lzma2, tar_7z_lzma, tar_7z_ppmd,
  tar_7z_bzip2`), `backup_format_db` (`zip, zip_bzip2, gzip, bzip2, xz, 7z_lzma2, 7z_lzma, 7z_ppmd,
  7z_bzip2`), `backup_encrypt` (boolean ↔ `y/n`), `backup_password` (write-only, max 255). Enabling
  encryption with no stored and no supplied password → 422. The change is written with
  `WebDomain::forceFill($native)->save()`, which applies the `u` write gate and writes one `web_domain`
  `u` datalog entry (suppressed when nothing changed).
- **Rationale**: values and regex copied from `web_vhost_domain.tform.php` (Backup tab field definitions);
  legacy stores `backup_password` as plain text (TEXT field without encryption), so the API does too.
  `backup_format_*`, `backup_encrypt` and `backup_password` stay hidden on the web-domain resource.
- **Alternatives considered**: `DatalogService::updateRecord()` (bypasses the model write gate).
- **Owner decision 2026-09-14**: the same `backup_copies` rule (one shared list, `WebBackupService::BACKUP_COPIES`)
  replaces the `min:1|max:30` rule of `WebDomainRequest`, so `POST`/`PUT /sites/web-domains` also accept only
  `1..10, 15, 20, 30` for all keys (spec FR-016); an intentional behaviour change for the release candidate.

## R12 — `limit_backup` on existing endpoints

- **Decision**: a request concern `EnforcesBackupLimit` used by `WebDomainRequest` (store/update) and the
  store/update web-database requests throws `AuthorizationException` (403) when any `backup_*` key is
  present in the input and the backup gate (R8 step 3) fails. Admin keys are unaffected.
- **Rationale**: spec US2 scenario 6 / FR-009; legacy hides the fields together with the tab.

## R13 — Download delivery (version 1)

- **Decision**: a download job with `state = ok` whose backup still exists carries
  `download = {path: "backup/<filename>", filename, available_until}` where `available_until =
  created_at + 3 days` is documented as the earliest removal time. Nothing is served over HTTP.
- **Rationale**: `backup.inc.php::downloadBackup()` writes the file to `<document_root>/backup/` (directory
  secured to `root:<group>` 0750); `backup.inc.php` removes files there whose mtime is at least
  `60*60*24*3` seconds old (line 1821). The file is created when the server processes the job, which is
  never earlier than the job's `tstamp`. Owner decision 2026-09-14: folder delivery only.

## R14 — Test fixtures

- **Decision**: extend `tests/Support/SitesSchema.php` with `sys_remoteaction` (columns as live `DESCRIBE`:
  `action_id` PK, `server_id`, `tstamp`, `action_type`, `action_param` text nullable, `action_state` string
  default `pending`, `response` text nullable) and a `monitor_data` table when absent; seed
  `server.config` with an INI blob containing `[server]\nbackup_dir=/var/backup` (and a variant without it);
  `web_backup` already exists in `SitesSchema`. Tenancy through `TenantFixtures` (`seedTenants`, `ownedBy`,
  `tenantHeaders`); the `client.limit_backup` column already exists in `ClientSchema`.

## Parity re-check on isp-test (T049, 2026-09-15)

Read-only against ISPConfig 3.3.1p1 on isp-test (`plugin_backuplist.inc.php`, `web_vhost_domain.tform.php`,
`backup.inc.php`):

- **R2 rows**: unchanged — `makeBackup()` inserts `backup_web_files` on the website's server or one
  `backup_database` row per `SELECT DISTINCT server_id FROM web_database`; restore, download and delete use
  the backup's `server_id` when > 0, else the website's; all rows `(…, UNIX_TIMESTAMP(), type, param,
  'pending', '')`; pending check by state, type and param.
- **R7 derived fields**: unchanged — `download_available` is `backup.server_id == website.server_id`;
  `backup::downloadBackup()` appends the web/db extension to borg archive names and uses the website's
  format and password, as the API's `filename` and `download.path` do.
- **R8 gate**: the tform reads `limit_backup` of the client joined through the user's default group.
- **R11 validation**: interval, copies, excludes regex and max 255, both format lists, `backup_encrypt`
  `n`/`y` and `backup_password` max 255 match exactly.
- **Intentional differences (no change)**: a database backup of a website without databases is 422 (legacy
  reports success and queues nothing); a target server without `backup_dir` is 409 (R9); enabling
  encryption without any password is 422 (the tform has no validator and would produce unencrypted borg
  archives with an empty password); a download of a backup on another server is 422 (legacy only hides the
  button).
