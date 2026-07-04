<?php

namespace App\Services;

use App\Models\BaseModel;
use App\Models\ShellUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared derivation logic for the sites child resources (FTP/shell users,
 * databases, cron jobs, folders, WebDAV users): every one of them copies
 * server_id and sys_groupid — plus per-resource fields — from its parent
 * web domain (legacy *_edit.php onSubmit/onAfterInsert). The API applies
 * the derived values BEFORE the insert so the datalog payload carries the
 * complete record.
 */
class SitesService
{
    public function __construct(
        protected DatalogService $datalog,
        protected SitesConfigService $config,
    ) {}

    /**
     * The parent web domain row (any vhost type — legacy child-resource
     * dropdowns list vhost-family records only).
     *
     * @return object the web_domain row
     */
    public function parentDomain(int $domainId): object
    {
        $row = DB::table('web_domain')->where('domain_id', $domainId)->first();

        if ($row === null) {
            throw ValidationException::withMessages([
                'parent_domain_id' => 'The parent web domain does not exist.',
            ]);
        }

        return $row;
    }

    /**
     * Derived fields for FTP users (ftp_user_edit.php::onAfterInsert /
     * onAfterUpdate): server_id, dir=document_root, uid=system_user,
     * gid=system_group, sys_groupid — always from the parent domain. The
     * legacy is_allowed_user/group guard rejects disallowed system users
     * (e.g. root).
     */
    public function deriveFtpFields(BaseModel $model, object $web): void
    {
        $this->assertAllowedSystemUser((string) $web->system_user, (string) $web->system_group);

        $model->forceFill([
            'server_id' => (int) $web->server_id,
            'dir' => (string) $web->document_root,
            'uid' => (string) $web->system_user,
            'gid' => (string) $web->system_group,
            'sys_groupid' => (int) $web->sys_groupid,
        ]);
    }

    /**
     * Derived fields for shell users (shell_user_edit.php::onAfterInsert):
     * like FTP but with puser/pgroup.
     */
    public function deriveShellFields(BaseModel $model, object $web): void
    {
        $this->assertAllowedSystemUser((string) $web->system_user, (string) $web->system_group);

        $model->forceFill([
            'server_id' => (int) $web->server_id,
            'dir' => (string) $web->document_root,
            'puser' => (string) $web->system_user,
            'pgroup' => (string) $web->system_group,
            'sys_groupid' => (int) $web->sys_groupid,
        ]);
    }

    /**
     * Derived fields for cron jobs, WebDAV users and web folders:
     * server_id + sys_groupid from the parent domain.
     */
    public function deriveServerAndGroup(BaseModel $model, object $web): void
    {
        $model->forceFill([
            'server_id' => (int) $web->server_id,
            'sys_groupid' => (int) $web->sys_groupid,
        ]);
    }

    /**
     * Cron job type derivation (cron_edit.php::onSubmit): http(s) commands
     * are always `url`; otherwise the owning client's limit_cron_type
     * applies ('full' stays full, anything else chroots); admin-owned
     * sites (no client) get `full`.
     */
    public function deriveCronType(string $command, object $web): string
    {
        if (preg_match("'^http(s)?:\/\/'i", $command)) {
            return 'url';
        }

        $limitCronType = DB::table('sys_group')
            ->join('client', 'sys_group.client_id', '=', 'client.client_id')
            ->where('sys_group.groupid', (int) $web->sys_groupid)
            ->value('client.limit_cron_type');

        if ($limitCronType === null) {
            return 'full'; // site assigned to the admin
        }

        return $limitCronType === 'full' ? 'full' : 'chrooted';
    }

    /**
     * The web folder row for folder users (web_folder_user_edit.php).
     */
    public function webFolder(int $webFolderId): object
    {
        $row = DB::table('web_folder')->where('web_folder_id', $webFolderId)->first();

        if ($row === null) {
            throw ValidationException::withMessages([
                'web_folder_id' => 'The web folder does not exist.',
            ]);
        }

        return $row;
    }

