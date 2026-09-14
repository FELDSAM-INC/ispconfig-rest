<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client lock and cancel side effects (spec 019) — a port of legacy
 * interface/lib/classes/functions.inc.php func_client_lock() and
 * func_client_cancel() (ISPConfig 3.3.1p1) and their inline copy in
 * reseller_edit.php.
 *
 *  - lock: every record of the lock table list owned by the client's group
 *    is disabled through a datalog update that also sets sys_userid; the
 *    previous inactive states and differing owners are stored in the
 *    client.tmp_data snapshot (PHP serialize, legacy key layout).
 *  - unlock: records are enabled unless the snapshot recorded them as
 *    inactive; owners are rewritten to the client's control-panel user
 *    (legacy reads a `prev_sysuser` key lock never writes, so previous owners
 *    are never restored — mirrored on purpose); prev_active is dropped.
 *  - cancel: sys_user.active of the client's control-panel user(s).
 *
 * client.tmp_data and sys_user.active are written with plain queries, as
 * legacy does — documented constitution Principle II exceptions.
 */
class ClientLockService
{
    /**
     * Legacy $to_disable list in order: [table, key column, lock column,
     * reversed]. The legacy `mail_user_smtp` pseudo entry is the reversed
     * disablesmtp column of mail_user ('y' = sending disabled).
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: bool}>
     */
    public const LOCK_ENTRIES = [
        ['cron', 'id', 'active', false],
        ['ftp_user', 'ftp_user_id', 'active', false],
        ['mail_domain', 'domain_id', 'active', false],
        ['mail_user', 'mailuser_id', 'postfix', false],
        ['mail_user', 'mailuser_id', 'disablesmtp', true],
        ['mail_forwarding', 'forwarding_id', 'active', false],
        ['mail_get', 'mailget_id', 'active', false],
        ['openvz_vm', 'vm_id', 'active', false],
        ['shell_user', 'shell_user_id', 'active', false],
        ['webdav_user', 'webdav_user_id', 'active', false],
        ['web_database', 'database_id', 'active', false],
        ['web_domain', 'domain_id', 'active', false],
        ['web_folder', 'web_folder_id', 'active', false],
        ['web_folder_user', 'web_folder_user_id', 'active', false],
    ];

    /**
     * @var array<string, bool>
     */
    protected array $tableExists = [];

    public function __construct(protected DatalogService $datalog) {}

    /**
     * Lock-managed columns of a table: column => reversed.
     *
     * @return array<string, bool>
     */
    public static function lockColumns(string $table): array
    {
        $columns = [];

        foreach (self::LOCK_ENTRIES as [$entryTable, , $column, $reversed]) {
            if ($entryTable === $table) {
                $columns[$column] = $reversed;
            }
        }

        return $columns;
    }

    public static function enabledValue(bool $reversed): string
    {
        return $reversed ? 'n' : 'y';
    }

    public static function disabledValue(bool $reversed): string
    {
        return $reversed ? 'y' : 'n';
    }

    /**
     * Lock the client's services (func_client_lock with locked = 'y').
     *
     * @param  int|null  $writeUserId  sys_userid written to the records; null = the
     *                                 client's control-panel user (client form). The
     *                                 reseller form passes the acting user.
     */
    public function lock(int $clientId, ?int $writeUserId = null): void
    {
        [$clientUserId, $groupId] = $this->identity($clientId);
        $writeUserId ??= $clientUserId;

        $snapshot = $this->readSnapshot($clientId);
        $prevActive = [];
        $prevOwner = [];

        foreach (self::LOCK_ENTRIES as [$table, $key, $column, $reversed]) {
            $prevActive[$table] ??= [];
            $prevOwner[$table] ??= [];

            foreach ($this->records($table, $key, $column, $groupId) as $record) {
                $id = (int) $record->{$key};
                $value = (string) $record->{$column};

                if (! $reversed && $value !== 'y') {
                    $prevActive[$table][$id][$column] = 'n';
                } elseif ($reversed && $value === 'y') {
                    $prevActive[$table][$id][$column] = 'y';
                }

                if ($clientUserId === null || (int) $record->sys_userid !== $clientUserId) {
                    $prevOwner[$table][$id] = (string) $record->sys_userid;
                }

                $this->datalog->updateRecord($table, $key, $id, $this->recordUpdate($column, self::disabledValue($reversed), $writeUserId));
            }
        }

        $snapshot['prev_active'] = $prevActive;
        $snapshot['prev_sys_userid'] = $prevOwner;

        $this->writeSnapshot($clientId, $snapshot);
    }

