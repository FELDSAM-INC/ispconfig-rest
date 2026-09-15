# Data Model: Hosting Addresses and Name Servers for Scoped Keys

No migrations. Read-only view derived per request.

## HostingAddresses (`api/components/schemas/HostingAddresses.yaml`)

| Field | Type | Source |
|---|---|---|
| `client_id` | integer | described client |
| `web` | HostingServer[] | valid assigned web servers (`client.web_servers` order), then non-mirror web servers of the client's `web_domain` rows by id |
| `mail` | HostingServer[] | valid assigned mail servers, then non-mirror mail servers of the client's `mail_domain` rows by id (spec 025 order) |
| `dns` | HostingDnsServer[] | valid assigned DNS servers (`client.dns_servers`), then non-mirror DNS servers of the client's `dns_soa` rows by id |

"Valid assigned" = exists, has the role flag, `mirror_server_id = 0` (spec 016). The client's rows are those whose
`sys_groupid` belongs to a `sys_group` with the client's `client_id`.

## HostingServer

| Field | Type | Source |
|---|---|---|
| `server_id` | integer | `server.server_id` |
| `server_name` | string | `server.server_name` (host name for MX records and mail programs) |
| `is_default` | boolean | first valid assigned server of the role |
| `ipv4` | string[] | public IPv4 addresses (below) |
| `ipv6` | string[] | public IPv6 addresses (below) |

## HostingDnsServer

`server_id`, `server_name`, `is_default` as HostingServer, plus `nameservers` (NameServer[]).

## NameServer

| Field | Type | Source |
|---|---|---|
| `name` | string | `server_name` of the DNS server and of servers with `mirror_server_id` = it (ordered by `server_name`), then `sys_ini` [dns] `dns_external_slave_fqdn` entries (split on commas/whitespace); trailing dots removed; case-insensitive duplicates dropped |
| `ipv4` | string[] | public addresses of that server; `[]` for external names |
| `ipv6` | string[] | idem |

## Address rule (per server)

```
SELECT ip_type, ip_address FROM server_ip
WHERE server_id = :server AND client_id IN (0, :client)
ORDER BY server_ip_id
```

- `ip_type = IPv4` → valid IPv4, `IPv6` → valid IPv6 (other/mismatch skipped);
- public: `FILTER_VALIDATE_IP` with `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` (PHP 8.3: excludes
  10/8, 172.16/12, 192.168/16, 127/8, 169.254/16, 0/8, 240/4, fc00::/7, fe80::/10, ::1, ::);
- `virtualhost` not considered; duplicates removed (first occurrence).

## Refusals

| Case | Status |
|---|---|
| unknown query parameter | 400 |
| admin key without `client_id` | 422 `errors.client_id` |
| unknown client, other client's id (client key), non-child id (reseller key) | 404 |
