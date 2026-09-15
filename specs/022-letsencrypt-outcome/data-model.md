# Data Model: Let's Encrypt Issuance Outcome (022)

No new tables, no writes. One response schema.

## WebDomainSslStatus (`api/components/schemas/WebDomainSslStatus.yaml`)

| Field | Type | Source |
|-------|------|--------|
| `website_id` | integer | `web_domain.domain_id` |
| `domain` | string | `web_domain.domain` |
| `https_enabled` | boolean | `web_domain.ssl = 'y'` |
| `letsencrypt_enabled` | boolean | `web_domain.ssl_letsencrypt = 'y'` |
| `state` | enum `none`, `requested`, `issued`, `failed` | FR-004 |
| `requested_at` | date-time \| null | request entry `tstamp` (API timezone) |
| `change_set_id` | string \| null | request entry `session_id` (spec 015) |
| `change_status` | enum `pending`, `applied`, `failed`, `stalled` \| null | `ChangeStatusResolver::statusOf()` |
| `failure` | `WebDomainSslFailure` \| null | only when `state = failed` |
| `excluded_domains` | string[] | only when `state = issued` (R3 line 390 rows) |
| `certificate` | `WebDomainSslCertificate` \| null | only when `state = issued` and the file is readable (R4) |

### failure

| Field | Type | Notes |
|-------|------|-------|
| `reason` | enum `domain_not_reachable`, `issuance_failed`, `certificate_not_found`, `client_unavailable`, `unknown` | R3 precedence |
| `detail` | string | fixed per reason (below) |
| `domains` | string[] | hostnames parsed from the log (may be empty) |

Detail texts:

- `domain_not_reachable`: "The domain does not point to this server yet, so the certificate authority could not verify it."
- `issuance_failed`: "The certificate authority did not issue the certificate. Check the domain's DNS records and try again later."
- `certificate_not_found`: "The certificate was requested but could not be installed on the server."
- `client_unavailable`: "Free certificates are not available on this server. Contact support."
- `unknown`: "The certificate could not be issued. Make sure the domain points to this server and try again."

### certificate

| Field | Type |
|-------|------|
| `valid_from` | date-time |
| `expires_at` | date-time |
| `issuer` | string |
| `domains` | string[] |

## State derivation (FR-004)

```
entries = newest ≤ 50 sys_datalog rows (web_domain, domain_id:{id}), newest first
relevant = first entry that is a request entry or an off entry
if relevant is off entry            -> none
if relevant is request entry:
    status = ChangeStatusResolver::statusOf(entry)
    if status in (pending, stalled) -> requested
    elif row.ssl_letsencrypt = y    -> issued
    else                            -> failed
if no relevant entry:
    row.ssl = y and row.ssl_letsencrypt = y -> issued (requested_at null)
    else                                    -> none
```

Validation rules on read inputs: `document_root` must start with `/` and contain no `..` segment; domain names must
match `^(\*\.)?([a-z0-9-]+\.)+[a-z0-9-]+$` (case-insensitive) before being used in a path or returned.
