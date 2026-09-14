# Feature Specification: Website & Database Backups

**Feature Branch**: `018-backups`  
**Created**: 2026-09-14  
**Status**: Draft  
**Module**: sites  
**Input**: User description: "Backups for a customer hosting panel: list available website and database backups, request an on-demand backup, restore a backup, download a backup, delete a backup, and read/change a website's backup settings (interval, copies, excluded directories) where legacy allows clients to. Usable by client-scoped keys (own rows only) and admin keys."

## Context

ISPConfig keeps per-website backups of web files and MySQL databases in the server's backup directory
and records each one in `web_backup`. Customers manage them on the website's **Backup** tab: they set
the schedule, see the list, start a manual backup, and restore, download or delete a backup. None of
this is exposed by the API today — the web-domain resource exposes only `backup_interval`,
`backup_copies` and `backup_excludes`, and the backup list and actions are missing entirely.

The first consumer is a WHMCS client panel where a customer restores their own site. Backup actions in
ISPConfig are **remote actions**, not datalog changes: the panel inserts a `sys_remoteaction` row for the
server that holds the backup, and that server's `server.sh` (runs every minute) executes it and sets the
action state to `ok` or `error`.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - List backups and restore one (Priority: P1)

A customer's panel, using the customer's client-scoped key, lists the backups of one of the customer's
websites (web files and databases, newest first, with date, type, size, format, whether it was manual or
scheduled, and whether it is encrypted). The customer picks one and restores it. The API queues the
restore and returns a backup job; the panel polls the job until it finishes and tells the customer the
result.

**Why this priority**: Restoring after a mistake or a hack is the reason customers pay for backups; the list
is required to choose what to restore. This alone is a viable MVP.

