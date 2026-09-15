# Contract: Zone Removal With Records

OpenAPI source: `api/modules/dns/soa.yaml` (DELETE `/dns/soa/{id}`).

## Before

> A zone that still contains DNS records is refused with 400. This is an intentional deviation from legacy
> ISPConfig, which deletes the records with the zone.

Responses: 204, **400**, 401, 403, 404, 500.

## After

> Deletes the zone and everything in it: the zone is journaled as inactive, every resource record of the zone is
> deleted and then the zone itself — the same sequence, and the same journal entries, as the ISPConfig panel
> (`dns_soa_del.php`). All entries share one `X-Change-Set-Id`; a failure rolls the whole deletion back.

Responses: 204, 401, 403, 404, 500 (the 400 case is removed).

| Case | Status |
|---|---|
| zone with N records, key may delete it | 204, N + 2 journal entries |
| empty zone | 204, 2 journal entries |
| zone of another client (client key) | 404 |
| unknown id | 404 |

## Consumer impact (WHMCS module 005)

`specs/005-dns/contracts/ispconfig-rest-calls.md` documents `DELETE /dns/soa/{id}` as "400 while records exist
(removal flow)"; the module can replace its record-by-record removal with the single call.
