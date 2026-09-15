# Research: Web Permission Enforcement for Scoped Keys

Legacy source: ISPConfig 3.3.1p1 on isp-test.feldhost.cz (`/usr/local/ispconfig/interface`), read 2026-09-15.
Live data: `client` column defaults, `sys_ini [sites]`, server 1 `[web]`.

## R1 — Plan flags forced on every non-admin save

**Legacy**: `sites/web_vhost_domain_edit.php:979-996` — for `typ != 'admin'` (clients and resellers) the logged-in
user's own client row (`sys_group.groupid = default_group`) is read and, on every submit of any tab:

| Client column | Forced value | Legacy line |
|---|---|---|
| `limit_cgi != 'y'` | `cgi = 'n'` | 986 |
| `limit_ssi != 'y'` | `ssi = 'n'` | 987 |
| `limit_perl != 'y'` | `perl = 'n'` | 988 |
| `limit_ruby != 'y'` | `ruby = 'n'` | 989 |
| `limit_python != 'y'` | `python = 'n'` | 990 |
| `force_suexec == 'y'` | `suexec = 'y'` | 991 |
| `limit_hterror != 'y'` | `errordocs = 'n'` (int column → 0) | 992 |
| `limit_wildcard != 'y'` and `subdomain == '*'` | `subdomain = 'n'` (invalid value) | 993 |
| `limit_ssl != 'y'` | `ssl = 'n'` | 994 |
| `limit_ssl_letsencrypt != 'y'` | `ssl_letsencrypt = 'n'` | 995 |
| `limit_directive_snippets != 'y'` | `directive_snippets_id = 0` | 996 |

The wildcard line only triggers when the submitted record carries `subdomain = '*'`.
Live defaults (`information_schema`): all `limit_*` flags `'n'`, `force_suexec 'y'`, `limit_backup 'y'`,
`web_php_options 'no,fast-cgi,cgi,mod,suphp,php-fpm,hhvm'`.

**Decision**: `WebPermissionService::forcedAttributes()` returns these raw values for client and reseller scopes and
`WebDomainService` merges them into the record before insert/update (same datalog entry). A missing client row
yields every flag "not included" and suEXEC not forced (the legacy query returns null). Wildcard: an explicit
`'*'` is refused (R8), so the invalid `'n'` is never written (owner-delegated deviation).

## R2 — Allowed PHP modes

**Legacy**: `form/web_vhost_domain.tform.php:254` `'valuelimit' => 'system:sites:web_php_options;client:web_php_options'`;
`lib/classes/tform_base.inc.php:339-430 applyValueLimit()` filters the select options by each limit in turn (system
list, then — for non-admins — the client list), always keeping the current value. Used for rendering only; submit does
not re-check.

**Decision**: allowed modes = system list ∩ client list for client and reseller scopes. An empty or missing system
key does not restrict (legacy would offer only the current value; owner-delegated). Enforced server-side (422) when
`php` is sent on create or changed on update. Omitted on create: `fast-cgi` if allowed, else the first allowed mode
other than `no` in the client list order, else `no`.

## R3 — PHP versions for clients and the mode reset

**Legacy UI list** (`web_vhost_domain_edit.php:247-258`, `ajax_get_json.php:66-120`):

- php-fpm (and hhvm on nginx): `php_fpm_init_script != '' AND php_fpm_ini_dir != '' AND php_fpm_pool_dir != ''`
- fast-cgi: `php_fastcgi_binary != '' AND php_fastcgi_ini_dir != ''`
- `server_id = <website server>` (new vhost: client's default web server; child types: parent's server),
  `(client_id = 0 OR client_id = <logged-in client>)`, `active = 'y'`, `ORDER BY sortprio`.

**Legacy submit** (`web_vhost_domain_edit.php:1286-1304`, all users): non-zero `server_php_id` is reset to 0 when the
version is inactive or lacks the mode's binary (`php_fpm_init_script` / `php_fastcgi_binary`), and always for modes
other than `php-fpm`/`fast-cgi`. The server and client are not re-checked on submit.

**Decision**: for client and reseller scopes a non-zero `server_php_id` sent on create, changed on update, or kept
while `php` changes must be in `PhpVersionService::usable(serverId, clientIds, mode)` with the UI-list conditions
(client ids: 0, the acting account's client, the website owner's client); otherwise 422. The existing
`resolveServerPhpId()` mode reset stays for all keys (FR-005). Ordering: `sortprio`, then `server_php_id`.

