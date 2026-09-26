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

## Public document root and environment variables

After updating REST, run its migrations, then re-run this installer on each web
server. It adds `WebRuntimeDirectory.php` and advertises `runtime_version=1` with
the existing heartbeat. No new master DB grants or listener are required. Existing
reader versions still serve logs; they do not enable directory changes or nginx
environment editing. Upgrade every web server that should offer these settings.

The API's website detail now returns `runtime_settings`. A customer can update
both settings through the normal website PUT, without permission to enter raw
Apache/nginx directives. The base `document_root`, system user and `web_folder`
remain fixed. The user must first create a child folder (for example `app/public`)
via FTP/SSH. Before saving, this reader checks the owning website, server and group
again and walks the base folder and requested child components. Missing directories,
symlinks and traversal are rejected. This is a check at save time, not an OS jail;
normal filesystem ownership and web-server restrictions still apply afterward.

Apache uses DocumentRoot and SetEnvIfExpr with a base64-decoding expression.
nginx uses ISPConfig's native `##subroot …##` and `##merge##` handling, appending
FastCGI parameters inside the existing PHP/CGI locations. PHP-FPM chroot paths are
adjusted without changing the jail. Values are request environment, not shell/cron
or global PHP-FPM process environment.

On nginx the installer adds `/etc/nginx/conf.d/ispcp-runtime.conf` in the **http**
context. Its root-owned geo constants make dollar signs, braces, angle brackets
and hashes literal, preventing nginx/ISPConfig template expansion of application
secrets. The installer checks `nginx -T` (without printing configuration) and
refuses a conflicting file or missing include. It does not reload nginx; ISPConfig
reloads it with the subsequent website update. Do not delete this constants file
while any managed environment is configured. A heartbeat only advertises the
feature while the installed constants match the worker's copy.

Managed blocks in both native directive columns are replaced idempotently and
removed when both settings are cleared; unrelated administrator directives stay
unchanged. An administrator-defined document root cannot be overridden. ISPConfig
continues provisioning through its datalog and normal config validation/reload.
Environment values appear in the website's private configuration: integrations
must redact the runtime object and native directives from diagnostic logs.

Verified against ISPConfig 3.3.1p1 sources and real Apache 2.4/nginx PHP-FPM requests.
Apache expression reference: https://httpd.apache.org/docs/2.4/expr.html
nginx FastCGI reference: https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html
