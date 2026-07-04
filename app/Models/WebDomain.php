<?php

namespace App\Models;

use App\Casts\YesNoBoolean;
use App\Models\Concerns\HasSitesDisplayFields;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * web_domain vhost types — vhost / vhostsubdomain / vhostalias (contract:
 * api/components/schemas/WebDomain.yaml; legacy:
 * source_code/interface/web/sites/form/web_vhost_domain.tform.php).
 *
 * Subdomains and alias domains live in the same table with type
 * subdomain/alias and are served by the WebChildDomain model — the global
 * scope here keeps the two resources disjoint (a child domain id 404s on
 * the web-domains endpoints and vice versa).
 */
class WebDomain extends BaseModel
{
    use HasSitesDisplayFields;

    public const VHOST_TYPES = ['vhost', 'vhostsubdomain', 'vhostalias'];

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'web_domain';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'domain_id';

    protected static function booted(): void
    {
        static::addGlobalScope('vhostTypes', function ($query): void {
            $query->whereIn('type', self::VHOST_TYPES);
        });
    }

    /**
     * Writable fields per the contract. document_root / system_user /
     * system_group / added_date / added_by / log fields are server-derived
     * (WebDomainService); ssl certificate material is managed by the
     * /ssl subresource only. sys_groupid is writable on this resource
     * ("supply a client group to assign the site to a client").
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'server_id', 'ip_address', 'ipv6_address', 'domain', 'type',
        'parent_domain_id', 'vhost_type', 'web_folder', 'hd_quota',
        'traffic_quota', 'cgi', 'ssi', 'suexec', 'errordocs', 'subdomain',
        'php', 'server_php_id', 'perl', 'ruby', 'python', 'enable_pagespeed',
        'active', 'directive_snippets_id', 'redirect_type', 'redirect_path',
        'seo_redirect', 'rewrite_rules', 'rewrite_to_https', 'ssl',
        'ssl_letsencrypt', 'ssl_letsencrypt_exclude', 'ssl_state',
        'ssl_locality', 'ssl_organisation', 'ssl_organisation_unit',
        'ssl_country', 'ssl_domain', 'stats_type', 'stats_password',
        'backup_interval', 'backup_copies', 'backup_excludes',
        'allow_override', 'proxy_protocol', 'php_fpm_use_socket',
        'php_fpm_chroot', 'pm', 'pm_max_children', 'pm_start_servers',
        'pm_min_spare_servers', 'pm_max_spare_servers',
        'pm_process_idle_timeout', 'pm_max_requests',
        'disable_symlinknotowner', 'php_open_basedir', 'custom_php_ini',
        'apache_directives', 'nginx_directives', 'proxy_directives',
        'http_port', 'https_port', 'log_retention',
        'jailkit_chroot_app_sections', 'jailkit_chroot_app_programs',
        'delete_unused_jailkit', 'sys_groupid',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'server_id' => 'integer',
        'parent_domain_id' => 'integer',
        'hd_quota' => 'integer',
        'traffic_quota' => 'integer',
        'errordocs' => 'integer',
        'server_php_id' => 'integer',
        'directive_snippets_id' => 'integer',
        'backup_copies' => 'integer',
        'pm_max_children' => 'integer',
        'pm_start_servers' => 'integer',
        'pm_min_spare_servers' => 'integer',
        'pm_max_spare_servers' => 'integer',
        'pm_process_idle_timeout' => 'integer',
        'pm_max_requests' => 'integer',
        'http_port' => 'integer',
        'https_port' => 'integer',
        'log_retention' => 'integer',
        'sys_userid' => 'integer',
        'sys_groupid' => 'integer',
        'cgi' => YesNoBoolean::class,
        'ssi' => YesNoBoolean::class,
        'suexec' => YesNoBoolean::class,
        'perl' => YesNoBoolean::class,
        'ruby' => YesNoBoolean::class,
        'python' => YesNoBoolean::class,
        'enable_pagespeed' => YesNoBoolean::class,
        'active' => YesNoBoolean::class,
        'rewrite_to_https' => YesNoBoolean::class,
        'ssl' => YesNoBoolean::class,
        'ssl_letsencrypt' => YesNoBoolean::class,
        'ssl_letsencrypt_exclude' => YesNoBoolean::class,
        'proxy_protocol' => YesNoBoolean::class,
        'php_fpm_use_socket' => YesNoBoolean::class,
        'php_fpm_chroot' => YesNoBoolean::class,
        'disable_symlinknotowner' => YesNoBoolean::class,
        'delete_unused_jailkit' => YesNoBoolean::class,
    ];

    /**
     * writeOnly password, ssl certificate material (subresource-only),
     * and DB columns the contract does not expose.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'domain_id',
        'stats_password',
        'ssl_request', 'ssl_cert', 'ssl_bundle', 'ssl_key', 'ssl_action',
        'is_subdomainwww', 'folder_directive_snippets',
        'backup_format_web', 'backup_format_db', 'backup_encrypt',
        'backup_password', 'traffic_quota_lock', 'last_quota_notification',
        'last_jailkit_update', 'last_jailkit_hash',
    ];

    /**
     * @var array<int, string>
     */
    protected $appends = [
        'id',
        'server_name',
    ];

