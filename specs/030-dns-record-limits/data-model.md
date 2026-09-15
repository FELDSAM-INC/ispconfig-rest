# Data Model: DNS Record Limit Parity

No migrations. Existing columns `client.limit_dns_record` (default -1) and `dns_rr.sys_groupid`.

## Record cap

| Input | Source |
|---|---|
| limit | acting client's `limit_dns_record` (-1 unlimited, 0 no records, n cap) |
| used | `COUNT(*) FROM dns_rr WHERE sys_groupid = <key user's default group>` |
| applies to | `POST /dns/records` by client and reseller keys; not admin keys, not keys without a client row |
| refuse when | limit ≥ 0 and used ≥ limit |
| reseller cap | none |

## Refusal (403, spec 023)

```json
{
  "type": "https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#limit-reached",
  "title": "Forbidden",
  "status": 403,
  "detail": "You have reached the maximum number of DNS records allowed for your account.",
  "limit": {"name": "limit_dns_record", "scope": "client", "max": 50, "used": 50}
}
```

## UsageSummary.counts (`api/components/schemas/UsageSummary.yaml`) — addition

| Key | Schema | used | limit |
|---|---|---|---|
| `dns_records` | `UsageCount` | count as above for the summary's client | `limit_dns_record`, null when negative |
