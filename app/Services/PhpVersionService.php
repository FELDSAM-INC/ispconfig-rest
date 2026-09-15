<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PHP versions a website may use (spec 020 R3/R4, reused by spec 021
 * `GET /me/php-versions`).
 *
 * Mirrors the legacy client version list (web_vhost_domain_edit.php:247-258,
 * ajax_get_json.php:66-120): active rows of `server_php` on the website's
 * server, public (client_id 0) or belonging to one of the given clients, with
 * the binaries the PHP mode needs, ordered by sortprio. The server's `[web]`
 * config decides whether the built-in default version (id 0) is offered.
 */
class PhpVersionService
{
    /** PHP modes that use selectable versions. */
    public const VERSION_MODES = ['php-fpm', 'fast-cgi'];

    public function __construct(protected SitesConfigService $config) {}

    /**
     * Usable versions for a server, client set and mode, ordered by sortprio
     * then id. An unknown mode yields no versions.
     *
     * @param  array<int, int>  $clientIds  owning clients besides the public 0
     * @return Collection<int, object>
     */
    public function usable(int $serverId, array $clientIds, string $mode): Collection
    {
        if (! in_array($mode, self::VERSION_MODES, true)) {
            return collect();
        }

        $clients = array_values(array_unique(array_merge([0], array_map('intval', $clientIds))));

        $query = DB::table('server_php')
            ->where('server_id', $serverId)
            ->where('active', 'y')
            ->whereIn('client_id', $clients);

        foreach (self::modeColumns($mode) as $column) {
            $query->whereNotNull($column)->where($column, '!=', '');
        }

        return $query->orderBy('sortprio')->orderBy('server_php_id')->get();
    }

    /**
     * Whether a server_php row supports the mode (legacy binary checks).
     */
    public static function supportsMode(object $row, string $mode): bool
    {
        if (! in_array($mode, self::VERSION_MODES, true)) {
            return false;
        }

        foreach (self::modeColumns($mode) as $column) {
            if (trim((string) ($row->{$column} ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Modes a server_php row supports, in legacy order.
     *
     * @return array<int, string>
     */
    public static function modesOf(object $row): array
    {
        return array_values(array_filter(self::VERSION_MODES, fn (string $mode): bool => self::supportsMode($row, $mode)));
    }

    /**
     * Legacy `[web] php_default_hide = y` hides the default version (id 0).
     */
    public function defaultHidden(int $serverId): bool
    {
        return strtolower((string) ($this->config->serverConfig($serverId, 'web')['php_default_hide'] ?? 'n')) === 'y';
    }

    /**
     * Display name of the default version (legacy `[web] php_default_name`).
     */
    public function defaultName(int $serverId): string
    {
        $name = trim((string) ($this->config->serverConfig($serverId, 'web')['php_default_name'] ?? ''));

        return $name !== '' ? $name : 'Default';
    }

    /**
     * Web server type (`apache` when not configured).
     */
    public function serverType(int $serverId): string
    {
        $type = trim((string) ($this->config->serverConfig($serverId, 'web')['server_type'] ?? ''));

        return $type !== '' ? $type : 'apache';
    }

    /**
     * @return array<int, string>
     */
    protected static function modeColumns(string $mode): array
    {
        return $mode === 'php-fpm'
            ? ['php_fpm_init_script', 'php_fpm_ini_dir', 'php_fpm_pool_dir']
            : ['php_fastcgi_binary', 'php_fastcgi_ini_dir'];
    }
}
