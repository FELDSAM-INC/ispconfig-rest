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

    /**
     * Permissions per resolved scope object: one AuthScope is built per
     * request, so the memo never outlives the request even when the service
     * instance does (cached controllers, long-running workers).
     *
     * @var \WeakMap<AuthScope, array<string, mixed>>
     */
    protected \WeakMap $cache;

    public function __construct(
        protected SitesConfigService $config,
        protected PhpVersionService $phpVersions,
    ) {
        $this->cache = new \WeakMap;
    }

    /**
     * The acting account's website permissions (data-model.md
     * AccountWebPermissions). Memoized per scope object.
     *
     * @return array{client_id: int, is_admin: bool, is_reseller: bool, client_found: bool, flags: array<string, bool>, force_suexec: bool, php_modes: array<int, string>, advanced_options: bool, locked: bool, canceled: bool}
     */
    public function forScope(AuthScope $scope): array
    {
        return $this->cache[$scope] ??= $this->resolve($scope->clientId, $scope->isAdmin, $scope->isAdmin ? false : $scope->isReseller());
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
     * Field violations of a website write by a client or reseller key (FR-001,
     * FR-003, FR-004, FR-006–FR-010). Empty for admin scopes.
     *
     * @param  array<string, mixed>  $input  request input (flags already normalized to booleans)
     * @param  array{is_create: bool, type: string, server_id: int, owner_client_id: int, current: array<string, mixed>}  $context
     * @return array<string, string> field => message
     */
    public function violations(AuthScope $scope, array $input, array $context): array
    {
        if ($scope->isAdmin) {
            return [];
        }

        $account = $this->forScope($scope);
        $violations = [];

        foreach ($this->planFlagViolations($account, $input, $context) as $field => $message) {
            $violations[$field] = $message;
        }

        foreach ($this->phpViolations($scope, $account, $input, $context) as $field => $message) {
            $violations[$field] = $message;
        }

        return $violations;
    }

    /**
     * PHP mode used when a create omits `php` (FR-003): `fast-cgi` if allowed,
     * else the first allowed mode other than `no`, else `no`.
     *
     * @param  array<string, mixed>  $account
     */
    public function defaultPhpMode(array $account): string
    {
        if (in_array('fast-cgi', $account['php_modes'], true)) {
            return 'fast-cgi';
        }

        foreach ($account['php_modes'] as $mode) {
            if ($mode !== 'no') {
                return $mode;
            }
        }

        return 'no';
    }

    /**
     * Clients whose private PHP versions the website may use: the acting
     * account and the website owner (legacy client_id = 0 OR own).
     *
     * @param  array<string, mixed>  $context
     * @return array<int, int>
     */
    protected function phpClientIds(AuthScope $scope, array $context): array
    {
        return array_values(array_unique(array_filter(
            [$scope->clientId, (int) ($context['owner_client_id'] ?? 0)],
            fn (int $id): bool => $id > 0
        )));
    }

    /**
     * PHP mode and version rules (FR-003, FR-004, FR-006; research R2–R4).
     *
     * @param  array<string, mixed>  $account
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    protected function phpViolations(AuthScope $scope, array $account, array $input, array $context): array
    {
        $current = $context['current'];
        $isCreate = $context['is_create'];

        if ($this->requests('php', $input, $context, true)
            && (! is_string($input['php']) || ! in_array($input['php'], $account['php_modes'], true))) {
            return ['php' => 'The selected PHP mode is not available for this account.'];
        }

        $mode = array_key_exists('php', $input) && is_string($input['php'])
            ? $input['php']
            : ($isCreate ? $this->defaultPhpMode($account) : (string) ($current['php'] ?? ''));

        if (! in_array($mode, PhpVersionService::VERSION_MODES, true)) {
            return [];
        }

        $serverId = (int) $context['server_id'];
        $phpChanged = ! $isCreate && array_key_exists('php', $input)
            && $this->normalize($input['php']) !== $this->normalize($current['php'] ?? null);
        $versionSent = array_key_exists('server_php_id', $input) && is_numeric($input['server_php_id']);
        $versionRequested = $versionSent && ($isCreate || $phpChanged || $this->requests('server_php_id', $input, $context));
        $version = $versionSent ? (int) $input['server_php_id'] : ($isCreate ? 0 : (int) ($current['server_php_id'] ?? 0));

        $usable = null;
        $usableIds = function () use (&$usable, $serverId, $scope, $context, $mode): array {
            return $usable ??= $this->phpVersions->usable($serverId, $this->phpClientIds($scope, $context), $mode)
                ->map(fn (object $row): int => (int) $row->server_php_id)
                ->all();
        };

        if ($version !== 0 && ($versionRequested || $phpChanged) && ! in_array($version, $usableIds(), true)) {
            if ($versionRequested) {
                return ['server_php_id' => 'The selected PHP version is not available for this website.'];
            }

            // A kept version that does not fit the new mode is reset
            // (legacy onSubmit:1286-1304).
            $version = 0;
        }

        if ($version === 0 && $this->phpVersions->defaultHidden($serverId)) {
            if ($versionRequested) {
                return ['server_php_id' => 'A PHP version must be selected for this website.'];
            }

            if ($usableIds() === []) {
                return ['server_php_id' => "No PHP version is available for the selected PHP mode on this website's server."];
            }
        }

        return [];
    }

    /**
     * Raw attribute values a client or reseller save stores regardless of the
     * request (legacy onSubmit:986-996, FR-002). Empty for admin scopes.
     *
     * @param  array<string, mixed>  $record  raw attributes about to be written
     * @param  array{is_create: bool, type: string, server_id: int, owner_client_id: int}  $context
     * @return array<string, mixed>
     */
    public function forcedAttributes(AuthScope $scope, array $record, array $context): array
    {
        if ($scope->isAdmin) {
            return [];
        }

        $account = $this->forScope($scope);
        $forced = [];

        foreach (self::PLAN_FLAGS as $field => [, , $forcedValue]) {
            if (! $account['flags'][$field]) {
                $forced[$field] = $forcedValue;
            }
        }

        if ($account['force_suexec']) {
            $forced['suexec'] = 'y';
        }

        if (! $account['flags']['error_documents']) {
            $forced['errordocs'] = 0;
        }

        if (! $account['flags']['directive_snippets']) {
            $forced['directive_snippets_id'] = 0;
        }

        // PHP mode default on create (FR-003) and version (FR-005/FR-006).
        $mode = (string) ($record['php'] ?? '');

        if ($context['is_create'] && empty($context['php_sent'])) {
            $mode = $this->defaultPhpMode($account);
            $forced['php'] = $mode;
        }

        if (! in_array($mode, PhpVersionService::VERSION_MODES, true)) {
            $forced['server_php_id'] = 0;

            return $forced;
        }

        $serverId = (int) $context['server_id'];
        $version = (int) ($record['server_php_id'] ?? 0);
        $clientIds = $this->phpClientIds($scope, $context);

        if ($version !== 0 && ! empty($context['php_changed'])
            && ! $this->phpVersions->usable($serverId, $clientIds, $mode)->contains(fn (object $row): bool => (int) $row->server_php_id === $version)) {
            $version = 0;
            $forced['server_php_id'] = 0;
        }

        if ($version === 0 && $this->phpVersions->defaultHidden($serverId)) {
            $first = $this->phpVersions->usable($serverId, $clientIds, $mode)->first();

            if ($first !== null) {
                $forced['server_php_id'] = (int) $first->server_php_id;
            }
        }

        return $forced;
    }

    /**
     * @param  array<string, mixed>  $account
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    protected function planFlagViolations(array $account, array $input, array $context): array
    {
        $violations = [];
        $isChild = in_array($context['type'], ['vhostsubdomain', 'vhostalias'], true);

        foreach (self::PLAN_FLAGS as $field => [, $forbidden, , $label]) {
            if (! $account['flags'][$field]
                && $this->requests($field, $input, $context, true)
                && $input[$field] === $forbidden) {
                $violations[$field] = "The {$label} option is not included in the account's plan.";
            }
        }

        if (! $account['flags']['error_documents']
            && $this->requests('errordocs', $input, $context, true)
            && is_numeric($input['errordocs']) && (int) $input['errordocs'] === 1) {
            $violations['errordocs'] = "The custom error documents option is not included in the account's plan.";
        }

        if (! $account['flags']['directive_snippets']
            && $this->requests('directive_snippets_id', $input, $context, true)
            && is_numeric($input['directive_snippets_id']) && (int) $input['directive_snippets_id'] !== 0) {
            $violations['directive_snippets_id'] = "The directive snippets option is not included in the account's plan.";
        }

        if ($this->requests('subdomain', $input, $context, true) && $input['subdomain'] === '*') {
            if ($isChild) {
                $violations['subdomain'] = 'Wildcard subdomains are not available for this website type.';
            } elseif (! $account['flags']['wildcard_subdomains']) {
                $violations['subdomain'] = "The wildcard subdomains option is not included in the account's plan.";
            }
        }

        if ($account['force_suexec']
            && $this->requests('suexec', $input, $context, true)
            && $input['suexec'] === false) {
            $violations['suexec'] = "suEXEC is required by the account's plan.";
        }

        return $violations;
    }

    /**
     * Whether the request sets a field (research R8): on create every sent
     * value counts when $sentOnCreate, otherwise only values different from
     * the model default; on update only values different from the stored raw
     * value.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     */
    protected function requests(string $field, array $input, array $context, bool $sentOnCreate = false): bool
    {
        if (! array_key_exists($field, $input)) {
            return false;
        }

        if ($context['is_create'] && $sentOnCreate) {
            return true;
        }

        return $this->normalize($input[$field]) !== $this->normalize($context['current'][$field] ?? null);
    }

    /**
     * Comparable form of an input or raw value: booleans as y/n, null as ''.
     */
    protected function normalize(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'y' : 'n';
        }

        if ($value === null) {
            return '';
        }

        return is_scalar($value) ? strtolower(trim((string) $value)) : json_encode($value);
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
