# Contract: Hosting Addresses and Name Servers

OpenAPI sources: `api/modules/me/hosting-addresses.yaml`, `api/components/schemas/HostingAddresses.yaml`,
`HostingServer.yaml`, `HostingDnsServer.yaml`, `NameServer.yaml`.

## GET /me/hosting-addresses[?client_id=N]

Every valid key. Client keys: own client only; reseller keys: own or child client; admin keys: `client_id` required.

### 200

```json
{
  "client_id": 42,
  "web": [
    {"server_id": 1, "server_name": "web1.example.com", "is_default": true,
     "ipv4": ["192.0.2.10"], "ipv6": ["2001:db8::10"]}
  ],
  "mail": [
    {"server_id": 3, "server_name": "mail1.example.com", "is_default": true,
     "ipv4": ["192.0.2.30"], "ipv6": []}
  ],
  "dns": [
    {"server_id": 4, "server_name": "ns1.example.com", "is_default": true,
     "nameservers": [
       {"name": "ns1.example.com", "ipv4": ["192.0.2.40"], "ipv6": ["2001:db8::40"]},
       {"name": "ns2.example.com", "ipv4": ["198.51.100.50"], "ipv6": []},
       {"name": "ns3.example.net", "ipv4": [], "ipv6": []}
     ]}
  ]
}
```

### Errors

| Status | When |
|---|---|
| 400 | unknown query parameter, `client_id` not a positive integer |
| 401 | missing/invalid key |
| 404 | client not visible to the key or unknown |
| 422 | admin key without `client_id` |

## Consumer mapping (WHMCS module 005)

| Module need (proposed flat shape) | Read from |
|---|---|
| `web.ipv4[]`, `web.ipv6[]` | `web[0].ipv4`, `web[0].ipv6` (default web server) |
| `mail.host` | `mail[0].server_name` |
| `nameservers[{name, ip}]` | `dns[?server_id == zone.server_id].nameservers[]` (`name`, `ipv4[0]`/`ipv6[0]` for glue), else `dns[0]` |
