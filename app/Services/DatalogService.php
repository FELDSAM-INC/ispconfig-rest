<?php

namespace App\Services;

use App\Support\IspContext;
use Illuminate\Support\Facades\DB;

/**
 * Writes ISPConfig sys_datalog rows in the exact format the legacy interface
 * produces (source_code/interface/lib/classes/db_mysql.inc.php —
 * datalogSave() + diffrec()). ISPConfig server daemons parse these rows, so
 * this format is a hard compatibility contract:
 *
 *  dbtable     Target table name, e.g. "mail_domain".
 *  dbidx       "<primary key column>:<primary key value>", e.g. "domain_id:7".
 *  server_id   old record's server_id when > 0, overridden by the new
 *              record's server_id whenever the new record carries the key;
 *              0 otherwise.
 *  action      'i' (insert), 'u' (update) or 'd' (delete).
 *  tstamp      Unix timestamp of the write.
 *  user        Username of the acting interface user. Legacy reads
 *              $_SESSION['s']['user']['username'] with fallback 'admin';
 *              here it is resolved from sys_user.username via IspContext.
 *  data        PHP serialize() of the FULL differential record produced by
 *              legacy diffrec(): an array with keys 'old' and 'new' where
 *              BOTH sides contain EVERY column of the record — unchanged
 *              columns carry the same value on both sides, changed columns
 *              carry their respective values, and a missing counterpart is
 *              null. Key order matters for byte parity: updates/deletes
 *              (old record present) serialize 'old' before 'new'; inserts
 *              serialize 'new' before 'old'. Values are DB-native strings
 *              or null — legacy reads rows through mysqli, which returns
 *              every column as a string — never PHP ints/bools.
 *  status      'ok' (the column default; legacy does not set it explicitly).
 *  session_id  Legacy writes PHP's session_id(), grouping all rows of one
 *              interface request (e.g. a delete cascade); we write the
 *              request-scoped id from IspContext for the same semantics.
 *
 * A row is only written when at least one column actually changed
 * (diff_num > 0), mirroring legacy no-change suppression. Pass $force = true
 * to emit a row for an unchanged record (legacy $force_update, used by
 * resync — a documented constitution Principle II exception).
 */
class DatalogService
{
    /**
     * Write one sys_datalog row (mirror of legacy datalogSave()).
     *
     * @param  string  $table  target table, e.g. 'mail_domain'
     * @param  string  $primaryKey  primary key column, e.g. 'domain_id'
     * @param  int|string  $primaryKeyValue  primary key value
     * @param  string  $action  'i', 'u' or 'd'
     * @param  array<string, mixed>  $oldRecord  full record before the change ([] on insert)
     * @param  array<string, mixed>  $newRecord  full record after the change ([] on delete)
     * @param  bool  $force  emit a row even when nothing changed (resync semantics)
     * @return int|null the new datalog_id, or null when suppressed (no changes)
     */
    public function log(string $table, string $primaryKey, int|string $primaryKeyValue, string $action, array $oldRecord, array $newRecord, bool $force = false): ?int
    {
        $oldRecord = $this->normalizeRecord($oldRecord);
        $newRecord = $this->normalizeRecord($newRecord);

        if ($force) {
            // Legacy force path: full new/old state, 'new' key first.
            $diffRecord = ['new' => $newRecord, 'old' => $oldRecord];
            $diffNum = count($newRecord);
        } else {
            [$diffNum, $diffRecord] = $this->diffRecord($oldRecord, $newRecord);
        }

        if ($diffNum < 1) {
            return null; // No actual change — legacy writes nothing.
        }

        // server_id resolution, verbatim from legacy datalogSave().
        $serverId = (isset($oldRecord['server_id']) && (int) $oldRecord['server_id'] > 0)
            ? (int) $oldRecord['server_id']
            : 0;
        if (isset($newRecord['server_id'])) {
            $serverId = (int) $newRecord['server_id'];
        }

        $context = app(IspContext::class);

        return (int) DB::table('sys_datalog')->insertGetId([
            'dbtable' => $table,
            'dbidx' => $primaryKey.':'.$primaryKeyValue,
            'server_id' => $serverId,
            'action' => $action,
            'tstamp' => time(),
            'user' => $context->username(),
            'data' => serialize($diffRecord),
            'status' => 'ok',
            'session_id' => $context->sessionId(),
        ], 'datalog_id');
    }

