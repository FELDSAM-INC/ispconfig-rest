# Contract: spam filter level of mailboxes and mail domains

Source of truth after implementation: `api/components/schemas/MailUserSpamFilter.yaml`,
`api/components/schemas/MailDomain.yaml`, `api/modules/mail/user-spamfilter.yaml`, `api/modules/mail/domains.yaml`,
`api/modules/mail/spamfilter-policies.yaml`.

## GET /api/v1/mail/users/{id}/spamfilter

```json
{
  "id": 12,
  "move_junk": "y",
  "purge_trash_days": 0,
  "purge_junk_days": 0,
  "custom_mailfilter": null,
  "policy_id": 5
}
```

## PUT /api/v1/mail/users/{id}/spamfilter

Body (all fields optional): `{"policy_id": 5}` — 0 = inherit the domain or server level. Response 200 as GET, with
`X-Change-Set-Id` when a change was journaled. Errors: 401; 403 (no update permission, feature 025 tab rules for tab
fields); 404; 422 `policy_id` (`The selected policy id is invalid.`).

## Mail domain

`GET /api/v1/mail/domains`, `GET /api/v1/mail/domains/{id}`, and the `POST`/`PUT` responses include
`"spamfilter_policy_id": 5` (0 = no policy).

`POST /api/v1/mail/domains` and `PUT /api/v1/mail/domains/{id}` accept `"spamfilter_policy_id": 5`; without the field
the level is not written. Errors add 422 `spamfilter_policy_id` (`The selected spamfilter policy id is invalid.`) and,
for `PUT`, 403 without update permission.

## GET /api/v1/mail/spamfilter/policies

Unchanged: client and reseller keys see the policies they can read — the levels they may choose.
