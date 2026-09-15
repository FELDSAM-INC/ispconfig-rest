<?php

namespace App\Services;

use App\Models\RemoteAction;
use App\Models\Server;
use App\Models\WebBackup;
use App\Models\WebDomain;
use App\Support\AuthScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Public job actions => sys_remoteaction action types (research R2).
     */
    public const ACTION_TYPES = [
        'backup' => ['backup_web_files', 'backup_database'],
        'restore' => ['backup_restore'],
        'download' => ['backup_download'],
        'delete' => ['backup_delete'],
    ];

    /**
     * Action types whose parameter is a backup id (the others carry the website id).
     */
    public const BACKUP_REFERENCE_ACTIONS = ['backup_restore', 'backup_download', 'backup_delete'];

    public const JOB_STATES = ['pending', 'ok', 'warning', 'error'];

    /**
     * Seconds after which backup.inc.php removes files from the website's backup folder.
     */
    public const DOWNLOAD_RETENTION = 60 * 60 * 24 * 3;

    private const MANUAL_PREFIX = 'manual-';

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
     * server of its databases, ids > 0 only (plugin_backuplist::onShow(), R6).
     *
     * @return array<int, int>
     */
    public function backupServerIds(WebDomain $website): array
    {
        return array_values(array_filter(array_unique(array_merge(
            [(int) $website->getAttributes()['server_id']],
            $this->databaseServerIds($website),
        )), fn (int $id): bool => $id > 0));
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

    /**
     * Backups listed for the website (R6): its rows on the website's and its
     * databases' servers.
     */
    public function visibleBackups(WebDomain $website): Builder
    {
        return WebBackup::query()
            ->where('parent_domain_id', $website->getKey())
            ->whereIn('server_id', $this->backupServerIds($website));
    }

    /**
     * A backup of the website for an action — legacy action branches look the
     * backup up by id and check only the parent website, not the server.
     */
    public function backupOfWebsite(WebDomain $website, int $backupId): WebBackup
    {
        return WebBackup::query()
            ->where('parent_domain_id', $website->getKey())
            ->whereKey($backupId)
            ->firstOrFail();
    }

    /**
     * Server that processes an action on a backup: the backup's server, or the
     * website's when the backup row has none (R2).
     */
    public function actionServerId(WebBackup $backup, WebDomain $website): int
    {
        $serverId = (int) $backup->getAttributes()['server_id'];

        return $serverId > 0 ? $serverId : (int) $website->getAttributes()['server_id'];
    }

    /**
     * Backup representation with the derived fields of
     * plugin_backuplist::onShow() (R7). The stored password is never returned.
     *
     * @return array<string, mixed>
     */
    public function backupRepresentation(WebBackup $backup, WebDomain $website): array
    {
        $row = $backup->getAttributes();
        $web = $website->getAttributes();

        $mode = (string) $row['backup_mode'];
        $type = (string) $row['backup_type'];
        $format = (string) $row['backup_format'];
        $filename = (string) $row['filename'];
        $password = (string) $row['backup_password'];

        if ($mode === 'borg') {
            if ($type === 'mysql') {
                $format = (string) ($web['backup_format_db'] ?? '');
                if ($format === '' || $format === 'default') {
                    $format = self::defaultFormat('rootgz', 'mysql');
                }
                $filename .= (string) self::databaseExtension($format);
            } elseif ($type === 'web') {
                $format = (string) ($web['backup_format_web'] ?? '');
                if ($format === '' || $format === 'default') {
                    $format = self::defaultFormat($mode, 'web');
                }
                $filename .= (string) self::webExtension($format);
            }

            $password = ($web['backup_encrypt'] ?? 'n') === 'y' ? trim((string) ($web['backup_password'] ?? '')) : '';
        } elseif ($format === '') {
            // A backup from an old ISPConfig version.
            $format = self::defaultFormat($mode, $type);
        }

        $filesize = (string) $row['filesize'];

        return [
            'id' => (int) $row['backup_id'],
            'server_id' => (int) $row['server_id'],
            'parent_domain_id' => (int) $row['parent_domain_id'],
            'backup_type' => $type,
            'database_name' => $this->databaseName($type, (string) $row['filename']),
            'backup_mode' => $mode,
            'backup_format' => $format !== '' ? $format : null,
            'filename' => $filename,
            'filesize' => ctype_digit($filesize) ? (int) $filesize : null,
            'filesize_approximate' => $mode === 'borg',
            'created_at' => $this->timestamp((int) $row['tstamp']),
            'job' => str_starts_with($filename, self::MANUAL_PREFIX) ? 'manual' : 'auto',
            'encrypted' => $password !== '',
            'download_available' => (int) $row['server_id'] === (int) $web['server_id'],
        ];
    }

    /**
     * Jobs of the website (R5): backup jobs by website id, restore/download/
     * delete jobs by the ids of the website's backup rows.
     */
    public function jobsOf(WebDomain $website): Builder
    {
        $websiteId = (string) $website->getKey();
        $backupIds = DB::table('web_backup')
            ->where('parent_domain_id', $website->getKey())
            ->pluck('backup_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        return RemoteAction::query()->where(function (Builder $query) use ($websiteId, $backupIds): void {
            $query->where(function (Builder $backupJobs) use ($websiteId): void {
                $backupJobs->whereIn('action_type', self::ACTION_TYPES['backup'])
                    ->where('action_param', $websiteId);
            });

            if ($backupIds !== []) {
                $query->orWhere(function (Builder $referenceJobs) use ($backupIds): void {
                    $referenceJobs->whereIn('action_type', self::BACKUP_REFERENCE_ACTIONS)
                        ->whereIn('action_param', $backupIds);
                });
            }
        });
    }

    /**
     * Backup rows referenced by restore/download/delete jobs, keyed by backup id.
     *
     * @param  iterable<int, RemoteAction>  $jobs
     * @return array<int, WebBackup>
     */
    public function referencedBackups(WebDomain $website, iterable $jobs): array
    {
        $ids = [];

        foreach ($jobs as $job) {
            $attributes = $job->getAttributes();

            if (in_array($attributes['action_type'], self::BACKUP_REFERENCE_ACTIONS, true)) {
                $ids[] = (int) $attributes['action_param'];
            }
        }

        if ($ids === []) {
            return [];
        }

        return WebBackup::query()
            ->where('parent_domain_id', $website->getKey())
            ->whereIn('backup_id', array_values(array_unique($ids)))
            ->get()
            ->keyBy(fn (WebBackup $backup): int => (int) $backup->getKey())
            ->all();
    }

    /**
     * Job representation (R4, R13).
     *
     * @param  array<int, WebBackup>  $backups  referenced backups keyed by id
     * @return array<string, mixed>
     */
    public function jobRepresentation(RemoteAction $job, WebDomain $website, array $backups): array
    {
        $row = $job->getAttributes();
        $type = (string) $row['action_type'];
        $state = in_array($row['action_state'], self::JOB_STATES, true) ? (string) $row['action_state'] : 'error';

        $backupId = in_array($type, self::BACKUP_REFERENCE_ACTIONS, true) ? (int) $row['action_param'] : null;
        $backup = $backupId !== null ? ($backups[$backupId] ?? null) : null;

        $backupType = match ($type) {
            'backup_web_files' => 'web',
            'backup_database' => 'mysql',
            default => $backup !== null ? (string) $backup->getAttributes()['backup_type'] : null,
        };

        $createdAt = (int) $row['tstamp'];
        $download = null;

        if ($type === 'backup_download' && $state === 'ok' && $backup !== null) {
            $filename = $this->backupRepresentation($backup, $website)['filename'];
            $download = [
                'path' => 'backup/'.$filename,
                'filename' => $filename,
                'available_until' => $this->timestamp($createdAt + self::DOWNLOAD_RETENTION),
            ];
        }

        return [
            'id' => (int) $row['action_id'],
            'action' => $this->publicAction($type),
            'backup_type' => $backupType,
            'backup_id' => $backupId,
            'server_id' => (int) $row['server_id'],
            'state' => $state,
            'created_at' => $this->timestamp($createdAt),
            'download' => $download,
        ];
    }

    /**
     * Backup settings of the website (R9-R11). The password is never returned.
     *
     * @return array<string, mixed>
     */
    public function settingsRepresentation(WebDomain $website): array
    {
        $web = $website->getAttributes();
        $serverId = (int) $web['server_id'];

        return [
            'backup_interval' => (string) ($web['backup_interval'] ?? 'none'),
            'backup_copies' => (int) ($web['backup_copies'] ?? 1),
            'backup_excludes' => isset($web['backup_excludes']) ? (string) $web['backup_excludes'] : null,
            'backup_format_web' => (string) ($web['backup_format_web'] ?? 'default'),
            'backup_format_db' => (string) ($web['backup_format_db'] ?? 'gzip'),
            'backup_encrypt' => strtolower((string) ($web['backup_encrypt'] ?? 'n')) === 'y',
            'backup_password_set' => trim((string) ($web['backup_password'] ?? '')) !== '',
            'backups_available' => $this->backupsAvailable($serverId),
            'missing_utils' => $this->missingUtils($serverId),
        ];
    }

    /**
     * Compression tools missing on the server, from the newest backup_utils
     * monitor blob (`['missing_utils' => [...]]`, research R10).
     *
     * @return array<int, string>|null
     */
    public function missingUtils(int $serverId): ?array
    {
        $blob = $this->monitor->latestBlobs(['backup_utils'], [$serverId])[$serverId]['backup_utils']['data'] ?? null;

        if (! is_array($blob) || ! isset($blob['missing_utils']) || ! is_array($blob['missing_utils'])) {
            return null;
        }

        return array_values(array_map('strval', $blob['missing_utils']));
    }

    /**
     * Unix timestamps of ISPConfig tables in the API timezone (017 alignment).
     */
    public function timestamp(int $tstamp): string
    {
        return CarbonImmutable::createFromTimestamp($tstamp, (string) config('app.timezone'))->toIso8601String();
    }

    public function databaseName(string $type, string $filename): ?string
    {
        if (! in_array($type, ['mysql', 'mongodb'], true)) {
            return null;
        }

        // Pattern of backup.inc.php::downloadBackup().
        return preg_match('/^(manual-)?db_(?<db>.+)_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}/', $filename, $matches) === 1
            ? $matches['db']
            : null;
    }

    protected function publicAction(string $type): string
    {
        foreach (self::ACTION_TYPES as $action => $types) {
            if (in_array($type, $types, true)) {
                return $action;
            }
        }

        return $type;
    }

    /**
     * plugin_backuplist::getDefaultBackupFormat().
     */
    protected static function defaultFormat(string $mode, string $type): string
    {
        return match ($type) {
            'mysql' => 'gzip',
            'web' => $mode === 'userzip' ? 'zip' : 'tar_gzip',
            default => '',
        };
    }

    /**
     * plugin_backuplist::getBackupDbExtension().
     */
    protected static function databaseExtension(string $format): ?string
    {
        return match ($format) {
            'gzip' => '.sql.gz',
            'bzip2' => '.sql.bz2',
            'xz' => '.sql.xz',
            'zip', 'zip_bzip2' => '.zip',
            'rar' => '.rar',
            default => str_starts_with($format, '7z_') ? '.sql.7z' : null,
        };
    }

    /**
     * plugin_backuplist::getBackupWebExtension().
     */
    protected static function webExtension(string $format): ?string
    {
        return match ($format) {
            'tar_gzip' => '.tar.gz',
            'tar_bzip2' => '.tar.bz2',
            'tar_xz' => '.tar.xz',
            'zip', 'zip_bzip2' => '.zip',
            'rar' => '.rar',
            default => str_starts_with($format, 'tar_7z_') ? '.tar.7z' : null,
        };
    }
}
