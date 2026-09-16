# Research: Database User Usage and Unlink Safety

Legacy source: ISPConfig 3.3.1p1 on isp-test.feldhost.cz (read-only). Live facts from isp-test on 2026-09-16.

## R1 — What legacy does on delete

`sites/database_user_del.php::onBeforeDelete()`:

```php
$tmp = $app->db->queryOneRecord('SELECT `database_id` FROM `web_database`
        WHERE `database_user_id` = ? OR `database_ro_user_id` = ?', $this->id, $this->id);
if(!empty($tmp)) {
    $app->error($app->tform->lng('error_del_db_user_in_use_txt'));
}
```

`error_del_db_user_in_use_txt` = *"The user cannot be deleted. It is still being used by a database."*
(`sites/lib/lang/en_database_user.lng:25`). The check runs for every logged-in user type — it is a data-integrity
guard, not a permission.

`onAfterDelete()` nulls `database_user_id` / `database_ro_user_id` on the remaining rows, but only ever runs after
the guard has passed, i.e. when nothing references the user any more. It is not a fallback for the guard.

## R2 — What the API does today

`WebDatabaseUserController::destroy()` deletes unconditionally:

```php
DB::transaction(fn () => $webDatabaseUser->delete());
return response()->noContent();
```

The contract states the opposite of legacy: *"Databases still referencing this user lose their credentials —
reassign them first. This action cannot be undone."* (`api/modules/sites/database-users.yaml`, DELETE description).
An integration following the documentation can break a live database in one call.

`DatabaseUser.yaml` exposes `id`, `server_id`, `database_user`, `database_user_prefix`, `database_password`,
`client_id` and the `sys_*` fields — nothing about usage, so a panel cannot warn beforehand without reading every
database of the account.

## R3 — Status code and problem type

**Options**: (a) 409 with a new problem type; (b) 422 on a field; (c) 403 with an existing type.

**Findings**: this is a dependency conflict, not field validation and not a permission. The API already answers 409
for exactly this shape elsewhere — `DirectiveSnippetController::destroy()` throws `ConflictHttpException('The
snippet cannot be deleted while websites use it.')`, and client templates and resellers refuse deletion with 409
while something still references them. Contracts express it as `'409': $ref: components/responses/Conflict.yaml`.
Spec 023's registry has no type for it (`account-locked`, `limit-reached`, `quota-exceeded`,
`feature-not-allowed`, `server-not-assigned`, `validation-failed`).

**Decision** (owner-delegated 2026-09-16): (a) — 409 with a new `resource-in-use` type documented in
`docs/problems.md`, raised through `ProblemAuthorizationException`'s sibling path for conflicts so the type URI is
rendered. The existing untyped 409s stay as they are; this spec adds a type only where it introduces one.

## R4 — Scoping the count and the guard

Every list in this API is scoped to what the key may read (spec 011). Two consequences:

- The reported count must only include databases the key can read, otherwise it leaks the existence of another
  client's database.
- The guard must use the same scope, so a client key is never refused because of a row it cannot see. In practice a
  database and its user belong to the same client, so the two coincide; the difference matters only for
  administrator-made cross-assignments, where an administrator key still sees — and is refused by — the reference.

**Decision**: one scoped count, used by both the resource field and the delete guard.

## R5 — Keeping the list cheap

The constitution forbids per-row API calls on list screens and the module's budget assumes cheap lists. A count per
row must therefore come from a single grouped query over `web_database` for the ids on the page, not from N queries.

**Decision**: `databases_in_use` is computed for the whole page at once (`GROUP BY` over both credential columns,
counting a row once even when it names the user twice) and attached to each item.

## R6 — Name vs count

Returning the database names would help a panel explain the refusal, but a client key must not learn names it cannot
read, and the field would grow the list payload.

**Decision**: a plain integer. A panel that wants names filters the databases it already lists by
`database_user_id` / `database_ro_user_id`.

## R7 — Effect on consumers

- The WHMCS module (spec 006 dependency 039) can stop pre-checking with its own database list and rely on the
  refusal, while still showing the count.
- Integrations that currently delete a user and expect the databases to keep working were already broken; they now
  receive a clear 409 instead of a silently damaged database.
