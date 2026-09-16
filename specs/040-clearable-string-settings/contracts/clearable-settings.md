# Contract: Clearable String Settings

OpenAPI sources: `api/modules/system/system-config.yaml` and the per-section configuration files, plus the
`System*Config` schemas.

## PUT /system/config/{section} and PUT /system/config

Unchanged shape: a flat section object (or the five sections), every field optional, absent keys left unchanged.

One rule is added to the descriptions:

> An empty string clears a text setting: the value is stored empty, exactly as ISPConfig's own form saves a cleared
> field. `null` means the same as `""`. Settings that are not text — numbers, `y`/`n` switches and
> `web_php_options`, which ISPConfig marks as required — still refuse an empty value with 422 naming the field.

### Clearing (200)

```http
PUT /api/v1/system/config/mail
{"webmail_url": ""}
```

A read then returns `"webmail_url": ""`, and every other key of the blob — including keys this contract does not
expose — is unchanged.

The same applies to `dns_external_slave_fqdn` (`dns`), `ssh_authentication` (`sites`), `company_name` (`misc`) and
every other exposed text setting.

### Still refused (422)

```http
PUT /api/v1/system/config/sites
{"web_php_options": []}
```

```json
{
  "type": "https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#validation-failed",
  "title": "Validation failed",
  "status": 422,
  "detail": "One or more fields are invalid.",
  "errors": { "web_php_options": ["The web php options field must have at least 1 items."] }
}
```

Integer settings (`default_webserver`, `default_dbserver`, `default_mailserver`, `default_dnsserver`,
`default_slave_dnsserver`, `min_password_length`) and `y`/`n` switches keep refusing an empty value the same way.

No new problem type: these stay ordinary validation failures.

## Consumer note

An integration can now return a setting to its default by sending an empty string — previously the only way back was
editing the database directly, which is what the spec 037 verification had to do.
