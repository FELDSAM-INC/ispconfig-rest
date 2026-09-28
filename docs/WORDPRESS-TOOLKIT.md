# WordPress Toolkit Lite

Implemented: per-public-root cached inventory and on-demand rescan; Check, Secure
and Revert for the approved security measures; read-only core checksum verification;
managed native cron takeover. Core reinstallation, updates and login are not exposed.

## Deployment and capabilities

Apply the `api_wordpress_*` migration using an administrative migration connection,
then update `--components file-manager` with the server-tools CLI on each webserver.
The worker's managed grants include the new API tables; it never writes native
ISPConfig rows directly. Apache directives and language settings go through normal
REST model validation and ISPConfig datalog processing.

This extends the existing file-manager worker with a separate one-minute WordPress
queue and lock. The existing SFTP jail, accounts and key configuration are retained.
WP-CLI 2.12.0 is downloaded from its official release with a pinned SHA-256. Linux
bubblewrap must support unprivileged user namespaces, `--bind-fd`, and
`--disable-userns`; there is no unjailed fallback. The selected website PHP version
must have a matching CLI under `/usr/bin/phpX.Y` (Debian/Ubuntu layout), with mysqli,
mysqlnd, phar and WP-CLI's required extensions. The worker bridge needs PHP 8.3+,
pdo_mysql, posix and mbstring. Missing runtime capabilities are reported, not guessed.

Every WordPress/PHP/MySQL subprocess runs under the website UID/GID in a namespace
containing only its public root, private job directory, root-owned runtime and
required libraries/CA certificates/local database socket. No ISPConfig configuration,
other webspaces or WHMCS credentials are exposed. WP-CLI ignores website/local
configuration files, packages and normal plugins/themes; WordPress bootstrap and
must-use plugins remain untrusted code inside that sandbox.

## Supported scope and restrictions

- Primary sites and vhost aliases/subdomains use their own public docroot and
  identity-bound inventory. Simple aliases have no independent installation.
- Server protections require Apache 2.4. nginx installations retain applicable
  WordPress/configuration measures; Apache measures are unavailable with a reason.
  Fixed managed blocks preserve WAF, runtime and other administrator directives.
  Vhost conditions are applied after per-directory conditions, including hostile
  `.htaccess` `<If>` overrides. Server rule status remains pending until the generated vhost contains the block.
- Paths unsafe for Apache syntax can still be inventoried; server measures are
  disabled for those paths. Symlinked installations/configs are rejected.
- Permissions require PHP-FPM or suexec FastCGI. WordPress files become 0644,
  directories 0755 and wp-config.php 0600. ISPConfig's reserved statistics directory
  is excluded. Symlinks, hardlinks and other foreign-owned files prevent this action.
- Prefix/admin changes require a dedicated, local, customer-owned MySQL database
  and healthy local HTTP response. WordPress options, users and usermeta tables
  must use InnoDB. Multisite, shared databases, custom user tables and external
  databases are excluded. Only the default `wp_` prefix is randomized.
- Worker version 3 makes prefix/admin changes reversible without database exports
  or a backup confirmation checkbox. A protected intent journal records table names,
  the previous/new prefix and username/ID. Metadata updates and a recovery marker
  commit together in one InnoDB transaction; multi-table RENAME and wp-config changes
  are separate journaled steps. CLI and local HTTP verify the result. Failure or
  interruption applies inverse changes; it never replaces current database contents.
- Revert restores recorded values and preserves posts, users and other content added
  after Secure. Conflicting names, external configuration changes and unexpected
  tables stop the operation. Without reliable previous values, Revert is unavailable.
  Recent successful version-1/2 protected journals can supply these previous values.
- Terminal job work/journal directories expire after seven days; normal Revert uses
  private metadata retained in the installation inventory. Recovery-required jobs
  are never removed automatically. Old in-flight jobs retain their legacy full-snapshot
  recovery path. Existing exports retain the database worker's expiration policy.

Recovery journals live in `/var/lib/ispcp-files/wordpress/<job UUID>.recovery/` (root 0700).
Do not remove a recovery directory while its job is running or needs recovery.
Do not manually mark an uncertain job completed: restore or verify it first.
User-facing errors contain safe codes, never SQL, configuration or credentials.

## Development verification (2026-09-27)

Disposable `wp-toolkit-lite.dot.com`, website 52, WordPress 6.8.3 on the site's
PHP 8.5 runtime: rescan/check; all eleven Apache measures; constants/pingbacks
Secure and Revert; permissions, salts and languages; administrator rename and
randomized prefix. Independent checks retained administrator ID 1, administrator
role and the test post's author. An injected must-use plugin returned HTTP 500
only after prefix changes: the worker restored the protected SQL/config snapshot,
reported `verification_failed_restored`, and HTTP returned 200 again.

HTTP verification uses an expiring, token-checked temporary PHP probe to compare
WordPress's actual web-runtime prefix/site URL with CLI state, then checks the
homepage. This avoids falsely accepting stale OPcache configuration or a cached
HTTP 200. The probe is removed in a finally block; its token expires after ten
minutes even if the process is killed. A later re-check with the fault removed
successfully applied all eight local/hosting security measures.

