<?php

namespace App\Http\Requests;

use App\Models\WebDomain;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Shared rules for web-domain writes (api/modules/sites/web-domains.yaml;
 * legacy form/web_vhost_domain.tform.php + web_vhost_domain_edit.php).
 * Config-dependent checks (SNI, nginx rewrite rules, PHP-FPM pool
 * inequality, unique-key 409) live in WebDomainService.
 */
abstract class WebDomainRequest extends SitesRequest
{
    protected function booleanFields(): array
    {
        return [
            'cgi', 'ssi', 'suexec', 'perl', 'ruby', 'python',
            'enable_pagespeed', 'active', 'rewrite_to_https', 'ssl',
            'ssl_letsencrypt', 'ssl_letsencrypt_exclude', 'proxy_protocol',
            'php_fpm_use_socket', 'php_fpm_chroot', 'disable_symlinknotowner',
            'delete_unused_jailkit',
        ];
    }

    protected function normalizesDomain(): bool
    {
        return true;
    }

    /**
     * Rules identical between store and update.
     *
     * @return array<string, mixed>
     */
    protected function commonRules(): array
    {
        return [
            'ip_address' => ['sometimes', 'nullable', 'string', 'max:39'],
            'ipv6_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'vhost_type' => ['sometimes', Rule::in(['name', 'ip'])],
            'web_folder' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:@^((?!(.*\.\.)|(.*\./)|(.*//))[^/][\w/_\.\-]{1,100})?$@'],
            'traffic_quota' => ['sometimes', ...$this->quotaRules()],
            'cgi' => ['sometimes', 'boolean'],
            'ssi' => ['sometimes', 'boolean'],
            'suexec' => ['sometimes', 'boolean'],
            'errordocs' => ['sometimes', 'integer', Rule::in([0, 1])],
            'subdomain' => ['sometimes', Rule::in(['none', 'www', '*'])],
            'php' => ['sometimes', Rule::in(['no', 'fast-cgi', 'cgi', 'mod', 'suphp', 'php-fpm', 'hhvm'])],
            'server_php_id' => ['sometimes', 'integer', 'min:0'],
            'perl' => ['sometimes', 'boolean'],
            'ruby' => ['sometimes', 'boolean'],
            'python' => ['sometimes', 'boolean'],
            'enable_pagespeed' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'directive_snippets_id' => ['sometimes', 'integer', 'min:0'],
            'redirect_type' => ['sometimes', 'nullable', Rule::in(['', 'no', 'R', 'L', 'R,L', 'R=301,L', 'last', 'break', 'redirect', 'permanent', 'proxy'])],
            'redirect_path' => [
                'sometimes', 'nullable', 'string', 'max:255',
                'regex:@^(([\.]{0})|((ftp|https?|\[scheme\])://([-\w\.]+)+(:\d+)?(/([\w/_\.\,\-\+\?\~!:%]*(\?\S+)?)?)?)(?:#\S*)?|(/(?!.*\.\.)[\w/_\.\-]{1,255}/))$@',
                $this->proxyRequiresUrlRule(),
            ],
            'seo_redirect' => ['sometimes', 'nullable', Rule::in(['', 'non_www_to_www', 'www_to_non_www', '*_domain_tld_to_domain_tld', '*_domain_tld_to_www_domain_tld', '*_to_domain_tld', '*_to_www_domain_tld'])],
            'rewrite_rules' => ['sometimes', 'nullable', 'string'],
            'rewrite_to_https' => ['sometimes', 'boolean'],
            'ssl' => ['sometimes', 'boolean'],
            'ssl_letsencrypt' => ['sometimes', 'boolean'],
            'ssl_letsencrypt_exclude' => ['sometimes', 'boolean'],
            'ssl_state' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ssl_locality' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ssl_organisation' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ssl_organisation_unit' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ssl_country' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ssl_domain' => ['sometimes', 'nullable', 'string', 'max:255'],
            'stats_type' => ['sometimes', 'nullable', Rule::in(['awstats', 'goaccess', 'webalizer', ''])],
            'stats_password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'backup_interval' => ['sometimes', Rule::in(['none', 'daily', 'weekly', 'monthly'])],
            'backup_copies' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'backup_excludes' => ['sometimes', 'nullable', 'string', 'regex:@^(?!.*\.\.)[-a-zA-Z0-9_/.~,*]*$@'],
            'allow_override' => ['sometimes', 'string', 'max:255'],
            'proxy_protocol' => ['sometimes', 'boolean'],
            'php_fpm_use_socket' => ['sometimes', 'boolean'],
            'php_fpm_chroot' => ['sometimes', 'boolean'],
            'pm' => ['sometimes', Rule::in(['static', 'dynamic', 'ondemand'])],
            'pm_max_children' => ['sometimes', 'integer', 'min:1'],
            'pm_start_servers' => ['sometimes', 'integer', 'min:1'],
            'pm_min_spare_servers' => ['sometimes', 'integer', 'min:1'],
            'pm_max_spare_servers' => ['sometimes', 'integer', 'min:1'],
            'pm_process_idle_timeout' => ['sometimes', 'integer', 'min:1'],
            'pm_max_requests' => ['sometimes', 'integer', 'min:0'],
            'disable_symlinknotowner' => ['sometimes', 'boolean'],
            'php_open_basedir' => ['sometimes', 'nullable', 'string'],
            'custom_php_ini' => ['sometimes', 'nullable', 'string', $this->customPhpIniRule()],
            'apache_directives' => ['sometimes', 'nullable', 'string'],
            'nginx_directives' => ['sometimes', 'nullable', 'string'],
            'proxy_directives' => ['sometimes', 'nullable', 'string'],
            'http_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'https_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'log_retention' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'jailkit_chroot_app_sections' => ['sometimes', 'nullable', 'string', 'regex:/^[a-zA-Z0-9\-\_\ ]*$/'],
            'jailkit_chroot_app_programs' => ['sometimes', 'nullable', 'string', 'regex:/^[a-zA-Z0-9\.\-\_\/\ ]*$/'],
            'delete_unused_jailkit' => ['sometimes', 'boolean'],
            'sys_groupid' => ['sometimes', 'integer', Rule::exists('sys_group', 'groupid')],
        ];
    }

    /**
     * Legacy validate_domain::_regex_validate (no wildcard) + the
     * .acme.invalid rejection.
     */
    protected function webDomainFormatRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! preg_match('/^[\w\.\-]{1,255}\.[a-zA-Z0-9\-]{2,63}$/', $value)) {
                $fail('The :attribute must be a valid domain name.');

                return;
            }

            if (preg_match('/\.acme\.invalid$/', $value)) {
                $fail('The :attribute must not end in .acme.invalid.');
            }
        };
    }

    /**
     * Legacy: redirect_type=proxy requires a URL, not a path.
     */
    protected function proxyRequiresUrlRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $type = $this->input('redirect_type');

            if ($type === 'proxy' && is_string($value) && str_starts_with($value, '/')) {
                $fail('A proxy redirect requires a URL, not a path.');
            }
        };
    }

    /**
     * Legacy custom php.ini line-by-line whitelist.
     */
    protected function customPhpIniRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && ! WebDomain::validateCustomPhpIni($value)) {
                $fail('The :attribute contains invalid php.ini settings.');
            }
        };
    }

    /**
     * hd_quota may never be 0 for type=vhost (legacy
     * limit_web_quota_not_0).
     */
    protected function vhostQuotaNotZeroRule(Closure $resolveType): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($resolveType): void {
            if ((int) $value === 0 && $resolveType() === 'vhost') {
                $fail('The :attribute must not be 0 for a vhost (use -1 for unlimited).');
            }
        };
    }
}
