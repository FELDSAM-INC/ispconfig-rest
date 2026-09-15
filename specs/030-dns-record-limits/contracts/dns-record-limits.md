# Contract: DNS Record Limit Parity

OpenAPI sources: `api/modules/dns/records.yaml`, `api/modules/usage/summary.yaml`,
`api/components/schemas/UsageSummary.yaml`, `docs/problems.md`.

## POST /dns/records

Unchanged request and 201 response. Added to the description:

> Client and reseller keys are bound by the account's DNS record limit (`limit_dns_record`): records carrying the
> account's group are counted and a create at the limit is refused with 403 `limit-reached`
> (`limit.name = limit_dns_record`, `scope = client`). Updates and deletions are never refused by the limit. Admin keys
> are not limited.

| Case | Status | Body |
|---|---|---|
| under the limit / unlimited / admin key | 201 | DnsRecord |
| at the limit (limit ≥ 0, used ≥ limit) | 403 | `limit-reached`, `limit {name: limit_dns_record, scope: client, max, used}` |

## GET /usage/summary

`counts` gains the required member `dns_records` (`UsageCount`: `{used: integer, limit: integer|null}`).

```json
"counts": {
  "dns_zones": {"used": 2, "limit": 5},
  "dns_records": {"used": 12, "limit": 50}
}
```
