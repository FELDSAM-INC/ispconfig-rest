# Per-website ModSecurity WAF

For automatic installation/upgrades from the REST host, use
`sudo ispconfig-rest server-tools install --components waf`.
See the [server tools CLI guide](../server-tools/README.md) for SSH setup and dry runs.

Install this optional tool on **each ISPConfig webserver**, including slave servers. The REST API can remain on the master. Debian/Ubuntu distribution packages supply ModSecurity and OWASP CRS; Apache uses ModSecurity 2, nginx uses libmodsecurity 3 and the ABI-matched distribution connector. A custom nginx build needs a matching connector and is not automatically supported by this installer. PHP CLI 8.3+, pdo_mysql and mbstring are required.

## Install

1. Update the master REST API and run its migrations, including `2026_09_27_000002_create_web_waf_tables.php` and `2026_09_27_000003_add_web_waf_application_profiles.php`, with a database administrator. The runtime API user does not need CREATE/ALTER privileges.
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

### ISPConfig directive validation

The installer also configures ISPConfig's supported `security/apache_directives.blacklist.custom` override to permit **only** these exact generated lines:

```apache
Include /etc/ispconfig-waf/base.conf
Include /etc/ispconfig-waf/owasp.conf
Include /etc/ispconfig-waf/atomic.conf
```

`apache_directives_scan_enabled` stays unchanged. Arbitrary includes, IncludeOptional, wildcards, additional arguments, module loading and the remaining vendor/administrator restrictions stay blocked. An existing custom blacklist is merged with current vendor rules; repeated installation is idempotent and picks up new upstream restrictions. The first original effective blacklist is saved as `apache_directives.blacklist.custom.before-ispcp-waf` (root-only). The new override remains root-owned and readable by the vendor file's group, mode 0640. Unsafe filesystem paths or unsupported custom regular expressions fail without replacing the existing blacklist. ISPConfig's own PHP files are not patched.

Validation runs on the **ISPConfig panel/interface host**, which may differ from the webserver or REST host. On a separate panel/master, stage the same reviewed root-owned release and run:

```sh
bash /root/ispconfig-rest-release/waf-server/install.sh --ispconfig-security-only
```

This option installs only the security override, without webserver packages, workers or reloads. No WAF files need to exist on the panel-only host; the permitted paths refer to root-owned configuration on the target webservers. Re-run after ISPConfig security blacklist updates to merge new vendor restrictions.

Websites remain **disabled** until explicitly enabled in WHMCS. The initial selected mode is **detection**. Review real traffic and exceptions before selecting enforcing. Native ISPConfig datalog regeneration and configuration validation apply changes asynchronously. Saving desired settings is not proof that the server accepted them; use the existing changes/status view and server logs to investigate failed regeneration.

The worker uses the existing ISPConfig server credentials to access the master database, as the other server workers do. Grant that account SELECT on `web_domain`, SELECT/INSERT/UPDATE on `api_web_waf_workers`, and SELECT/INSERT/UPDATE/DELETE on `api_web_waf_events` **on the master**. Retain any existing grants. The worker never writes native ISPConfig tables and never needs a REST administrator API key. Missing grants are reported in the server's root cron/system log. The capability appears after the next worker run (within a minute); its heartbeat expires after 150 seconds. An already configured website retains its tile during an outage so WAF can still be disabled.

## Application profiles

WHMCS offers **None**, plus the official CRS 3 application exclusions actually installed on that website's server: WordPress, Drupal, Nextcloud, DokuWiki, cPanel and XenForo. The root worker verifies the package files, version, ownership and generated include configuration before reporting profile IDs. The API never infers capabilities from the master server's filesystem. Older workers advertise no profiles until upgraded; rerun this release's installer on each webserver after the master migration.

The selected profile is stored in the website's managed WAF block and applied through a transaction variable. The generated `owasp.conf` loads CRS setup, resets the six application flags, enables only the website's selected profile, then loads CRS rules. This deliberately prevents a global `900130` setting from enabling an application profile on other managed websites. **None** restores standard protection, retaining manually configured rule/IP exceptions. The WAF mode and Atomicorp choice are independent; these profiles target OWASP CRS, not the Atomicorp feed.

Existing website markers default to None. Unknown profiles and unavailable selections are rejected. A disappeared profile cannot be enabled, but its saved selection can be retained when disabling WAF during an outage. Account/server transfers reset the profile with the rest of the WAF configuration. Profile changes use ISPConfig's normal asynchronous datalog processing.

These are upstream exclusions for the base application; themes, builders and plugins may still need narrow manual exceptions. No supported official Joomla or PrestaShop profile was verified. CRS 4 moved application exclusions into separate plugins; this release does **not** install or advertise those plugins. On CRS 4 the selector therefore offers None until a tested plugin loader is implemented. See the [official plugin registry](https://github.com/coreruleset/plugin-registry), [WordPress plugin](https://github.com/coreruleset/wordpress-rule-exclusions-plugin) and [CRS 3 WordPress rules](https://github.com/coreruleset/coreruleset/blob/v3.3.5/rules/REQUEST-903.9002-WORDPRESS-EXCLUSION-RULES.conf).

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

The native-engine checks run **only inside disposable containers** (they stub systemctl). They exercise detection/enforcing, scoped path/argument exceptions, IP bypass, disabled mode, audit normalization and cursor privacy. They also verify profile discovery and reject writable, foreign-owned, symlinked or incompatible rule files. WordPress Gutenberg content passes only with its profile, while other paths, arguments and another website remain protected, even if a global CRS setup flag tries to enable WordPress everywhere. All six profile configurations and switching back to None are tested on Apache and nginx. These checks use distribution OWASP CRS, not a paid Atomicorp feed.

The audit parser distinguishes the full stop after Apache’s `at ARGS:name.` from dots in a parameter name. Updating the WAF worker re-reads the bounded recent audit tail (up to 4 MiB) and corrects retained event parameters in place. Older or rotated-out records cannot be reconstructed from sanitized event metadata and expire with the normal seven-day retention. Existing exclusions are never rewritten. Run `ispconfig-rest server-tools update --components waf --yes` to update the collector and its database grants.
