<?php

namespace App\Services;

use App\Support\AuthScope;
use Illuminate\Support\Facades\DB;

/**
 * Website permissions of client and reseller keys (spec 020).
 *
 * Mirrors what ISPConfig 3.3.1p1 lets a non-admin user do on the website form:
 * plan flags forced on every save (web_vhost_domain_edit.php:979-996), PHP
 * modes limited by the system and client `web_php_options`
 * (tform_base.inc.php::applyValueLimit), PHP versions of the website's server
 * (R3/R4), the Options and SSL tabs only when allowed (tform 446, 791) and the
 * read-only domain tab of plain clients (tform 78-84). Admin scopes are never
 * restricted; every public method returns "no restriction" for them.
 */
class WebPermissionService
{
    /** Plan flag field => [client column, forbidden (requested) value, forced raw value, label]. */
    public const PLAN_FLAGS = [
        'ssl' => ['limit_ssl', true, 'n', 'SSL'],
        'ssl_letsencrypt' => ['limit_ssl_letsencrypt', true, 'n', "Let's Encrypt"],
        'cgi' => ['limit_cgi', true, 'n', 'CGI'],
        'ssi' => ['limit_ssi', true, 'n', 'SSI'],
        'perl' => ['limit_perl', true, 'n', 'Perl'],
        'ruby' => ['limit_ruby', true, 'n', 'Ruby'],
        'python' => ['limit_python', true, 'n', 'Python'],
    ];

    /** Options-tab fields (tform advanced tab, API-writable subset). */
    public const OPTIONS_FIELDS = [
        'allow_override', 'proxy_protocol', 'php_fpm_use_socket', 'php_fpm_chroot', 'pm', 'pm_max_children',
        'pm_start_servers', 'pm_min_spare_servers', 'pm_max_spare_servers', 'pm_process_idle_timeout',
        'pm_max_requests', 'disable_symlinknotowner', 'php_open_basedir', 'custom_php_ini', 'apache_directives',
        'nginx_directives', 'proxy_directives', 'http_port', 'https_port', 'log_retention',
        'jailkit_chroot_app_sections', 'jailkit_chroot_app_programs', 'delete_unused_jailkit',
    ];

    /** SSL-tab certificate subject fields writable on the website body. */
    public const SSL_TAB_FIELDS = ['ssl_state', 'ssl_locality', 'ssl_organisation', 'ssl_organisation_unit', 'ssl_country', 'ssl_domain'];

    /** Domain-tab fields read-only for plain clients on existing vhosts. */
    public const IDENTITY_FIELDS = ['domain', 'ip_address', 'ipv6_address', 'vhost_type'];

    /** Client columns read for the account permissions, with the legacy "no row" value. */
    protected const CLIENT_COLUMNS = [
        'limit_ssl', 'limit_ssl_letsencrypt', 'limit_cgi', 'limit_ssi', 'limit_perl', 'limit_ruby', 'limit_python',
        'force_suexec', 'limit_hterror', 'limit_wildcard', 'limit_directive_snippets', 'limit_backup', 'web_php_options',
    ];

    /** @var array<string, array<string, mixed>> */
    protected array $cache = [];

    public function __construct(
        protected SitesConfigService $config,
        protected PhpVersionService $phpVersions,
    ) {}

    /**
     * The acting account's website permissions (data-model.md
     * AccountWebPermissions). Memoized per scope identity.
     *
     * @return array{client_id: int, is_admin: bool, is_reseller: bool, client_found: bool, flags: array<string, bool>, force_suexec: bool, php_modes: array<int, string>, advanced_options: bool, locked: bool, canceled: bool}
     */
    public function forScope(AuthScope $scope): array
    {
        $key = $scope->sysUserId.':'.$scope->sysGroupId.':'.$scope->clientId.':'.($scope->isAdmin ? 'a' : 'u');

        return $this->cache[$key] ??= $this->resolve($scope->clientId, $scope->isAdmin, $scope->isAdmin ? false : $scope->isReseller());
    }

    /**
     * Permissions of a client account by id (spec 021 capabilities of a named
     * client use the client's own view: reseller when limit_client != 0).
     *
     * @return array{client_id: int, is_admin: bool, is_reseller: bool, client_found: bool, flags: array<string, bool>, force_suexec: bool, php_modes: array<int, string>, advanced_options: bool, locked: bool, canceled: bool}
     */
    public function forClient(int $clientId): array
    {
        $limitClient = $clientId > 0 ? DB::table('client')->where('client_id', $clientId)->value('limit_client') : null;

        return $this->resolve($clientId, false, $limitClient !== null && (int) $limitClient !== 0);
    }

    /**
     * @return array{client_id: int, is_admin: bool, is_reseller: bool, client_found: bool, flags: array<string, bool>, force_suexec: bool, php_modes: array<int, string>, advanced_options: bool, locked: bool, canceled: bool}
     */
    protected function resolve(int $clientId, bool $isAdmin, bool $isReseller): array
    {
        // Full row: minimal test schemas may lack some of CLIENT_COLUMNS; a
        // missing column reads as "not included" like the legacy null row.
        $row = $clientId > 0
            ? DB::table('client')->where('client_id', $clientId)->first()
            : null;

        $yes = fn (string $column): bool => $row !== null && strtolower((string) ($row->{$column} ?? '')) === 'y';

        $flags = [
            'ssl' => $yes('limit_ssl'),
            'ssl_letsencrypt' => $yes('limit_ssl_letsencrypt'),
            'cgi' => $yes('limit_cgi'),
            'ssi' => $yes('limit_ssi'),
            'perl' => $yes('limit_perl'),
            'ruby' => $yes('limit_ruby'),
            'python' => $yes('limit_python'),
            'error_documents' => $yes('limit_hterror'),
            'wildcard_subdomains' => $yes('limit_wildcard'),
            'directive_snippets' => $yes('limit_directive_snippets'),
            'backup' => $yes('limit_backup'),
        ];

        $sites = $this->config->globalConfig('sites');

        return [
            'client_id' => $clientId,
            'is_admin' => $isAdmin,
            'is_reseller' => $isReseller,
            'client_found' => $row !== null,
            'flags' => $flags,
            'force_suexec' => $yes('force_suexec'),
            'php_modes' => $this->allowedPhpModes(
                (string) ($sites['web_php_options'] ?? ''),
                $row !== null ? (string) ($row->web_php_options ?? '') : ''
            ),
            'advanced_options' => $isReseller && strtolower((string) ($sites['reseller_can_use_options'] ?? 'n')) === 'y',
            'locked' => $yes('locked'),
            'canceled' => $yes('canceled'),
        ];
    }

    /**
     * System list ∩ client list in the client list's order (legacy
     * applyValueLimit); an empty system list does not restrict
     * (owner-delegated decision 2026-09-15).
     *
     * @return array<int, string>
     */
    protected function allowedPhpModes(string $system, string $client): array
    {
        $split = fn (string $csv): array => array_values(array_filter(array_map('trim', explode(',', $csv)), fn (string $v): bool => $v !== ''));

        $clientModes = $split($client);
        $systemModes = $split($system);

        if ($systemModes === []) {
            return $clientModes;
        }

        return array_values(array_filter($clientModes, fn (string $mode): bool => in_array($mode, $systemModes, true)));
    }
}
