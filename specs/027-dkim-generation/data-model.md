# Data Model: DKIM Key Generation for Mail Domains

No migrations. Existing columns `mail_domain.dkim`, `dkim_selector`, `dkim_private`, `dkim_public`.

## MailDomainDkim (`api/components/schemas/MailDomainDkim.yaml`)

| Field | Type | Source |
|---|---|---|
| `id` | integer | `mail_domain.domain_id` |
| `domain` | string | `mail_domain.domain` |
| `enabled` | boolean | `mail_domain.dkim = y` |
| `selector` | string | `mail_domain.dkim_selector`, `default` when empty |
| `public_key` | string (PEM) \| null | `mail_domain.dkim_public`, null when empty |
| `key_bits` | integer \| null | RSA size of the public key |
| `dns_record` | object \| null | when a public key exists: `name` = `<selector>._domainkey.<domain>.`, `type` = `TXT`, `value` = `v=DKIM1; t=s; p=<public key without PEM armor and newlines>` |
| `dns_managed` | boolean | an active `dns_soa` zone encloses the domain (the record is maintained automatically while the domain is active) |
| `available` | boolean | the domain's mail server [mail] `dkim_path` is set, not empty, not `/` |

## MailDomainDkimGenerate (`api/components/schemas/MailDomainDkimGenerate.yaml`)

| Field | Type | Rule |
|---|---|---|
| `selector` | string, optional | `max:63`, `^[a-z0-9]{1,63}(?:\.[a-z0-9]{1,63})?$` |

## Write on generate

| Table | Datalog | Columns |
|---|---|---|
| `mail_domain` | `u` | `dkim` = `y`, `dkim_private` (PKCS#8 PEM), `dkim_public` (PEM), `dkim_selector` |
| `dns_rr` | `d` old `v=DKIM1` records of the previous selector, `i` new TXT record | hosted active zone and active domain only (existing logic) |
| `dns_soa` | `u` | serial (existing logic) |

Key size: server [mail] `dkim_strength` ∈ {1024, 2048, 4096}, otherwise 2048.

## Refusals

| Case | Status | Detail |
|---|---|---|
| domain not readable | 404 | not found |
| readable without `u` | 403 | `You do not have permission to update this resource.` |
| server without usable `dkim_path` | 409 | `DKIM signing is not available on the mail server of this domain.` |
| invalid `selector` | 422 | `errors.selector` |
| key generation failed | 500 | `The DKIM key could not be generated.` |

## Mail domain responses

`dkim_private` is removed for client and reseller keys (show, list, create, update); present for admin keys.
