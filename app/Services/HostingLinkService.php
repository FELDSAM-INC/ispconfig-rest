<?php

namespace App\Services;

use App\Support\AuthScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Administration and file-transfer links of an account (spec 036; contract
 * api/modules/me/hosting-links.yaml).
 *
 * Exposes exactly two `[sites]` settings to scoped keys, resolved for the
 * account's database servers:
 *
 *  - `phpmyadmin_url` with `[SERVERNAME]` replaced per server, gated by the
 *    interface's own switch `dblist_phpmyadmin_link` (legacy
 *    database_list.php:72, database_phpmyadmin.php:63-66). A `[DATABASENAME]`
 *    placeholder is left in place — one entry describes a server, not a
 *    database.
 *  - `webftp_url` verbatim (legacy ftp_user_list.php:58-60 substitutes
 *    nothing).
 *
 * Servers follow the spec 031 composition: the valid assigned database servers
 * in assignment order, then the non-mirror database servers hosting the
 * client's databases. Read-only.
 */
class HostingLinkService
{
    public function __construct(
        private readonly ServerAssignmentService $assignments,
        private readonly SitesConfigService $config,
    ) {}

    /**
     * @return array{client_id: int, database_administration: array<string, mixed>, file_transfer: array<string, mixed>}
     */
    public function links(int $clientId): array
    {
        $sites = $this->config->globalConfig('sites');
        $phpMyAdmin = trim((string) ($sites['phpmyadmin_url'] ?? ''));
        $webFtp = trim((string) ($sites['webftp_url'] ?? ''));
        $linkShown = (string) ($sites['dblist_phpmyadmin_link'] ?? 'n') === 'y';

        return [
            'client_id' => $clientId,
            'database_administration' => [
                'available' => $linkShown && $phpMyAdmin !== '',
                'servers' => $this->servers($clientId, $phpMyAdmin),
            ],
            'file_transfer' => [
                'available' => $webFtp !== '',
                'url' => $webFtp,
            ],
        ];
    }

    /**
     * @return array<int, array{server_id: int, server_name: string, url: string}>
     */
    protected function servers(int $clientId, string $template): array
    {
        // Lookup scope for the assignment columns of the client row (spec 031).
        $scope = new AuthScope(0, 0, [], false, $clientId);

        $ids = array_values(array_unique(array_merge(
            $this->assignments->assignedServerIds($scope, 'db'),
            $this->hostingServerIds($clientId)
        )));

        if ($ids === []) {
            return [];
        }

        $names = DB::table('server')
            ->whereIn('server_id', $ids)
            ->pluck('server_name', 'server_id')
            ->all();

        $servers = [];

        foreach ($ids as $id) {
            $name = (string) ($names[$id] ?? '');

            $servers[] = [
                'server_id' => $id,
                'server_name' => $name,
                'url' => $template === '' ? '' : str_replace('[SERVERNAME]', $name, $template),
            ];
        }

        return $servers;
    }

    /**
     * Non-mirror database servers hosting the client's databases, by id — the
     * same rule HostingAddressService applies to websites and zones.
     *
     * @return array<int, int>
     */
    protected function hostingServerIds(int $clientId): array
    {
        if (! Schema::hasTable('web_database')) {
            return [];
        }

        return DB::table('web_database')
            ->join('sys_group', 'sys_group.groupid', '=', 'web_database.sys_groupid')
            ->join('server', 'server.server_id', '=', 'web_database.server_id')
            ->where('sys_group.client_id', $clientId)
            ->where('server.db_server', 1)
            ->where('server.mirror_server_id', 0)
            ->distinct()
            ->orderBy('web_database.server_id')
            ->pluck('web_database.server_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
