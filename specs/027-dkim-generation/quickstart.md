# Quickstart: DKIM Key Generation for Mail Domains

## 1. Automated tests

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test \
  --filter='MailDomainDkimApiTest|MailDomainApiTest|SpamfilterLevelApiTest|ChangeSetHeader'
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test
```

## 2. Manual check on isp-test

Deploy: `ssh root@isp-test.feldhost.cz 'ispconfig-rest update && ispconfig-rest status'`.

Use a TEMPORARY client only (never clients 1, 2 or `WHMCS-*`). QA admin key `qa027 admin` (plaintext in a root-only
file, never printed). Never print private keys: compare them by hash only.

1. `POST /clients` `qa027…` with `mail_servers=1`, `limit_maildomain=1`; client key. Admin creates a DNS zone
   `qa027-<rand>.example.test.` on server 1 for the client (hosted zone).
2. Client key:
   - `POST /mail/domains` `qa027-<rand>.example.test` → 201 without `dkim_private`
   - `GET …/dkim` → 200, `enabled=false`, `public_key=null`, `dns_managed=true`, `available=true`
   - `POST …/dkim` → 200, `enabled=true`, `selector=default`, `key_bits=2048`, `dns_record.name =
     default._domainkey.qa027-<rand>.example.test.`, no private key in the body; `GET /mail/domains/{id}` without
     `dkim_private`
   - DNS: one `v=DKIM1` TXT record in the zone whose value equals `dns_record.value`
   - `POST …/dkim {selector: "s2"}` → 200, new public key, `s2._domainkey…` record present, `default._domainkey…` gone
   - `POST …/dkim {selector: "Bad!"}` → 422
3. Admin key: `GET /mail/domains/{id}` contains `dkim_private` (hash equals the stored key).
4. After the server processed the datalog: `/var/lib/amavis/dkim/<domain>.private` exists and hashes like the stored
   private key; `/etc/rspamd/local.d/dkim_selectors.map` maps the domain to `s2`.
5. Client `PUT /mail/domains/{id} {dkim: false}` → 200; after processing the key files and map lines are gone; `GET …/dkim`
   → `enabled=false`, public key kept.
6. Cleanup: delete mail domain, DNS zone and client (admin), wait until `server.updated` ≥ the last datalog id, delete QA
   keys by SQL, verify no `qa027` rows, DNS records, key files, map lines or client directories remain.

## 3. Results

Recorded in tasks.md after the run.
