# Contract: Database User Usage and Unlink Safety

OpenAPI sources: `api/components/schemas/DatabaseUser.yaml`, `api/modules/sites/database-users.yaml`,
`docs/problems.md`.

## GET /sites/database-users, GET /sites/database-users/{id} — one more field

```json
{
  "id": 4,
  "database_user": "c42_shop",
  "database_user_prefix": "c42_",
  "databases_in_use": 2
}
```

`databases_in_use` is the number of databases **the key may read** that reference this user as their credentials
(`database_user_id`) or as their read-only user (`database_ro_user_id`). A database naming the user in both columns
counts once. Read-only, always present, `0` when nothing uses the user.

The field is computed for a whole page at once, so listing stays a single query regardless of page size.

## DELETE /sites/database-users/{id} — refused while in use

The description currently claims the opposite of the enforced behaviour ("Databases still referencing this user lose
their credentials — reassign them first") and is corrected to state the refusal.

### 409

```json
{
  "type": "https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#resource-in-use",
  "title": "Conflict",
  "status": 409,
  "detail": "The user cannot be deleted. It is still being used by a database."
}
```

- Raised while `databases_in_use` is greater than 0 for the acting key.
- Applies to **every** key type, administrator keys included: this protects data, it is not a permission.
- Nothing is written — no `sys_datalog` row is produced.
- Reassign or delete the databases first, then the delete returns 204 as before.

Responses otherwise unchanged: `204` on success, `401`, `403`, `404`, `500`.

## docs/problems.md — one new type

| Type | Status | Meaning |
|---|---|---|
| `resource-in-use` | 409 | the resource cannot be deleted while another resource depends on it |

No extension members for this feature. Existing untyped 409s elsewhere in the API (in-use directive snippets, client
templates, resellers) keep `about:blank`; this spec types only the refusal it introduces.

## Consumer mapping (WHMCS module spec 006)

| Panel element | Source |
|---|---|
| "Used by N databases" next to a database user | `databases_in_use` |
| Disabling or explaining the Delete button | `databases_in_use > 0` |
| Message when a delete is attempted anyway | 409 with `type` = `resource-in-use` |