**Independent Test**: Seed website W (owned by client A, `limit_backup = 'y'`) with two `web_backup` rows
(web and mysql) and website V of client B with one row. With A's key, `GET /sites/web-domains/{W}/backups`
returns W's two backups only; `GET` on V's backups → 404. `POST /sites/web-domains/{W}/backups/{id}/restore`
→ 201 with a job in state `pending`, and exactly one `sys_remoteaction` row (`backup_restore`, param = backup
id, server = backup's server) is written, no `sys_datalog` row. A second identical restore while the first is
pending → 409. Setting the action row to `ok` makes `GET .../backup-jobs/{job}` report `ok`.

**Acceptance Scenarios**:

1. **Given** a website with backups, **When** a key that can read the website lists its backups, **Then** 200
   returns `{data, meta}` ordered newest first, each with id, type (`web` or `mysql`), database name (for
   database backups), created time, size in bytes, format, `manual`/`auto` job origin, encrypted flag, and
   whether download is available.
2. **Given** a backup of a website the key can update, **When** it requests a restore, **Then** 201 returns a
   backup job (`restore`, backup id, state `pending`, created time) and the restore is queued for the server
   that stores the backup.
3. **Given** a pending restore for the same backup, **When** another restore is requested, **Then** 409
   problem+json and nothing is queued (legacy "There is already a pending backup restore job").
4. **Given** a backup id that belongs to another website or another client, **When** it is listed, shown or
   restored through a website the key cannot read, **Then** 404; through a website the key can read but not
   update, restore returns 403.
5. **Given** a client key whose client has `limit_backup = 'n'`, **When** it calls any backup endpoint, **Then**
   403 (legacy hides the Backup tab); admin keys are not limited.
6. **Given** a queued job, **When** the server processes it, **Then** the job's state becomes `ok` or `error`
   and can be read at `GET /sites/web-domains/{id}/backup-jobs/{job_id}`.

---

### User Story 2 - Back up now, delete backups, change the schedule (Priority: P2)

Before a risky change the customer starts a manual backup of the website files or of the website's
databases, and later deletes backups they no longer need. The customer also turns scheduled backups on
or off, chooses daily/weekly/monthly, the number of copies to keep, and paths to exclude; admins (and
customers where legacy allows it) choose compression format and encryption.

**Why this priority**: High value but not needed to recover a site; the scheduler keeps working with the
current settings.

**Independent Test**: With A's key, `POST /sites/web-domains/{W}/backups` `{"type": "web"}` → 201 job
(`backup_web_files`, param W, server = W's server). `{"type": "mysql"}` with W's databases on two servers →
one job per database server. Repeating while pending → 409. `DELETE /sites/web-domains/{W}/backups/{id}` → 204
and a `backup_delete` action is queued. `PUT /sites/web-domains/{W}/backup-settings`
`{"interval": "daily", "copies": 7, "excludes": "tmp/*,cache/*"}` → 200 and one `web_domain` datalog update is
written; `copies: 11` → 422; client with `limit_backup = 'n'` → 403.

**Acceptance Scenarios**:

1. **Given** a website the key can update, **When** it requests a backup of type `web`, **Then** 201 returns one
   job for the website's web server; type `mysql` queues one job per server that hosts one of the website's
   MySQL databases, and 422 if the website has no MySQL database.
2. **Given** a pending backup job of the same type for the same website, **When** another is requested, **Then**
   409.
3. **Given** a backup, **When** it is deleted, **Then** 204, a delete job is queued for the backup's server, and the
   backup disappears from the list once the server has removed it; a second delete while pending → 409.
4. **Given** a website, **When** its backup settings are read, **Then** 200 returns interval
   (`none`/`daily`/`weekly`/`monthly`), copies, excludes, web format, database format, encryption enabled
   (never the password), and whether backups are available on the website's server.
5. **Given** valid settings, **When** they are updated, **Then** 200 and the change is written as a `web_domain`
   datalog update; copies must be one of 1–10, 15, 20, 30; excludes must match the legacy pattern; formats must be
   in the legacy lists; enabling encryption requires a password.
6. **Given** the existing `PUT /sites/web-domains/{id}` and database endpoints that already carry
   `backup_interval`/`backup_copies`/`backup_excludes`, **When** a client key whose client has
   `limit_backup = 'n'` changes those fields, **Then** 403, consistent with the backup-settings endpoint.

---

### User Story 3 - Download a backup (Priority: P3)

The customer wants a copy of a backup on their own computer. They request a download; the server prepares
the file and the customer retrieves it.

**Why this priority**: Useful for migration and local archives, but restore covers recovery, and delivery
depends on a scope decision below.

**Independent Test**: With A's key, `POST /sites/web-domains/{W}/backups/{id}/download` → 201 job
(`backup_download`, param = backup id); repeating while pending → 409; for a database backup stored on a
different server than W's web server → 422 (legacy marks download unavailable).

**Acceptance Scenarios**:

1. **Given** a backup stored on the website's web server and a key that can read the website, **When** a download
   is requested, **Then** 201 returns a download job; when it completes, the backup file is available to the
   customer as described in FR-014.
2. **Given** a backup stored on another server than the website (for example a database server), **When** a
   download is requested, **Then** 422 problem+json explains download is not available for that backup.
3. **Given** a completed download job, **When** its job is read, **Then** it reports where and until when the file is
   available.

### Edge Cases

- Backups are not configured on the server (`backup_dir` empty in the server's `server` config): backup
  settings report backups unavailable; on-demand backup, restore, download and delete return 409 without
  queuing.
- Website is a subdomain or alias vhost (`type != 'vhost'`): legacy offers the Backup tab only for websites
  (vhosts) → backup endpoints on other types return 404 (resource not applicable).
- Backup made in `borg` mode: filename has no extension and the size is the repository archive size; list shows
  the final format and marks the size as approximate.
- Backup from an old ISPConfig version with empty `backup_format`: format is derived as legacy does (`gzip`
  for databases, `zip`/`tar_gzip` for web depending on mode).
- Manual backup job reaches `ok` but no new backup appears (legacy's manual backup callback always reports
  `ok`): consumers detect success by the new backup in the list; the job state alone does not guarantee a
  backup file.
- Restore of web files replaces the website's files with the backup, including removing files created after the
  backup (legacy two-pass `rsync --delete`, excluding `backup`, `log`, `ssl`, `tmp`); restore of a database
  overwrites its tables. The API documents this; confirmation is the consumer's responsibility.
- A backup row is removed by the server between listing and acting (rotation or garbage collection): acting on
  it returns 404 once the row is gone; a job already queued ends in `error`.
- Website deleted while jobs are pending: jobs end in `error`; backup rows are deleted by the existing website
  delete cascade.
- The database named in a database backup no longer exists: restore still runs as legacy does (re-creates
  tables in the database of that name if present) — outcome reported by the job state.
- Missing/invalid `X-API-Key` → 401; unknown query parameters → 400; `id`/`backup_id` not integers → 404.

## API Contract *(mandatory)*

- **Spec file(s)**: `api/modules/sites/web-backups.yaml` (new — backups, backup jobs, backup settings as
  sub-resources of web domains; to be authored first); `api/modules/sites/web-domains.yaml` and
  `api/modules/sites/databases.yaml` (existing — document the 403 for `limit_backup = 'n'` on backup fields).
- **Shared schemas**: `api/components/schemas/WebBackup.yaml`, `WebBackupJob.yaml`,
  `WebBackupCreate.yaml`, `WebBackupSettings.yaml`, `WebBackupSettingsUpdate.yaml` (new). Shared list
  parameters and problem responses are reused.
- **Endpoints**:

| Method | Path | Purpose | Success code |
|--------|------|---------|--------------|
| GET | `/api/v1/sites/web-domains/{id}/backups` | List website backups (paginated `{data, meta}`; filters `type`, `job`) | 200 |
| GET | `/api/v1/sites/web-domains/{id}/backups/{backup_id}` | Show one backup | 200 |
| POST | `/api/v1/sites/web-domains/{id}/backups` | Request an on-demand backup (`type`: `web` or `mysql`); returns job(s) | 201 |
| POST | `/api/v1/sites/web-domains/{id}/backups/{backup_id}/restore` | Queue a restore; returns job | 201 |
| POST | `/api/v1/sites/web-domains/{id}/backups/{backup_id}/download` | Queue a download preparation; returns job | 201 |
| DELETE | `/api/v1/sites/web-domains/{id}/backups/{backup_id}` | Queue deletion of a backup | 204 |
| GET | `/api/v1/sites/web-domains/{id}/backup-jobs` | List backup jobs of the website (filters `state`, `action`) | 200 |
| GET | `/api/v1/sites/web-domains/{id}/backup-jobs/{job_id}` | Show one job's state | 200 |
| GET | `/api/v1/sites/web-domains/{id}/backup-settings` | Read backup schedule and options | 200 |
| PUT | `/api/v1/sites/web-domains/{id}/backup-settings` | Update backup schedule and options (via datalog) | 200 |

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference** (ISPConfig 3.3.1p1): `interface/web/sites/form/web_vhost_domain.tform.php` (Backup tab,
  fields and `limit_backup`/vhost-type gating), `interface/lib/classes/plugin_backuplist.inc.php` (list, manual
  backup, download/restore/delete queueing, permission checks, pending de-duplication, format derivation),
  `interface/web/sites/lib/lang/en_web_backup_list.lng` (messages), `server/plugins-available/backup_plugin.inc.php`
  (action handlers), `server/lib/classes/backup.inc.php` (run/restore/download/delete, rotation, garbage
  collection), `server/lib/classes/modules.inc.php::processActions` (action state), `server/lib/classes/cron.d/500-backup.inc.php`
  (scheduled backups).
- **Legacy behaviors to mirror**:
  - Backup tab only for vhost websites; non-admins only when `client.limit_backup = 'y'`.
  - List shows `web_backup` rows of the website on the website's server and its databases' servers, newest
    first; `manual-` filename prefix means manual job; `download_available` only when the backup's server is the
    website's server; borg-mode filename extension and format derived from the website's formats.
  - Permission: download requires read permission on the website; restore and delete require update permission
    (`getAuthSQL('u')`); manual backup runs from the edit form (update permission).
  - Queueing: a new action of the same type and parameter is refused while one is `pending`; restore/download/delete
    target the backup's `server_id` (fallback the website's server); `backup_database` is queued once per distinct
    server of the website's databases; `backup_web_files` on the website's server; action parameter is the backup
    id or the website id.
  - Settings: interval `none|daily|weekly|monthly`; copies `1..10,15,20,30`; excludes regex
    `@^(?!.*\.\.)[-a-zA-Z0-9_/.~,*]*$@`; web formats `default, zip, zip_bzip2, tar_gzip, tar_bzip2, tar_xz,
    tar_7z_lzma2, tar_7z_lzma, tar_7z_ppmd, tar_7z_bzip2`; database formats `zip, zip_bzip2, gzip, bzip2, xz,
    7z_lzma2, 7z_lzma, 7z_ppmd, 7z_bzip2`; encryption flag and password. Legacy shows the compression/encryption
    options to every user who sees the Backup tab.
  - Download delivery: the server copies (or builds, for borg) the file into the website's `backup` folder
    (`<document_root>/backup`, outside the public web root, owned by the website user, mode 0640); files there older
    than 3 days are removed by the nightly backup job.
- **Tables written**:
  - `sys_remoteaction` — direct insert, **documented Principle II exception**: legacy inserts remote actions with
    direct SQL (no datalog) and server daemons read them from this table only.
  - `web_domain` — backup settings updates via datalog (`u`).
  - `web_backup` — never written by the API; rows are created and removed by the servers.
- **System fields handling**: remote actions carry no `sys_*` fields; the job is attributed to the website through its
  parameter, and job visibility is derived from the website's permissions (the requesting key's AuthScope).
- **Intentional deviations from legacy**:
  - Pending de-duplication returns 409 instead of a page message.
  - Backup endpoints on a website without backups configured on its server return 409 instead of silently queueing
    an action that the server ignores.
  - `limit_backup = 'n'` is also enforced on the backup fields of the existing web-domain and database endpoints
    (legacy achieves the same by hiding the tab).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST list a website's backups with the shared `{data, meta}` envelope, newest first, optional
  filters `type` (`web`, `mysql`) and `job` (`manual`, `auto`), scoped by the key's read permission on the website.
- **FR-002**: Backup representations MUST include id, website id, type, database name for database backups, created
  time, size in bytes, size-approximate flag, format, job origin, encrypted flag, and download availability; they
  MUST NOT include the backup password.
- **FR-003**: System MUST queue a restore for a backup of a website the key can update, targeting the server that
  stores the backup, and return a backup job.
- **FR-004**: System MUST queue an on-demand web backup (one job) or database backup (one job per database server)
  for a website the key can update, and return the job(s).
- **FR-005**: System MUST queue deletion of a backup for a website the key can update and return 204.
- **FR-006**: System MUST queue download preparation for a backup of a website the key can read, only when the backup
  is stored on the website's web server; otherwise 422.
- **FR-007**: System MUST refuse (409) a backup action whose type and target already have a pending job, without
  queuing a duplicate.
- **FR-008**: System MUST expose backup jobs of a website with action, target backup (or backup type), server, state
  (`pending`, `ok`, `warning`, `error`) and created time, scoped by the key's read permission on the website.
- **FR-009**: System MUST return 403 for all backup endpoints and backup-field changes when a client or reseller key's
  client has `limit_backup = 'n'`; admin keys are exempt.
- **FR-010**: System MUST return 404 for backup endpoints on web domains that are not vhost websites, and for backups
  or jobs that do not belong to the addressed website.
- **FR-011**: System MUST read and update a website's backup settings (interval, copies, excludes, web format, database
  format, encryption flag, write-only password) with legacy validation, writing updates through the datalog.
