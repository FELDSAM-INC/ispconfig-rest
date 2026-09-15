# Quickstart: Web Permission Enforcement for Scoped Keys

## 1. Automated tests

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test \
  --filter='WebPlanFlagsScopedKeyTest|WebPhpScopedKeyTest|WebAdminOptionsScopedKeyTest|WebDomainApiTest|WebDomainSslApiTest'
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test
```

## 2. Manual check on isp-test

Deploy: `ssh root@isp-test.feldhost.cz 'ispconfig-rest update && ispconfig-rest status'`.

Use a TEMPORARY client only (never clients 1, 2 or `WHMCS-*`). With a QA admin key
(`ispconfig-rest key:create "qa 020"`):

1. `POST /clients` for `qa020…` with `limit_ssl=n`, `limit_ssl_letsencrypt=n`, `limit_cgi=n`, `force_suexec=y`,
   `web_php_options=no,php-fpm`, `web_servers=1`, limits for one website; mint its client key
   (`POST /system/api-keys`).
2. Client key:
   - `POST /sites/web-domains` `{"domain":"qa020.example.test","ssl":true,"ssl_letsencrypt":true}` → 422 (`ssl`,
     `ssl_letsencrypt`)
   - `{"domain":…, "php":"fast-cgi"}` → 422 `php`
   - `{"domain":…, "php":"php-fpm"}` → 201; `server_php_id` = first version (server 1 hides the default)
   - `PUT {"cgi":true}` → 422; `PUT {"suexec":false}` → 422; `PUT {"pm":"static"}` → 422; `PUT {"domain":"x.test"}`
     → 422; `PUT {"server_php_id":999}` → 422; `PUT {"server_php_id":<another version>}` → 200
   - `POST /sites/web-domains/{id}/ssl` → 403
3. Admin key: `PUT {"pm":"ondemand"}` on the same site → 200 (unchanged behavior).
4. Cleanup: `DELETE /clients/{id}` (admin), wait until the datalog is processed, delete QA keys by SQL
   (`DELETE FROM api_keys WHERE id IN (…) AND name LIKE 'qa%'`), verify no `qa020` rows remain.

## 3. Results

Recorded in tasks.md after the run.
