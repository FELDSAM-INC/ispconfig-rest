# Research: Clearable String Settings in the System Configuration

Legacy source: ISPConfig 3.3.1p1 on isp-test.feldhost.cz (read-only). Live facts from isp-test on 2026-09-16.

## R1 — How the defect arises

`UpdateSystemConfigSectionRequest::rules()` returns `SystemConfigService::rulesFor($section)`, which builds
`['sometimes', ...$def['rules']]` per field. Every exposed text setting carries a bare `'string'` rule
(`webmail_url`, `dns_external_slave_fqdn`, `company_name`, `custom_login_text`, `custom_login_link`,
`default_remote_dbserver`, `mailmailinglist_url`, `ssh_authentication`, the dashboard URLs, `new_domain_html`, the
four name prefixes).

Laravel's default middleware converts an empty request string to `null` **before** validation. `'string'` rejects
`null`, so `{"ssh_authentication": ""}` is refused with *"The ssh authentication field must be a string."* An
administrator can set a value but never clear it.

`normalizeInput()` runs in `prepareForValidation()` but only touches values that are already strings
(`if (! isset($fields[$key]) || ! is_string($value)) continue;`), so a converted `null` passes straight through to
the failing rule. It is the natural place to fix this.

**Reproduced live** during the spec 037 verification: restoring `ssh_authentication` to its original empty value was
impossible through the API and had to be repaired with SQL.

## R2 — Which settings legacy lets an administrator blank

`admin/form/system_config.tform.php` contains exactly one `NOTEMPTY` validator in the whole form:
`web_php_options` (the PHP-mode checkbox array). Everything else is a plain text input, a select or a checkbox.

The four settings named in the request:

| Setting | Legacy definition | Blankable in legacy? |
|---|---|---|
| `webmail_url` | TEXT with a REGEX allowing zero length (`{0,255}`) | yes |
| `dns_external_slave_fqdn` | TEXT with STRIPTAGS + STRIPNL only | yes |
| `ssh_authentication` | SELECT whose first option is `''` | yes |
| `company_name` | TEXT with STRIPTAGS + STRIPNL only | yes |

**Decision**: every exposed **string** setting accepts an empty value; `web_php_options` keeps refusing one, matching
legacy exactly.

## R3 — Where to fix it

**Options**: (a) add `'nullable'` to every string rule in the field map; (b) coerce `null` back to `''` for string
fields in `normalizeInput()`; (c) disable Laravel's empty-string conversion for this route.

**Findings**: (a) changes 15+ rule lists and still stores `null`, which the blob writer would have to translate.
(c) is global-ish and would silently change behaviour for every other endpoint. (b) is one place, keeps the rules
honest (the value really is a string by the time they run), and matches the existing purpose of that method —
legacy's SAVE filters.

**Decision**: (b). `normalizeInput()` maps `null` to `''` for fields whose type is `string`, before trimming and
before the strip filters. Non-string types (`integer`, `yn`, `csv_array`) are untouched, so they keep refusing an
empty value.

## R4 — What "cleared" must look like in the blob

The merge writes `key=value` lines. ISPConfig's own save writes `key=` for an empty text field, and
`get_ini_string()` trims on write. Deleting the key instead would make the setting fall back to a code default,
which is a different behaviour from what the interface produces.

**Decision**: a cleared setting keeps its key with an empty value, and every unexposed key of the blob stays
byte-identical (the existing read-merge-write already guarantees the latter; the tests assert it).

## R5 — Both routes

`PUT /system/config` (whole document, `UpdateSystemConfigRequest`) and `PUT /system/config/{section}` share the field
map through `rulesFor($section, $prefix)` and `normalizeInput()`. Fixing the normalization covers both, provided the
whole-document request normalizes per section the same way — verified during implementation and covered by a test.

## R6 — Scope boundary

Per-server configuration (`server.config`, e.g. `nameservers` with its own NOTEMPTY-style rules) is a different
endpoint family and is out of scope. Settings the contract deliberately does not expose (`phpmyadmin_url`,
`webftp_url`, `client_protection`, `vhost_subdomains`) stay unexposed — spec 036 reads two of them through a
dedicated account endpoint instead.
