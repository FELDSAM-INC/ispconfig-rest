# Quickstart: Client Lock and Cancel Side Effects

## 1. Automated tests (local, Docker PHP 8.3)

```bash
cd ~/Projects/whmcs/ispconfig-rest
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test \
  --filter 'ClientLockApiTest|ClientCancelApiTest|ResellerLockApiTest|LockedClientWriteGuardTest'
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test   # full suite
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli vendor/bin/pint --test <changed files>
```

## 2. Manual check on isp-test (temporary client only)

Deploy: `ssh root@isp-test.feldhost.cz 'ispconfig-rest update && ispconfig-rest status'`.

Never lock or cancel existing clients. On the server (`API=https://localhost:8090/api/v1`):

1. Mint a QA admin key: `ispconfig-rest key:create "qa admin 019"`.
2. `POST /clients` with a unique username, password, contact fields and `"canceled": true` → 201; check
   `SELECT active FROM sys_user WHERE client_id = <id>` → `0`.
3. `POST /system/api-keys {"name":"qa 019","client_id":<id>}` → client key (keep it secret).
4. With the client key create a mail domain, a mailbox and a website on server 1 (default servers, spec 016).
5. Wait until `GET /monitor/data-logs?unprocessed_only=true` (admin key) returns no rows for the session.
6. `PUT /clients/<id> {"locked": true}` (admin key) → 200; the website, mail domain and mailbox show
   `active`/`postfix` false and `disablesmtp` true; `client.tmp_data` contains `prev_active`; datalog processed.
7. With the client key `PUT` the website `{"active": true}` → 403; with the client key create another mail
   domain → 403.
8. `PUT /clients/<id> {"locked": false}` → services enabled again; `prev_active` removed.
9. `PUT /clients/<id> {"canceled": false}` → `sys_user.active = 1`; `{"canceled": true}` → `0`.
10. Cleanup: `DELETE /clients/<id>` (cascade), wait for datalog processing, `DELETE` remaining QA client keys,
    `ispconfig-rest key:revoke <qa admin key id>`; verify with read-only SQL that no rows with the QA username
    or domains remain.
