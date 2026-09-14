# Quickstart: Website & Database Backups (018)

## Run the tests (PHP 8.3 in Docker)

The project requires PHP 8.3; use the official image when the host PHP is older.

```bash
cd ispconfig-rest
docker run --rm -v "$PWD":/app -w /app composer:2 install --no-interaction
docker run --rm -v "$PWD":/app -w /app php:8.3-cli vendor/bin/phpunit --filter 'WebBackup|BackupLimitGate|ListQuery'
docker run --rm -v "$PWD":/app -w /app php:8.3-cli vendor/bin/phpunit   # full suite
```

Check the contract parses:

```bash
npx @redocly/cli lint api/openapi.yaml
```

Then open Swagger UI (`php artisan serve`, `/api/documentation`) and check the **Web Backups** tag.

## Manual check on the test server

Use a disposable website: restore replaces files and database contents.

```bash
API=https://isp-test.feldhost.cz:8090/api/v1
KEY=isp_...            # client-scoped key of the website's client (limit_backup = y)
W=123                  # vhost website id

# list backups and settings
curl -s -H "X-API-Key: $KEY" "$API/sites/web-domains/$W/backups" | jq
curl -s -H "X-API-Key: $KEY" "$API/sites/web-domains/$W/backup-settings" | jq

# start a manual web backup, then poll the job
curl -s -X POST -H "X-API-Key: $KEY" -H 'Content-Type: application/json' \
  -d '{"type":"web"}' "$API/sites/web-domains/$W/backups" | jq
curl -s -H "X-API-Key: $KEY" "$API/sites/web-domains/$W/backup-jobs?state=pending" | jq

# the queued action row as the server sees it (read-only)
ssh root@isp-test.feldhost.cz \
  "mysql -e \"SELECT * FROM dbispconfig.sys_remoteaction ORDER BY action_id DESC LIMIT 5\""
```

Expected: the POST returns 201 with a `pending` job; within about a minute the job becomes `ok` and a
backup with `job = manual` appears in the list. A second POST while the first is pending returns 409.
Download jobs finish with a `download.path` like `backup/<filename>` inside the website.
