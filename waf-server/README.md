# Per-website ModSecurity WAF

Install this optional tool on **each ISPConfig webserver**, including slave servers. The REST API can remain on the master. Debian/Ubuntu distribution packages supply ModSecurity and OWASP CRS; Apache uses ModSecurity 2, nginx uses libmodsecurity 3 and the ABI-matched distribution connector. A custom nginx build needs a matching connector and is not automatically supported by this installer. PHP CLI 8.3+, pdo_mysql and mbstring are required.

## Install

1. Update the master REST API and run its migrations, including `2026_09_27_000002_create_web_waf_tables.php`, with a database administrator. The runtime API user does not need CREATE privileges.
2. Stage a reviewed release in a **root-owned directory with no group/other-writable ancestors**, for example `/root/ispconfig-rest-release`. Do not execute a web-writable REST checkout as root. Keep the `waf-server` and `app/Support` directories from the same release.
3. As root on each webserver:

```sh
chmod -R go-w /root/ispconfig-rest-release
bash /root/ispconfig-rest-release/waf-server/install.sh
ispconfig-waf status
# Optional: securely prompted server license, never a command-line argument:
ispconfig-waf atomic-key
```

The installer detects the enabled ISPConfig Apache/nginx plugin, refuses to take over an unmanaged active WAF, installs packages, validates configuration before reloading, and installs a root cron worker and log rotation. It preserves unrelated vhost directives. A failed configuration test restores managed configuration files; package installation itself is not rolled back. Package upgrades continue through the administrator's normal OS update policy. Re-running the installer rebuilds its generated configuration; do not edit these generated files locally.

Websites remain **disabled** until explicitly enabled in WHMCS. The initial selected mode is **detection**. Review real traffic and exceptions before selecting enforcing. Native ISPConfig datalog regeneration and configuration validation apply changes asynchronously. Saving desired settings is not proof that the server accepted them; use the existing changes/status view and server logs to investigate failed regeneration.

The worker uses the existing ISPConfig server credentials to access the master database, as the other server workers do. Grant that account SELECT on `web_domain`, SELECT/INSERT/UPDATE on `api_web_waf_workers`, and SELECT/INSERT/DELETE on `api_web_waf_events` **on the master**. Retain any existing grants. The worker never writes native ISPConfig tables and never needs a REST administrator API key. Missing grants are reported in the server's root cron/system log. The capability appears after the next worker run (within a minute); its heartbeat expires after 150 seconds. An already configured website retains its tile during an outage so WAF can still be disabled.

## Atomicorp (additional licensed rules)

Atomicorp's server API key lives only in `/etc/ispconfig-waf/atomic.conf` (root, mode 0600). WHMCS/API receive only an availability flag, never the key. The command validates the official `SecRemoteRules` feed before advertising it; a failed key replacement restores the prior file. OWASP CRS remains enabled when Atomicorp is selected.

The official feed is `https://waf.atomicorp.com/rules/srr.php`; `SecRemoteRulesFailAction Abort` prevents silently omitting licensed rules when retrieval fails. **A network/license outage may therefore prevent a subsequent server configuration reload until resolved.** The running configuration remains in service. Rules are retrieved on configuration reload; `ispconfig-waf refresh` validates then reloads explicitly. Automatic OS updates cover distribution CRS; schedule validated refreshes separately if required for Atomicorp.

To remove a license, disable Atomicorp on all websites, wait for ISPConfig to apply, then run `ispconfig-waf atomic-disable`. The command refuses removal while enabled vhosts reference the licensed rules. The integration follows the official native feed protocol; paid feed compatibility must be verified with an actual licensed key on your server before enabling it for customer traffic.

Official documentation: [Atomicorp remote rules](https://docs.atomicorp.com/gotrootModsec/remoterules.html), [OWASP CRS](https://coreruleset.org/docs/), [nginx connector](https://github.com/owasp-modsecurity/ModSecurity-nginx).

## Exceptions, events and privacy

The API accepts booleans, detection/enforcing mode, numeric rule IDs, exact URL paths, plain ARGS names, and IP/CIDR allowlists. It accepts no raw ModSecurity directives. Exceptions can apply to a whole rule or one argument, optionally restricted to a path; they apply only to that vhost. IP allowlists bypass WAF for that site. Behind a trusted reverse proxy, configure the webserver's real-client-IP handling first; never whitelist an entire shared proxy to exempt an individual client.

WHMCS shows the latest 50 rule events with paging, an outcome filter and optional 10-second refresh. A confirmed intervention is marked blocked; an application-generated HTTP 403 alone is not. Audit anomaly-summary rules do not offer a shortcut to exclusion: use the underlying rule. A prefilled exception still requires explicit submission. Native configuration changes require website update permission and an unlocked account; read endpoints require website ownership. `expected_revision` provides optimistic concurrency protection.

Raw native audit/error files stay under `/var/log/ispconfig-waf` (root-only directory). Native audit files **can contain private request headers/URLs**, so only administrators may read them. Bodies are not selected in the audit parts. Rotation is daily or 25 MiB when logrotate runs; arrange more frequent logrotate runs for high traffic. The module/API never receive bodies, headers, query values or matched values. Descriptions come from static CRS templates. Known CRS anomaly-summary messages additionally show validated numeric totals and nonzero category scores; arbitrary interpolated log text is never returned. Old summary entries whose numeric values were not retained use a concise description without placeholders. Paths, argument names and client IPs remain visible to the website owner.

The root worker collects every five seconds during its minute cron slot, caps read work and retains up to 1,000 rule events per website for seven days. This is a bounded recent-event view, not a complete forensic archive: a large backlog may be skipped. Event identity includes server, domain and owner. REST domain renames regenerate that identity; ownership/server transfers reset WAF to disabled with no inherited exceptions. Changes made directly through ISPConfig require the administrator to reset/reconfigure the managed block when changing this identity.

## Verification

```sh
docker build -t ispcp-waf-test tests/Integration/waf
docker run --rm -v "$PWD:/app:ro" -w /app ispcp-waf-test bash tests/Integration/waf/check.sh apache
docker run --rm -v "$PWD:/app:ro" -w /app ispcp-waf-test bash tests/Integration/waf/check.sh nginx
php vendor/bin/phpunit --filter WebWaf
```

The native-engine checks run **only inside disposable containers** (they stub systemctl). They exercise detection/enforcing, scoped path/argument exceptions, IP bypass, disabled mode, audit normalization and cursor privacy. These checks use distribution OWASP CRS, not a paid Atomicorp feed.
