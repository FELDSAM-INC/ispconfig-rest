# Contract: DNSSEC Management For Scoped Keys

OpenAPI sources: `api/modules/dns/soa.yaml` (`/dns/soa/{id}/dnssec`, and the mirror rule on PUT),
`api/components/schemas/DnsSoaDnssec.yaml` (new), `api/components/schemas/DnsSoa.yaml` (`dnssec_info`),
`api/components/schemas/_index.yaml`, `api/openapi.yaml`.

## GET /dns/soa/{id}/dnssec

> DNSSEC state of a zone, with the DS record to hand to the registrar and the published DNSKEY records.
>
> ISPConfig's DNS server signs the zone and writes its notes into the zone; this endpoint parses them into data.
> `state` is `off` when signing was not requested, `pending` while the server has not signed yet, `signed` once it
> has, and `unavailable` when the zone's DNS server is mirrored — ISPConfig signs only on the master, so the panel
> does not offer DNSSEC there either.
>
> Readable by every key that can read the zone (no plan gate). Read-only: no `X-Change-Set-Id`. Private keys, file
> paths and server command output are never returned.

```json
{
  "zone_id": 11,
  "origin": "example.com.",
  "state": "signed",
  "available": true,
  "wanted": true,
  "initialized": true,
  "algorithm": "ECDSAP256SHA256",
  "last_signed": "2026-09-16T01:38:02+02:00",
  "ds_records": [
    {
      "key_tag": 5269,
      "algorithm": 13,
      "digest_type": 2,
      "digest": "44F315FBF85AC547DBE621FEB51C011A735ECA5D44E0BDF07C47C4A7217F7D81",
      "record": "example.com. IN DS 5269 13 2 44F315FBF85AC547DBE621FEB51C011A735ECA5D44E0BDF07C47C4A7217F7D81"
    }
  ],
  "dnskey_records": [
    {
      "flags": 257,
      "protocol": 3,
      "algorithm": 13,
      "public_key": "HId4lryEWgDLIwtbwAyHy6N/O1jrw0+afJ6LDzVO/S6F8i9a2iCv4YT5E+eJ/2GYAS3Ytm/6lw0/n+Cgk7aZgQ==",
      "type": "ksk",
      "record": "example.com. IN DNSKEY 257 3 13 HId4lryEWgDLIwtbwAyHy6N/…"
    }
  ]
}
```

Responses: 200, 401, 404, 500.

## PUT /dns/soa/{id}

Unchanged request and response. Added to the description:

> `dnssec_wanted` can only be switched on where ISPConfig can sign: when the zone's DNS server has mirrors, the
> panel hides DNSSEC entirely and the API refuses the change with 422 and
> `error_types.dnssec_wanted` = `feature-not-allowed`. Switching DNSSEC off, and re-sending the stored value, are
> always accepted. Switching off keeps the keys ISPConfig generated, so the DS record stays valid if signing is
> switched on again.

| Case | Status |
|---|---|
| `dnssec_wanted: true`, zone's DNS server mirrored | 422 `feature-not-allowed` on `dnssec_wanted` |
| `dnssec_wanted: true`, no mirrors | 200 |
| `dnssec_wanted: false` | 200 |

## DnsSoa schema

`dnssec_info` gains:

> Raw DNSSEC notes written by the DNS server. Returned to administrator keys only — client and reseller keys get
> `null` and read `GET /dns/soa/{id}/dnssec` instead, which returns the same information as parsed DS and DNSKEY
> records without the server's command output.

## Consumer impact (WHMCS module 005)

`specs/005-dns/contracts/ispconfig-rest-calls.md` lists `PUT /dns/soa/{id}` `dnssec_wanted` as "032 (proposed) —
blocked" and the module parses `dnssec_info` itself. It can now read the sub-resource for status and DS data, hide
the switch when `available` is false, and map the typed 422 with its existing `feature-not-allowed` handling
(module task T092).
