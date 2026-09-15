# Data Model: Zone Removal With Records

No migrations.

## DELETE /dns/soa/{id}

| Step | Table | Datalog action | Values |
|---|---|---|---|
| 1 | `dns_soa` | `u` | `active` → `N` (skipped by the journal when the zone is already inactive) |
| 2 | `dns_rr` (each row with `zone = {id}`, ascending id) | `d` | — |
| 3 | `dns_soa` | `d` | — |

All three steps run in one database transaction and one change set (`X-Change-Set-Id`).

## Responses

| Case | Status |
|---|---|
| zone deleted (with or without records) | 204 + `X-Change-Set-Id` |
| zone not visible to the key, or unknown | 404 |
| key may see but not delete the zone (`sys_perm_*` without `d`) | 403 |

The previous `400` ("Cannot delete zone that contains DNS records (N associated records)") no longer exists.
