# Quickstart: Account Capabilities for Scoped Keys

## 1. Automated tests

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test \
  --filter='MeCapabilitiesApiTest|MePhpVersionsApiTest|WebPhpScopedKeyTest|MeServersApiTest|SwaggerSpecServerTest'
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test
```

## 2. Manual check on isp-test

Deploy: `ssh root@isp-test.feldhost.cz 'ispconfig-rest update && ispconfig-rest status'`.

Use a TEMPORARY client only (never clients 1, 2 or `WHMCS-*`). With a QA admin key
(`ispconfig-rest key:create "qa021 admin"`, plaintext kept in a root-only file):

1. `POST /clients` for `qa021…` with `limit_ssl=y`, `limit_ssl_letsencrypt=n`, `limit_wildcard=n`,
   `force_suexec=y`, `web_php_options=no,php-fpm`, `web_servers=1`; mint its client key (`POST /system/api-keys`).
2. Client key:
   - `GET /me/capabilities` → 200, `web.ssl=true`, `web.ssl_letsencrypt=false`, `web.wildcard=false`,
     `web.php_modes=["no","php-fpm"]`, `web.php_default_mode="php-fpm"`, `locked=false`
   - `GET /me/capabilities?client_id=1` → 404
   - `GET /me/php-versions?server_id=1` → 200, versions 1–5 with `modes=["php-fpm"]`, no id 0 (server 1 hides the
     default)
   - `?server_id=1&mode=fast-cgi` → 200, empty (mode not allowed); `?server_id=999` → 422; `?mode=hhvm` → 422
   - `POST /sites/web-domains` with `php=php-fpm` and the first listed `server_php_id` → 201 (list accepted)
3. Admin key: `GET /me/capabilities` → 422; `?client_id=<temp>` → 200 same values; admin creates a private PHP
   version for the temp client (copy of version 5's paths) → listed for the client key; `PUT` the website to it
   → 200; `PUT /clients/<temp>` `locked=true` → capabilities `locked=true`.
4. Cleanup: `DELETE` the website, the private PHP version and `/clients/<temp>` (admin), wait until
   `server.updated` ≥ the last datalog id, delete QA keys by SQL (`DELETE FROM api_keys WHERE id IN (…) AND name LIKE
   'qa%'`), verify no `qa021` rows, symlinks or client directories remain.

## 3. Results

Recorded in tasks.md after the run.
