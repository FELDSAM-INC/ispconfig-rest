# Changelog

Release notes for ISPConfig REST API. Versions refer to the application release;
the HTTP API remains under `/api/v1`.

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

[1.0.0]: https://github.com/FELDSAM-INC/ispconfig-rest/compare/v1.0.0-rc.3...v1.0.0
[1.0.0-rc.3]: https://github.com/FELDSAM-INC/ispconfig-rest/compare/v1.0.0-rc.2...v1.0.0-rc.3
[1.0.0-rc.2]: https://github.com/FELDSAM-INC/ispconfig-rest/compare/v1.0.0-rc.1...v1.0.0-rc.2
[1.0.0-rc.1]: https://github.com/FELDSAM-INC/ispconfig-rest/tree/v1.0.0-rc.1
