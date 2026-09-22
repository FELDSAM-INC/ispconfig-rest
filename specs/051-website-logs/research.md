# Website logs and protected directories — 2026-09-22

The owner confirmed a central REST API, with websites on multiple Apache/nginx
servers. ISPConfig 3.3.1p1 `apache2_plugin.inc.php`, `nginx_plugin.inc.php`,
`200-logfiles.inc.php`, and `create_daily_nginx_access_logs.sh` establish the
per-domain `/var/log/ispconfig/httpd/<domain>` directory and rotation patterns.
Monitor/system-logs is unrelated. No remote.d method reads these vhost logs.

Add a read-only GET subresource with the website's existing scoped route binding,
opaque authenticated pagination, bounded reverse reads, and explicit rotation /
unavailable states. Model `logs_available` is opt-in per local server or a fresh
remote worker heartbeat. A local API never guesses that a remote path is local.
The central deployment uses a small read-only worker on each website server,
connected through its existing ISPConfig master SQL account. A worker rechecks
server, site, owner group, domain and vhost type; it never accepts a disk path.
Transport tables are API-owned and do not use ISPConfig datalog. Content is
short-lived (60 seconds), and is not copied into module/worker diagnostic logs.
See web-log-worker/README.md for install, grants and preview bounds.

Existing web-folders and web-folder-users CRUD already provide password
protection, active flags and installation password policy. Block dot path
components before ISPConfig receives a folder path. No changes to website files
or server permissions are needed by the API itself.
