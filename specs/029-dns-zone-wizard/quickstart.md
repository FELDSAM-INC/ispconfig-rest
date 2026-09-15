# Quickstart: DNS Zone Wizard For Scoped Keys

## 1. Automated tests

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test \
  --filter='DnsZoneWizardApiTest|DnsTemplateApiTest|ScopingDnsModuleTest|ClientLimitDnsTest|DnsSoaApiTest'
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test
```

Baseline before this feature: 1158 passing.

## 2. Manual check on isp-test

Deploy: `ssh root@isp-test.feldhost.cz 'ispconfig-rest update && ispconfig-rest status'`.

Use a TEMPORARY client only (never clients 1, 2 or `WHMCS-*`), QA admin key `qa029 admin`; keys are never printed.
Another session may be working on isp-test — touch only rows you created.

1. Admin `POST /clients` `qa029…` (`dns_servers=1`, `limit_dns_zone=2`, `limit_dns_record=-1`); create a client key.
2. Client `GET /dns/zone-templates` → 200 and the shipped **Default** template (id 1) is listed with its `fields`;
   `GET /dns/templates` with the same key → still empty (row scoping unchanged).
3. Client `POST /dns/soa/from-template` `{template_id: 1, domain: qa029-<rand>.example.test, ip: <server ipv4>,
   ns1: isp-test.feldhost.cz, ns2: isp-test.feldhost.cz, email: hostmaster@qa029-<rand>.example.test}` → 201 with
   `X-Change-Set-Id`.
4. Compare with the template: the zone carries the template's timers and `active = true`; the records are the
   template's seven rows with the placeholders replaced (A apex/www/mail, two NS, MX priority 10, SPF TXT).
5. `GET /changes/{change_set_id}` → the zone insert, one entry per record and the zone activation, all in that
   change set, in that order.
6. Wait until `server.updated` ≥ the last datalog id, then on the server: the zone file `pri.qa029-…` exists in
   `/etc/bind`, `named-checkzone` accepts it, and it contains the same records.
7. **Wizard parity**: create a second zone through the ISPConfig panel's own wizard for another temporary domain
   with the same input, and diff the two `dns_soa` rows and their `dns_rr` sets (expected difference: only origin,
   id, serial and stamps).
8. **Record cap**: set `limit_dns_record = 3` on the temporary client (admin `PUT /clients/{id}` with
   `template_master: 0`), then run the wizard for a third domain → 403 `limit-reached` with
   `limit.name = limit_dns_record`, `max: 3`; no `dns_soa`, no `dns_rr` and no journal entry appeared.
9. **Zone cap**: restore `limit_dns_record = -1`, set `limit_dns_zone` to the current zone count → wizard → 403
   `limit-reached` with `limit.name = limit_dns_zone`.
10. **Invisible template**: admin `PUT /dns/templates/{id}` `{visible: false}` on a temporary template the admin
    created → client `GET /dns/zone-templates` no longer lists it and the wizard refuses its id with 422; restore
    it afterwards (never touch template 1's flags).
11. **DKIM**: admin creates a temporary mail domain for one of the temporary zones' domains with DKIM on; client
    runs the wizard with `dkim: true` for that domain → the zone additionally contains the
    `<selector>._domainkey.<domain>.` TXT record with the same public key; the private key appears nowhere.
12. **DNSSEC**: wizard with `dnssec: true` → the created zone has `dnssec_wanted = true` (signing itself is spec
    032).
13. **Server**: wizard with `server_id` of a nonexistent server → 422 with
    `error_types.server_id = server-not-assigned`.
14. Cleanup: delete every temporary zone and mail domain, then the client (admin), wait until `server.updated` ≥ the
    last datalog id, delete the QA keys by SQL (`name LIKE 'qa029%'`), and verify no `qa029` rows, zone files or
    `named.conf.local` references remain and that `dns_template` still holds exactly the pre-existing rows.

## 3. Results

Recorded in tasks.md after the run.
