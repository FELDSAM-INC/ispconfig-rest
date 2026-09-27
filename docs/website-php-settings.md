# Website PHP settings

`GET /sites/web-domains/{id}` includes `php_settings` for primary websites and
vhost children. `PUT` accepts a partial `php_settings` object with the eight
editable properties documented in `WebPhpSettings.yaml`. It uses the customer's
normal website permissions and ISPConfig datalog. Raw PHP configuration retains
its existing administrator-only permissions.

The read-only limits are **actual configured values**, not the UI example limits.
The web log/runtime worker reads the selected server PHP version's root-owned
`php.ini` and adjacent `conf.d/*.ini`, in lexical order, into an API-owned snapshot.
It returns only the allowlisted directives, never the complete INI or credentials.
It does not run PHP applications, create public diagnostic scripts, or use the
REST server's own `ini_get()` values. Snapshots expire after 150 seconds. An old,
missing or stale worker leaves controls read-only and unknown values unavailable.

PHP 8.5 includes OPcache in the binary. The worker checks the configured root-owned
native CGI binary using `-n -v` as `nobody`, with an empty PHP scan path and a bounded
timeout/output. No INI, website code or shell wrapper is executed. The matching FPM
configuration in the same version directory uses that build capability; standalone
FPM configurations still detect OPcache from their INI extension entries. Unknown
capabilities remain unavailable. See the [PHP RFC](https://wiki.php.net/rfc/make_opcache_required).

Supported handlers are Apache CGI/FastCGI and PHP-FPM (including nginx's
`fast-cgi` selection, which ISPConfig implements using FPM). For custom PHP
installations the configured INI directory must contain `php.ini`; additional
INI files use its adjacent `conf.d` directory. This describes server and website
configuration, before application or `.user.ini` overrides. Per-host/path INI
sections are not flattened into misleading website-wide values.

The integration preserves unrelated directives and the other disabled functions.
`opcache_get_status` is a function allow/block control, not an INI directive.
PHP-FPM appends pool restrictions to globally disabled functions, so a website
cannot enable that function when the global configuration blocks it.
PHP-FPM startup configuration also prevents enabling OPcache per website when
it is globally disabled. Those settings are displayed read-only. Required
ISPConfig PHP snippets and later CGI scan directives remain authoritative, and
affected controls become read-only. No web-server directives or arbitrary INI
names can be supplied through the structured settings.

Reference implementation was checked against the installed ISPConfig Apache/nginx
plugins and `php_fpm_pool.conf.master` on development. Both plugins append required
PHP snippets after custom PHP settings. PHP-FPM's global function restriction is
documented in [the PHP manual](https://www.php.net/manual/en/install.fpm.configuration.php).

Deployment: run the API migration with a privileged database login (the runtime
ISPConfig account normally lacks CREATE permission), then reinstall `web-log-worker/install.sh` on
each web server from a root-owned checkout/staging directory. The worker adds
`api_web_php_defaults` snapshots; no ISPConfig schema is changed. Existing PHP
limits and settings are unchanged until a customer saves an editable value.

Runtime acceptance checks use a disposable PHP 8.4 CGI/FPM container:

```sh
docker build -t ispcp-php-settings-test tests/Integration/web-php-settings
docker run --rm -v "$PWD/tests/Integration/web-php-settings/check.php:/fixture.php:ro" ispcp-php-settings-test php /fixture.php
```

The fixture verifies inherited limits, editable booleans, the literal error-reporting
expression and both directions of the function toggle while preserving other blocked functions.
