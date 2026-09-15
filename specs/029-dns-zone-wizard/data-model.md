# Data Model: DNS Zone Wizard For Scoped Keys

No migrations. Reads `dns_template`, `mail_domain`, `client`, `server`; writes `dns_soa` and `dns_rr` through
datalog only.

## GET /dns/zone-templates

Source: `dns_template WHERE visible = 'Y' ORDER BY name ASC` — no row predicate (legacy `dns_wizard.php:73`).

| Response field | Source | Notes |
|---|---|---|
| `id` | `template_id` | |
| `name` | `name` | |
| `fields` | `fields` | CSV split into an array of tokens, invalid tokens dropped |

`{data, meta}` with the shared `limit`/`offset`/`sort`/`order` parameters (`sort`: `name`, `id`; default `name`
asc). The template text and system fields are never returned.

## POST /dns/soa/from-template

### Request

| Field | Type | Required | Notes |
|---|---|---|---|
| `template_id` | integer | yes | must exist and be `visible` |
| `domain` | string | yes | IDN-encoded, lower-cased; legacy regex |
| `ip` | string (IPv4) | when the template declares `IP` | |
| `ipv6` | string (IPv6) | when the template declares `IPV6` | |
| `ns1`, `ns2` | string | when declared | IDN + lower-case, legacy host regex |
| `email` | string | when declared | valid email; becomes `mbox` with `@` → `.` |
| `dkim` | boolean | no, only when declared | appends the DKIM TXT record |
| `dnssec` | boolean | no, only when declared | sets `dnssec_wanted` |
| `server_id` | integer | no | spec 016 assigned-server rules |
| `client_id` | integer | no | administrator/reseller ownership, as `POST /dns/soa` |

A value or flag the template does not declare in `fields` is refused (422 on that field).

### Writes, in order, in one transaction and one change set

| Step | Table | Action | Values |
|---|---|---|---|
| 1 | `dns_soa` | `i` | `[ZONE]` keys after placeholder replacement; `active = N`; `serial` generated; `mbox` `@`→`.`; `sys_perm` `riud`/`riud`/`''`; `server_id` resolved; `sys_groupid` from the key or `client_id` |
| 2 | `dns_rr` | `i` (one per template row, template order, then the optional DKIM row) | `zone`, `name`, `type`, `data`, `aux`, `ttl`, `active = Y`, `server_id` and `sys_groupid` from the zone, `stamp` and `serial` as `POST /dns/records` sets them |
| 3 | `dns_soa` | `u` | `active = Y` |

### Zone keys read from `[ZONE]`

Required: `origin`, `ns`, `mbox`, `refresh`, `retry`, `expire`, `minimum`, `ttl`.
Optional: `xfer`, `also_notify`, `update_acl`, `dnssec_wanted`, `dnssec_algo` (provider-authored; `update_acl` from
a template is accepted although a request body may not set it — spec 033). Unknown keys are ignored.

### Record rows

`TYPE|name|data|aux|ttl`. `TYPE` must be one of the values the `dns_rr.type` enum accepts — `A`, `AAAA`, `ALIAS`,
`CNAME`, `DNAME`, `CAA`, `DS`, `HINFO`, `LOC`, `MX`, `NAPTR`, `NS`, `PTR`, `RP`, `SRV`, `SSHFP`, `TXT`, `TLSA`,
`DNSKEY` (verified on isp-test) — and `name` and `data` must be non-empty after replacement, `aux` and `ttl`
integers. A row with fewer parts gets `aux = 0` and the zone's `ttl`.

### Responses

| Case | Status | Body |
|---|---|---|
| created | 201 + `X-Change-Set-Id` | `DnsSoa` |
| duplicate `origin` | 409 | problem |
| record cap reached | 403 | `limit-reached`, `limit {name: limit_dns_record, scope: client, max, used}` |
| zone cap reached | 403 | `limit-reached`, `limit {name: limit_dns_zone, scope: client\|reseller, max, used}` |
| unknown, invisible or malformed template | 422 | `errors.template_id` |
| missing/undeclared placeholder value | 422 | `errors.<field>` |
| unassigned `server_id` | 422 | `errors.server_id`, `error_types.server_id = server-not-assigned` |

## Limit counting

| Limit | Table | Predicate | Reseller cap | Batch rule |
|---|---|---|---|---|
| `limit_dns_zone` | `dns_soa` | owner (`u`) | yes (legacy checks it) | `used + 1 > max` |
| `limit_dns_record` | `dns_rr` | `grp` (records carrying the key's group) | no (spec 030) | `used + n > max` where `n` = template rows + optional DKIM row |

`max < 0` means unlimited; `max = 0` refuses everything. Administrator keys and keys without a client row are never
limited.
