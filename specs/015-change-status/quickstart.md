# Quickstart: Change Status for API Writes

**Feature**: 015-change-status

How to verify the feature once implemented. The project needs PHP 8.3+; hosts with an older PHP run the
suite in a container.

## 1. Automated tests

```bash
# from the repository root
docker run --rm -v "$PWD":/app -w /app composer:2 composer install --ignore-platform-reqs
docker run --rm -v "$PWD":/app -w /app php:8.3-cli vendor/bin/phpunit --filter 'Change'
docker run --rm -v "$PWD":/app -w /app php:8.3-cli vendor/bin/phpunit   # full suite, regressions
```

Expected: `ChangeStatusApiTest`, `ChangeListApiTest`, `ChangeSetHeaderTest`, `ChangeStatusResolverTest`
and `ChangeSetHeaderContractTest` pass; existing suites (notably `DataLogApiTest`, `ModuleGateTest`,
write tests of all modules) stay green.

## 2. Contract

```bash
curl -s https://isp-test.feldhost.cz:8090/api/spec | grep -c 'X-Change-Set-Id'   # ≥ 148 operations
```

Open `/api/documentation`: the **Changes** tag shows `GET /changes` and `GET /changes/{change_set_id}`;
any POST/PUT/DELETE response lists the `X-Change-Set-Id` header.

## 3. Manual end-to-end on the test server

Use a client-scoped key (`ispconfig-rest key:create "qs" --client-id N`) as `$KEY` and the API base
`https://isp-test.feldhost.cz:8090/api/v1` as `$API`.

```bash
# 1. write something and capture the change set id
curl -si -X POST "$API/mail/domains" -H "X-API-Key: $KEY" -H 'Content-Type: application/json' \
  -d '{"domain":"quickstart-015.example","active":true}' | grep -i '^x-change-set-id'

# 2. poll the set: pending first, then applied within about a minute
curl -s "$API/changes/<id>" -H "X-API-Key: $KEY"

# 2b. entries are paginated; status and entry_counts always cover the whole set
curl -s "$API/changes/<id>?limit=1&offset=0" -H "X-API-Key: $KEY"   # meta.total = number of entries in the set

# 3. the customer's pending indicator
curl -s "$API/changes?status=pending" -H "X-API-Key: $KEY"

# 4. no-change update returns no header
curl -si -X PUT "$API/mail/domains/<domain_id>" -H "X-API-Key: $KEY" -H 'Content-Type: application/json' \
  -d '{"domain":"quickstart-015.example","active":true}' | grep -ci '^x-change-set-id'   # 0

# 5. another client's key gets 404 for the same set
curl -s -o /dev/null -w '%{http_code}\n' "$API/changes/<id>" -H "X-API-Key: $OTHER_CLIENT_KEY"
```

Cross-check the result against ISPConfig:

```bash
ssh root@isp-test.feldhost.cz "mysql dbispconfig -e \
  \"SELECT datalog_id, server_id, dbtable, action, error, session_id FROM sys_datalog ORDER BY datalog_id DESC LIMIT 5; \
    SELECT server_id, active, mirror_server_id, updated FROM server;\""
```

Clean up the quickstart domain with `DELETE $API/mail/domains/<domain_id>` (its change set can be polled
the same way).
