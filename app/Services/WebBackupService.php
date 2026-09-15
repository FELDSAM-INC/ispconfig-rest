<?php

namespace App\Services;

use App\Models\Server;
use App\Models\WebDomain;
use App\Support\AuthScope;
use Illuminate\Support\Facades\DB;

/**
 * Website backups (spec 018): the backup gate, backup availability per
 * server, backup visibility, derived backup fields, job attribution and
 * representation, and backup settings.
 */
class WebBackupService
{
    /**
     * Legacy backup_copies options (web_vhost_domain.tform.php); shared by the
     * backup settings and the web-domain requests (FR-016, owner decision
     * 2026-09-14).
     */
    public const BACKUP_COPIES = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 15, 20, 30];

    public function __construct(
        protected ServerConfigService $serverConfig,
        protected MonitorDataService $monitor,
    ) {}

    /**
     * Backup gate (research R8 step 3): admin keys always pass; client and
     * reseller keys need their client row with limit_backup = 'y' — a missing
     * client row hides the backup tab in legacy web_vhost_domain.tform.php.
     */
    public function backupAllowed(AuthScope $scope): bool
    {
        if ($scope->isAdmin) {
            return true;
        }

        if ($scope->clientId < 1) {
            return false;
        }

        $value = DB::table('client')->where('client_id', $scope->clientId)->value('limit_backup');

        return $value !== null && strtolower((string) $value) === 'y';
    }

    /**
     * A server processes backup actions only with a non-empty [server]
     * backup_dir (backup_plugin::backup_action() returns early otherwise, R9).
     */
    public function backupsAvailable(int $serverId): bool
    {
        $server = Server::query()->find($serverId);

        if ($server === null) {
            return false;
        }

        $dir = $this->serverConfig->getSection($server, 'server')['backup_dir'] ?? '';

        return trim((string) $dir) !== '';
    }

    /**
     * Servers holding the website's backups: the website's server plus every
     * server of its databases (plugin_backuplist::onShow(), R6).
     *
     * @return array<int, int>
     */
    public function backupServerIds(WebDomain $website): array
    {
        return array_values(array_unique(array_merge(
            [(int) $website->getAttributes()['server_id']],
            $this->databaseServerIds($website),
        )));
    }

    /**
     * Distinct servers of the website's databases (backup_database targets, R2).
     *
     * @return array<int, int>
     */
    public function databaseServerIds(WebDomain $website): array
    {
        return DB::table('web_database')
            ->where('parent_domain_id', $website->getKey())
            ->distinct()
            ->orderBy('server_id')
            ->pluck('server_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
