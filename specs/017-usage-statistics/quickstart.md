# Quickstart: Usage Statistics (017)

The project needs PHP 8.3+. On machines with an older PHP use the docker commands below.

## 1. Install dependencies and run the tests

```bash
cd ispconfig-rest
docker run --rm -v "$PWD":/app -w /app composer:2 install --ignore-platform-reqs --no-interaction
docker run --rm -v "$PWD":/app -w /app php:8.3-cli vendor/bin/phpunit --filter 'Usage|TrafficPeriod|MonitorLatestBlobs'
docker run --rm -v "$PWD":/app -w /app php:8.3-cli vendor/bin/phpunit   # full suite, no regressions
```

Expected: `UsageSummaryApiTest`, `WebDomainUsageApiTest`, `MailUserUsageApiTest`, `DatabaseUsageApiTest`,
`ScopingUsageModuleTest`, `UsageCollectorDataTest`, `TrafficPeriodServiceTest`, `MonitorLatestBlobsTest` pass;
existing `ServerStatusApiTest` and `ClientLimit*Test` still pass (shared services changed).

## 2. Contract checks

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php artisan serve --host=0.0.0.0 --port=8000 &
# open http://localhost:8000/api/documentation — the Usage tag lists 9 operations and renders without errors
```

## 3. Manual verification on isp-test (read-only calls)

ISPConfig on isp-test has client 1 (`test.cz`, system user `web1`, mailbox `info@test.cz`, database `c1testcz`).
Mint a client key after deployment (`ispconfig-rest key:create "usage test" --client-id 1`), then:

```bash
H='X-API-Key: <client key>'
B=https://isp-test.feldhost.cz:8090/api/v1
curl -s -H "$H" $B/usage/summary | jq .
curl -s -H "$H" $B/usage/web-domains | jq '.data[] | {domain, type, disk, traffic}'
curl -s -H "$H" "$B/usage/web-domains/1/traffic?months=24" | jq .
curl -s -H "$H" $B/usage/mail-users | jq .
curl -s -H "$H" $B/usage/databases | jq .
curl -s -H "$H" "$B/usage/summary?client_id=999" -o /dev/null -w '%{http_code}\n'   # 404
curl -s -H "X-API-Key: <admin key>" $B/usage/summary -o /dev/null -w '%{http_code}\n'  # 422
```

Compare with the ISPConfig panel pages (Sites → Statistics → Website quota / Traffic, Email → Statistics,
Sites → Database quota): `web1` used 868 KiB → `used_bytes` 888832, soft 1048576 KiB → 1073741824.

Collector data on the server (read-only):

```bash
ssh root@isp-test.feldhost.cz "mysql dbispconfig -e \"SELECT type, server_id, FROM_UNIXTIME(created) FROM monitor_data WHERE type IN ('harddisk_quota','email_quota','database_size')\""
```

## 4. Timezone alignment (FR-015)

```bash
ssh root@isp-test.feldhost.cz
timedatectl show -p Timezone --value            # Europe/Prague
grep APP_TIMEZONE /opt/ispconfig-rest/.env      # before: UTC
ispconfig-rest update
grep APP_TIMEZONE /opt/ispconfig-rest/.env      # after: Europe/Prague
grep TIMEZONE /etc/ispconfig-rest/install.conf  # TIMEZONE_MODE="auto"
ispconfig-rest artisan tinker --execute='echo config("app.timezone");'   # Europe/Prague
ispconfig-rest status                           # shows timezone line, no warning
```

Explicit override on a scratch VM: `install.sh --timezone UTC` → `TIMEZONE_MODE="explicit"`; a later
`ispconfig-rest update` keeps `UTC`.
