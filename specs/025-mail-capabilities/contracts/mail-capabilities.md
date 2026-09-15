# Contract: mail capabilities, `GET /me/mail-settings`, usage counts, tab refusals

Source of truth after implementation: `api/modules/me/capabilities.yaml`, `api/modules/me/mail-settings.yaml`,
`api/modules/usage/summary.yaml`, `api/modules/mail/user-autoresponder.yaml`, `user-spamfilter.yaml`,
`user-filters.yaml`, schemas `AccountCapabilities.yaml`, `AccountMailSettings.yaml`, `AccountMailServer.yaml`,
`MailConnection.yaml`, `MailPasswordPolicy.yaml`, `UsageSummary.yaml`.

## GET /api/v1/me/capabilities (addition)

```json
{
  "client_id": 42,
  "account_type": "client",
  "locked": false,
  "canceled": false,
  "web": { "…": "unchanged (spec 021)" },
  "mail": {
    "autoresponder": true,
    "mail_filters": true,
    "custom_rules": false,
    "spamfilter_policy": true,
    "dkim": true,
    "custom_login": false,
    "password_policy": { "min_length": 8, "min_strength": 3, "ascii_only": false }
  }
}
```

## GET /api/v1/me/mail-settings

Query: `client_id` (integer ≥ 1; required for admin keys). Errors: 400 unknown parameter; 401; 404 target client not
visible/unknown; 422 `client_id`.

200:

```json
{
  "client_id": 42,
  "custom_login": false,
  "webmail_link": true,
  "password_policy": { "min_length": 8, "min_strength": 3, "ascii_only": false },
  "servers": [
    {
      "server_id": 1,
      "host": "mail1.example.com",
      "webmail_url": "https://mail1.example.com:8081/webmail",
      "imap": { "port": 993, "security": "ssl" },
      "pop3": { "port": 995, "security": "ssl" },
      "smtp": [
        { "port": 587, "security": "starttls" },
        { "port": 465, "security": "ssl" }
      ]
    }
  ]
}
```

## GET /api/v1/usage/summary (addition)

`counts` gains `mail_catchalls`, `mail_alias_domains`, `mail_filters`, `fetchmail_accounts`, each
`{ "used": 1, "limit": 1 }` (`limit` null = unlimited).

## Refusals for client and reseller keys

`PUT`/`DELETE /mail/users/{id}/autoresponder` with the vacation tab off:

```json
{
  "type": "https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#feature-not-allowed",
  "title": "Forbidden",
  "status": 403,
  "detail": "Autoresponders are not enabled on this installation.",
  "feature": "mailbox_show_autoresponder_tab"
}
```

`POST`/`PUT`/`DELETE /mail/users/{id}/filters…`, and `PUT /mail/users/{id}/spamfilter` carrying `move_junk`,
`purge_trash_days` or `purge_junk_days`, with the mail filter tab off: same shape, detail `Mail filters are not
enabled on this installation.`, `feature: mailbox_show_mail_filter_tab`.

`PUT /mail/users/{id}/spamfilter` with `custom_mailfilter`:

```json
{
  "type": "https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#validation-failed",
  "title": "Validation failed",
  "status": 422,
  "detail": "One or more fields are invalid.",
  "errors": { "custom_mailfilter": ["Custom mail filter rules can only be changed with an administrator key."] },
  "error_types": {
    "custom_mailfilter": "https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#feature-not-allowed"
  }
}
```

Admin keys are never refused by these rules. Reads (`GET`) are never refused by them.