    /**
     * Raw column defaults mirroring the legacy tform + the contract's
     * documented defaults ($attributes bypasses casts — DB-native values).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'vhost',
        'parent_domain_id' => 0,
        'vhost_type' => 'name',
        'hd_quota' => -1,
        'traffic_quota' => -1,
        'cgi' => 'n',
        'ssi' => 'n',
        'suexec' => 'y',
        'errordocs' => 1,
        'subdomain' => 'www',
        'php' => 'fast-cgi',
        'server_php_id' => 0,
        'perl' => 'n',
        'ruby' => 'n',
        'python' => 'n',
        'enable_pagespeed' => 'n',
        'active' => 'y',
        'directive_snippets_id' => 0,
        'redirect_type' => '',
        'seo_redirect' => '',
        'rewrite_to_https' => 'n',
        'ssl' => 'n',
        'ssl_letsencrypt' => 'n',
        'ssl_letsencrypt_exclude' => 'n',
        'stats_type' => 'awstats',
        'backup_interval' => 'none',
        'backup_copies' => 1,
        'allow_override' => 'All',
        'proxy_protocol' => 'n',
        'php_fpm_use_socket' => 'y',
        'php_fpm_chroot' => 'n',
        'pm' => 'ondemand',
        'pm_max_children' => 10,
        'pm_start_servers' => 2,
        'pm_min_spare_servers' => 1,
        'pm_max_spare_servers' => 5,
        'pm_process_idle_timeout' => 10,
        'pm_max_requests' => 0,
        'disable_symlinknotowner' => 'n',
        'http_port' => 80,
        'https_port' => 443,
        'delete_unused_jailkit' => 'n',
    ];

    /**
     * The contract exposes the primary key as `id`.
     */
    protected function id(): Attribute
    {
        return Attribute::get(fn () => $this->getKey());
    }

    /**
     * Response-only convenience field (schema `server_name`).
     */
    protected function serverName(): Attribute
    {
        return Attribute::get(fn () => $this->lookupServerName((int) ($this->getAttributes()['server_id'] ?? 0)));
    }

