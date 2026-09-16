# Data Model: Clearable String Settings in the System Configuration

No migrations, no new fields. One normalization rule.

## Input normalization (`SystemConfigService::normalizeInput()`)

| Field type | Input `null` or `""` | Input non-empty |
|---|---|---|
| `string` | becomes `""` → validated as a string → stored as `key=` | trimmed, STRIPTAGS/STRIPNL where marked, then validated |
| `integer` | unchanged → refused by the `integer` rule (422) | unchanged |
| `yn` | unchanged → refused by the `y`/`n` enum (422) | unchanged |
| `csv_array` (`web_php_options`) | unchanged → refused by `array` + `min:1` (422, legacy `NOTEMPTY`) | unchanged |

The mapping happens in `prepareForValidation()`, before the existing trim and strip filters, so the rules see a real
string and the stored value is what ISPConfig's own save would write.

## Settings affected (exposed `string` fields)

| Section | Settings |
|---|---|
| `sites` | `dbname_prefix`, `dbuser_prefix`, `ftpuser_prefix`, `shelluser_prefix`, `default_remote_dbserver`, `ssh_authentication` |
| `mail` | `webmail_url`, `mailmailinglist_url` |
| `dns` | `dns_external_slave_fqdn` |
| `domains` | `new_domain_html` |
| `misc` | `company_name`, `custom_login_text`, `custom_login_link`, `dashboard_atom_url_admin`, `dashboard_atom_url_reseller`, `dashboard_atom_url_client`, `min_password_strength` |

Each already permits an empty value in ISPConfig's own form (research R2). Regex-validated members
(`webmail_url`, `custom_login_link`, the prefixes) have patterns that allow zero length, so an empty value passes
their rule as well.

## Stored result

A cleared setting keeps its key with an empty value (`key=`), like every empty text field ISPConfig writes. Keys the
contract does not expose are preserved untouched by the existing read-merge-write.

## Not changed

Per-server configuration (`server.config`), the set of exposed settings, how values are read back, and every
non-string validation rule.
