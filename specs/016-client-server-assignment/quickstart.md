# Quickstart: Verify Client Server Assignment (spec 016)

For the implementation phase. Planning did not run code: the project needs PHP ≥ 8.3.

## 1. Automated tests (SQLite in memory)

```bash
# In a checkout of branch 016-client-server-assignment
docker run --rm -v "$PWD":/app -w /app php:8.3-cli bash -c '
  apt-get update -qq && apt-get install -y -qq git unzip libsqlite3-dev >/dev/null &&
  curl -sS https://getcomposer.org/installer | php -- --quiet &&
  php composer.phar install --no-interaction --quiet &&
  vendor/bin/phpunit --filter "ClientServerAssignment|MeServersApi"'

# Regression bar (SC-004): full suite, including admin-key tests unchanged
docker run --rm -v "$PWD":/app -w /app php:8.3-cli vendor/bin/phpunit
```

Expected: new tests green; every pre-existing admin-key test passes without modification; updated 011/012
non-admin tests pass after assigning servers in their fixtures.

## 2. Contract checks

```bash
# Spec parses and the new path is served
php artisan serve &
curl -s http://127.0.0.1:8000/api/spec | grep -n "/me/servers"
```

Open `/api/documentation` and confirm `GET /me/servers`, `AssignedServers`, `AssignedServer` render and the six
schemas no longer list `server_id` as required.

## 3. Manual scenarios (disposable test installation only)

Use a throwaway ISPConfig + API installation. Do not run write scenarios against `/opt/ispconfig-rest` on a
shared server without the owner's approval.

Setup: web servers 1 and 2, mail server 3; client A with `web_servers = "2"`, `mail_servers = "3"`,
`db_servers = ""`, `default_slave_dnsserver = 4`; a client-scoped key for A (`ispconfig-rest key:create a
--client-id <A>`) and an admin key.

```bash
API=https://<host>:8090/api/v1
A="X-API-Key: <client A key>"; ADMIN="X-API-Key: <admin key>"; J="Content-Type: application/json"

# US1: omitted server_id → server 2
curl -s -H "$A" -H "$J" -X POST $API/sites/web-domains -d '{"domain":"a-site.test","hd_quota":1000}' | grep server_id
# US1: unassigned (1) and nonexistent (99) → identical 422 bodies
curl -s -H "$A" -H "$J" -X POST $API/sites/web-domains -d '{"domain":"b.test","hd_quota":1000,"server_id":1}'
curl -s -H "$A" -H "$J" -X POST $API/sites/web-domains -d '{"domain":"b.test","hd_quota":1000,"server_id":99}'
# US1: no db server assigned → 422 "No database server is assigned to this account."
# US1: admin without server_id → 422 required (unchanged)
curl -s -H "$ADMIN" -H "$J" -X POST $API/mail/domains -d '{"domain":"x.test"}'

# US2: discovery
curl -s -H "$A" $API/me/servers
curl -s -H "$ADMIN" $API/me/servers

# US3: secondary zone forced to server 4; server change on a zone update rejected
curl -s -H "$A" -H "$J" -X POST $API/dns/slaves -d '{"origin":"slave.test.","ns":"203.0.113.1"}' | grep server_id
curl -s -H "$A" -H "$J" -X PUT $API/dns/soa/<A zone id> -d '{"server_id":5}'
# US3 (FR-014): client B's mailbox as fetchmail destination → same 422 as a nonexistent mailbox
curl -s -H "$A" -H "$J" -X POST $API/mail/fetchmail -d '{...,"destination":"<client B mailbox>"}'
```

After each rejected request, confirm no new journal entry:
`GET $API/monitor/data-logs?limit=1&sort=datalog_id&order=desc` with the admin key shows the same last id.