- **FR-012**: Backup settings MUST report whether backups are available on the website's server; when they are not,
  actions MUST return 409 without queuing.
- **FR-013**: System MUST document in the contract that restore replaces current website files (removing newer files)
  or database contents, and that job state `ok` for a manual backup does not by itself prove a backup was created.
- **FR-014**: Download delivery: a completed download job MUST tell the consumer that the file is in the website's
  `backup` folder, reachable with the website's FTP/SSH users, and is removed after 3 days. Folder delivery is the
  version 1 behaviour (owner decision 2026-09-14); direct browser download is out of scope.
- **FR-015**: Every endpoint MUST be defined in the OpenAPI contract first and covered by feature tests for success,
  validation (422), permission (403/404), duplicate (409), `limit_backup` and unconfigured-server cases, including the
  exact `sys_remoteaction` rows written.

### Key Entities

- **Website Backup**: a stored backup of a website's files or of one of its MySQL databases — table `web_backup`,
  schema `api/components/schemas/WebBackup.yaml`, model `app/Models/WebBackup.php` (new, read-only).
- **Backup Job**: a queued backup action (backup, restore, download, delete) and its state — table `sys_remoteaction`,
  schema `api/components/schemas/WebBackupJob.yaml`, model `app/Models/RemoteAction.php` (new).
