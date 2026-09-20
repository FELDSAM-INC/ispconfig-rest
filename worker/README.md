# Database operations worker

Optional component for import, export and copy. Install on **each database server**
whose databases should offer the operations. Requires PHP 8.3 CLI (pdo_mysql,
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

SQL and gzip SQL uploads up to 8 MiB are supported (64 MiB inflated). Export/copy SQL is capped at 64 MiB
and compressed download at 16 MiB. Artifacts expire after 24 hours. The worker
cleans expired jobs on its next run. No SQL, credentials or client stderr is logged.

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
