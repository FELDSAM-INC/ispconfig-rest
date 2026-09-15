# Quickstart: Let's Encrypt Issuance Outcome (022)

## 1. Automated tests

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli \
  php artisan test --filter='WebDomainSslStatusApiTest'
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test
```

## 2. Manual check on isp-test

After `ispconfig-rest update` (commit containing 022):

1. Mint a QA admin key (`ispconfig-rest key:create "qa 022 admin"`).
2. Create a temporary client with `limit_ssl = y`, `limit_ssl_letsencrypt = y` and a client key.
3. With the client key create a vhost for a domain that does **not** resolve to isp-test (e.g. `qa022-<random>.invalid`
   is rejected by validation, use `qa022-<random>.example.com`); wait until `server.updated` reaches its entry.
4. `GET /sites/web-domains/{id}/ssl/status` → `state = none`.
5. `PUT /sites/web-domains/{id}` with `ssl: true, ssl_letsencrypt: true` → read status immediately → `requested`,
   `change_status = pending`.
6. Wait for processing → status `failed`, `letsencrypt_enabled = false`, `failure.reason = unknown` (server log level
   2 hides warnings).
7. Optional reason check: set server 1 `[server] loglevel` to `1` (admin `PUT /servers/1/configs/server`), repeat
   step 5–6 → `failure.reason = domain_not_reachable`, `failure.domains` contains the domain; restore `loglevel = 2`.
8. Other tenant's key → 404; no key → 401.

## 3. Cleanup

Delete the temporary client with the admin key, wait until `server.updated` ≥ the last `sys_datalog` id, delete QA keys
by SQL (`DELETE FROM api_keys WHERE id IN (...) AND name LIKE 'qa%'`), check no client dir / vhost link remains and the
server log level is back to 2.
