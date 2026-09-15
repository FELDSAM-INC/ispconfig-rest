# Data Model: Zone and Record Rule Parity for Scoped Keys

No migrations. Existing columns.

## Zone field rules (`dns_soa`)

| Field | Admin | Reseller key | Client key | Comparison |
|---|---|---|---|---|
| `update_acl` | writable | refused when changed | refused when changed | submitted value vs stored (`null` = `''`) |
| `origin` (update) | writable | writable | refused when changed | normalized (IDN, lower case, trailing dot) |
| `xfer`, `also_notify`, `dnssec_wanted`, `dnssec_algo` | writable | writable | writable | — |
| `server_id` | writable | immutable (spec 016) | immutable (spec 016) | — |

Refusal: 422 `validation-failed` with `errors.<field>` and `error_types.<field>` =
`…/docs/problems.md#feature-not-allowed`.

| Field | Message |
|---|---|
| `update_acl` | `The dynamic update ACL can only be changed with an administrator key.` |
| `origin` | `The zone name cannot be changed. Please contact your administrator to change the zone.` |

## Record duplicate rules (`dns_rr`, every key type)

| Type | Refused when another record in the zone (id != current) has | Message |
|---|---|---|
| `MX` | `type = MX`, same `name`, same composed `data` (the target; priority not compared) | `An identical MX record already exists for this name in the zone.` |
| `TLSA` | `type = TLSA`, same `name`, same composed `data` | `An identical TLSA record already exists for this name in the zone.` |
| `DKIM` | stored `type = TXT` classified DKIM, same `name`, same `data` | `An identical DKIM record already exists for this name in the zone.` |
| `SPF` | stored `type = TXT`, same `name`, `data` starting `v=spf1` | `An SPF record already exists for this name in the zone.` |

All four run in addition to the existing CNAME-conflict check for the type, are zone-scoped, exclude the record being
updated, and run on create and on update whenever the participating fields are submitted (spec 013 FR-012 tolerance).
