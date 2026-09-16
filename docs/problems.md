# Problem types

Errors of the ISPConfig REST API are [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem details
(`application/problem+json`). Refusals that integrations act on carry a stable `type` URI of the form

```
https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#<name>
```

Compare the `type` as an exact string; `title` and `detail` are for humans and may be reworded. Problems not listed
here use `type: about:blank`.

## account-locked

**Status**: 403

The account (ISPConfig client) is locked, so the request would enable or add services, or start, restore, delete or
reconfigure backups. Read requests and administrator keys are not affected. Unlock the client to continue.

No extension members.

## limit-reached

**Status**: 403

A count limit of the plan is reached (e.g. number of websites, mail domains, databases, database users, DNS zones,
DNS records), or a scheduled task would run more often than `limit_cron_frequency` allows (spec 035 — there `max` is
the plan's shortest interval in minutes and `used` the interval the task would run at).

Extension member `limit`:

| Member | Meaning |
|--------|---------|
| `name` | client limit column, e.g. `limit_web_domain` |
| `scope` | `client` (the account's cap) or `reseller` (its reseller's cap) |
| `max` | the cap |
| `used` | records counted by the check |

## quota-exceeded

**Status**: 403

A quota sum of the plan would be exceeded (web disk, mailbox or database quota).

Extension member `limit`:

| Member | Meaning |
|--------|---------|
| `name` | client limit column, e.g. `limit_mailquota` |
| `scope` | `client` or `reseller` |
| `unit` | `MB` |
| `max` | the cap in MB |
| `used` | MB already allocated |
| `requested` | MB requested, or `null` when an unlimited quota was requested under a finite cap |

## feature-not-allowed

**Status**: 403, or as a field type in `error_types` of a 422

The plan or the installation does not include the feature. As a 403 it refuses the whole request and carries
`feature`: the client limit column (`limit_backup`, `limit_ssl`, `limit_ssl_letsencrypt`, `limit_mailrouting`, `limit_cron_type`, …), or
the system setting that switched a mailbox option off for client and reseller keys (`mailbox_show_autoresponder_tab`,
`mailbox_show_mail_filter_tab`). As a field type it marks website fields the plan does not allow: SSL, Let's Encrypt,
CGI, SSI, Perl, Ruby, Python, custom error documents, directive snippets, wildcard subdomains, the forced suEXEC
option, PHP modes and versions, and administrator-only settings; and the administrator-only custom mail filter rules
(`custom_mailfilter`).

## server-not-assigned

**Field type** in `error_types` of a 422

The server in `server_id` is not assigned to the account, or no server of the required service (web, mail, database,
DNS, secondary DNS) is assigned. Unassigned and nonexistent servers are reported the same way.

## validation-failed

**Status**: 422

One or more fields are invalid. `errors` maps each field to its messages. `error_types` maps fields whose error is a
`feature-not-allowed` or `server-not-assigned` refusal to that type URI; it is absent when no field has a typed error.

## resource-in-use

**Status**: 409

The resource cannot be deleted while another resource depends on it. Remove or reassign the dependants first, then
repeat the delete.

Raised by `DELETE /sites/database-users/{id}` while a database still names the user as its credentials
(`database_user_id`) or as its read-only user (`database_ro_user_id`) — the count is readable as
`databases_in_use` on the database user. This protects data rather than permissions, so it applies to every key
type, administrator keys included, and nothing is written (spec 039).

No extension members. Other conflict responses in the API (an in-use directive snippet, an in-use client template, a
reseller that still has clients, a duplicate name) keep `type: about:blank`.

## download-not-prepared

**Status**: 409

No copy of the backup is available to download. ISPConfig does not serve backups from where it stores them; a
copy must first be delivered into the website's own `backup` folder with
`POST /sites/web-domains/{id}/backups/{backup_id}/download`, which the server processes within about a minute.

Raised by `GET`/`HEAD /sites/web-domains/{id}/backups/{backup_id}/download` when no copy exists, when the
folder cannot be resolved, or when the existing copy is past its three-day retention and may vanish at any
moment (spec 042). Prepare a fresh copy and retry.

No extension members. The backup's own `download` object reports the same situation as `state: not_prepared`,
so a consumer can avoid the request entirely.

## download-not-readable

**Status**: 409

A copy of the backup exists, but this API cannot read it, so it cannot be streamed over HTTP. Fetch it with
the website's FTP or SSH access from the website's `backup` folder.

Normally the copy *is* readable — ISPConfig adds the web server user to every client group, which is how Apache
serves the 0750 client directories — so this refusal means the installation differs: hardened permissions, a
different runtime user for the API, or a backup stored on another server than the website, where a copy can never
be delivered (spec 042). It is a property of the installation, not a transient failure: retrying does not help,
and the API never changes permissions to make itself able to read.

No extension members, and no file system path is disclosed. The backup's own `download` object reports this
as `http: false`, so a consumer can offer the FTP/SSH explanation instead of a download button.
