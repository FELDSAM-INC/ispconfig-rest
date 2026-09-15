# Research: Hosting Addresses and Name Servers for Scoped Keys

Legacy source: ISPConfig 3.3.1p1 on isp-test.feldhost.cz (read-only). Live facts from isp-test on 2026-09-16.

## R1 — Endpoint shape

**Options**: (a) extend `GET /me/servers` entries with `ipv4[]`/`ipv6[]` and add `GET /me/nameservers`; (b) one
`GET /me/hosting-addresses` as proposed by the WHMCS module contract (`specs/005-dns/contracts/ispconfig-rest-calls.md`:
`web.ipv4[]`, `web.ipv6[]`, `mail.host`, `nameservers[{name, ip}]`).

**Findings**: `/me/servers` (spec 016) answers "where may new resources be placed" for every key type, lists every
eligible server for admin keys and its schema promises "no other server data". Addresses depend on the described
client (dedicated addresses, R2), which `/me/servers` has no parameter for. Name servers depend on the DNS server
holding a zone (R4), so a flat list is wrong for accounts with several primary DNS servers.

**Decision** (owner-delegated 2026-09-16): (b) with per-server entries — `web[]`, `mail[]` (`server_id`,
`server_name`, `is_default`, `ipv4[]`, `ipv6[]`) and `dns[]` (same identity plus `nameservers[{name, ipv4[], ipv6[]}]`),
with the spec 025 target resolution (`client_id` for admin and reseller keys). The module reads the default entries or
the entry of the zone's server (contracts/hosting-addresses.md "Consumer mapping").

## R2 — Which addresses a client may see

**Legacy**:
- `admin/form/server_ip.tform.php` 103–113: `client_id` is a SELECT of `(SELECT 0 AS client_id, '' AS name) UNION
  ALL clients` — 0 (empty) means the address is not dedicated; `ip_type` IPv4/IPv6 (114–119).
- `sites/web_vhost_domain_edit.php` 209 and 225: the address picker of a client's website lists `server_ip WHERE
  server_id = ? AND ip_type = 'IPv4'|'IPv6' AND virtualhost = 'y' AND (client_id = 0 OR client_id = <own>)`.
- `admin/lib/lang/en_server_ip.lng` 8: `virtualhost` = "HTTP NameVirtualHost" — it controls whether the address gets
  a NameVirtualHost/listen block; websites with address `*` answer on every address.
- isp-test: `server_ip` 1 `185.174.170.53` IPv4 and 2 `2a0b:a901::b9ff:feae:aa35` IPv6, both `client_id = 0`,
  `virtualhost = n`; websites use `*`.

**Decision**: rows of the server with `client_id IN (0, <described client>)`, any `virtualhost` value (strict parity
with the picker would return nothing on isp-test although both addresses serve the websites). Dedicated addresses of
other clients are never read.

## R3 — Public addresses only

**Legacy**: ISPConfig has no public/private flag. `server_ip_map` (`admin/server_ip_map_edit.php`,
`server/plugins-available/apache2_plugin.inc.php` 1795–1800) only translates a website's address on a web mirror
server (`mirror_server_id > 0`); it is not a NAT public-address mapping (empty on isp-test).

**PHP 8.3** `filter_var(ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)` (checked in
`php:8.3-cli`): `10.0.0.1`, `127.0.0.1`, `169.254.1.1`, `fd00::1`, `fe80::1`, `::1` rejected; `185.174.170.53`,
`2a0b:a901::b9ff:feae:aa35`, documentation ranges `192.0.2.0/24`, `198.51.100.0/24`, `203.0.113.0/24`,
`2001:db8::/32` and `100.64.0.1` accepted.

**Decision**: apply that filter plus the `ip_type` family check; skip invalid rows; deduplicate by binary address
(`inet_pton`), keeping the first stored spelling. Installations registering only private addresses get empty lists
(consumer fallback).

## R4 — Name servers

**Legacy**: there is no name server setting.
- `dns/dns_import.php` 81–82, 272–287: `$servers = SELECT server_name FROM server WHERE server_id = <zone server> OR
  mirror_server_id = <zone server> ORDER BY server_name`, each with a trailing dot, then
  `preg_split('/[\s,]+/', dns_external_slave_fqdn)` with `rtrim('.') . '.'`; 634–643: when the imported file has no
  apex NS records (or they are ignored), one NS record per entry of that list is created.
- `admin/lib/lang/en_system_config.lng` 115: `dns_external_slave_fqdn` = "External DNS servers (comma separated)";
  isp-test: empty.
- `server/lib/classes/modules.inc.php` 104–142: a mirror server processes the datalog of its mirrored server and
  rewrites `server_id`, so it serves the same zones.
- `dns/dns_wizard.php` / `lib/classes/dns_wizard.inc.php` 167–170 and template 1 (`ns={NS1}.`, `NS … {NS1}.`,
  `{NS2}.`): free-text inputs, no defaults. `server.config [server] nameservers` (isp-test `8.8.8.8,8.8.4.4`) are the
  server's resolvers, not authoritative name servers.
- `client.default_slave_dnsserver` is only the default server for secondary zones (`dns_slave_edit.php` 66, 193) and
  does not serve the account's primary zones.

**Decision**: per DNS server exactly the zone-import list — the server and its mirrors ordered by `server_name` with
their public addresses (R2/R3), then the external names with empty addresses; names without trailing dots,
case-insensitive duplicates removed. No DNS lookups.

## R5 — Server lists

**API**: `ServerAssignmentService::assignedServerIds()` (spec 016) returns valid assigned ids in list order;
`AccountMailService::accountMailServers()` (spec 025) appends non-mirror mail servers hosting the client's mail
domains (join `sys_group.client_id`).

**Decision**: the same composition for all three roles — assigned (default = first), then servers hosting the
client's `web_domain`, `mail_domain` (existing method) or `dns_soa` rows, by id. The secondary DNS server is not
listed (R4). Database servers are out of scope.
