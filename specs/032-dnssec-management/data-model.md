# Data Model: DNSSEC Management For Scoped Keys

No migrations. Reads `dns_soa` and `server`; writes nothing (the existing `PUT /dns/soa/{id}` keeps its datalog
path).

## GET /dns/soa/{id}/dnssec

| Field | Type | Source |
|---|---|---|
| `zone_id` | integer | `dns_soa.id` |
| `origin` | string | `dns_soa.origin` |
| `state` | `off` \| `pending` \| `signed` \| `unavailable` | derived (R5) |
| `available` | boolean | no mirror of the zone's DNS server |
| `wanted` | boolean | `dns_soa.dnssec_wanted` |
| `initialized` | boolean | `dns_soa.dnssec_initialized` |
| `algorithm` | string | `dns_soa.dnssec_algo` |
| `last_signed` | date-time, nullable | `dns_soa.dnssec_last_signed` (0 ⇒ null), API timezone |
| `ds_records` | array | parsed from `dns_soa.dnssec_info` |
| `dnskey_records` | array | parsed from `dns_soa.dnssec_info` |

### `ds_records[]`

| Field | Type | Notes |
|---|---|---|
| `key_tag` | integer | |
| `algorithm` | integer | 13 = ECDSAP256SHA256, 7 = NSEC3RSASHA1 |
| `digest_type` | integer | |
| `digest` | string | uppercase, whitespace removed (the server wraps long digests) |
| `record` | string | the full line to hand to a registrar |

### `dnskey_records[]`

| Field | Type | Notes |
|---|---|---|
| `flags` | integer | 257 = key-signing key, 256 = zone-signing key |
| `protocol` | integer | always 3 |
| `algorithm` | integer | |
| `public_key` | string | base64, whitespace removed |
| `type` | `ksk` \| `zsk` \| `other` | from `flags` |
| `record` | string | the full line |

Comment lines (`;`), the dashed separator and a PowerDNS `== Raw log ==` section are ignored. Unparsable or empty
notes yield empty arrays.

## State derivation

| `available` | `wanted` | `initialized` | `state` |
|---|---|---|---|
| false | any | any | `unavailable` |
| true | false | any | `off` |
| true | true | false | `pending` |
| true | true | true | `signed` |

## Availability

`available = false` ⟺ `SELECT COUNT(*) FROM server WHERE mirror_server_id = <dns_soa.server_id>` is greater than
zero (legacy `dns_soa_edit.php:95-98`).

## PUT /dns/soa/{id} — the mirror rule

| Case | Result |
|---|---|
| `dnssec_wanted: true` while `available` is false | 422, `errors.dnssec_wanted`, `error_types.dnssec_wanted` = `feature-not-allowed`, nothing journaled |
| `dnssec_wanted: false`, or the stored value re-sent | accepted |
| `available` is true | unchanged behaviour |

Applies to every key type, as legacy hides the block for everyone.

## Zone resource change

| Field | Client / reseller key | Admin key |
|---|---|---|
| `dnssec_info` | `null` | unchanged |
| `dnssec_wanted`, `dnssec_algo`, `dnssec_initialized`, `dnssec_last_signed` | unchanged | unchanged |

## Responses

| Case | Status |
|---|---|
| zone readable | 200 |
| zone not visible to the key, or unknown | 404 |
| no key | 401 |
