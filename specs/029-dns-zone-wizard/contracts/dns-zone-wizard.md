# Contract: DNS Zone Wizard For Scoped Keys

OpenAPI sources: `api/modules/dns/zone-templates.yaml` (new), `api/modules/dns/soa.yaml`
(`/dns/soa/from-template`), `api/modules/dns/_index.yaml`, `api/openapi.yaml`,
`api/components/schemas/DnsZoneTemplate.yaml` (new), `api/components/schemas/DnsZoneFromTemplate.yaml` (new),
`api/components/schemas/_index.yaml`.

## GET /dns/zone-templates

> The zone templates the DNS wizard offers. Every key sees the templates marked visible, whoever owns them — the
> rule the ISPConfig wizard applies (`dns_wizard.php`). Only the template's name and the values it asks for are
> returned; the template text and its owner are administrator information and stay in `/dns/templates`, which keeps
> its row-level scoping and administrator-only writes.

```json
{
  "data": [
    {"id": 1, "name": "Default", "fields": ["DOMAIN", "IP", "NS1", "NS2", "EMAIL", "DKIM", "DNSSEC"]}
  ],
  "meta": {"total": 1, "limit": 25, "offset": 0}
}
```

Responses: 200, 400, 401, 500.

## POST /dns/soa/from-template

> Creates a DNS zone and its records from a zone template — the ISPConfig DNS wizard. The template's placeholders
> are replaced with the submitted values, the zone is written inactive, every record of the template is created, and
> the zone is activated; all entries share one `X-Change-Set-Id` and the whole creation is one transaction, so a
> refusal leaves no zone behind.
>
> Every placeholder the template declares in `fields` must be supplied, and values the template does not declare are
> refused. `dkim` adds the published DKIM record of the domain's mail domain when the key can read one; `dnssec`
> creates the zone with DNSSEC signing requested. The account's DNS zone and DNS record limits are both enforced
> before anything is written, the whole batch of records at once.

Request (`DnsZoneFromTemplate`):

```json
{
  "template_id": 1,
  "domain": "example.com",
  "ip": "192.0.2.10",
  "ns1": "ns1.provider.net",
  "ns2": "ns2.provider.net",
  "email": "hostmaster@example.com",
  "dkim": false,
  "dnssec": false
}
```

Response 201: the created `DnsSoa`, with `X-Change-Set-Id`.

| Case | Status | Body |
|---|---|---|
| created | 201 | `DnsSoa` |
| duplicate origin | 409 | problem |
| `limit_dns_record` reached by the batch | 403 | `limit-reached` (`limit.name = limit_dns_record`) |
| `limit_dns_zone` reached | 403 | `limit-reached` (`limit.name = limit_dns_zone`) |
| unknown, invisible or malformed template | 422 | `errors.template_id` |
| missing or undeclared placeholder value | 422 | `errors.<field>` |
| `server_id` not assigned to the account | 422 | `errors.server_id`, `error_types.server_id` = `server-not-assigned` |

Responses: 201, 400, 401, 403, 404, 409, 422, 500.

## Unchanged

`GET /dns/templates[/{id}]` stays row-scoped, its writes administrator-only (spec 011). `POST /dns/soa` is
untouched.

## Consumer impact (WHMCS module 005)

`specs/005-dns/contracts/ispconfig-rest-calls.md` lists both calls as "029 (proposed) — not deployed → standard
set". The module can replace its hard-coded record set with the template list and one wizard call (task T049), and
map the two `limit-reached` names to its existing limit messages.
