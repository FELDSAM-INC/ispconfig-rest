# Data Model: Mail Capabilities and Email Program Settings for Scoped Keys

No tables or migrations. All values are derived per request (read-only).

## AccountCapabilities.mail (`api/components/schemas/AccountCapabilities.yaml`)

| Field | Type | Source |
|---|---|---|
| `mail.autoresponder` | boolean | `sys_ini` [mail] `mailbox_show_autoresponder_tab` = `y` or missing |
| `mail.mail_filters` | boolean | `sys_ini` [mail] `mailbox_show_mail_filter_tab` = `y` or missing |
| `mail.custom_rules` | boolean | always `false` (administrator-only) |
| `mail.spamfilter_policy` | boolean | ≥ 1 `spamfilter_policy` row readable (`'r'`) by the client's own scope |
| `mail.dkim` | boolean | ≥ 1 account mail server with [mail] `dkim_path` set, not empty, not `/` |
| `mail.custom_login` | boolean | `sys_ini` [mail] `enable_custom_login = y` |
| `mail.password_policy` | MailPasswordPolicy | see below |

## MailPasswordPolicy (`api/components/schemas/MailPasswordPolicy.yaml`)

| Field | Type | Source |
|---|---|---|
| `min_length` | integer ≥ 0 | `sys_ini` [misc] `min_password_length` (missing → 8, empty → 0) |
| `min_strength` | integer 0–5 | `sys_ini` [misc] `min_password_strength` (missing or empty → 0; 1 Weak … 5 Very Strong) |
| `ascii_only` | boolean | `sys_ini` [mail] `mail_password_onlyascii = y` |

## AccountMailSettings (`api/components/schemas/AccountMailSettings.yaml`)

| Field | Type | Source |
|---|---|---|
| `client_id` | integer | target client (R1) |
| `custom_login` | boolean | [mail] `enable_custom_login = y` (mailbox login is `login`, not the address) |
| `webmail_link` | boolean | [mail] `mailboxlist_webmail_link = y` (missing → `n`) |
| `password_policy` | MailPasswordPolicy | as above |
| `servers` | AccountMailServer[] | account mail servers (R6), assigned first |

## AccountMailServer (`api/components/schemas/AccountMailServer.yaml`)

| Field | Type | Source |
|---|---|---|
| `server_id` | integer | `server.server_id` |
| `host` | string | `server.server_name` |
| `webmail_url` | string | [mail] `webmail_url` with `[SERVERNAME]` → host; empty → `https://<host>/webmail` (nginx: `:8081`) |
| `imap` | MailConnection | `{port: 993, security: ssl}` |
| `pop3` | MailConnection | `{port: 995, security: ssl}` |
| `smtp` | MailConnection[] | `[{port: 587, security: starttls}, {port: 465, security: ssl}]` |

## MailConnection (`api/components/schemas/MailConnection.yaml`)

| Field | Type |
|---|---|
| `port` | integer 1–65535 |
| `security` | `ssl` \| `starttls` |

## UsageSummary.counts (additions)

| Key | Limit column | Counted rows (spec 012 create rule) |
|---|---|---|
| `mail_catchalls` | `limit_mailcatchall` | `mail_forwarding` `type = catchall`, `'u'` predicate |
| `mail_alias_domains` | `limit_mailaliasdomain` | `mail_forwarding` `type = aliasdomain`, `'u'` predicate |
| `mail_filters` | `limit_mailfilter` | `mail_user_filter`, `'u'` predicate |
| `fetchmail_accounts` | `limit_fetchmail` | `mail_get`, `'u'` predicate |

## Refusals

| Case | Status | Body |
|---|---|---|
| non-admin `PUT`/`DELETE` autoresponder, tab off | 403 | `feature-not-allowed`, `feature: mailbox_show_autoresponder_tab`, detail `Autoresponders are not enabled on this installation.` |
| non-admin filter `POST`/`PUT`/`DELETE`, tab off | 403 | `feature-not-allowed`, `feature: mailbox_show_mail_filter_tab`, detail `Mail filters are not enabled on this installation.` |
| non-admin spamfilter `PUT` with `move_junk`/`purge_*`, tab off | 403 | as filters |
| non-admin spamfilter `PUT` with `custom_mailfilter` | 422 | `errors.custom_mailfilter` `Custom mail filter rules can only be changed with an administrator key.`, `error_types.custom_mailfilter` = feature-not-allowed |
| unknown query parameter on `/me/mail-settings` | 400 | `Unknown parameter '<name>'. Allowed: client_id.` |
| `client_id` invalid / admin without it | 422 | `The client id must be a positive integer.` / `The client id is required for admin keys.` |
| target client not visible | 404 | not found problem |
