# Changelog

Release notes for ISPConfig REST API. Versions refer to the application release;
the HTTP API remains under `/api/v1`.

## [Unreleased]

## [1.4.2] - 2026-10-28

### Fixed

- Apply the dedicated API account and mode 0600 to the existing PHP-FPM socket after reloading its pool.
  PHP-FPM inherits this socket on reload and leaves its previous ownership unchanged. Migrating from a
  shared web-server account could therefore leave the API returning 502/503 despite an active web service.
  The installer now repairs the socket without restarting other PHP-FPM pools.
- Add a live migration regression test using an existing socket, a real FPM reload and HTTPS requests
  through both Apache and nginx.

### Upgrade notes

- Use the 1.4.2 installer when migrating an existing deployment to the dedicated API identity. Hosting
  1.1.4 remains current; no database migrations or WHMCS module changes are needed.
- If a 1.4.1 migration left the API unavailable, restart its configured PHP-FPM service, or set the owner
  of `/run/ispconfig-rest.sock` to the configured dedicated API account and its mode to `0600`. A deployment
  already recovered this way needs no further service restart.

## [1.4.1] - 2026-10-28

Security fixes and dependency updates. No migrations. Requires PHP 8.3 or newer and ISPConfig 3.3; works with the
hosting extension 1.0.2. The HTTP API remains under `/api/v1`.

### Security

- Enforce client ownership when assigning websites and write permissions on DNS zones, including DNS changes
  made by mail-domain DKIM operations. Mail-domain cleanup respects permissions on related records.
- Restrict customer reparenting and reseller-capacity changes to administrators. Scope client-template references
  to readable templates and require update permission before changing assignments.
- Require update permission to retrieve TLS private keys and before generating DKIM keys.
- Reject ownership transfers outside the acting key's managed groups, including domain registrations and
  DNS records. Hide private template details in assignment responses and authorize legacy template conversion.
- Quote generated Sieve literals and reject line breaks in structured mail-filter fields.
- Restrict ACME keys to the change status of requests made with that exact key.
- Verify the opened backup archive before streaming and keep the same file handle throughout the response.
- Run privileged installer migrations and install the manager from fresh private sources. Verify extension
  revisions against their registered repositories, replace the runtime environment file atomically, and verify
  the Composer bootstrap before execution.
- Synchronize `.env` timezones as the runtime user without following links as root. Use an isolated runtime
  account and require PHP-FPM for managed Apache/nginx installations. Run the API web server as an isolated
  service under that account, with a private FPM socket and a single allowed PHP entry point.
- Require an exact trusted-ref match before replacing privileged command files. Default updates use the
  root-owned registered channel, so runtime Git metadata cannot silently select older privileged code.
- Preserve unspecified ISPConfig database fields when overriding credentials and reject root as a runtime
  database user; privileged migration credentials remain separate.
- Read installer database passwords from private files or the existing hidden prompt/environment rather
  than exposing password-valued command-line arguments to local process observers.
- Update locked dependencies to resolve published advisories, including Symfony Mime CVE-2026-45070.

### Maintenance

- Add dependency, source, secret, static-analysis and HTTP security checks to the release gates, plus CodeQL
  and an optional Codex Security workflow. Document local subscription scans and private security reporting.

### Upgrade notes

- Replace installer `--db-pass` / `--db-admin-pass` arguments with `--db-pass-file` /
  `--db-admin-pass-file`. Files must be owned by root, inaccessible to group/others, and contain one password
  line. Socket authentication and the existing hidden prompt/admin-password environment variable remain available.
- Existing installations using mod_php or a shared web-server account need a dedicated PHP-FPM pool and
  API account. The code updater does not change service identities: migrate ownership of the application
  checkout and rerun the verified installer with that dedicated account to create `ispconfig-rest-web.service`
  and its private PHP-FPM pool before serving the API. The API account must not execute tenant code; grant only the filesystem access
  needed for enabled backup features. Fresh installs default to `ispconfig-rest`.
- Select update channels with `ispconfig-rest update --branch NAME` or `--tag VERSION`; changing the
  runtime checkout manually no longer changes the registered channel. Configure a limited runtime DB user
  before rerunning the installer if an existing deployment uses root.
- The updater installs the corrected manager after its current invocation. On installations with untrusted
  runtime code, refresh `/usr/local/bin/ispconfig-rest` from a verified 1.4.1 release before running an update.
- If the hosting extension's database worker is installed, update hosting to 1.1.4 and run
  `sudo ispconfig-rest server-tools update --components database` to install its SQL-output fix.
- ACME change IDs created before this release cannot be queried with restricted ACME keys. Full keys retain
  their existing access. Treat returned change IDs as opaque strings.
- HTTP backup downloads require a canonical document root and access to Linux `/proc/self/fd` for descriptor
  verification. Use FTP/SSH when HTTP download is unavailable.
- A fresh install directory needs an existing root-owned parent that other users cannot write. Privileged
  installer migrations include only extensions recorded in the root-owned manager registry.

## [1.4.0] - 2026-10-17

Domains without hosting can be removed with their services. No migrations. Requires PHP 8.3 or newer and ISPConfig
3.3; works with the hosting extension 1.0.2.

### Added

- `DELETE /sites/domain-services/{domain}` removes a domain without hosting from the account in one change set: its
  DNS zone with every record, its mail domain with all mailboxes and addresses, and its registration. A domain with a
  website or alias is refused (409).

## [1.3.0] - 2026-10-17

Zones of new domains from ISPConfig DNS templates. No migrations. Requires PHP 8.3 or newer and ISPConfig 3.3; works
with the hosting extension 1.0.2.

### Added

- `POST /sites/domain-services` and its activation accept `dns_template_id`: a new zone gets the records of a visible
  ISPConfig DNS template, its placeholders filled from the account's servers (the website's or the default web
  server's addresses, the DNS server's name servers, `hostmaster@` the domain). Without it the zone gets the built-in
  records as before; a template needing a value the servers do not provide is refused with 422.

