# Contract: Zone and Record Rule Parity

OpenAPI sources: `api/modules/dns/soa.yaml`, `api/modules/dns/records.yaml`,
`api/components/schemas/DnsSoa.yaml`.

## POST /dns/soa, PUT /dns/soa/{id}

Added to the descriptions and to the `update_acl` / `origin` schema text:

> `update_acl` can only be set with an administrator key (the ISPConfig form hides it from customers); client and
> reseller keys that submit a different value get 422 with `error_types.update_acl` = `feature-not-allowed`.
> Re-sending the stored value is accepted.
>
> Renaming a zone (`origin`) needs an administrator or reseller key; a client key that changes it gets 422 with
> `error_types.origin` = `feature-not-allowed`.

| Case | Status | Body |
|---|---|---|
| client/reseller key changes `update_acl` | 422 | `errors.update_acl`, `error_types.update_acl` = `feature-not-allowed` |
| client key (not a reseller) changes `origin` | 422 | `errors.origin`, `error_types.origin` = `feature-not-allowed` |
| same value re-sent, or admin key | 201/200 | zone |

## POST /dns/records, PUT /dns/records/{id}

Added to the descriptions:

> As in the ISPConfig forms, an identical MX, TLSA or DKIM record (same name and data in the zone) and a second SPF
> record for the same name are refused with 422 — for every key type.

| Case | Status | Body |
|---|---|---|
| identical MX / TLSA / DKIM in the zone | 422 | `errors.name` |
| second `v=spf1` TXT for the name | 422 | `errors.name` |
| different name, data, or the record itself | 201/200 | record |