    /**
     * Unlock the client's services (func_client_lock with locked = 'n').
     * Owners are always rewritten to the client's (or reseller's) own
     * control-panel user, as legacy does.
     */
    public function unlock(int $clientId): void
    {
        [$clientUserId, $groupId] = $this->identity($clientId);

        $snapshot = $this->readSnapshot($clientId);
        $prevActive = is_array($snapshot['prev_active'] ?? null) ? $snapshot['prev_active'] : [];

        foreach (self::LOCK_ENTRIES as [$table, $key, $column, $reversed]) {
            $inactive = self::disabledValue($reversed);

            foreach ($this->records($table, $key, $column, $groupId) as $record) {
                $id = (int) $record->{$key};
                $previous = $prevActive[$table][$id][$column] ?? null;
                $value = $previous == $inactive ? $inactive : self::enabledValue($reversed);

                $this->datalog->updateRecord($table, $key, $id, $this->recordUpdate($column, $value, $clientUserId));
            }
        }

        unset($snapshot['prev_active']);

        $this->writeSnapshot($clientId, $snapshot);
    }

    /**
     * Enable or disable the client's control-panel login
     * (func_client_cancel: canceled 'y' → active 0, 'n' → active 1).
     */
    public function setLoginActive(int $clientId, bool $active): void
    {
        DB::table('sys_user')->where('client_id', $clientId)->update(['active' => $active ? 1 : 0]);
    }

    /**
     * The lock snapshot; empty, missing or unreadable values are an empty
     * array (legacy unserialize guard).
     *
     * @return array<mixed>
     */
    public function readSnapshot(int $clientId): array
    {
        $raw = DB::table('client')->where('client_id', $clientId)->value('tmp_data');

        if ($raw === null || $raw === '') {
            return [];
        }

        $snapshot = @unserialize((string) $raw, ['allowed_classes' => false]);

        return is_array($snapshot) ? $snapshot : [];
    }

    /**
     * @param  array<mixed>  $snapshot
     */
    protected function writeSnapshot(int $clientId, array $snapshot): void
    {
        DB::table('client')->where('client_id', $clientId)->update(['tmp_data' => serialize($snapshot)]);
    }

    /**
     * The client's control-panel user and group (legacy queryOneRecord on
     * sys_user / sys_group by client_id).
     *
     * @return array{0: int|null, 1: int|null} [userid, groupid]
     */
    protected function identity(int $clientId): array
    {
        $userId = DB::table('sys_user')->where('client_id', $clientId)->value('userid');
        $groupId = DB::table('sys_group')->where('client_id', $clientId)->value('groupid');

        return [$userId === null ? null : (int) $userId, $groupId === null ? null : (int) $groupId];
    }

    /**
     * Records of one lock entry owned by the group. Tables absent from the
     * installation (openvz_vm) are skipped; ordering by key keeps the datalog
     * order deterministic.
     *
     * @return iterable<object>
     */
    protected function records(string $table, string $key, string $column, ?int $groupId): iterable
    {
        if ($groupId === null) {
            return [];
        }

        $this->tableExists[$table] ??= Schema::hasTable($table);

        if (! $this->tableExists[$table]) {
            return [];
        }

        return DB::table($table)
            ->where('sys_groupid', $groupId)
            ->orderBy($key)
            ->get([$key, 'sys_userid', $column]);
    }

    /**
     * @return array<string, int|string>
     */
    protected function recordUpdate(string $column, string $value, ?int $userId): array
    {
        $data = [$column => $value];

        if ($userId !== null) {
            $data['sys_userid'] = $userId;
        }

        return $data;
    }
}
