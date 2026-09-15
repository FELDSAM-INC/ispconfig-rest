# Quickstart: DNS Record Limit Parity

## 1. Automated tests

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test \
  --filter='ClientLimitDnsTest|UsageSummaryApiTest|DnsRecordApiTest'
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test
```

## 2. Manual check on isp-test

Deploy: `ssh root@isp-test.feldhost.cz 'ispconfig-rest update && ispconfig-rest status'`.

Use a TEMPORARY client only (never clients 1, 2 or `WHMCS-*`). QA admin key `qa030 admin`; keys are never printed.

1. Admin `POST /clients` `qa030…` with `dns_servers=<dns server>`, `limit_dns_zone=1`, `limit_dns_record=2`; client key.
2. Client `POST /dns/soa` zone `qa030-<rand>.example.test` → 201.
3. Client `GET /usage/summary` → `counts.dns_records = {used: 0, limit: 2}`.
4. Client `POST /dns/records` A `www` and A `mail` → 201, 201; summary `used: 2`.
5. Client `POST /dns/records` A `ftp` → 403 `limit-reached`, `limit {limit_dns_record, client, 2, 2}`; no new datalog
   row.
6. Client `PUT /dns/records/{www}` ttl 7200 → 200; admin `POST /dns/records` A `ftp` in the zone → 201; summary
   `used: 3`.
7. Client `DELETE /dns/records/{www}` → 204; client `POST` still 403 (`used: 2`, max 2).
8. Cleanup: delete the zone's records, the zone and the client (admin), wait until `server.updated` ≥ the last datalog
   id, delete QA keys by SQL, verify no `qa030` rows or bind zone files remain.

## 3. Results

Recorded in tasks.md after the run.
