# Data Model: Database User Usage and Unlink Safety

No migrations. One derived read-only field and one delete guard.

## DatabaseUser.databases_in_use (`api/components/schemas/DatabaseUser.yaml`)

| Field | Type | Source |
|---|---|---|
| `databases_in_use` | integer, read-only, minimum 0 | number of `web_database` rows the key may read whose `database_user_id` **or** `database_ro_user_id` is this user |

A row that names the user in both columns counts once. Present on the single resource and on every list item.

**Computation**: one grouped query over `web_database` restricted by the key's read scope, for the ids on the page:

```
SELECT user_id, COUNT(DISTINCT database_id)
  FROM web_database
 WHERE (database_user_id IN (:ids) OR database_ro_user_id IN (:ids))
   AND <read scope>
```

Users with no rows report 0 — never a missing key.

## Delete guard

| Endpoint | Condition | Result |
|---|---|---|
| `DELETE /sites/database-users/{id}` | `databases_in_use > 0` for the acting key | 409, problem type `resource-in-use`, detail *"The user cannot be deleted. It is still being used by a database."* |

Checked before `delete()`, so nothing is written and no `sys_datalog` row appears. Applies to every key type,
including administrator keys.

## Problem type registry (`docs/problems.md`, spec 023)

| Type | Status | Meaning | Extension members |
|---|---|---|---|
| `resource-in-use` | 409 | the resource cannot be deleted while another resource depends on it | none for this feature |

The type URI follows the existing scheme
(`https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#resource-in-use`). Existing untyped 409s
(in-use directive snippets, client templates, resellers) keep `about:blank`; this spec types only the refusal it
introduces.

## Not changed

Storage, the credential columns themselves, the `onAfterDelete` behaviour of legacy (which only ever applied once
nothing referenced the user), PostgreSQL-specific users, and the read-only user assignment on databases.
