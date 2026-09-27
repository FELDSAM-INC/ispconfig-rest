# WordPress Toolkit Lite: capability audit and first milestone

The existing file-manager worker provisions jailed SFTP but has no PHP runtime.
WordPress commands must run in a separate, fail-closed bubblewrap sandbox as the
native site UID/GID. This is an extension of the same installed worker, with its
own queue lock so long jobs cannot delay SFTP credential revocation. WP-CLI is
pinned and checksum verified. No customer shell or arbitrary command endpoint.

Native ISPConfig has no WordPress inventory or WP-CLI API. API-owned tables hold
worker capability, identity-bound inventory and bounded asynchronous jobs.
Native vhost changes continue through WebDomain/BaseModel and sys_datalog.
Existing WAF/runtime managed blocks must survive every WordPress change.

Apache supports the requested server protections in native vhost directives.
Those protections are unavailable on nginx in this milestone; PHP/config/DB
measures remain independent. Applied state requires observing generated vhost
configuration rather than assuming that a native row save was applied.

Database export already exists as a queued worker operation. Irreversible DB
changes require that export and a private rollback snapshot, explicit confirmation,
WP-CLI verification and local HTTP verification. External/unmatched databases,
multisite prefix renaming and unsafe configuration layouts must fail before edits.
Existing WP plugins, mu-plugins, drop-ins and wp-config.php are untrusted code even
with --skip-plugins/--skip-themes, which is why filesystem isolation is mandatory.

First delivery: detection and security, including guarded one-way actions, module
UI, adversarial tests and a disposable real WordPress on development. Integrity
and managed wp-cron are a later milestone after the requested progress report.
