# Contract: `GET /me/capabilities`, `GET /me/php-versions`

Source of truth after implementation: `api/modules/me/capabilities.yaml`, `api/modules/me/php-versions.yaml`,
`api/components/schemas/AccountCapabilities.yaml`, `api/components/schemas/AccountPhpVersion.yaml`.

## GET /api/v1/me/capabilities

Query: `client_id` (integer ≥ 1; required for admin keys).

200:

```json
{
  "client_id": 42,
  "account_type": "client",
  "locked": false,
  "canceled": false,
  "web": {
    "ssl": true,
    "ssl_letsencrypt": true,
    "wildcard": false,
    "cgi": false,
    "ssi": false,
    "perl": false,
    "ruby": false,
    "python": false,
    "error_documents": false,
    "directive_snippets": false,
    "suexec_forced": true,
    "backup": true,
    "advanced_options": false,
    "php_modes": ["no", "php-fpm"],
    "php_default_mode": "php-fpm"
  }
}
```

Errors: 400 unknown parameter; 401; 404 target client not visible/unknown; 422 `client_id`.

## GET /api/v1/me/php-versions

Query: `client_id` (as above), `server_id` (integer ≥ 1, a web server of the account), `mode` (`php-fpm` |
`fast-cgi`), `limit` (1–100, default 25), `offset` (≥ 0).

200 (server 1 does not hide the default, account allows `php-fpm` and `fast-cgi`):

```json
{
  "data": [
    {"id": 0, "name": "Default", "server_id": 1, "modes": ["php-fpm", "fast-cgi"], "is_default": true},
    {"id": 7, "name": "PHP 8.3", "server_id": 1, "modes": ["php-fpm"], "is_default": false},
    {"id": 6, "name": "PHP 8.2", "server_id": 1, "modes": ["php-fpm", "fast-cgi"], "is_default": false}
  ],
  "meta": {"total": 3, "limit": 25, "offset": 0}
}
```

Errors: 400 unknown parameter or invalid `limit`/`offset`; 401; 404 target client; 422 `client_id`, `server_id`,
`mode`.

## Consumer mapping (WHMCS module spec 003)

| Module field | API field |
|---|---|
| web flags `ssl`, `ssl_letsencrypt`, `wildcard` | `web.ssl`, `web.ssl_letsencrypt`, `web.wildcard` |
| `php_modes` | `web.php_modes` |
| `locked` | `locked` |
| `data[].id, name` (`?server_id={id}`) | `data[].id`, `data[].name` (+ `modes`, `is_default`, `server_id`) |