    /**
     * Line-by-line whitelist for nginx rewrite rules, ported verbatim from
     * web_vhost_domain_edit.php:1174-1228. Returns true when every
     * non-comment line matches an allowed directive and braces balance.
     */
    public static function validateRewriteRules(string $rules): bool
    {
        $rules = trim($rules);

        if ($rules === '') {
            return true;
        }

        $ifLevel = 0;
        $rules = str_replace(["\r\n", "\r"], "\n", $rules);

        foreach (explode("\n", $rules) as $line) {
            if (substr(ltrim($line), 0, 1) === '#') {
                continue;
            }
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('@^\s*rewrite\s+(^/)?\S+(\$)?\s+\S+(\s+(last|break|redirect|permanent|))?\s*;\s*$@', $line)) {
                continue;
            }
            if (preg_match('@^\s*rewrite\s+(^/)?(\'[^\']+\'|"[^"]+")+(\$)?\s+(\'[^\']+\'|"[^"]+")+(\s+(last|break|redirect|permanent|))?\s*;\s*$@', $line)) {
                continue;
            }
            if (preg_match('@^\s*rewrite\s+(^/)?(\'[^\']+\'|"[^"]+")+(\$)?\s+\S+(\s+(last|break|redirect|permanent|))?\s*;\s*$@', $line)) {
                continue;
            }
            if (preg_match('@^\s*rewrite\s+(^/)?\S+(\$)?\s+(\'[^\']+\'|"[^"]+")+(\s+(last|break|redirect|permanent|))?\s*;\s*$@', $line)) {
                continue;
            }
            if (preg_match('@^\s*if\s+\(\s*\$\S+(\s+(\!?(=|~|~\*))\s+(\S+|\".+\"))?\s*\)\s*\{\s*$@', $line)) {
                $ifLevel += 1;

                continue;
            }
            if (preg_match('@^\s*if\s+\(\s*\!?-(f|d|e|x)\s+\S+\s*\)\s*\{\s*$@', $line)) {
                $ifLevel += 1;

                continue;
            }
            if (preg_match('@^\s*break\s*;\s*$@', $line)) {
                continue;
            }
            if (preg_match('@^\s*return\s+\d\d\d.*;\s*$@', $line)) {
                continue;
            }
            if (preg_match('@^\s*return(\s+\d\d\d)?\s+(http|https|ftp)\://([a-zA-Z0-9\.\-]+(\:[a-zA-Z0-9\.&%\$\-]+)*\@)*((25[0-5]|2[0-4][0-9]|[0-1]{1}[0-9]{2}|[1-9]{1}[0-9]{1}|[1-9])\.(25[0-5]|2[0-4][0-9]|[0-1]{1}[0-9]{2}|[1-9]{1}[0-9]{1}|[1-9]|0)\.(25[0-5]|2[0-4][0-9]|[0-1]{1}[0-9]{2}|[1-9]{1}[0-9]{1}|[1-9]|0)\.(25[0-5]|2[0-4][0-9]|[0-1]{1}[0-9]{2}|[1-9]{1}[0-9]{1}|[0-9])|localhost|([a-zA-Z0-9\-]+\.)*[a-zA-Z0-9\-]+\.(com|edu|gov|int|mil|net|org|biz|arpa|info|name|pro|aero|coop|museum|[a-zA-Z]{2}))(\:[0-9]+)*(/($|[a-zA-Z0-9\.\,\?\'\\\+&%\$#\=~_\-]+))*\s*;\s*$@', $line)) {
                continue;
            }
            if (preg_match('@^\s*set\s+\$\S+\s+\S+\s*;\s*$@', $line)) {
                continue;
            }
            if (trim($line) === '}') {
                $ifLevel -= 1;

                continue;
            }

            return false;
        }

        return $ifLevel === 0;
    }

    /**
     * Line-by-line whitelist for custom php.ini settings, ported verbatim
     * from web_vhost_domain_edit.php:1231-1257.
     */
    public static function validateCustomPhpIni(string $settings): bool
    {
        $settings = trim($settings);

        if ($settings === '') {
            return true;
        }

        $settings = str_replace(["\r\n", "\r"], "\n", $settings);

        foreach (explode("\n", $settings) as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (substr(trim($line), 0, 1) === ';') {
                continue;
            }
            if (preg_match('@^\s*;*\s*[a-zA-Z0-9._]*\s*=\s*;*\s*$@', $line)) {
                continue;
            }
            if (preg_match('@^\s*;*\s*[a-zA-Z0-9._]*\s*=\s*".*"\s*;*\s*$@', $line)) {
                continue;
            }
            if (preg_match('@^\s*;*\s*[a-zA-Z0-9._]*\s*=\s*\'.*\'\s*;*\s*$@', $line)) {
                continue;
            }
            if (preg_match('@^\s*;*\s*[a-zA-Z0-9._]*\s*=\s*[-a-zA-Z0-9~&=_\@/,.#\{\}\s\|]*\s*;*\s*$@', $line)) {
                continue;
            }

            return false;
        }

        return true;
    }
}
