# Quickstart: Zone Removal With Records

## 1. Automated tests

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test \
  --filter='DnsZoneCascadeDeleteTest|DnsSoaApiTest|ScopingDnsModuleTest|ScopedReferenceDnsTest'
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test
```

## 2. Manual check on isp-test

Deploy: `ssh root@isp-test.feldhost.cz 'ispconfig-rest update && ispconfig-rest status'`.

Use a TEMPORARY client only (never clients 1, 2 or `WHMCS-*`). QA admin key `qa034 admin`; keys are never printed.

1. Admin `POST /clients` `qa034…` (`dns_servers=1`, `limit_dns_zone=2`, `limit_dns_record=-1`); client key.
2. Client `POST /dns/soa` `qa034-<rand>.example.test` → 201; add 3 records (A `www`, A `mail`, MX) → 201 each.
3. Wait until `server.updated` ≥ the last datalog id, then confirm the zone file exists in `/etc/bind`
   (`pri.qa034-…`) and `named-checkzone` accepts it.
4. Client `DELETE /dns/soa/{id}` → 204 with `X-Change-Set-Id`; `dns_rr` rows of the zone and the `dns_soa` row are
   gone; the journal shows `dns_soa` update (`active` N), three `dns_rr` deletes and one `dns_soa` delete with the
   same change set id.
5. Wait for the server again → the zone file and the `named.conf.local` entry are gone.
6. Client `DELETE /dns/soa/{id}` again → 404. Second zone: admin creates one for the client, client deletes it → 204.
7. Cleanup: delete the client (admin), wait until `server.updated` ≥ the last datalog id, delete QA keys by SQL,
   verify no `qa034` rows, zone files or `named.conf` references remain.

## 3. Results

Recorded in tasks.md after the run.
