# Per-website access and error log reader

Run API migrations first (`ispconfig-rest update`). For a single-server API whose
runtime already reads the site's logs, set `WEB_LOG_SERVER_ID` in the API `.env`
to that machine's ISPConfig server ID and rebuild its config cache. This enables
direct reads of `/var/log/ispconfig/httpd/<domain>/`; do not set a remote server ID.
No permissions are changed by the API.

For remote web servers or restricted API runtimes, install the optional reader
on **each web server**, independently of the database operations worker:

```
sudo sh web-log-worker/install.sh
```

Copy this directory and `app/Support/WebLogReader.php` preserving their relative
layout, or run the installer from a trusted API checkout. It installs root-owned
copies and a cron entry. Re-run to upgrade; never execute a web-writable checkout
as root from cron. Requires PHP 8.3 CLI with pdo_mysql, mbstring, zlib and posix.

Like ISPConfig itself, the worker uses local `server/lib/config.inc.php` to connect
to the master database. Its existing master SQL account needs SELECT on
`web_domain`, SELECT/INSERT/UPDATE on `api_web_log_workers` and SELECT/UPDATE/DELETE
on `api_web_log_reads`. Grant only these tables, not global database privileges.
There is no new HTTP listener, SSH credential or public log URL.

A current heartbeat enables the tile for websites on that server. API queries
return `state: pending` while the worker fetches a bounded slice, normally under a
second (up to ten seconds between cron runs). Results are cached for eight
seconds, removed after sixty seconds, and never enter ISPConfig's datalog.
The worker checks the website ID, server, owner group, domain and vhost type again
before reading. Diagnostics go to cron/system logs without access/error content.

Log pages allow 1–1000 lines, at most 256 KiB per page. Signed cursors bind to the
website, owner group and log type. Older pages traverse ISPConfig's dated access
logs and numbered error archives. Each gzip archive is expanded to a private
temporary file (deleted on close), capped at 128 MiB and five seconds; oversized
archives return an explicit `logs_archive_limit` instead of blocking a request.
Use FTP for those archives. Current plain-text logs may be much larger: reads
seek backwards in bounded blocks. No arbitrary paths or symlinks outside the
website's own canonical log directory are followed. Log rotation resets the view
with a notice. Responses use `Cache-Control: private, no-store`.
