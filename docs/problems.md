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

A count limit of the plan is reached (e.g. number of websites, mail domains, databases, DNS zones).

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
`feature`: the client limit column (`limit_backup`, `limit_ssl`, `limit_ssl_letsencrypt`, `limit_mailrouting`, …), or
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
