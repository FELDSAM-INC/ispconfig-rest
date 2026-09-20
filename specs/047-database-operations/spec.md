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
partially executed imports. Queued jobs expire after 30 minutes, execution after
10 minutes. Artifacts expire after 24 hours. SQL imports are explicit overwrites
and may partially apply on failure. An import accepts SQL or gzip SQL uploads up to 8 MiB (64 MiB inflated);
exports/copies accept SQL up to 64 MiB and compressed artifacts up to 16 MiB.

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
