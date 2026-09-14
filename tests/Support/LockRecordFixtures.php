<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Seeds records of the legacy client lock table list (spec 019) for
 * lock/unlock and write-guard tests. Requires SitesSchema and
 * MailCompletionSchema.
 */
trait LockRecordFixtures
{
    /**
     * The lock table list in legacy func_client_lock order (openvz_vm has no
     * test table but is part of every snapshot).
     *
     * @var array<int, string>
     */
    protected array $lockSnapshotTables = [
        'cron', 'ftp_user', 'mail_domain', 'mail_user', 'mail_forwarding', 'mail_get', 'openvz_vm',
        'shell_user', 'webdav_user', 'web_database', 'web_domain', 'web_folder', 'web_folder_user',
    ];

    /**
     * Insert one record with ISPConfig system fields.
     *
     * @param  array<string, mixed>  $attrs
     */
    protected function seedLockRecord(string $table, string $primaryKey, int $groupId, array $attrs, int $ownerUserId = 1): int
    {
        return (int) DB::table($table)->insertGetId(array_merge([
            'sys_userid' => $ownerUserId,
            'sys_groupid' => $groupId,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
        ], $attrs), $primaryKey);
    }

    /**
     * @return array<string, mixed>
     */
    protected function siteAttrs(string $domain, string $active): array
    {
        return [
            'ip_address' => '*', 'domain' => $domain, 'type' => 'vhost', 'parent_domain_id' => 0,
            'vhost_type' => 'name', 'document_root' => '/var/www/'.$domain, 'system_user' => 'web1',
            'system_group' => 'client1', 'hd_quota' => -1, 'traffic_quota' => -1, 'allow_override' => 'All',
            'backup_copies' => 1, 'active' => $active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function mailUserAttrs(string $email, string $postfix, string $disableSmtp = 'n'): array
    {
        return [
            'email' => $email, 'login' => $email, 'password' => 'x', 'maildir' => '/var/vmail/'.$email,
            'postfix' => $postfix, 'disablesmtp' => $disableSmtp,
        ];
    }

    /**
     * One record per lock-list table in the given group, plus the spec's
     * restore matrix: a disabled website owned by the client's own user and
     * a mailbox whose sending was already disabled.
     *
     * @return array<string, int>
     */
    protected function seedLockMatrix(int $groupId, int $clientUserId): array
    {
        $ids = [];

        $ids['cron'] = $this->seedLockRecord('cron', 'id', $groupId, [
            'parent_domain_id' => 0, 'type' => 'url', 'command' => 'https://acme.test/cron',
            'run_min' => '*', 'run_hour' => '*', 'run_mday' => '*', 'run_month' => '*', 'run_wday' => '*',
            'active' => 'y',
        ]);
        $ids['ftp_user'] = $this->seedLockRecord('ftp_user', 'ftp_user_id', $groupId, [
            'parent_domain_id' => 0, 'username' => 'ftpacme', 'password' => 'x', 'quota_size' => -1,
            'active' => 'y', 'uid' => 'web1', 'gid' => 'client1', 'dir' => '/var/www/acme.test',
        ]);
        $ids['mail_domain'] = $this->seedLockRecord('mail_domain', 'domain_id', $groupId, [
            'domain' => 'acme.test', 'active' => 'y',
        ]);
        $ids['mail_smtp_on'] = $this->seedLockRecord('mail_user', 'mailuser_id', $groupId,
            $this->mailUserAttrs('on@acme.test', 'y', 'n'));
        $ids['mail_smtp_off'] = $this->seedLockRecord('mail_user', 'mailuser_id', $groupId,
            $this->mailUserAttrs('off@acme.test', 'y', 'y'));
        $ids['mail_forwarding'] = $this->seedLockRecord('mail_forwarding', 'forwarding_id', $groupId, [
            'source' => 'alias@acme.test', 'destination' => 'on@acme.test', 'type' => 'forward', 'active' => 'y',
        ]);
        $ids['mail_get'] = $this->seedLockRecord('mail_get', 'mailget_id', $groupId, [
            'type' => 'pop3', 'source_server' => 'pop.remote.test', 'source_username' => 'remote',
            'source_password' => 'x', 'destination' => 'on@acme.test', 'active' => 'y',
        ]);
        $ids['shell_user'] = $this->seedLockRecord('shell_user', 'shell_user_id', $groupId, [
            'parent_domain_id' => 0, 'username' => 'shellacme', 'password' => 'x', 'quota_size' => -1,
            'active' => 'y', 'puser' => 'web1', 'pgroup' => 'client1', 'shell' => '/bin/bash',
            'dir' => '/var/www/acme.test', 'chroot' => '',
        ]);
        $ids['webdav_user'] = $this->seedLockRecord('webdav_user', 'webdav_user_id', $groupId, [
            'parent_domain_id' => 0, 'username' => 'davacme', 'password' => 'x', 'active' => 'y', 'dir' => 'dav',
        ]);
        $ids['web_database'] = $this->seedLockRecord('web_database', 'database_id', $groupId, [
            'parent_domain_id' => 0, 'type' => 'mysql', 'database_name' => 'c1acme', 'database_quota' => -1,
            'database_user_id' => 0, 'active' => 'y',
        ]);
        $ids['site_active'] = $this->seedLockRecord('web_domain', 'domain_id', $groupId,
            $this->siteAttrs('active.acme.test', 'y'));
        $ids['site_disabled'] = $this->seedLockRecord('web_domain', 'domain_id', $groupId,
            $this->siteAttrs('disabled.acme.test', 'n'), $clientUserId);
        $ids['web_folder'] = $this->seedLockRecord('web_folder', 'web_folder_id', $groupId, [
            'parent_domain_id' => $ids['site_active'], 'path' => '/protected', 'active' => 'y',
        ]);
        $ids['web_folder_user'] = $this->seedLockRecord('web_folder_user', 'web_folder_user_id', $groupId, [
            'web_folder_id' => $ids['web_folder'], 'username' => 'folderuser', 'password' => 'x', 'active' => 'y',
        ]);

        return $ids;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function emptySnapshotTables(): array
    {
        return array_fill_keys($this->lockSnapshotTables, []);
    }
}
