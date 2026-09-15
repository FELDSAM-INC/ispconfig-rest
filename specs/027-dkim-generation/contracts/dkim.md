# Contract: mail domain DKIM

Source of truth after implementation: `api/modules/mail/domain-dkim.yaml`, `api/components/schemas/MailDomainDkim.yaml`,
`api/components/schemas/MailDomainDkimGenerate.yaml`, `api/components/schemas/MailDomain.yaml`,
`api/modules/mail/domains.yaml`.

## GET /api/v1/mail/domains/{id}/dkim

200:

```json
{
  "id": 7,
  "domain": "example.com",
  "enabled": true,
  "selector": "default",
  "public_key": "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA…\n-----END PUBLIC KEY-----\n",
  "key_bits": 2048,
  "dns_record": {
    "name": "default._domainkey.example.com.",
    "type": "TXT",
    "value": "v=DKIM1; t=s; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA…"
  },
  "dns_managed": true,
  "available": true
}
```

Without a key: `public_key`, `key_bits` and `dns_record` are `null`. Errors: 401, 404.

## POST /api/v1/mail/domains/{id}/dkim

Body (optional): `{"selector": "mail2026"}`. Generates a new RSA key pair (size from the mail server), enables DKIM and
publishes the DNS record in a hosted zone. 200 with the view above and `X-Change-Set-Id`. Errors: 401, 403 (no update
permission), 404, 409 (server cannot sign), 422 (`selector`), 500 (key generation failed).

The private key is never part of the response.

## Disable

`PUT /api/v1/mail/domains/{id}` `{"dkim": false}` (unchanged).

## Mail domain responses

For client and reseller keys `GET /mail/domains`, `GET /mail/domains/{id}`, `POST /mail/domains` and
`PUT /mail/domains/{id}` omit `dkim_private`. Admin keys still receive it.
