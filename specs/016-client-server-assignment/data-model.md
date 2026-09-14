# Data Model: Client Server Assignment for Non-Admin Keys

**Feature**: 016-client-server-assignment | **Date**: 2026-09-14

No new tables and no writes to ISPConfig tables. The feature reads existing columns and derives two views.

## Entities (read)

### Client server assignment — table `client`

| Column | Type | Meaning |
|--------|------|---------|
| `client_id` | int | Row of the acting identity: `AuthScope::$clientId` (`sys_user.client_id`) |
| `web_servers` | CSV text | Web servers the account may use, in preference order |
| `mail_servers` | CSV text | Mail servers |
| `db_servers` | CSV text | Database servers |
| `dns_servers` | CSV text | Primary DNS servers |
| `default_slave_dnsserver` | int | Server for secondary DNS zones (0 = none) |

Resellers use the same columns on their own row (`client/form/reseller.tform.php`). Admin scopes never read
this row. Schema `api/components/schemas/Client.yaml`, model `app/Models/Client.php` (unchanged).

### Server — table `server`

| Column | Used for |
|--------|----------|
| `server_id`, `server_name` | Identity and display in discovery |
| `web_server`, `mail_server`, `db_server`, `dns_server` | Eligibility per service (must be 1) |
| `mirror_server_id` | Eligibility (must be 0) |
| `active` | Not used (legacy parity) |

Schema `api/components/schemas/Server.yaml`, model `app/Models/Server.php` (unchanged; still admin-only through
`/servers`).

### System configuration defaults — table `sys_ini` (admin discovery only)

Read through `SystemConfigService::getSection()`: `sites.default_webserver`, `sites.default_dbserver`,
`mail.default_mailserver`, `dns.default_dnsserver`, `dns.default_slave_dnsserver`.

### Mailbox server — table `mail_user`

`mail_user.server_id` of the row whose `email` equals the normalized fetchmail `destination`.

## Derived views

### AssignedServer (schema `AssignedServer.yaml`, new)

| Field | Type | Rule |
|-------|------|------|
| `server_id` | integer ≥ 1 | Eligible server |
| `server_name` | string | `server.server_name` |
| `is_default` | boolean | Non-admin: first list entry (and the slave DNS server). Admin: equals the system config default |

### AssignedServers (schema `AssignedServers.yaml`, new)

| Field | Type | Content |
|-------|------|---------|
| `web` | AssignedServer[] | Ordered: list order (non-admin) / `server_id` (admin) |
| `mail` | AssignedServer[] | same |
| `db` | AssignedServer[] | same |
| `dns` | AssignedServer[] | same |
| `dns_slave` | AssignedServer or null | Non-admin: valid `default_slave_dnsserver`; admin: valid `dns.default_slave_dnsserver` |

No other properties (`additionalProperties: false`).

## Service map

| Service key | List column | Server flag | Label in messages | Covered create |
|-------------|-------------|-------------|-------------------|----------------|
| `web` | `web_servers` | `web_server` | web | `POST /sites/web-domains` (type `vhost`) |
| `mail` | `mail_servers` | `mail_server` | mail | `POST /mail/domains` |
| `db` | `db_servers` | `db_server` | database | `POST /sites/databases` |
| `dns` | `dns_servers` | `dns_server` | DNS | `POST /dns/soa` |

## Resolution algorithm (non-admin scopes)

```text
assignedServerIds(scope, service):
    row  = client row for scope.clientId (memoized per request); none → []
    ids  = parse CSV(row[listColumn]): split ',', trim, int, keep > 0, unique (first wins)
    valid = SELECT server_id FROM server
            WHERE server_id IN ids AND <flag> = 1 AND mirror_server_id = 0
    return ids filtered to valid, in original order

defaultServerId(scope, service) = first(assignedServerIds) or null

slaveDnsServerId(scope):
    id = client.default_slave_dnsserver (int)
    return id if server exists with dns_server = 1 AND mirror_server_id = 0, else null
```

## Validation matrix

"assigned" = in `assignedServerIds`; messages per research.md R4. Admin keys: current rules, no change.

| Endpoint | Key | `server_id` omitted | assigned | unassigned / nonexistent / mirror / wrong flag | no valid server |
|----------|-----|---------------------|----------|-----------------------------------------------|-----------------|
| POST sites/web-domains (vhost) | client/reseller | default merged → 201 | 201 | 422 not available | 422 no web server |
| POST sites/web-domains (child) | client/reseller | parent's server (existing) | overwritten by parent (existing) | overwritten by parent (existing) | n/a |
| POST mail/domains | client/reseller | default → 201 | 201 | 422 not available | 422 no mail server |
| POST sites/databases | client/reseller | default → 201 | 201 | 422 not available | 422 no database server |
| POST dns/soa | client/reseller | default → 201 | 201 | 422 not available | 422 no DNS server |
| POST dns/slaves | client/reseller | slave default → 201 | = slave default: 201 | ≠ slave default: 422 not available | 422 no secondary DNS server |
| POST mail/fetchmail | client/reseller | mailbox server → 201 | = mailbox server: 201 | ≠ mailbox server: 422 not available | mailbox missing: existing `destination` 422 |
| PUT dns/soa/{id}, dns/slaves/{id} | client/reseller | unchanged | = current: 200 | ≠ current: 422 cannot be changed | n/a |
| any of the above | admin | 422 required (unchanged, except PUT) | unchanged | unchanged `exists` message | n/a |

Rejected requests fail in FormRequest validation: no controller logic, no `sys_datalog` row.

## State transitions

None. Records already on a server that later leaves the account's list stay readable, updatable (without a
server change) and deletable (FR-011).
