# Data Model: Account Capabilities for Scoped Keys

No tables or migrations. Both resources are derived per request (read-only).

## AccountCapabilities (`api/components/schemas/AccountCapabilities.yaml`)

| Field | Type | Source |
|---|---|---|
| `client_id` | integer | target client (R1) |
| `account_type` | `client` \| `reseller` | `client.limit_client != 0` → reseller |
| `locked` | boolean | `client.locked = 'y'` |
| `canceled` | boolean | `client.canceled = 'y'` |
| `web.ssl` | boolean | `client.limit_ssl = 'y'` |
| `web.ssl_letsencrypt` | boolean | `client.limit_ssl_letsencrypt = 'y'` |
| `web.wildcard` | boolean | `client.limit_wildcard = 'y'` |
| `web.cgi` / `ssi` / `perl` / `ruby` / `python` | boolean | `client.limit_cgi` / `limit_ssi` / `limit_perl` / `limit_ruby` / `limit_python` = `'y'` |
| `web.error_documents` | boolean | `client.limit_hterror = 'y'` |
| `web.directive_snippets` | boolean | `client.limit_directive_snippets = 'y'` |
| `web.suexec_forced` | boolean | `client.force_suexec = 'y'` |
| `web.backup` | boolean | `client.limit_backup = 'y'` |
| `web.advanced_options` | boolean | reseller and `sys_ini [sites] reseller_can_use_options = 'y'` |
| `web.php_modes` | string[] | `sys_ini [sites] web_php_options` ∩ `client.web_php_options` (client order; empty system list → client list) |
| `web.php_default_mode` | string | `fast-cgi` if in `php_modes`, else first mode ≠ `no`, else `no` |

All values come from `WebPermissionService::forClient()` (feature 020's derivation).

## AccountPhpVersion (`api/components/schemas/AccountPhpVersion.yaml`)

| Field | Type | Source |
|---|---|---|
| `id` | integer | `server_php.server_php_id`; `0` for the default entry |
| `name` | string | `server_php.name`; default entry: server `[web] php_default_name` or `Default` |
| `server_id` | integer | considered server (R5) |
| `modes` | string[] (`php-fpm`, `fast-cgi`) | allowed version modes the version supports (nginx: FPM columns for both); default entry: all considered modes |
| `is_default` | boolean | `true` only for the id 0 entry |

**Selection**: `active = 'y'`, `server_id` considered, `client_id IN (0, target)`, at least one considered mode
supported. **Order**: server (assigned order, then hosting servers by id) → default entry → `sortprio` → id.
**List**: `{data: AccountPhpVersion[], meta: {total, limit, offset}}`.

## Validation messages

| Case | Status | Field | Message |
|---|---|---|---|
| unknown query parameter | 400 | — | `Unknown parameter '<name>'. Allowed: <list>.` |
| `limit` / `offset` invalid | 400 | — | existing list messages |
| `client_id` not a positive integer | 422 | `client_id` | `The client id must be a positive integer.` |
| admin key without `client_id` | 422 | `client_id` | `The client id is required for admin keys.` |
| target client unknown / not visible | 404 | — | not found problem |
| `server_id` not a positive integer | 422 | `server_id` | `The server id must be a positive integer.` |
| `server_id` not a web server of the account | 422 | `server_id` | `The selected server is not a web server of this account.` |
| `mode` not `php-fpm` / `fast-cgi` | 422 | `mode` | `The mode must be php-fpm or fast-cgi.` |
