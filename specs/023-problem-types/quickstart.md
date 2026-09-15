# Quickstart: Machine-Readable Problem Types (023)

## 1. Automated tests

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli \
  php artisan test --filter='ProblemTypeRenderingTest|LockedClientWriteGuardTest|WebBackupLockedClientTest|ClientLimitSitesTest|ClientQuotaSumTest|ClientLimitResellerTest|BackupLimitGateTest|WebAdminOptionsScopedKeyTest|WebPlanFlagsScopedKeyTest|WebPhpScopedKeyTest|ClientServerAssignmentTest|ClientServerAssignmentWritesTest|ScopingMailModuleTest'
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test
```

## 2. Manual check on isp-test

After `ispconfig-rest update`, with a QA admin key and a TEMPORARY client (`limit_web_domain = 1`,
`limit_ssl_letsencrypt = n`, web server 1) and its client key:

1. Create one website → 201; create a second → 403 `type …#limit-reached`, `limit = {limit_web_domain, client, 1, 1}`.
2. `PUT` the first website with `ssl: true, ssl_letsencrypt: true` → 422 `type …#validation-failed`,
   `error_types.ssl_letsencrypt = …#feature-not-allowed` (plan without Let's Encrypt; `limit_ssl` y).
3. Create a website with `server_id: 99` → 422 `error_types.server_id = …#server-not-assigned`.
4. Lock the client (admin `PUT /clients/{id}` `locked: true`); `PUT` the website `active: true` after an admin
   disabled it → 403 `type …#account-locked`.
5. Plain 422 (e.g. invalid domain) → `type …#validation-failed`, no `error_types`; 404 keeps `about:blank`.

## 3. Cleanup

Unlock and delete the temporary client with the admin key, wait until `server.updated` ≥ the last `sys_datalog` id,
delete QA keys by id (`name LIKE 'qa%'`), verify no client, website or directory remains.
