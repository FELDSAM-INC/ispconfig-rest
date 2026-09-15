# Data Model: Administration and File-Transfer Links for Scoped Keys

No migrations. A read-only view derived per request.

## HostingLinks (`api/components/schemas/HostingLinks.yaml`)

| Field | Type | Source |
|---|---|---|
| `client_id` | integer | described client |
| `database_administration` | DatabaseAdministrationLink | `[sites]` `phpmyadmin_url`, `dblist_phpmyadmin_link` + the account's database servers |
| `file_transfer` | FileTransferLink | `[sites]` `webftp_url` |

## DatabaseAdministrationLink

| Field | Type | Source |
|---|---|---|
| `available` | boolean | `dblist_phpmyadmin_link` is `y` **and** `phpmyadmin_url` is not empty (research R5) |
| `servers` | HostingLinkServer[] | the account's database servers (below); `[]` when it has none |

## HostingLinkServer

| Field | Type | Source |
|---|---|---|
| `server_id` | integer | `server.server_id` |
| `server_name` | string | `server.server_name` |
| `url` | string | `phpmyadmin_url` with `[SERVERNAME]` replaced by `server_name`; `""` when the setting is empty |

A `[DATABASENAME]` placeholder is **left in** `url`: one entry describes a server, not a database, so the consumer
substitutes the database's own (prefixed) name — the same rule that leaves `[DOMAINID]` in the spec 035 prefixes.

**Server composition** (spec 031 rule, `db` role): `ServerAssignmentService::assignedServerIds($scope, 'db')` in
assignment order — which already skips mirrors, missing servers and servers without `db_server` — then the non-mirror
servers with `db_server = 1` hosting the client's `web_database` rows, by id, each server once.

## FileTransferLink

| Field | Type | Source |
|---|---|---|
| `available` | boolean | `webftp_url` is not empty |
| `url` | string | `webftp_url` verbatim (legacy substitutes nothing); `""` when unset |

## Example

```json
{
  "client_id": 42,
  "database_administration": {
    "available": true,
    "servers": [
      {"server_id": 2, "server_name": "db1.example.com", "url": "https://db1.example.com:8081/phpmyadmin"}
    ]
  },
  "file_transfer": {"available": false, "url": ""}
}
```

## Not exposed

No other `[sites]` value (prefixes, default servers, `ssh_authentication`, `postgresql_database`, …), no server data
beyond id and name, no `phppgadmin_url`, no addresses and no other client's resources.
