# Quickstart: API Key Management over HTTP

**Feature**: 014-api-key-management

## 1. Automated verification (PHP ≥ 8.3 required)

The workstation PHP is 8.1, below `composer.json`'s `php ^8.3`. Run the suite in a PHP 8.3 environment:

```bash
# local container
docker run --rm -v "$PWD":/app -w /app php:8.3-cli bash -c \
  "curl -sS https://getcomposer.org/installer | php -- --quiet && php composer.phar install -q && php artisan test"

# focused runs
php artisan test --filter=ApiKeyManagementApiTest
php artisan test --filter=MeApiTest
php artisan test --filter=ApiKeyCommandsTest
php artisan test --filter=ClientDeleteRevokesKeysTest
php artisan test --filter='ApiKeyAuthTest|CreateApiKeyClientIdTest|ModuleGateTest'
```

Contract check: open `/api/documentation`, confirm the **API Keys** and **Me** tags render and
"Try it out" works for all six operations.

## 2. Manual end-to-end check on the test server

After deploying the branch to `isp-test.feldhost.cz` (`/opt/ispconfig-rest`, PHP 8.3):

```bash
API=https://isp-test.feldhost.cz:8090/api/v1
ADMIN=isp_...   # admin key from: sudo ispconfig-rest key:create "qa admin"

# create a client-scoped key (client 42 must exist and have a control-panel user)
curl -s -X POST "$API/system/api-keys" -H "X-API-Key: $ADMIN" -H 'Content-Type: application/json' \
  -d '{"name":"qa client 42","client_id":42}'
# → 201 with "key": "isp_..." — copy it as CLIENT

curl -s "$API/me" -H "X-API-Key: $CLIENT"                      # → scope "client", client_id 42
curl -s "$API/mail/domains" -H "X-API-Key: $CLIENT"            # → only client 42's domains
curl -s -o /dev/null -w '%{http_code}\n' "$API/system/api-keys" -H "X-API-Key: $CLIENT"   # → 403

curl -s "$API/system/api-keys?client_id=42" -H "X-API-Key: $ADMIN"   # → the key, no secret fields
curl -s -X PUT "$API/system/api-keys/ID" -H "X-API-Key: $ADMIN" -H 'Content-Type: application/json' \
  -d '{"active":false}'
curl -s -o /dev/null -w '%{http_code}\n' "$API/ping" -H "X-API-Key: $CLIENT"   # → 401

sudo ispconfig-rest key:list
sudo ispconfig-rest key:revoke ID
```

Expected failures worth trying: `client_id` of a nonexistent client (422), body with `sys_userid`
(422), `PUT` with a different `client_id` (422), deactivating the admin key with itself (409).

## 3. Cleanup

Delete QA keys with `DELETE /system/api-keys/{id}`; do not delete ISPConfig clients on the shared test
server unless they were created for this check.