- **Backup Settings**: the backup schedule and options of a website — columns `backup_*` of table `web_domain`,
  schema `api/components/schemas/WebBackupSettings.yaml`, existing model `app/Models/WebDomain.php`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A customer can find a backup and start its restore in two API calls, and learn the outcome by polling one
  job.
- **SC-002**: Every backup action queued through the API is processed by an unmodified ISPConfig 3.3 server with the
  same result as the same action started from the ISPConfig panel.
- **SC-003**: 0 backups, jobs or settings of another client are visible or actionable with a client-scoped key
  (verified by tests for list, show, actions and jobs).
- **SC-004**: 0 duplicate pending actions can be created for the same backup or website and action type.
- **SC-005**: All endpoints in the API Contract render in Swagger UI and behave as documented, including
  400/401/403/404/409/422 cases.

## Assumptions

- Mail backups (`mail_backup`, `backup_restore_mail`, `backup_delete_mail`) are out of scope; they belong to the mail
  module.
- MongoDB backups (`backup_type = 'mongodb'`) are listed if present but restore/download support follows legacy only;
  MySQL and web files are the supported types.
- Job states come only from `sys_remoteaction.action_state`; the backup plugin writes no response text, so jobs carry
  no detailed error message.
- Backup server configuration (backup directory, mode, time, mount) stays admin-only in the server module (feature 007);
  this feature only reads whether backups are configured.
- Remote actions older than the retention of `sys_remoteaction` (ISPConfig never prunes it by default) remain visible as
  job history; no cleanup is added.
- Consumers poll jobs; no push notification is provided. Server processing starts within about a minute
  (`server.sh` cron) and restores can take several minutes.
- Direct browser download of backups is deferred to a later feature: the API runs on the master server while backups
  live on web/backup servers, so it needs a component on those servers or shared backup storage (owner decision
  2026-09-14).