    /**
     * Insert a row and datalog it (mirror of legacy datalogInsert()):
     * INSERT, re-SELECT the complete row (so DB defaults are part of the
     * payload), then log action 'i' with an empty old record.
     *
     * Intended for side-effect writes to tables without a dedicated model
     * (cascades, DNS records); model writes go through BaseModel::save().
     *
     * @param  array<string, mixed>  $data  column => DB-native value
     * @return int the new primary key value
     */
    public function insertRecord(string $table, string $primaryKey, array $data): int
    {
        $id = (int) DB::table($table)->insertGetId($data, $primaryKey);
        $newRecord = (array) DB::table($table)->where($primaryKey, $id)->first();

        $this->log($table, $primaryKey, $id, 'i', [], $newRecord);

        return $id;
    }

    /**
     * Update a row and datalog it (mirror of legacy datalogUpdate()):
     * SELECT old, UPDATE, re-SELECT new, log action 'u' (suppressed when
     * nothing changed unless $force).
     *
     * @param  array<string, mixed>  $data  column => DB-native value
     */
    public function updateRecord(string $table, string $primaryKey, int|string $id, array $data, bool $force = false): void
    {
        $oldRecord = (array) DB::table($table)->where($primaryKey, $id)->first();

        DB::table($table)->where($primaryKey, $id)->update($data);

        $newRecord = (array) DB::table($table)->where($primaryKey, $id)->first();

        $this->log($table, $primaryKey, $id, 'u', $oldRecord, $newRecord, $force);
    }

    /**
     * Delete a row and datalog it (mirror of legacy datalogDelete()):
     * SELECT the full old record, DELETE, log action 'd' with an empty
     * new record.
     */
    public function deleteRecord(string $table, string $primaryKey, int|string $id): void
    {
        $oldRecord = (array) DB::table($table)->where($primaryKey, $id)->first();

        if ($oldRecord === []) {
            return;
        }

        DB::table($table)->where($primaryKey, $id)->delete();

        $this->log($table, $primaryKey, $id, 'd', $oldRecord, []);
    }

    /**
     * Faithful port of legacy db::diffrec().
     *
     * When the old record is non-empty its keys drive the iteration and
     * 'old' is assigned before 'new' (serialized key order!); otherwise the
     * new record drives and 'new' comes first. Comparison is PHP loose
     * inequality exactly like legacy's `@$record_new[$key] != $val`.
     *
     * @return array{0: int, 1: array{old?: array<string, mixed>, new?: array<string, mixed>}}
     */
    protected function diffRecord(array $oldRecord, array $newRecord): array
    {
        $diff = [];
        $diffNum = 0;

        if ($oldRecord !== []) {
            foreach ($oldRecord as $key => $value) {
                $newValue = $newRecord[$key] ?? null;
                if ($newValue != $value) {
                    $diff['old'][$key] = $value;
                    $diff['new'][$key] = $newValue;
                    $diffNum++;
                } else {
                    $diff['old'][$key] = $value;
                    $diff['new'][$key] = $value;
                }
            }
        } else {
            foreach ($newRecord as $key => $value) {
                if ($value != null) {
                    $diff['new'][$key] = $value;
                    $diff['old'][$key] = null;
                    $diffNum++;
                } else {
                    $diff['new'][$key] = $value;
                    $diff['old'][$key] = $value;
                }
            }
        }

        return [$diffNum, $diff];
    }

    /**
     * Coerce record values to what legacy sees when reading rows through
     * mysqli: every column is a string, NULL columns are null. Serializing
     * PHP ints/bools would change the payload bytes and could break strict
     * comparisons in server plugins.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, string|null>
     */
    protected function normalizeRecord(array $record): array
    {
        $normalized = [];

        foreach ($record as $key => $value) {
            if ($value === null) {
                $normalized[$key] = null;
            } elseif (is_bool($value)) {
                // Defensive: models store DB-native 'y'/'n' strings, so raw
                // booleans should never reach a datalog payload.
                $normalized[$key] = $value ? '1' : '0';
            } else {
                $normalized[$key] = (string) $value;
            }
        }

        return $normalized;
    }
}