Regression results: REST 1,542 tests / 12,025 assertions (one existing skip),
worker Python 13 tests, server-tools Python 17 tests; real Apache nine HTTP
assertions including encoded queries and `.htaccess` overrides. Module 3,489
PHP tests / 103,465 assertions (four existing skips); browser renders for default
and Lagom2 at 1440, 960 and 390px, with `vars/minified.css` loaded.

Final deployed-worker check returned OK for all 19 measures. Live Apache returned
200 for the homepage and 403 for XML-RPC and percent-encoded author scans.
The disposable website, client-domain registration, database/user, exported dumps,
WordPress job/cache rows, recovery snapshots, sandbox account and test SFTP bind
mounts/helper were removed. Existing development websites were not modified.

## Integrity checks and managed cron (worker version 2)

Apply migration `2026_09_28_000002_create_wordpress_cron_table.php` before upgrading
workers. Reinstall the server-tools manager and update `--components file-manager`
to refresh both worker files and remote master grants (`cron` SELECT and
`api_wordpress_cron` SELECT/UPDATE). The module offers new actions only when a live
version-2 or newer worker advertises `tools_available`.

`verify_integrity` reads the installed version and locale as text, then runs
`wp core verify-checksums --include-root` against official checksums.
The pinned WP-CLI 2.12 diagnostics are normalized to safe structured results
(JSON checksum output is not supported in that release). The site is mounted read-only; broken WordPress PHP is not bootstrapped. It reports
changed, missing and unexpected files (up to 500 in the public result). It does not
scan plugin/theme/upload contents, remove files or claim malware detection.
Network/unpublished-checksum failures never become a clean result.

`cron_enable` accepts a fixed interval in minutes, reserves one native ISPConfig
cron row through the normal model/datalog path and enforces client/reseller counts,
command permission and minimum frequency. Full and native Jailkit/chrooted plans
are supported. URL-only plans cannot use takeover. Managed native rows are shown
in Scheduled tasks but can only be changed through WordPress controls.

Native cron writes an empty private trigger using a shell builtin as the website
user. The existing worker validates the native command, applied schedule, identity
and private directory, consumes the trigger in the UID sandbox, and runs
`wp cron event run --due-now` with the site's PHP version. Plugins/themes are loaded
for cron callbacks. Triggers coalesce and execution can lag by one worker minute or
while another operation is running; events do not overlap with security jobs.

Only after native cron and (where required) Jailkit are installed does the worker
set `DISABLE_WP_CRON=true`. The previous literal/absent value is persisted first.
`cron_disable` deletes the native reservation and restores that previous value.
It remains available after a plan downgrade. Errors preserve explicit state and
allow retry/stop; unexpected external config edits are never overwritten on stop.

### Development verification, 2026-09-28 (cron/integrity)

- REST: 1,545 tests, 12,060 assertions, one existing skip. Worker Python: 17 tests;
  server-tools: 17 tests. Added plan counts/frequency/type and managed-cron write
  protection tests, checksum output validation and boolean config normalization.
- A fresh WordPress 6.8.3 installation on disposable website 53 / PHP 8.5 was
  detected through the customer API. Its unmodified core verified, with native
  ISPConfig favicon/error/statistics files reported as unexpected additions.
- Deliberately modified `wp-includes/version.php`, missing `readme.html` and an
  extra test PHP file were correctly reported as changed/missing/unexpected.
  Original files were restored after the check; checksum checks themselves made
  no changes to the site.
- Native chrooted cron 7 used the five-minute plan-permitted interval. The worker
  waited for ISPConfig/Jailkit, set the previously absent constant, consumed the
  native cron trigger and executed a due callback from an active ordinary plugin.
  Stopping takeover removed the native row and restored the absent constant.
- Worker compatibility was checked against the pinned WP-CLI 2.12 binary: it lacks
  the newer JSON checksum formatter and emits JSON booleans for literal constants.
  Both paths are handled explicitly; no unpinned runtime upgrade was needed.
- Live WHMCS service 2 rendered all three tools in both themes from real API data.
  Disposable website 53, database 13, database user 15, cron, worker metadata/job
  directories, SFTP bind mounts/account and temporary helpers were removed.

## Reversible database changes (worker version 3)

No new migration is required. Update the API and file-manager component on each
webserver; older workers do not advertise prefix/admin actions. The API owns the
installation/database lookup and prevents concurrent database credential/delete or
website changes while a job is active. Private undo values never leave the API.
The database export worker is no longer required for these actions.

### Checksum diagnosis, 2026-09-28

The four reported differences on a fresh Czech WordPress 7.1.2 installation were
verified against the official `wordpress-7.1.2-cs_CZ.zip`: crystal/license.txt,
js/codemirror/csslint.js under wp-includes, wp-config-sample.php and license.txt.
All differences were CRLF-to-LF conversion, with no content changes. csslint.js
has mixed line endings in the official package. Strict byte checksums correctly
report these changes; files are not silently ignored or rewritten. Binary-mode
transfer preserves the original bytes. The user's website was read only.
