# 057 — ACME DNS-01 challenges for certbot and acme.sh

Owner request (2026-10-11): DNS plugins for certbot and acme.sh that use ispconfig-rest, so external VPS servers can
obtain certificates with DNS challenges for domains whose zones are hosted in ISPConfig.

## Findings

- API keys are bound to the admin (everything) or to a client (everything the client may do: websites, mail, every
  DNS record). A key stored on an external server should only be able to answer DNS challenges.
- A DNS-01 challenge is a TXT record `_acme-challenge.<domain>` with the key authorization digest (43 characters,
  base64url). A wildcard and its base name use the same record name with two values at once. The CA asks the zone's
  authoritative servers, so the record must be live on the primary and its secondaries before validation.
- ISPConfig writes a record through the datalog; the DNS server applies it within a minute (server cron). The API
  already knows when a datalog entry is processed (spec 015, `ChangeStatusResolver`), so a client can wait for the
  record to be live instead of sleeping a fixed time. A record write is two entries: the `dns_rr` row and the zone's
  serial update (`dns_soa`); the later one decides.
- certbot plugins: `certbot.plugins.dns_common.DNSAuthenticator` (pip-installed certbot); snap and distribution
  packages cannot load pip plugins, so manual hooks (`--manual-auth-hook`, `--manual-cleanup-hook`, environment
  `CERTBOT_DOMAIN`, `CERTBOT_VALIDATION`) are the portable route. acme.sh plugins: `dnsapi/dns_<name>.sh` with
  `dns_<name>_add` / `dns_<name>_rm`, credentials kept with `_saveaccountconf_mutable`.

## Decisions (owner-delegated)

- Key access `acme` next to `full` (API-owned `api_keys.access`, `api_keys.zones`; migration with the privileged
  step). An ACME key reaches only `/dns/acme-challenges`, `GET /changes/{id}` and `GET /me`; anything else is `403`.
  It keeps the bound identity (admin or client), so a client's ACME key sees only that client's zones; an optional
  zone list narrows it further (exact zone names). Created with `ispconfig-rest key:create NAME --acme [--client-id N]
  [--zone example.com ...]` or `POST /system/api-keys` with `access` and `zones`.
- `POST /dns/acme-challenges {name, value}`: the name must start with `_acme-challenge.`; the zone is the longest
  active primary zone the key may use whose origin is a suffix of the name (also the name itself, for delegated
  challenge zones). The TXT record is stored as an FQDN with TTL 60 through the normal record path (client limits,
  datalog, serial). The same name and value again returns the existing record (`200`). At most 100 challenge records
  per zone at a time (`409`).
- The response carries `status` (`pending`, `applied`, `failed`, `stalled`) of the record on its DNS server and
  mirrors; `GET /dns/acme-challenges/{id}` refreshes it. `DELETE /dns/acme-challenges/{id}` and
  `DELETE /dns/acme-challenges?name=&value=` remove challenge records (the latter idempotent, `204`). Other records are
  invisible to these endpoints (`404`).
- Clients in `acme-dns/` (since moved to the repository ispconfig-acme-dns): the acme.sh plugin `dns_ispconfig_rest.sh`, the certbot plugin `certbot-dns-ispconfig-rest`
  (`--authenticator dns-ispconfig-rest`), and `ispconfig-rest-certbot-hook` for any certbot through manual hooks. All
  wait until the record is applied (default up to 300 s), then a short propagation delay for secondaries.
- Tests: feature tests of the endpoints and key access; plugin tests against a stub API; end to end with Pebble
  against a real ISPConfig DNS server installed by the Ansible roles.
