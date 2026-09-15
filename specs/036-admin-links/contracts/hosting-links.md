# Contract: Administration and File-Transfer Links

OpenAPI sources: `api/modules/me/hosting-links.yaml`, `api/components/schemas/HostingLinks.yaml`,
`DatabaseAdministrationLink.yaml`, `HostingLinkServer.yaml`, `FileTransferLink.yaml`.

## GET /me/hosting-links[?client_id=N]

Every valid key. Client keys: own client only; reseller keys: own or a child client; admin keys: `client_id`
required. Read-only.

### 200

```json
{
  "client_id": 42,
  "database_administration": {
    "available": true,
    "servers": [
      {"server_id": 2, "server_name": "db1.example.com", "url": "https://db1.example.com:8081/phpmyadmin"},
      {"server_id": 5, "server_name": "db2.example.com", "url": "https://db2.example.com:8081/phpmyadmin"}
    ]
  },
  "file_transfer": {"available": true, "url": "https://files.example.com"}
}
```

- `database_administration.available` — the installation shows the per-database link (`dblist_phpmyadmin_link = y`)
  and an address is configured. When false, a panel hides the button; `servers` is still returned.
- `servers` — the account's database servers: assigned database servers in assignment order, then other database
  servers hosting the account's databases, by id.
- `url` — the configured address with `[SERVERNAME]` replaced by that server's name. **A `[DATABASENAME]` placeholder
  is left in place**; the consumer replaces it with the database's own full (prefixed) name, for example
  `c42_shop`. Empty when no address is configured.
- `file_transfer.url` — the configured address verbatim (ISPConfig substitutes nothing there). Empty when unset.

### Errors

| Status | When |
|---|---|
| 400 | unknown query parameter |
| 401 | no or invalid key |
| 404 | `client_id` names a client the key may not describe, or an unknown client |
| 422 | admin key without `client_id`, or a non-positive `client_id` |

Identical to `/me/mail-settings` and `/me/hosting-addresses`.

## Consumer mapping (WHMCS module spec 006)

| Panel element | Source |
|---|---|
| "Open database administration" button | `database_administration.available`; the link is the `url` of the entry whose `server_id` matches the database's server, with `[DATABASENAME]` replaced by the database's full name |
| "Open file manager" button on FTP accounts | `file_transfer.available` and `file_transfer.url` |

A database whose server is missing from `servers` (possible only if the database was moved by an administrator
between two reads) means the panel hides the button for that row rather than guessing an address.