## [1.2.1] - 2026-10-17

A fix for multiserver setups. Requires PHP 8.3 or newer and ISPConfig 3.3; works with the hosting extension 1.0.2.

### Fixed

- The DNS records of a zone created with a domain (`POST /sites/domain-services` and its activation) were saved for
  server 1 instead of the zone's name server. ISPConfig hands a server only the changes of its own server ID, so on a
  setup with a separate DNS server the name server got the zone without any records (single-server installations were
  not affected). The records now carry the zone's server and owner, and the zone gets a new serial once they exist.
  `ispconfig-rest update` repairs existing records: a migration moves them to their zone's server through the
  datalog, so the name server receives them, and gives each such zone a new serial.

## [1.2.0] - 2026-10-17

`GET /me` now tells a panel which services the account has a server for. No migrations. The hosting extension needs
1.0.2 or newer (`ispconfig-rest update` updates it on its channel). Requires PHP 8.3 or newer and ISPConfig 3.3; the
HTTP API continues to use `/api/v1`.

### Added

- `GET /me` reports `services`: whether the account has a server for web, mail, databases and DNS (the lists of
  `GET /me/servers` are not empty; for admin keys, whether the installation has such a server). A panel can leave out
  mail, for example, on an installation or a plan without a mail server, without another request.

## [1.1.1] - 2026-10-17

A fix for the installer on servers with several PHP versions, such as Ubuntu 26.04 (default PHP 8.5). No migrations;
the hosting extension 1.0.0 works with it. Requires PHP 8.3 or newer and ISPConfig 3.3; the HTTP API continues to use
`/api/v1`.

### Fixed

- The installer served the API with the newest PHP-FPM on the server, not the one of the PHP CLI it checked
  (`--php`). On Ubuntu 26.04, whose default PHP is 8.5, an API installed for PHP 8.3 then ran on PHP-FPM 8.5
  without its extensions. It now uses the matching PHP-FPM and warns when it has to use another.

## [1.1.0] - 2026-10-11

