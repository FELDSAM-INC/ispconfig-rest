# Quickstart: Hosting Addresses and Name Servers for Scoped Keys

## 1. Automated tests

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test \
  --filter='MeHostingAddressesApiTest|MeServersApiTest|MeMailSettingsApiTest|SwaggerSpecServerTest'
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test
```

## 2. Manual check on isp-test

Deploy: `ssh root@isp-test.feldhost.cz 'ispconfig-rest update && ispconfig-rest status'`.

isp-test has one server (1, `isp-test.feldhost.cz`: web, mail, DNS) with shared addresses `185.174.170.53` (IPv4)
and `2a0b:a901::b9ff:feae:aa35` (IPv6), both `virtualhost = n`; `dns_external_slave_fqdn` is empty. Use TEMPORARY
clients only (never clients 1, 2 or `WHMCS-*`); do not change system settings. Temporary `server_ip` rows created for
the check are removed afterwards.

1. Admin `POST /clients` `qa031a…` (`web_servers=1`, `mail_servers=1`, `dns_servers=1`) and `qa031b…` (no servers);
   client key for `qa031a`.
2. Client A `GET /me/hosting-addresses` → `web[0]`, `mail[0]`, `dns[0]` = server 1 with both shared addresses;
   `dns[0].nameservers` = `[{isp-test.feldhost.cz, same addresses}]`.
3. Admin `POST /servers/1/ip-addresses` `203.0.113.77` dedicated to client B and `10.10.10.10` shared (temporary);
   client A read → neither listed.
4. Admin `GET /me/hosting-addresses?client_id=<B>` → `web`, `mail`, `dns` empty (no servers, no resources); admin
   without `client_id` → 422; client A with `client_id=<B>` → 404; `?foo=1` → 400.
5. Cleanup: delete the temporary IP rows and clients (admin), wait until `server.updated` ≥ the last datalog id,
   delete QA keys by SQL, verify no `qa031` rows and that `server_ip` holds only rows 1 and 2.

## 3. Results

Recorded in tasks.md after the run.
