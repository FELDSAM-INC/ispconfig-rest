# Contract Changes to Existing Files (spec 016)

Apply before implementation (constitution Principle I). No path, method or status code is added to the
existing operations: all covered POST and PUT operations already declare `422` (verified 2026-09-14).

## `api/openapi.yaml`

- `paths`: add `/me/servers: $ref: './modules/me/servers.yaml#/~1me~1servers'` (next to `/me` from spec 014).
- `components.schemas`: add `AssignedServers` and `AssignedServer`.

## `api/modules/me/_index.yaml` (owned by spec 014)

- Add `servers: $ref: './servers.yaml'`. Create the file with this entry if spec 014 has not merged yet.

## Shared schemas

Common description sentence (adapted per resource) for `server_id`:

> Required for admin keys. For client and reseller keys it may be omitted: the first server assigned to the
> account for this service is used. A server that is not assigned to the account is rejected with 422.
> Cannot be changed after creation.

| Schema | `required` change | `server_id` description change |
|--------|-------------------|--------------------------------|
| `WebDomain.yaml` | remove `server_id` | common sentence; for `vhostsubdomain`/`vhostalias` the parent's server is always used |
| `MailDomain.yaml` | remove `server_id` | common sentence |
| `Database.yaml` | none (never listed) | common sentence (database servers; parent website's server is not implied) |
| `DnsSoa.yaml` | remove `server_id` | common sentence; non-admin keys cannot move the zone to another server |
| `DnsSlave.yaml` | remove `server_id` | Required for admin keys. For client and reseller keys the account's secondary DNS server is always used; another value is rejected with 422; cannot be changed by non-admin keys |
| `MailGet.yaml` | remove `server_id` | Required for admin keys. For client and reseller keys the destination mailbox's server is always used; another value is rejected with 422 |

## Operation descriptions (append a "Server selection" block)

| File | Operation | Text to add |
|------|-----------|-------------|
| `modules/sites/web-domains.yaml` | POST `/sites/web-domains` | Client/reseller keys: `server_id` optional for `vhost` (first assigned web server); must be assigned to the account; 422 `errors.server_id` when not available or when no web server is assigned. Children use the parent's server. Admin keys unchanged. |
| `modules/mail/domains.yaml` | POST `/mail/domains` | Same rule with mail servers. |
| `modules/sites/databases.yaml` | POST `/sites/databases` | Same rule with database servers. |
| `modules/dns/soa.yaml` | POST `/dns/soa` | Same rule with DNS servers. |
| `modules/dns/soa.yaml` | PUT `/dns/soa/{id}` | Client/reseller keys cannot change `server_id` (current value accepted, otherwise 422). |
| `modules/dns/slave.yaml` | POST `/dns/slaves` | Client/reseller keys: the account's secondary DNS server is used; another value or no assigned secondary DNS server → 422. |
| `modules/dns/slave.yaml` | PUT `/dns/slaves/{id}` | Client/reseller keys cannot change `server_id`. |
| `modules/mail/fetchmail.yaml` | POST `/mail/fetchmail` | Client/reseller keys: the destination mailbox's server is used; another value → 422. |

## 422 messages (for examples in the descriptions)

| Case | `errors.server_id[0]` |
|------|-----------------------|
| Unassigned, nonexistent, mirror or wrong-role server (non-admin) | `The selected server is not available for this account.` |
| No valid server for the service | `No web server is assigned to this account.` (mail / database / DNS analogously) |
| No valid secondary DNS server | `No secondary DNS server is assigned to this account.` |
| Server change on DNS zone / secondary zone update (non-admin) | `The server cannot be changed after creation.` |