    /**
     * Folder delete cascade: all web_folder_user rows first, then the
     * folder itself (contract note + legacy web_vhost_domain_del.php).
     */
    public function deleteFolderWithUsers(BaseModel $folder): void
    {
        $userIds = DB::table('web_folder_user')
            ->where('web_folder_id', $folder->getKey())
            ->pluck('web_folder_user_id');

        foreach ($userIds as $id) {
            $this->datalog->deleteRecord('web_folder_user', 'web_folder_user_id', $id);
        }

        $folder->delete();
    }

    /**
     * Forced datalog touch of a linked web_database_user row syncing its
     * server_id (database_edit.php onBeforeInsert/onBeforeUpdate: legacy
     * writes ONLY the datalog row — the table row keeps server_id 0 — so
     * the daemons (re)create the grants on the database's server).
     */
    public function touchLinkedDatabaseUser(int $databaseUserId, int $serverId): void
    {
        if ($databaseUserId <= 0) {
            return;
        }

        $oldRecord = (array) DB::table('web_database_user')
            ->where('database_user_id', $databaseUserId)
            ->first();

        if ($oldRecord === []) {
            return;
        }

        $newRecord = $oldRecord;
        $newRecord['server_id'] = $serverId;

        $this->datalog->log('web_database_user', 'database_user_id', $databaseUserId, 'u', $oldRecord, $newRecord);
    }

    /**
     * Database remote-access auto-fix (database_edit.php
     * onBeforeInsert/onBeforeUpdate): when the parent web domain lives on
     * a different server than the database, the web server's IP (plus the
     * configured default_remote_dbserver IPs and mirror-server IPs) is
     * appended to remote_ips and remote_access is forced to 'y'. When the
     * servers match, only the default_remote_dbserver list applies.
     *
     * @param  array<string, mixed>  $record  raw web_database attributes (modified in place)
     */
    public function applyRemoteAccessAutoFix(array &$record, object $web): void
    {
        $dbServerId = (int) ($record['server_id'] ?? 0);
        $webServerId = (int) $web->server_id;
        $sitesConfig = $this->config->globalConfig('sites');
        $defaultRemote = trim((string) ($sitesConfig['default_remote_dbserver'] ?? ''));

        if ($webServerId > 0 && $webServerId !== $dbServerId) {
            $serverConfig = $this->config->serverConfig($webServerId, 'server');
            $webIp = (string) ($serverConfig['ip_address'] ?? '');

            $remoteIps = $defaultRemote === '' ? [] : explode(',', $defaultRemote);
            if ($webIp !== '' && ! in_array($webIp, $remoteIps, true)) {
                $remoteIps[] = $webIp;
            }

            // Mirror servers of the web server need access too.
            $mirrors = DB::table('server')->where('mirror_server_id', $webServerId)->pluck('server_id');
            foreach ($mirrors as $mirrorId) {
                $mirrorConfig = $this->config->serverConfig((int) $mirrorId, 'server');
                $mirrorIp = (string) ($mirrorConfig['ip_address'] ?? '');
                if ($mirrorIp !== '' && ! in_array($mirrorIp, $remoteIps, true)) {
                    $remoteIps[] = $mirrorIp;
                }
            }

            if ($webIp !== '') {
                if (($record['remote_access'] ?? 'n') !== 'y') {
                    $record['remote_ips'] = implode(',', $remoteIps);
                    $record['remote_access'] = 'y';
                } elseif (($record['remote_ips'] ?? '') !== '') {
                    $current = preg_split('/\s*,\s*/', (string) $record['remote_ips']) ?: [];
                    $record['remote_ips'] = implode(',', array_unique(array_merge($current, $remoteIps)));
                }
            }

            return;
        }

        if ($defaultRemote !== '' && ($record['remote_access'] ?? 'n') !== 'y') {
            $record['remote_ips'] = $defaultRemote;
            $record['remote_access'] = 'y';
        }
    }

    /**
     * Legacy is_allowed_user/is_allowed_group guard on the parent's
     * system user/group (never root & friends).
     */
    protected function assertAllowedSystemUser(string $uid, string $gid): void
    {
        if (! ShellUser::isAllowedUser($uid) || ! ShellUser::isAllowedUser($gid)) {
            throw ValidationException::withMessages([
                'parent_domain_id' => 'Invalid system user or group on the parent web domain.',
            ]);
        }
    }
}
