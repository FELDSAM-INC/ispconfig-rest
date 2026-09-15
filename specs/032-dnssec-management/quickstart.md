# Quickstart: DNSSEC Management For Scoped Keys

## 1. Automated tests

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test \
  --filter='DnsSoaDnssecApiTest|DnsSoaApiTest|DnsRuleParityTest|ScopingDnsModuleTest'
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test
```

Baseline before this feature: 1186 passing.

## 2. Manual check on isp-test

Deploy: `ssh root@isp-test.feldhost.cz 'ispconfig-rest update && ispconfig-rest status'`.

Use a TEMPORARY client only (never clients 1, 2 or `WHMCS-*`), QA keys named `qa032…`; keys are never printed.
Another session may be working on isp-test — touch only rows you created.

1. Admin `POST /clients` (`contact_name`, `email`, `username`, `password`, `customer_no` `QA032-…`); mint a
   client-scoped key with `ispconfig-rest key:create "qa032 client" --client-id=<id>`.
   *(Note from spec 029: `dns_servers` is a comma-separated string, not an array; new clients get the default
   servers anyway.)*
2. Client `POST /dns/soa/from-template` (spec 029 wizard) for `qa032-<rand>.example.test` **without** `dnssec` →
   `GET /dns/soa/{id}/dnssec` → `state` `off`, `available` true, both record lists empty.
3. Client `PUT /dns/soa/{id}` `{"dnssec_wanted": true}` → 200. Immediately read the sub-resource → `state`
   `pending`.
4. Wait until `server.updated` ≥ the last datalog id, then read again → `state` `signed`, `last_signed` set,
   `ds_records[0]` and at least two `dnskey_records` (one `ksk`, one `zsk`).
5. On the server, compare `ds_records[0].digest` with `/etc/bind/dsset-qa032-….` (whitespace removed) and the
   DNSKEY values with the `K…key` files — they must match byte for byte.
6. Client `GET /dns/soa/{id}` → `dnssec_info` is `null`; admin `GET /dns/soa/{id}` → the raw notes are returned.
7. Client `PUT /dns/soa/{id}` `{"dnssec_wanted": false}` → 200; the sub-resource reports `off` while
   `initialized` stays true and the key files remain on disk (bind parity); switch it back on → the DS record is
   unchanged.
8. **Mirror rule**: add a temporary mirror row (`INSERT INTO server … mirror_server_id = 1, dns_server = 1`, or
   set an unused server's `mirror_server_id` to 1) → the sub-resource reports `available` false and `state`
   `unavailable`; `PUT … {"dnssec_wanted": true}` → 422 with `error_types.dnssec_wanted` = `feature-not-allowed`
   and no journal entry; `{"dnssec_wanted": false}` still 200. **Remove the temporary row again and confirm the
   `server` table is byte-identical to before.**
9. Scoping: a second temporary client's key reads the zone's sub-resource → 404.
10. Cleanup: delete the zone (spec 034 cascade) and both clients through the API, wait until `server.updated` ≥ the
    last datalog id, delete the QA keys by SQL (`name LIKE 'qa032%'`), and verify no `qa032` rows, bind zone files,
    DNSSEC key files or `named.conf.local` references remain, and that the `server` table has only its original
    rows.

## 3. Results

Recorded in tasks.md after the run.