## R4 — Hidden default PHP version

**Legacy**: `web_vhost_domain_edit.php:1507-1546 validateDefaultFastcgiPhpVersion()` (all users): when the server's
`[web] php_default_hide = y` and PHP is enabled, an empty version is an error, except once-confirmed for websites
already on the default. The UI list then starts with the first real version, so an untouched form submits it.
isp-test server 1 has `php_default_hide = y`, `php_default_name = Default`, versions 1–5 all `sortprio 100`.

**Decision** (client and reseller scopes): explicit `server_php_id = 0` sent on create or changed on update with
`php` in `php-fpm`/`fast-cgi` → 422; a resulting 0 otherwise is replaced by the first usable version for the mode
(UI parity); none usable → 422 on `server_php_id`. Admin keys unchanged (no confirmation equivalent).

## R5 — Options and SSL tabs

**Legacy tform**: `web_vhost_domain.tform.php:791-795` the `advanced` ("Options") tab exists only for admins or for
resellers when `sites.reseller_can_use_options = y` (isp-test: `n`). Its fields (lines 803-1102): `document_root`,
`system_user`, `system_group`, `allow_override`, `proxy_protocol`, `php_fpm_use_socket`, `php_fpm_chroot`, `pm`,
`pm_max_children`, `pm_start_servers`, `pm_min_spare_servers`, `pm_max_spare_servers`, `pm_process_idle_timeout`,
`pm_max_requests`, `disable_symlinknotowner`, `php_open_basedir`, `custom_php_ini`, `apache_directives`,
`nginx_directives`, `proxy_directives`, `added_date`, `added_by`, `http_port`, `https_port`, `log_retention`,
`jailkit_chroot_app_sections`, `jailkit_chroot_app_programs`, `delete_unused_jailkit`. Fields not in the form
definition are ignored on submit. The `ssl` tab (lines 446-600: `ssl_state`, `ssl_locality`, `ssl_organisation`,
`ssl_organisation_unit`, `ssl_country`, `ssl_domain`, `ssl_key`, `ssl_request`, `ssl_cert`, `ssl_bundle`,
`ssl_action`) exists only when `limit_ssl = y` (lines 88-95). `stats` and `redirect` tabs are always available.

**Decision**: the API-writable Options fields (the list above minus fields the API never accepts) are refused with 422
when their value differs from the stored (update) or model default (create) value; the SSL-tab body fields likewise
when `limit_ssl != y`. Certificate upload/delete (`/ssl`) return 403 without `limit_ssl`, renew additionally requires
`limit_ssl_letsencrypt`. Reading `/ssl` stays allowed (own data; owner-delegated).

## R6 — Domain tab read-only for plain clients

**Legacy**: `web_vhost_domain.tform.php:78-84` `readonly` domain tab for users without clients (not resellers) when
editing type `domain` (vhost); `readonly` is a template flag only (`tform_base.inc.php:1583`), submit is not blocked.
Wildcard `*` is removed from the subdomain options for child types and when `limit_wildcard != y` (lines 86-95, 236).

**Decision** (owner-delegated): enforce read-only only for the identity fields `domain`, `ip_address`,
`ipv6_address`, `vhost_type` (plain client keys, existing vhosts, 422 on change). Plan-bounded settings on that tab
(PHP, quotas, SSL flags, `active`, `subdomain`) stay writable within the plan so the panel can offer self-service;
resellers keep full access as in legacy. `subdomain = '*'` refused for `vhostsubdomain`/`vhostalias` (all scoped keys).

## R7 — Whose limits apply

**Legacy**: the logged-in user's `default_group` → own client row, also when a reseller edits a client's website.

**Decision**: `AuthScope::$clientId` (acting account). Plain client = `! isAdmin && ! isReseller()`.

## R8 — Change detection

**Decision**: create — every sent value of plan flags, `php`, `server_php_id` is checked; Options/SSL-tab fields only
when different from the model default (`WebDomain::$attributes`), so consumers echoing defaults are not refused.
Update — a field is checked only when its normalized value differs from the stored raw value (booleans vs `y`/`n`,
integers vs numeric strings, `null` vs `''`). Several violations are returned together.

## Alternatives considered

- Silent clearing only (pure legacy): rejected for explicit requests — consumers would believe the option is on.
- Refusing unchanged forbidden values: rejected — breaks full-object PUTs after plan downgrades; forcing handles them.
- Checking the website owner's plan for reseller keys: rejected — legacy uses the logged-in account.
