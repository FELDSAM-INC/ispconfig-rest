# Data Model: Web Permission Enforcement for Scoped Keys

No new tables or columns. Derived per request.

## AccountWebPermissions (value array, `WebPermissionService::forScope()`)

| Key | Source | Meaning |
|---|---|---|
| `client_id` | `AuthScope::$clientId` | acting account's client (0 = none) |
| `is_reseller` | `AuthScope::isReseller()` | reseller scope |
| `flags.ssl` … `flags.directive_snippets` | `client.limit_ssl`, `limit_ssl_letsencrypt`, `limit_cgi`, `limit_ssi`, `limit_perl`, `limit_ruby`, `limit_python`, `limit_hterror`, `limit_wildcard`, `limit_directive_snippets` = `'y'` | option included in plan |
| `force_suexec` | `client.force_suexec = 'y'` | suEXEC forced on |
| `php_modes` | `sys_ini [sites] web_php_options` ∩ `client.web_php_options` (empty system list → client list) | allowed PHP modes, client list order |
| `advanced_options` | reseller and `sys_ini [sites] reseller_can_use_options = 'y'` | Options-tab fields writable |

Missing client row: every flag false, `force_suexec` false, `php_modes` = [].

## Field classes (`WebPermissionService`)

| Class | Fields | Rule |
|---|---|---|
| Plan flags | `ssl`, `ssl_letsencrypt`, `cgi`, `ssi`, `perl`, `ruby`, `python` (bool, forbidden value `true`); `errordocs` (forbidden `1`); `directive_snippets_id` (forbidden non-zero); `subdomain` (forbidden `'*'`); `suexec` (forbidden `false` when forced) | 422 when sent (create) / changed (update) to the forbidden value; forced value stored on every save |
| PHP mode | `php` | 422 when not in `php_modes` and sent (create) / changed (update); create default per FR-003 |
| PHP version | `server_php_id` | 422 when non-zero and unusable (R3) or explicit 0 with hidden default (R4); default first usable when hidden |
| Options tab | `allow_override`, `proxy_protocol`, `php_fpm_use_socket`, `php_fpm_chroot`, `pm`, `pm_max_children`, `pm_start_servers`, `pm_min_spare_servers`, `pm_max_spare_servers`, `pm_process_idle_timeout`, `pm_max_requests`, `disable_symlinknotowner`, `php_open_basedir`, `custom_php_ini`, `apache_directives`, `nginx_directives`, `proxy_directives`, `http_port`, `https_port`, `log_retention`, `jailkit_chroot_app_sections`, `jailkit_chroot_app_programs`, `delete_unused_jailkit` | 422 when different from stored/default unless `advanced_options` |
| SSL tab | `ssl_state`, `ssl_locality`, `ssl_organisation`, `ssl_organisation_unit`, `ssl_country`, `ssl_domain` | 422 when different from stored/default unless `flags.ssl` |
| Identity (plain client, existing vhost) | `domain`, `ip_address`, `ipv6_address`, `vhost_type` | 422 when changed |

## UsablePhpVersion (`PhpVersionService::usable()`)

Row of `server_php` (`server_php_id`, `server_id`, `client_id`, `name`, `sortprio`, mode binaries) with
`active = 'y'`, `server_id = :server`, `client_id IN (0, :clients…)`, mode conditions:

- `php-fpm`: `php_fpm_init_script`, `php_fpm_ini_dir`, `php_fpm_pool_dir` non-empty
- `fast-cgi`: `php_fastcgi_binary`, `php_fastcgi_ini_dir` non-empty

Ordered by `sortprio`, `server_php_id`. Server `[web]` config: `php_default_hide` (`y` hides id 0),
`php_default_name`, `server_type`.

## Messages (422 `errors.<field>[0]`, 403 `detail`)

| Case | Message |
|---|---|
| Plan flag | `The :label option is not included in the account's plan.` (labels: SSL, Let's Encrypt, CGI, SSI, Perl, Ruby, Python, custom error documents, wildcard subdomains, directive snippets) |
| Forced suEXEC | `suEXEC is required by the account's plan.` |
| PHP mode | `The selected PHP mode is not available for this account.` |
| PHP version | `The selected PHP version is not available for this website.` |
| Hidden default | `A PHP version must be selected for this website.` |
| Options/SSL tab | `The :attribute setting can only be changed by an administrator.` |
| Identity | `The :attribute of this website cannot be changed by this account.` |
| Wildcard on child | `Wildcard subdomains are not available for this website type.` |
| SSL operation 403 | `SSL certificates are not included in the account's plan.` / `Let's Encrypt certificates are not included in the account's plan.` |
