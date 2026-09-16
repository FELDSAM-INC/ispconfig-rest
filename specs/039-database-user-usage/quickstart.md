# Quickstart: Database User Usage and Unlink Safety

## 1. Automated tests

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test \
  --filter='WebDatabaseUserUsageTest|WebDatabaseUserApiTest|SwaggerSpecServerTest'
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test
```

## 2. Manual check on isp-test

Deploy: `ssh root@isp-test.feldhost.cz 'ispconfig-rest update && ispconfig-rest status'` (only commits already on
`origin/main`).

Rules for this server: TEMPORARY clients only — never clients 1, 2, 19 or any `customer_no` starting `WHMCS-`; never
delete keys, clients or data another session created (the pre-existing `c1jozko` database user belongs to client 1).
Passwords must satisfy the installation policy (spec 038: 8 characters, strength "Good").

1. Admin key: `POST /clients` `qa039…` (`template_master: 0`, `db_servers=1`, `web_servers=1`); mint a
   client-scoped key; create a website for the client.
2. Client key: create a database user, then a database bound to it.
3. Client key `GET /sites/database-users/{id}` → `databases_in_use` is 1; the list entry shows the same.
4. Client key `DELETE /sites/database-users/{id}` → 409 with `type` ending `#resource-in-use` and the legacy
   message; the journal count is unchanged and both rows still exist.
5. Admin key: the same delete → 409 as well (the guard is not a permission).
6. Client key: create a second database user and assign it as the database's read-only user; delete that second
   user → 409 (the `database_ro_user_id` column is checked too).
7. Client key: delete the database, then `GET` the user → `databases_in_use` is 0, and the delete now returns 204.
8. Client key: create a user nothing references → `databases_in_use` is 0 and it deletes with 204.

## 4. Results on isp-test (2026-09-16, deployed `3e2ef1d`)

Temporary client 49 (`qa039temp`) with website 27, database users 6 (`owner`) and 7 (`readonly`), and database 5
(`shop`) bound to both — 6 as its credentials, 7 as its read-only user.

| Check | Result |
|---|---|
| Unused user `GET /sites/database-users/{id}` | `databases_in_use` = 0 |
| After creating the database — owner / read-only user | `databases_in_use` = 1 each |
| List `GET /sites/database-users` | carries the field: `owner` = 1, `readonly` = 1 |
| Client key deletes the owner user | 409, `type` = `…#resource-in-use`, detail "The user cannot be deleted. It is still being used by a database." |
| Client key deletes the read-only user | 409 — the `database_ro_user_id` column is checked too |
| Admin key deletes the owner user | 409 — the guard protects data, not permissions |
| Journal during the three refusals | 0 new rows |
| After deleting the database | `databases_in_use` = 0, and both users delete with 204 |

Cleanup: website and client deleted through the API; `server.updated` reached the last journal id; QA keys 110–112
removed. No `qa039` client, website, database or database user remains and nothing is pending. The one database and
one database user still present (`c1jozko`) belong to client 1 and predate this run; clients 1, 2 and 19 and the
remaining keys were untouched.

## 3. Cleanup

1. Delete the remaining database users, the website and the client through the API.
2. Wait until `server.updated` equals the last `sys_datalog.datalog_id`.
3. Delete the QA keys by SQL (`name LIKE 'qa039%'` and the ids created) after checking the names.
4. Verify no `qa039` client, group, user, website, database or database user remains, that the pre-existing
   `c1jozko` row is untouched, and that nothing is pending.