This release makes the repository the ISPConfig REST API alone. The features of the FeldHost hosting deployment —
database operations, website logs and runtime settings, file manager and WordPress tools, WAF, product PHP policies
and PHP-FPM limits, DNS catalog zones and the server tools — moved to the extension
[ispconfig-rest-hosting](https://github.com/FELDSAM-INC/ispconfig-rest-hosting) 1.0.0, which this release can
install and update; the ACME DNS clients and the Ansible roles moved to their own repositories. With the extension
installed the HTTP API is unchanged. No migrations. Requires PHP 8.3 or newer and ISPConfig 3.3; the HTTP API
continues to use `/api/v1`. See the upgrade notes before updating an installation that uses the hosting features.

### Added

- Extensions (spec 058): directories `extensions/NAME` with an `extension.json` (version, supported releases, PSR-4
  autoload, service providers, command-line files) are loaded at boot when compatible; an incompatible or broken one
  is skipped and reported by `php artisan extension:list` / `ispconfig-rest extension:list`. Extension providers add
  routes, migrations and hooks (`App\Extensions\Hooks`: attributes, detail, payload fields, preflight, saving and
  saved callbacks, guards, deleted callbacks, named filters) to websites, scheduled tasks, databases, database users
  and clients; their `api/openapi.yaml` and `api/patches/` join the served OpenAPI document.
- `ispconfig-rest extension:install NAME [--branch NAME | --tag VERSION]`, `extension:update`, `extension:list` and
  `extension:remove`; `update` also updates installed extensions on their own channel. Commands the manager does not
  know are offered to the extensions, which run as root only from copies of their verified published commit.
  `install.sh --extension NAME[@REF]` installs extensions with the API.
- The certbot plugin is on PyPI: `pip install certbot-dns-ispconfig-rest` (1.0.0).

### Changed

- **Breaking:** the hosting features moved to the extension
  [ispconfig-rest-hosting](https://github.com/FELDSAM-INC/ispconfig-rest-hosting): database import/export/copy,
  website logs and runtime/PHP settings, scheduled task output logs, file manager and WordPress tools, website WAF,
  product PHP policies and PHP-FPM limits (`/usage/resources`), DNS catalog zones and the server tools
  (`ispconfig-rest server-tools`, `workers:*`, `waf:*`, `php-limits:*`, `dns-catalog:*`). Without the extension
  their endpoints return 404 and their fields (`logs_available`, `waf_available`, `waf_mode`, `wordpress`,
  `runtime_capabilities`, `runtime_settings`, `php_settings` on websites; `operations` on databases; `output_log`
  and `wordpress` on scheduled tasks; `web_php_policy` and `web_resource_limits` on clients) are absent;
  `public_document_root` is the document root's `web` folder (or a child website's folder). With the extension
  installed, the HTTP API is unchanged.
- `symfony/yaml` is a runtime dependency (the served OpenAPI document with extensions).

### Upgrade notes

1. **Install the hosting extension right after the update** if you use those features (the FeldHost WHMCS module
   does): `sudo ispconfig-rest update --tag v1.1.0` (or `--branch main`), then
   `sudo ispconfig-rest extension:install hosting`. Its tables keep their migration names, so nothing is migrated
   again; between the two commands the hosting endpoints are unavailable.
2. Managers before 1.1.0 copy compatibility stubs of `server-tools/manage.py` and `remote.php` during this update
   and then replace themselves. The server-tools commands return with the extension;
   `sudo ispconfig-rest server-tools update` refreshes the workers from the extension's repository.
3. Integrations that use only ISPConfig resources need no changes.

- The certbot and acme.sh clients moved to [ispconfig-acme-dns](https://github.com/FELDSAM-INC/ispconfig-acme-dns),
  which publishes the plugin to PyPI; the API keeps the `/dns/acme-challenges` endpoints.
  The Ansible roles and the BIND secondary playbook of the dns-catalog tool moved to
  [ispconfig-ansible](https://github.com/FELDSAM-INC/ispconfig-ansible). This repository
  keeps the API.
- `PUT /servers/{id}/configs/{section}` changes only the keys it sends. Omitted
  checkbox keys used to be written as `n` (ISPConfig's form behaviour, where an
  unticked box is not submitted), so saving one setting silently switched off every
  other checkbox of that section. Omitted keys of any kind now keep their value, as
  in the system config.

## [1.0.7] - 2026-10-11

This release adds DNS-01 challenges for certbot and acme.sh, so servers outside ISPConfig
obtain certificates for domains whose zones ISPConfig hosts, with API keys limited to
that; Ansible roles that install ISPConfig servers; and fixes for the installer and the
WAF. One migration of an API-owned table (`api_keys`). Requires PHP 8.3 or newer and
ISPConfig 3.3; the HTTP API continues to use `/api/v1`.

### Added

- DNS-01 challenges for certbot and acme.sh (spec 057): `POST /dns/acme-challenges`
  publishes `_acme-challenge` TXT records in the zone that is authoritative for the name,
  `GET /dns/acme-challenges/{id}` reports when ISPConfig's DNS server has applied them,
  and `DELETE` removes them. API keys get `access`: `ispconfig-rest key:create NAME
  --acme [--zone Z]...` (or `access`/`zones` on `POST /system/api-keys`) creates a key
  that can only do that. The clients in `acme-dns/` (an acme.sh DNS API plugin, the
  certbot plugin `certbot-dns-ispconfig-rest` and a hook script for any certbot) wait
  until the record is live instead of a fixed time. See
  [DNS challenges with certbot and acme.sh](acme-dns/README.md). The API-owned
  migration `2026_10_12_000001_add_access_to_api_keys.php` adds `access` and `zones` to
  `api_keys`: when the runtime account lacks ALTER rights, run it as root as described in
  the upgrade notes of 1.0.5.

- Ansible roles to install ISPConfig servers (spec 056): `ansible/site.yml` installs the
  panel (ISPConfig master with ispconfig-rest and its server tools), web/FTP/SSH, database
  and primary DNS servers with the ISPConfig autoinstaller, from groups set in the
  inventory. Servers join the panel unattended. Web servers get PHP 5.6 and 7.0–8.5 from the sury
  repository, and PHP 5.3 built from source (PHP-FPM, with its own OpenSSL 1.0.2).
  See [Install ISPConfig servers with Ansible](ansible/README.md).

### Fixed

- Installer on a new ISPConfig server: without an ISPConfig firewall record,
  `artisan firewall:allow` failed and `set -e` ended the installer before its summary;
  the ufw/firewalld fallback now runs. On nginx servers the installer configured an
  Apache vhost, because php-fpm packages create `/etc/apache2`; it now prefers the
  running web server. Run again with another `--branch`, it switches an existing shallow
  checkout to that branch or release tag instead of failing.
- WAF on Apache (ModSecurity 2): CRS response rules such as 951190, 951220 and 951250
  logged "PCRE limits exceeded (-47)" and were skipped for pages with the word warning
  before a long minified line (WooCommerce, block themes). In the request phase the same
  would let rule 200005 deny the request. The WAF installer sets `SecPcreMatchLimit
  1000000`, which covers a whole inspected response.

### Upgrade from 1.0.6

1. **Update the API and apply the migration.** `sudo ispconfig-rest update --branch main`
   (or `--tag v1.0.7`). The migration `2026_10_12_000001_add_access_to_api_keys.php` adds
   `access` and `zones` to the API-owned `api_keys` table. If the runtime database account
   lacks ALTER rights the update stops at the migration: run, as root in the installation
   directory, `php artisan config:clear` and
   `DB_USERNAME=root DB_PASSWORD='' php artisan migrate --force` (MySQL root over the
   local socket; use your administrative account otherwise), then repeat
   `sudo ispconfig-rest update`. Existing keys keep working before the migration;
   creating keys needs it.
2. **Apply the WAF fix:** `sudo ispconfig-rest waf:update` writes the new PCRE match limit
   on the WAF servers and reloads Apache or nginx. Per-website WAF settings are kept.
3. **Optional: certificates on other servers.** Create a key limited to challenges with
   `sudo ispconfig-rest key:create NAME --acme [--zone example.com]` and set up acme.sh or
   certbot as described in [DNS challenges with certbot and acme.sh](acme-dns/README.md).
   The panel's ispconfig-rest certificate must be valid for its name (ISPConfig's Let's
   Encrypt certificate is).
4. **New installations** can use the Ansible roles in `ansible/`
   ([guide](ansible/README.md)); they install ispconfig-rest 1.0.7 or newer. Existing
   installations need nothing from them.

## [1.0.6] - 2026-10-10

This release adds the `dns-catalog` server tool: a catalog zone on each ISPConfig DNS
server, so secondaries follow its zones by themselves, with an Ansible playbook for the
secondaries. No database migrations and no API changes. Requires PHP 8.3 or newer and
ISPConfig 3.3; the HTTP API continues to use `/api/v1`.

### Added

- `dns-catalog` server tool (spec 055): a catalog zone (RFC 9432, version 2) on each
  ISPConfig DNS server, so secondaries provision and remove its zones by themselves,
  also zones created in the ISPConfig interface. It follows `named.conf.local` with a
  systemd path unit, authorises transfers with a generated TSIG key and keeps that
  permission through ISPConfig updates (conf-custom). Zones with their own transfer
  list are left out. Opt-in: `ispconfig-rest server-tools install --components
  dns-catalog` (DNS servers only; the inventory now reports the `dns` role). Secondaries
  are set up with the Ansible playbook `dns-catalog/ansible/secondary.yml` or by hand;
  see [DNS catalog zones](dns-catalog/README.md).

### Upgrade from 1.0.5

1. **Update the API:** `sudo ispconfig-rest update --branch main` (or `--tag v1.0.6`).
   There are no migrations; the update also refreshes the `ispconfig-rest` command with
   `dns-catalog:install` and `dns-catalog:update`.
2. **Optional: catalog zones for secondaries.** On the REST host run
   `sudo ispconfig-rest dns-catalog:install`; it goes to the active, non-mirror DNS
   servers. It changes the BIND configuration: the global `allow-transfer` of
   `named.conf.options` (and its ISPConfig conf-custom copy) admits the generated TSIG
   key instead of `none`, and `named.conf` includes the catalog configuration. Then set
   up the secondaries with `dns-catalog/ansible/secondary.yml` or by hand, as described
   in [DNS catalog zones](dns-catalog/README.md). Existing server tools need no update.

## [1.0.5] - 2026-10-02

This release adds logs for scheduled tasks and for the WordPress cron takeover, and
the per-website data that clients need for tile badges without a request per website:
WAF mode, record counts and WordPress check states. One migration (an API-owned
column); the `file-manager` and `web-logs` server tools need updating. Requires PHP
8.3 or newer and ISPConfig 3.3; the HTTP API continues to use `/api/v1`.

### Added

- Websites report `waf_mode` (`off`, `detection` or `enforcing`; null when the
  managed block was changed outside the API), so clients can show the WAF state in
  lists without a request per website.
- Website usage rows (`GET /usage/web-domains`) include `records`: the databases,
  FTP and shell users, cron jobs and protected folders of each website and the
  mailboxes, aliases and forwards of its domain, counted only for the website's own
  client with a few grouped queries per page.
- Scheduled tasks can keep their own output log (`output_log`, spec 054): every run's
  output and errors, between its UTC time and exit status, are appended as the website
  user to the website's `private/.ispcp-cron-<token>.log`, and
  `GET /sites/cron-jobs/{id}/log` returns the latest lines through the web-log worker,
  which reads and trims the file (above 1 MiB, to 512 KiB) as the website user. The
  native command carries a managed prefix that the API never returns; ISPConfig's shared
  `log` option is turned off for such tasks. URL tasks are not supported. For WordPress
  cron takeover tasks the endpoint returns the website's `private/wp-cron.log`. Update the
  `web-logs` server tool: it needs the new `cron` SELECT grant.
- WordPress cron takeover logs every run: WP-CLI output, including PHP messages from
  plugin callbacks, is appended as the website user to `private/wp-cron.log` (rotated
  at 1 MiB), and the latest run's output is returned as `cron.last_output`. Requires
  migration `2026_10_01_000001_add_wordpress_cron_output.php` and the updated
  file-manager server tool; server-tools refuses to install that worker before the
  migration.
- Each installation in a website's `wordpress` summary has `checks`: available
  security measures counted by status, the managed wp-cron state and the last core
  file check, for badges without a request per website.

### Upgrade from 1.0.4

1. **Update the API and apply the migration.** `sudo ispconfig-rest update --branch main`
   (or `--tag v1.0.5`). The migration `2026_10_01_000001_add_wordpress_cron_output.php`
   adds a column to the API-owned `api_wordpress_cron` table. If the runtime database
   account lacks ALTER rights the update stops at the migration: run, as root in the
   installation directory, `php artisan config:clear` and
   `DB_USERNAME=root DB_PASSWORD='' php artisan migrate --force` (MySQL root over the
   local socket; use your administrative account otherwise), then repeat
   `sudo ispconfig-rest update`.
2. **Update the server tools:** `sudo ispconfig-rest server-tools update`. The
   `web-logs` worker reads and trims scheduled task logs and gets SELECT on `cron`;
   the `file-manager` worker logs WordPress cron runs. server-tools refuses the new
   file-manager worker until the migration is applied.
3. **Turn logs on per task.** Existing scheduled tasks keep running unchanged; their
   own log starts when `output_log` is turned on (the WHMCS module offers it on each
   task's log page). The native ISPConfig panel then shows the task's managed prefix.
4. **Update the consuming WHMCS module** for task logs and tile badges. Older module
   versions keep working.

## [1.0.4] - 2026-09-29

This release moves the WAF from the distribution CRS 3 package to the upstream
OWASP CRS 4.25 LTS release, with application profiles based on the official CRS 4
plugins, and reports which WordPress installation a job acted on. No database
migrations. Requires PHP 8.3 or newer and ISPConfig 3.3; the HTTP API continues to
use `/api/v1`.

### Changed

- WAF uses upstream **OWASP CRS 4.25.1 (LTS)** instead of the distribution
  `modsecurity-crs` package (CRS 3.3.5 on Ubuntu 24.04). The installer downloads the
  pinned release, verifies its SHA-256 and OpenPGP signature against the shipped CRS
  release key, and keeps verified versions in `/usr/local/share/ispconfig-waf/crs`.
  Application profiles now use the official CRS 4 rule exclusion plugins (WordPress,
  Drupal, Nextcloud, DokuWiki, cPanel, XenForo), pinned by SHA-256. Local CRS
  settings go in `/etc/ispconfig-waf/crs-setup.local.conf`, which updates keep.
  Requires ModSecurity 2.9.6+ (Apache) or libmodsecurity 3.0.8+ (nginx), that is
  Debian 12+ or Ubuntu 24.04+, and HTTPS access to GitHub from each webserver. On
  libmodsecurity older than 3.0.16, rule 901181's XML attribute opt-out is removed
  so the rules load. Security events describe the CRS 4 anomaly summaries.
  Update with `ispconfig-rest server-tools update --components waf --yes`; see
  [upgrading from distribution CRS 3](waf-server/README.md#upgrading-from-distribution-crs-3)
  for settings in `/etc/modsecurity/crs/crs-setup.conf` and removing the old package.

### Added

- WordPress jobs report the `installation` they acted on (null for a website-wide
  rescan), so clients can show a result next to the tool and installation that
  started it.

### Upgrade from 1.0.3

1. **Update the API:** `sudo ispconfig-rest update --branch main` (or `--tag v1.0.4`).
   There are no migrations.
2. **Update the WAF on its webservers:** `sudo ispconfig-rest server-tools update
   --components waf --yes`. Each webserver needs Debian 12+ or Ubuntu 24.04+ and HTTPS
   access to `github.com` and `raw.githubusercontent.com`. All managed websites switch
   to CRS 4 in one validated reload and keep their modes, profiles, exceptions and IP
   allowlists; a rejected configuration restores the previous state. On an older
   engine the installer stops before changing anything and the server stays on CRS 3.
3. **Move local CRS settings and remove the old package.** The installer warns when
   `/etc/modsecurity/crs/crs-setup.conf` has local changes; CRS 4 does not read it.
   Put the settings you still need in `/etc/ispconfig-waf/crs-setup.local.conf` with
   their CRS 4 names, then run `apt-get remove modsecurity-crs` when nothing else uses
   it. Review new rule events, preferably in detection mode, before relying on older
   rule exceptions. See
   [upgrading from distribution CRS 3](waf-server/README.md#upgrading-from-distribution-crs-3).
4. **Update the consuming WHMCS module** to offer no exception shortcut on the CRS 4
   anomaly summary events. Older module versions keep working.

## [1.0.3] - 2026-09-29

This release adds administrator product policies for PHP: INI limits and PHP-FPM
pool settings per client, and PHP-FPM resource limits with cgroup v2 applied by the
new `php-limits` server tool. It also fixes WAF argument names in security events.
Requires PHP 8.3 or newer and ISPConfig 3.3; the HTTP API continues to use `/api/v1`.
Both new features need their migrations; resource limits also need the server tool.

### Added

- PHP-FPM resource limits with cgroup v2: administrator-only client
  `web_resource_limits` (account and per-website CPU percent, memory MiB, tasks) and
  `GET /usage/resources` with per-server account usage, per-website containment
  state and 24-hour limit hits. The new `php-limits` server tool runs each limited
  account as a systemd slice and each of its PHP-FPM pools as its own php-fpm
  service inside it, without editing distribution or ISPConfig files. Install with
  `sudo ispconfig-rest php-limits:install` (restarts PHP-FPM once; not part of the
  default server-tools selection). Requires migration
  `2026_09_28_000004_create_php_resource_limit_tables.php`. See
  [PHP-FPM resource limits](php-limits/README.md).

- Administrator-only client `web_php_policy` for product INI limits and native
  PHP-FPM pool settings, socket/chroot and optional FPM-only hosting. Applies to
  current owned vhosts and future REST-created sites, preserves unrelated INI,
  and restores previous managed settings when removed. Requires migration
  `2026_09_28_000003_create_client_web_php_policies.php`. See
  [product PHP policy](docs/product-php-policy.md) for scope and native-panel limits.

### Fixed

- Re-applying a product PHP policy with Force PHP-FPM no longer moves an existing
  website from the hidden default PHP to the first additional version by sort order
  (often an older PHP). It now selects the additional version that runs the server's
  default PHP-FPM (same pool directory, else init script); new websites keep the
  documented first-usable rule.
- WAF events and exception suggestions no longer include the full stop from
  Apache's `at ARGS:name.` audit message in the argument name. Real dots in names
  are preserved. Update the WAF server tool as well as the API: its versioned
  cursor re-reads the bounded recent audit tail and repairs stored parameter
  metadata without duplicating events. The server-tools manager grants the
  collector UPDATE on `api_web_waf_events` for this repair; native tables remain
  read-only to the collector.

### Upgrade from 1.0.2

1. **Back up and apply migrations first.** Two new migrations create API-owned tables
   for product PHP policies and PHP-FPM resource limits
   (`2026_09_28_000003_create_client_web_php_policies.php`,
   `2026_09_28_000004_create_php_resource_limit_tables.php`); native ISPConfig tables
   are not migrated. Run `sudo ispconfig-rest update --branch main` (or
   `--tag v1.0.3`). The updater migrates with the runtime database account; if it lacks
   DDL rights the update stops at the migration. Then run, as root in the installation
   directory, `php artisan config:clear` followed by
   `DB_USERNAME=root DB_PASSWORD='' php artisan migrate --force` (MySQL root over the
   local socket, as the installer does; use your administrative account otherwise), and
   repeat `sudo ispconfig-rest update` to rebuild caches and reload. Do not leave
   database-administrator credentials in the API runtime configuration.
2. **Refresh installed components:** `sudo ispconfig-rest server-tools update`. This
   updates the WAF collector for the argument-name fix and grants it UPDATE on
   `api_web_waf_events`. Updates do not install absent components.
3. **Add resource limits deliberately.** `sudo ispconfig-rest php-limits:install --dry-run`,
   then repeat without `--dry-run`. It needs Debian/Ubuntu with systemd 243+, cgroup v2
   with the cpu, memory and pids controllers and distribution php-fpm units, and
   restarts each managed PHP-FPM service once. Accounts without `web_resource_limits`
   keep running in the shared masters. See [PHP-FPM resource limits](php-limits/README.md).
4. **Update the consuming WHMCS module** after the API migrations. Its product PHP
   settings and resource limit profiles require this release; products that do not use
   them keep working with older API releases.

## [1.0.2] - 2026-09-28

Bugfix release for the installation manager. No API endpoint, database migration
or worker changes compared with 1.0.1; existing workers do not need reinstalling.

### Fixed

- **Updater release selection:** follow the checked-out branch instead of silently
  restoring the install-time branch; keep detached release tags pinned. Add
  `update --branch NAME` and `update --tag VERSION`, fetch tags on shallow installs,
  and report the actual checkout in `version`/`status`. Refuse to discard tracked
  edits, local commits or changed release tags. Refresh the CLI from verified
  official sources and retain the corrected updater when selecting an older API
  release. Worker provenance follows the selected branch or release tag.
- **Shallow checkout recovery:** complete Git history before checking whether an
  update is a fast-forward. Repeated depth-one fetches from older updaters no longer
  make a valid update appear to contain divergent local commits.

### Upgrade from 1.0.1

The old manager reads `BRANCH` from `/etc/ispconfig-rest/install.conf`, ignores
`--branch`/`--tag` update options and can switch back to `develop` even after a
manual checkout of `main` or a release tag. **Refresh the manager first**, then
choose the update channel explicitly:

```bash
sudo bash <<'SH'
set -eu
manager="$(mktemp)"
trap 'rm -f -- "$manager"' EXIT
curl -fsSL https://raw.githubusercontent.com/FELDSAM-INC/ispconfig-rest/v1.0.2/bin/ispconfig-rest -o "$manager"
bash -n "$manager"
install -o root -g root -m 0755 "$manager" /usr/local/bin/ispconfig-rest
ispconfig-rest update --branch main
SH
```

To pin this exact release instead, replace the final update command with
`ispconfig-rest update --tag v1.0.2`. Once the corrected manager is installed,
ordinary `sudo ispconfig-rest update` follows the current branch or keeps a
detached release tag pinned. It fetches release tags and reports the actual
checkout in `version`/`status`. Local tracked edits and divergent commits must be
resolved before updating; they are no longer discarded automatically.

The bootstrap above uses the manager from the immutable `v1.0.2` release tag;
it leaves the installation configuration and API credentials in place. An
installation that already has the corrected manager can run
`sudo ispconfig-rest update --branch main` directly. For upgrades from 1.0.0,
also follow the [1.0.1 migration and worker upgrade notes](CHANGELOG.md#upgrade-from-100).

## [1.0.1] - 2026-09-28

This release adds WordPress Tools, per-website WAF controls, configured PHP settings
and jailed file-manager access, with a CLI to install and update server components
from the ISPConfig master. Requires PHP 8.3 or newer and ISPConfig 3.3; the HTTP API
continues to use `/api/v1`. New features require their migrations and matching workers.

### Added

- **Server-tools installer:** `ispconfig-rest server-tools install|update|status`
  discovers ISPConfig servers and deploys database, web-log/runtime, file-manager
  and WAF components through verified, passwordless SSH. Supports dry runs, selected
  servers/components, required table grants and trusted release staging. Updates
  preserve server configuration and only update components already installed.
  See the [CLI guide](server-tools/README.md).
- **WordPress discovery and security:** scoped inventory, cached public site
  name/URLs, rescan and asynchronous Check/Secure/Revert jobs for primary websites
  and independent vhosts. Server protections use managed Apache directive blocks;
  applicable configuration measures remain available on nginx. WP-CLI/PHP commands
  run as the website user in a namespace sandbox, through the file-manager worker.
  Supported measures include configuration constants, pingbacks, permissions,
  scripting-language restrictions and reversible database-prefix/admin-name changes
  for eligible installations. Protected journals and CLI/HTTP verification support
  recovery without requiring full database exports.
- **WordPress integrity:** read-only core verification against official checksums
  for the installed version and locale. Changed, missing and unexpected files are
  reported separately. Verified CRLF/LF-only differences are informational; the
  worker validates comparison files against official checksums and never rewrites
  website files during a check.
- **Managed WordPress cron:** take over `wp-cron.php` using a native ISPConfig cron
  reservation that respects account limits, allowed task types and minimum frequency.
  Due events run under the site's PHP/user sandbox. Browser-triggered cron is disabled
  only after native configuration is applied; stopping takeover restores the recorded
  previous setting. See [WordPress Tools](docs/WORDPRESS-TOOLS.md).
- **Web Application Firewall:** website-scoped settings and sanitized recent events
  at `/sites/web-domains/{id}/waf` and `/waf/events`, plus an Apache/nginx ModSecurity
  and OWASP CRS installer. Includes detection/enforcing mode, rule/path/argument
  exceptions, IP allowlists and optional server-local Atomicorp licensing. Supported
  CRS 3 application profiles are advertised only when their installed files are
  verified. Existing websites remain opted out. See the [WAF guide](waf-server/README.md).
- **Configured PHP settings:** structured `php_settings` reads and restricted
  updates for websites/vhost children. The web-log worker reports the selected PHP
  runtime's configured limits and capabilities; editable options cover OPcache,
  function access, error reporting and common PHP switches. Administrator/global
  restrictions remain authoritative. See [PHP settings](docs/website-php-settings.md).
- **Jailed file access:** an optional [file-manager worker](file-manager-worker/README.md)
  provisions restricted SFTP identities, per-vhost jails and private trash mounts for
  the WHMCS file manager. Website UID/GID and quotas are retained. Unsafe paths fail
  closed; inactive/deleted websites lose access. Existing helper keys, users and
  website mounts are preserved during upgrades.
- **Native panel URL:** `GET /me` includes `panel_url`, discovered from the master's
  active Apache/nginx interface vhost. `ISPCONFIG_PANEL_URL` supports an administrator
  override for reverse proxies.

### Changed and fixed

- Start WordPress work promptly through a persistent queue service and reconcile
  pending native changes without requiring repeated manual security checks.
- Make prefix/admin changes and full security reverts reliable: retain literal
  option names, reconnect idle database sessions, use ISPConfig's configured preview
  hostname and tolerate native webserver reloads during HTTP verification. Safe
  exception locations aid diagnostics without logging command output or credentials.
- Preserve native statistics permissions and apply Apache security protections after
  directory conditions. Existing WAF and other managed directives are retained.
- Accept ISPConfig textarea CRLF endings in managed settings and detect PHP 8.5's
  built-in OPcache when advertising PHP controls.
- Permit only the exact generated WAF include lines through ISPConfig's custom
  directive security override; retain arbitrary-include restrictions and other
  vendor/administrator rules. Show validated numeric WAF anomaly scores instead of
  placeholder values while keeping request data out of event descriptions.
- Keep ISPConfig website parents immutable while provisioning private trash. An
  individual unsafe website no longer prevents unrelated server-tool updates.

### Upgrade from 1.0.0

1. **Back up and apply migrations first.** Five new migrations create API-owned PHP
   snapshot, WAF/profile and WordPress/cron tables; native ISPConfig tables are not
   migrated. Run `sudo ispconfig-rest update`. If the runtime database account lacks
   DDL rights, complete `php artisan migrate --force` using an administrative migration
   connection before enabling workers. Clear cached configuration before temporary
   credential overrides, then rebuild it with normal runtime settings. Do not leave
   database-administrator credentials in the API runtime configuration.
2. **Refresh installed components:** `sudo ispconfig-rest server-tools update`.
   The new CLI is installed/refreshed with the API; older manager invocations bootstrap
   its root-owned helpers on first use. Review `server-tools status` and the displayed
   grants. On remote servers, configure administrator SSH keys and verify host keys
   before retrying a failed preflight. Updates do not install absent components.
3. **Add optional components deliberately.** For example,
   `sudo ispconfig-rest server-tools install --components database,web-logs,waf --dry-run`,
   then repeat without `--dry-run`. Without a component selection all four are selected.
   First-time `file-manager` installation also needs `--file-manager-key` containing
   only the WHMCS public key, and `--whmcs-ip` with its outgoing IP. Configure WHMCS's
   private SFTP mapping separately; never copy its private key to workers.
4. **Check WordPress prerequisites before adding/updating file-manager.** The bundled
   WordPress runtime requires compatible bubblewrap with unprivileged user namespaces,
   matching website PHP CLI binaries and extensions, MySQL client tools and the pinned
   WP-CLI runtime. There is no unjailed fallback. The worker bridge requires PHP 8.3+
   with `pdo_mysql`, `posix`, `pcntl` and `mbstring`. The installer stops existing work
   gracefully before replacing its runtime. Keep API and workers on the same release.
5. **Enable WAF per website after installation.** Start with detection and review
   traffic before enforcing. Enter optional Atomicorp keys through
   `ispconfig-waf atomic-key` on each licensed server. For an ISPConfig interface hosted outside the
   managed targets, apply the WAF guide's security-only installation there too.
6. **Verify capabilities.** PHP snapshots and optional feature availability follow
   the workers' first successful runs. Retain the API scheduler and existing worker
   jobs. Update the consuming WHMCS module after API migrations and worker updates.

### Availability limits

- WordPress server-rule protections require Apache 2.4. nginx supports applicable
  WordPress/configuration measures. Core installation, updates, reinstallation and
  automatic WordPress login are not exposed; checksum verification is not a malware scan.
- Prefix/admin changes require an eligible local, dedicated customer database and
  working local HTTP verification. Multisite, shared/external databases and custom
  user tables are excluded. Revert needs recorded prior state and refuses conflicts.
- WordPress cron takeover requires command or supported Jailkit/chrooted cron and
  an available plan slot; URL-only cron plans cannot use it.
- WAF installation targets supported Debian/Ubuntu packages. Application profiles
  use supported installed CRS 3 exclusions; CRS 4 plugins are not installed or
  advertised. Atomicorp needs a separately licensed, verified feed; a feed outage
  can prevent a subsequent configuration reload. Events are a bounded recent view.
- File-manager trash still consumes website quota. The SFTP worker does not change
  unsafe ownership/permissions to bypass jail checks. PHP snapshots describe configured
  values before application or `.user.ini` overrides; global restrictions cannot be
  relaxed through a website's settings.

## [1.0.0] - 2026-09-26

This first stable release includes the changes since `v1.0.0-rc.3`. It expands the
API for customer hosting panels, including coordinated domain services, remote
database operations, website logs, backups, and application runtime settings.
Requires PHP 8.3 or newer and ISPConfig 3.3.

### Added

- **Combined domain creation:** register an account's domain and provision its
  selected web hosting, DNS and mail services in one transaction using
  `/sites/domain-services`. Supports domains without a website and shared aliases;
  DNS and mail can also be activated later, idempotently.
- **Website alias services:** coordinate alias DNS zones and mail-domain routing.
  Optional DNS synchronization copies primary-zone changes and protects synchronized
  zones from independent edits. A scheduled reconciliation also picks up changes
  made directly in ISPConfig. Relationship fields connect website, DNS and mail
  resources for consuming panels.
- **Website runtime settings:** structured `runtime_settings` for a public child
  directory and application environment variables on Apache and nginx, including
  PHP-FPM chroots and vhost aliases/subdomains. Directory changes require an existing
  folder verified on the website's server. Managed settings preserve unrelated
  administrator directives; the base directory and system user remain fixed.
  `public_document_root` and `runtime_capabilities` support list and detail views.
- **Website log previews:** scoped access/error log pages, signed cursors for older
  entries, rotation handling and bounded compressed-archive reads. An optional
  reader supports remote web servers when REST runs only on the ISPConfig master.
- **Database import, export and copy:** asynchronous MySQL/MariaDB operations with
  a worker on each database server. Copy provisions a new database and preserves
  the source. Chunked SQL/gzip uploads and compressed exports support up to 2 GiB;
  negotiated uploads accept up to four parallel requests. Expanded SQL has no fixed
  size limit, subject to disk space, database quota and execution limits. Native
  database tools run with bounded PHP memory and restricted credentials.
- **Website backups:** list, create, restore, delete, prepare downloads, manage
  backup settings and poll jobs. `/me/backups` provides an account-wide overview;
  HTTP downloads stream prepared archives when the API can read the site's backup
  copy.
- **Change tracking:** `X-Change-Set-Id` on datalogging writes and scoped endpoints
  for change-set status, pending/failed changes and an individual resource's history.
  Success still means queued ISPConfig changes, not completed provisioning.
- **Account discovery and API keys:** `/me`, assigned servers, plan capabilities,
  available PHP versions, hosting addresses, name servers, mail-client settings and
  administration links. Admin endpoints and CLI commands manage scoped API keys;
  deleting a client revokes its keys.
- **Usage reporting:** account limits and resource counts, website/mailbox/database
  usage, traffic history and collector freshness, including collection intervals
  and last/next collection times.
- **Mail tools:** DKIM status and server-side key generation, domain/mailbox spam
  policy selection, recipient-bound allow/deny rules, mailbox-scoped fetchmail
  lists and mailbox access switches. DKIM private keys are hidden from scoped keys.
- **DNS tools:** zone creation from ISPConfig templates, optional DKIM/DNSSEC setup,
  DNSSEC state and record counts. Zone removal cascades through its records.
- **Website discovery:** parent filters for vhost subdomains/aliases, the hosting
  server's web-server type and the resolved Website auto alias test hostname.
- **Actionable errors:** stable problem types and structured details for locked
  accounts, resource limits, quotas, unsupported plan features and server assignment
  refusals. Database users report their database references and cannot be deleted
  while in use.

### Changed and fixed

- Enforce account ownership, readable parent references, assigned servers, PHP
  modes/versions and hosting-plan options for client and reseller keys. Scheduled
  tasks and SSH authentication also follow their account capabilities.
- Apply ISPConfig client lock/unlock and cancel side effects. Scoped keys cannot
  add resources, re-enable services or mutate backups while the owner is locked.
- Apply the installation's password policy to FTP, SSH, WebDAV, protected-folder,
  database-user, statistics and client/reseller passwords, as well as mailbox
  passwords. Integrations submitting weaker passwords now receive `422`.
- Match ISPConfig's DNS duplicate rules and administrator-only zone fields, enforce
  limits across complete zone-wizard batches, and refuse DNSSEC signing on mirrored
  servers. Protect primary DNS zones while synchronized aliases depend on them.
- Register client-domain names atomically when creating website aliases, supporting
  installations that enable ISPConfig's client domain limits.
- Resolve database-user prefixes from the target client when an administrator
  provisions on that client's behalf. Preserve recipient visibility for sender
  rules created by resellers.
- Enforce actual database table/index storage quotas during import/copy independently
  of dump size; check temporary disk space and provide worker diagnostics. Failed
  imports may leave applied statements; failed copies retain their new database
  for inspection or removal.
- Report Let's Encrypt request outcomes and available certificate validity/failure
  details. Align change timestamps and traffic reporting with the server timezone.
- Allow empty text system settings to be cleared and make the client company name
  optional, matching ISPConfig.

### Upgrade notes

1. **Run migrations before enabling the new features.** Seven migrations since
   rc.3 create or extend API-owned alias-service, database-operation and web-reader
   tables. They do not alter native ISPConfig tables. `ispconfig-rest update` runs
   migrations with the configured runtime database account; if it lacks DDL rights,
   run `php artisan migrate --force` from the updated checkout with a privileged
   migration connection. Clear cached configuration before applying temporary
   database-credential overrides, then rebuild it with the normal runtime settings.
   The installer also provides a privileged migration path.
2. **Install the scheduler.** After upgrading an older installation, run
   `sudo ispconfig-rest schedule:install` once to enable alias DNS reconciliation.
   Current installation/update scripts install the cron entry automatically.
3. **Install or upgrade database workers** on each database server that should
   offer import/export/copy: `sudo sh worker/install.sh`. Configure the master-table
   grants, native database clients, upload limits and transfer timeouts described
   in the [database worker guide](worker/README.md). Existing installations need a
   16 MiB API request-body limit for the largest base64 upload envelope. Database
   quotas still apply; a dump's uncompressed size is not its database storage usage.
4. **Install or upgrade web readers** on each web server that should offer remote
   logs, document-root changes or nginx environment settings:
   `sudo sh web-log-worker/install.sh`. Copy the support files with their documented
   directory layout or use a trusted full checkout. nginx requires the installer's
   constants include in the `http` context. Existing readers continue to serve logs
   but do not advertise the new runtime controls. See the
   [web reader guide](web-log-worker/README.md).
5. **Check integration compatibility.** Generated passwords must meet the
   installation policy; requests outside an account's ownership, assignment or
   plan permissions are now rejected. Respect `409` when deleting a referenced
   database user or editing a synchronized DNS zone. Redact runtime settings and
   native vhost directives from integration logs because they can contain secrets.

### Availability limits

- Database operations require an active MySQL database and a current worker
  heartbeat; PostgreSQL operations are not offered. Import is destructive and is
  not transactional across SQL DDL.
- HTTP backup downloads require an API-readable prepared backup copy. Remote or
  restricted files remain available through ISPConfig's FTP/SSH preparation flow.
- Certificate expiry details require access to the website's certificate file;
  alternate test hostnames do not guarantee DNS readiness or certificate coverage.
- Runtime environment values apply to website requests, not SSH, scheduled tasks
  or global PHP-FPM process environment. New capability fields let panels offer
  only controls supported by the serving machine.

## [1.0.0-rc.3] - 2026-07-07

- Mark ISPConfig `sys_*` metadata as read-only in OpenAPI so it is excluded from
  generated request bodies.

## [1.0.0-rc.2] - 2026-07-07

- Accept `client_id` when creating resources to assign ownership to a client.
- Add owning-client and parent-domain list filters.

## [1.0.0-rc.1] - 2026-07-05

- Initial release candidate.

[1.0.1]: https://github.com/FELDSAM-INC/ispconfig-rest/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/FELDSAM-INC/ispconfig-rest/compare/v1.0.0-rc.3...v1.0.0
[1.0.0-rc.3]: https://github.com/FELDSAM-INC/ispconfig-rest/compare/v1.0.0-rc.2...v1.0.0-rc.3
[1.0.0-rc.2]: https://github.com/FELDSAM-INC/ispconfig-rest/compare/v1.0.0-rc.1...v1.0.0-rc.2
[1.0.0-rc.1]: https://github.com/FELDSAM-INC/ispconfig-rest/tree/v1.0.0-rc.1
