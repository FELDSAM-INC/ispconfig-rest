# Quickstart: Zone and Record Rule Parity for Scoped Keys

## 1. Automated tests

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test \
  --filter='DnsRuleParityTest|DnsRecordHardeningTest|DnsSoaApiTest|DnsRecordApiTest|ScopingDnsModuleTest'
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test
```

## 2. Manual check on isp-test

Deploy: `ssh root@isp-test.feldhost.cz 'ispconfig-rest update && ispconfig-rest status'`.

Use a TEMPORARY client only (never clients 1, 2 or `WHMCS-*`). QA admin key `qa033 admin`; keys are never printed.

1. Admin `POST /clients` `qa033…` (`dns_servers=1`, `limit_dns_zone=1`, `limit_dns_record=-1`); client key.
2. Client `POST /dns/soa` `qa033-<rand>.example.test` → 201.
3. Zone fields:
   - client `PUT /dns/soa/{id}` `{"update_acl": "192.0.2.0/24"}` → 422, `errors.update_acl`,
     `error_types.update_acl` = `…#feature-not-allowed`, no datalog row
   - client `PUT` `{"update_acl": ""}` (stored value) → 200
   - client `PUT` `{"origin": "qa033-renamed.example.test"}` → 422 `errors.origin` + `error_types.origin`
   - client `PUT` `{"origin": "<stored origin without trailing dot>"}` → 200
   - admin `PUT` `{"update_acl": "192.0.2.0/24"}` → 200; admin `PUT` `{"update_acl": ""}` → 200
4. Record duplicates (client key unless noted):
   - `POST /dns/records` MX `@` `mail.<zone>` priority 10 → 201; the same host name with priority 20 → 422
     `errors.name`; `mail2.<zone>` → 201
   - `POST` TXT SPF `v=spf1 mx ~all` at `@` → 201; a second SPF at `@` → 422; SPF at `sub` → 201
   - `POST` TLSA `_443._tcp` → 201; identical → 422; different hash → 201
   - admin `POST` an identical MX → 422 (the rule applies to every key)
   - `PUT` on one of the records without changing it → 200
5. Cleanup: delete the records, the zone and the client (admin), wait until `server.updated` ≥ the last datalog id,
   delete QA keys by SQL, verify no `qa033` rows or zone files remain.

## 3. Results

Recorded in tasks.md after the run.
