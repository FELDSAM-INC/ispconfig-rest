# WordPress Toolkit Lite: detection and security

Implemented: per-public-root cached inventory and on-demand rescan; Check, Secure
and Revert for the approved security measures. Integrity and managed wp-cron are
separate follow-up milestones. There are no update, login, integrity or cron buttons.

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
  directories 0755 and wp-config.php 0600. ISPConfig's protected statistics launcher
  is excluded. Symlinks, hardlinks and other foreign-owned files prevent this action.
- Prefix/admin changes require a dedicated, local, customer-owned MySQL database,
  a live database export worker, a healthy local HTTP response and explicit consent.
  Multisite, shared databases, custom user tables and external databases are excluded.
  Only the default `wp_` prefix is randomized. Renaming admin preserves the ID,
  role and post ownership; it does not create a replacement user.
- Prefix/admin operations first create a downloadable existing-worker DB export,
  then a separate SQL/config recovery snapshot protected from the site UID. Apply
  verifies CLI state and local HTTP (allowing PHP-FPM's cache refresh). Failure
  restores the snapshot as the site UID. Interrupted operations restore before
  accepting more work. Failed restoration blocks further operations and requires
  administrator recovery; the protected snapshot is retained.
- Terminal job work/recovery directories and rows expire after seven days. Jobs
  requiring recovery are never automatically removed. Downloadable exports keep
  the existing database worker's expiration policy.

Backups live in `/var/lib/ispcp-files/wordpress/<job UUID>.recovery/` (root 0700).
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
worker Python 12 tests, server-tools Python 17 tests; real Apache nine HTTP
assertions including encoded queries and `.htaccess` overrides. Module 3,489
PHP tests / 103,465 assertions (four existing skips); browser renders for default
and Lagom2 at 1440, 960 and 390px, with `vars/minified.css` loaded.
