# Contract: `GET /sites/web-domains/{id}/ssl/status` (022)

Added to `api/modules/sites/web-domains.yaml` as path item `/sites/web-domains/{id}/ssl/status`, registered in
`api/openapi.yaml` before `/sites/web-domains/{id}/ssl`. Schema `api/components/schemas/WebDomainSslStatus.yaml`
(registered in `api/components/schemas/_index.yaml`).

## Request

- Header `X-API-Key` (admin, reseller or client key).
- Path `id` — integer website id (`vhost`, `vhostsubdomain`, `vhostalias`).
- No query parameters.

## Responses

| Status | Body | When |
|--------|------|------|
| 200 | `WebDomainSslStatus` | website readable by the key |
| 401 | problem+json (`Unauthorized`) | missing/invalid key |
| 404 | problem+json (`NotFound`) | unknown id, other tenant's website, non-vhost type |

No `X-Change-Set-Id` header (read-only).

### Example — requested

```json
{
  "website_id": 12,
  "domain": "shop.example.com",
  "https_enabled": true,
  "letsencrypt_enabled": true,
  "state": "requested",
  "requested_at": "2026-09-15T18:02:11+02:00",
  "change_set_id": "3f2b7c0d9e8a4b1c",
  "change_status": "pending",
  "failure": null,
  "excluded_domains": [],
  "certificate": null
}
```

### Example — failed

```json
{
  "website_id": 12,
  "domain": "shop.example.com",
  "https_enabled": false,
  "letsencrypt_enabled": false,
  "state": "failed",
  "requested_at": "2026-09-15T18:02:11+02:00",
  "change_set_id": "3f2b7c0d9e8a4b1c",
  "change_status": "applied",
  "failure": {
    "reason": "domain_not_reachable",
    "detail": "The domain does not point to this server yet, so the certificate authority could not verify it.",
    "domains": ["shop.example.com", "www.shop.example.com"]
  },
  "excluded_domains": [],
  "certificate": null
}
```

### Example — issued

```json
{
  "website_id": 12,
  "domain": "shop.example.com",
  "https_enabled": true,
  "letsencrypt_enabled": true,
  "state": "issued",
  "requested_at": "2026-09-15T18:02:11+02:00",
  "change_set_id": "3f2b7c0d9e8a4b1c",
  "change_status": "applied",
  "failure": null,
  "excluded_domains": ["www.shop.example.com"],
  "certificate": {
    "valid_from": "2026-09-15T17:03:00+02:00",
    "expires_at": "2026-12-14T17:02:59+01:00",
    "issuer": "Let's Encrypt",
    "domains": ["shop.example.com"]
  }
}
```
