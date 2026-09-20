# Database operations

The hosting panel lists databases and users in separate tabs. Each database has
administration, connection, import, export and copy actions. Copy creates a NEW
database on the same server and website, using the source database user, charset
and quota; an existing database is never the copy target.

ISPConfig 3.3.1p1 `server/plugins-available/mysql_clientdb_plugin.inc.php` provisions
databases through datalog; `server/lib/classes/backup.inc.php` backs up websites,
not individual databases. There is no native per-database import/export/copy API.
An optional root-owned worker is installed on each MySQL/MariaDB server. It uses
ISPConfig's existing local database administration credentials and master metadata
connection. A fresh heartbeat advertises support; unsupported/offline servers and
PostgreSQL do not advertise these actions. No browser or WHMCS receives credentials.

Jobs are API-owned records, scoped through the current readable database and its
unchanged owner/server/name. Starting a job additionally requires update permission
and an unlocked owner. One active job per source database; no automatic retries of
partially executed imports. Idle uploads expire after 30 minutes; queued jobs survive other jobs' runtime,
and execution is limited to four hours. Artifacts expire after 24 hours. SQL imports are explicit overwrites
and may partially apply on failure. An import accepts SQL or gzip SQL uploads up to 2 GiB; compressed exports are
limited to 2 GiB. Uncompressed SQL is bounded by available temporary disk space.

Imports execute as a temporary database-scoped principal, never as the database
administrator. Native client shell commands and local infile are disabled. The
OS process runs as nobody. Dumps with foreign DEFINERs are rejected by MySQL; users
should export portable SQL without DEFINER clauses. The built-in exporter includes tables, data, views, triggers, routines and events;
explicit DEFINERs are normalized by a SQL lexer. Copy does not rewrite dynamic SQL or
cross-database references. The same limitations are shown in the panel.

## Complexity / constitution exceptions
- API-owned jobs/chunks/heartbeat tables do not map ISPConfig tables. Direct writes
  are framework state, exempt from datalog. Target metadata uses the ordinary
  provisioning service/BaseModel, retaining limits, prefixes and user datalog.
- Physical SQL data import/export is a new explicit feature requested by the owner;
  like ISPConfig's native backup restore, it necessarily accesses hosted databases
  outside metadata datalog. Never execute submitted SQL as an administrator.

## Acceptance
Tenant/permission/lock checks, copy limits and source preservation, capabilities,
job lifecycle and download expiry are covered by API tests. Real MariaDB fixtures
exercise schema/data/view copy, import/export, failure and malicious SQL isolation.
Both WHMCS themes are rendered with their actual CSS and JavaScript tabs checked.


## Large database follow-up (2026-09-20)

Owner requested at least 1 GiB databases and worker logging. Native proc_open /
mysqldump / mysql remain the execution layer. The first large-file implementation used 768 KiB chunks with
an uploading state, exact declared byte count (up to 2 GiB), ordered idempotent
writes, explicit finalize and cancel. API-owned migration adds transfer sizes.
Generated SQL normalization, gzip and PDO chunk iteration are bounded-memory;
initially 4 GiB raw SQL, 2 GiB compressed export, a 4-hour deadline and progress
heartbeats. The raw SQL limit is superseded below.
Queued work survives another job's long runtime. Downloads use keyset batches.
Root-only rotated diagnostics contain IDs, phases and numeric MySQL errors only.
Tests include auth/ownership/locks, incomplete/changed/reordered chunk rejection,
retry/finalize/cancel, lexer buffer boundaries and actual 1 GiB random binary data.

## Parallel uploads and quota follow-up (2026-09-20)

New imports negotiate 8/4/2 MiB or 768 KiB chunks and allow four concurrent requests,
including out-of-order arrival. Exact lengths, total size and retry identity remain
enforced. Legacy jobs keep sequential 768 KiB chunks. A nullable API-owned chunk-size
column freezes the protocol per job; MySQL packet limits can reduce the requested size.

The reported 293 MB gzip expands to 5.36 GB: dump size is not database storage size.
The worker no longer imposes a 4 GiB raw SQL limit; available temporary disk space
(with a 64 MiB reserve) bounds processing instead. Uploaded/compressed artifacts
remain limited to 2 GiB. Failures log phase and processed bytes; successful gzip
expansion logs the final uncompressed byte count.

Import/copy checks the configured table+index storage quota before, during and after
SQL execution using ISPConfig's measure. MySQL 8 statistics expiry is disabled for
these reads. On quota failure, terminate only the job principal's sessions, rotate
and lock its password, and expose a safe quota-specific error. Checks every two
seconds are not a byte-perfect hard quota and cannot roll back already applied SQL.
Export remains available above quota. Tests cover quota failure before/during SQL
and successful 5.36 GiB expansion into a database that fits its unchanged quota.
