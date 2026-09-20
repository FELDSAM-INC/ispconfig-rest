# Database operations worker

Optional component for import, export and copy. Install on **each database server**
whose databases should offer the operations. Requires 64-bit PHP 8.3 CLI (pdo_mysql,
posix), MySQL 8 / MariaDB 10.4+, native mysql/mysqldump clients and util-linux
setpriv. PostgreSQL is not advertised. The API and WHMCS never hold local database
administrator credentials.

1. Deploy this API and run `php artisan migrate --force` on the master. Existing
   API installations need `post_max_size = 16M` in their dedicated PHP pool and
   nginx `client_max_body_size 16m` (or Apache `LimitRequestBody 16777216`) for the
   base64 upload envelope. New installs configure these limits automatically.
2. Grant the database server's existing ISPConfig master SQL account SELECT,
   INSERT, UPDATE, DELETE on `api_database_workers`, `api_database_operations` and
   `api_database_operation_chunks` in the master ISPConfig database. It also needs
   its existing read access to web_database, sys_group and client. Do not grant
   global privileges. In a single-server installation the existing ISPConfig SQL
   account may already have these database-scoped rights.
3. Copy the `worker` directory to the database server, then run `sh install.sh` as
   root. It installs root-owned files and a one-minute cron. Re-run to upgrade;
   never run a web-writable API checkout as root from cron.

A current heartbeat (3 minutes) enables tiles only for that server's active MySQL
records. One process per server uses flock. Jobs are not retried after execution
starts. If a worker stops during import, inspect the database before retrying.
Import is intentionally destructive and is not transactional across DDL.

SQL and gzip uploads support **2 GiB**, with **2 GiB compressed exports**. There is
no fixed uncompressed SQL limit for import, export or copy. Each processing stage
checks available temporary disk space and stops before the last 64 MiB is consumed.
Large statements still obey the local MySQL `max_allowed_packet`. Allow temporary disk space for the raw/normalized dump and
archive, plus the destination database for copy. Transport artifacts are stored as
base64 chunks in the API-owned master table (about 4/3 of the transferred size);
provision master storage accordingly. Artifacts expire 24 hours after completion.
The worker cleans expired/failed jobs and abandoned files on its next run.

Browser imports negotiate 8/4/2 MiB or 768 KiB chunks according to WHMCS PHP upload
and POST limits and the API database packet limit. Four requests can upload in
parallel; WHMCS releases its session lock before network calls. The API accepts
`upload_bytes` and optional `chunk_size` at job creation, starts in `uploading`,
and accepts `PUT .../operations/{id}/chunks/{sequence}` with `dump_base64`. Negotiated
chunks may arrive out of order; `POST .../operations/{id}/upload-complete` succeeds
only after every exact-length chunk arrived. Identical chunk retries and
finalization are idempotent. Existing jobs without a negotiated chunk size retain
768 KiB sequential uploads, so upgrades do not disrupt uploads already in progress. `DELETE .../operations/{id}` cancels
unfinished uploads only. Idle uploads expire after 30 minutes. The legacy inline
`dump_base64` create payload retains its 8 MiB bound; use chunking for large files.

The worker uses native `mysqldump --quick` and `mysql` through `proc_open`, with
file descriptors, protected option files and no shell interpolation. SQL scanning,
gzip, chunk reads and HTTP downloads have bounded memory. Worker PHP memory is
128 MiB; job execution allows four hours with 10-second heartbeats during every
processing stage. Web downloads allow four hours too; reverse-proxy/FPM request
timeouts must accommodate the transfer. Queued jobs are not expired merely because
a previous large job takes more than 30 minutes.

Database quota is separate from dump size: it measures table and index storage,
using the same `information_schema.TABLES` data/index sizes as ISPConfig. Import
and copy check this before SQL execution, every two seconds during execution and
after completion. A quota failure terminates the job's SQL sessions and reports
`database_quota_exceeded`; it never raises the configured quota. This is monitored
enforcement, not a byte-exact storage cap: a large statement can overshoot between
checks, and previously applied statements remain. Export remains available when a
database exceeds quota. Insufficient temporary space reports `insufficient_disk_space`.

There is no separate worker login. It reads ISPConfig's local `config.inc.php` and
`mysql_clientdb.conf`; these credentials never reach the API browser client.
Diagnostics are in `/var/log/ispconfig-rest-database-worker.log` (root:root 0600),
rotated weekly with eight compressed rotations. Log entries include job/server IDs,
action, phase, bytes, elapsed time, completion and numeric MySQL error/SQLSTATE.
SQL contents, passwords and raw stderr are never logged. Follow a job with:

```
sudo tail -f /var/log/ispconfig-rest-database-worker.log
```

Imports run with a database-scoped principal and an unprivileged OS identity;
local infile and mysql shell commands are disabled. The `ispcp_job_<database_id>`
SQL account remains locked between operations to preserve object definers. It has
privileges only on that database (escaped grant wildcard characters). Removing a
database can leave this locked account; operators can drop it after verifying the
associated database no longer exists. Cross-database references/dynamic SQL are
not portable; view references to the source are rewritten during built-in copy.

The source is never changed by copy. Failed copies retain the newly provisioned
database so users can inspect or delete it; they are never silently removed.

Integration fixture (destructive **only inside its disposable database container**):
build a PHP 8.3 CLI image with pdo_mysql, mysql/mysqldump and setpriv; start a fresh
`mariadb:10.11` container named `ispcp-worker-mariadb` with
`MARIADB_ROOT_PASSWORD=fixture-only`, then run:

```
docker run --rm --network container:ispcp-worker-mariadb -v "$PWD:/app:ro" \
  ispcp-database-worker-test php /app/tests/Integration/database-worker.php
```

The fixture has no configurable production hostname: it connects to loopback in
the disposable server's network namespace and uses a fixture-only credential.
It verifies data/views/triggers/routines/events, untouched source data, export/import
round-trip, locked credentials, private file cleanup and refused cross-database,
FILE, LOCAL INFILE, shell and foreign DEFINER operations.

For the opt-in large-data check, run the same command with
`tests/Integration/database-worker-large.php`. It seeds **1 GiB of random binary
data**, verifies every row's SHA-256 after copy and export/import, checks the
compressed export exceeds 1 GiB, and asserts peak PHP memory below 64 MiB. Allow
several minutes and at least 15 GiB of disposable container disk space.

For quota and gzip expansion checks, run `tests/Integration/database-worker-quota.php`
in the same disposable container setup. It verifies rejection before import and
termination during import when storage exceeds quota, then imports **5.36 GiB of
uncompressed SQL** into a database whose actual table/index storage fits a **1 MiB
quota**. It checks trailing SQL execution, unchanged quota, account locking and
peak PHP memory below 96 MiB. Allow at least 7 GiB temporary disk space.
