# Changelog

Release notes for ISPConfig REST API. Versions refer to the application release;
the HTTP API remains under `/api/v1`.

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
