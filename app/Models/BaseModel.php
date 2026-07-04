<?php

namespace App\Models;

use App\Services\DatalogService;
use App\Support\IspContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

/**
 * Base class for every model that maps an ISPConfig table (constitution
 * Principle II — datalog-only writes).
 *
 * save() and delete() perform the actual row change AND record it in
 * sys_datalog through DatalogService, exactly like the legacy interface's
 * datalogInsert()/datalogUpdate()/datalogDelete() (db_mysql.inc.php):
 *
 *  - insert: INSERT, then re-read the complete row from the DB (so column
 *    defaults the API never set are part of the payload) and log action 'i'
 *    with old = [].
 *  - update: log action 'u' with old = the record as originally loaded and
 *    new = the re-read row. DatalogService diffs the two and writes NOTHING
 *    when no column actually changed (legacy no-change suppression).
 *  - delete: log action 'd' with old = the full record, new = [].
 *
 * All records passed to the datalog are RAW attribute arrays (DB-native
 * 'y'/'n' strings etc.), never cast values — the payload must match what
 * legacy serializes byte for byte (see DatalogService for the format
 * contract).
 *
 * On insert, ISPConfig system fields are defaulted from the request-scoped
 * IspContext (sys_userid/sys_groupid of the authenticated API key) and the
 * legacy tform auth_preset permissions (sys_perm_user/group 'riud',
 * sys_perm_other '' — see e.g. mail_domain.tform.php). server_id is
 * resource-specific and stays the responsibility of models/controllers.
 */
abstract class BaseModel extends Model
{
    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table;

    /**
     * ISPConfig tables have no created_at/updated_at.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Whether the table carries the ISPConfig system fields (sys_userid,
     * sys_groupid, sys_perm_*). Models for the few tables without them
     * override this with false.
     */
    protected bool $hasSysFields = true;

    /**
     * Force a datalog row on the next save even when nothing changed
     * (legacy $force_update, needed for resync). One-shot flag.
     */
    protected bool $forceDatalog = false;

    /**
     * Emit a datalog row on the next save() even if no column changes.
     */
    public function forceDatalog(bool $force = true): static
    {
        $this->forceDatalog = $force;

        return $this;
    }

    /**
     * Save the model and record the change in sys_datalog.
     *
     * @return bool
     */
    public function save(array $options = [])
    {
        $wasUpdate = $this->exists;

        if (! $wasUpdate && $this->hasSysFields) {
            $this->applySysFieldDefaults();
        }

        // The 'old' record for updates: raw attributes as originally loaded
        // from the DB, captured before parent::save() syncs the original.
        $oldRecord = $wasUpdate ? $this->getRawOriginal() : [];

        $saved = parent::save($options);

        if ($saved) {
            // Legacy re-SELECTs the complete row after the write so the
            // datalog payload contains every column (including DB defaults
            // this process never set). Mirror that.
            $newRecord = $this->freshRecordFromDatabase() ?? $this->getAttributes();

            App::make(DatalogService::class)->log(
                $this->getTable(),
                $this->getKeyName(),
                $this->getKey(),
                $wasUpdate ? 'u' : 'i',
                $oldRecord,
                $newRecord,
                $this->forceDatalog
            );

            $this->forceDatalog = false;
        }

        return $saved;
    }

    /**
     * Delete the model and record the deletion in sys_datalog.
     *
     * @return bool|null
     */
    public function delete()
    {
        if (is_null($this->getKeyName())) {
            throw new \LogicException('No primary key defined on model.');
        }

        if (! $this->exists) {
            return true;
        }

        // Full old record (legacy datalogDelete SELECTs it before deleting).
        $oldRecord = $this->freshRecordFromDatabase() ?? $this->getAttributes();
        $primaryKeyValue = $this->getKey();

        $deleted = parent::delete();

        if ($deleted) {
            App::make(DatalogService::class)->log(
                $this->getTable(),
                $this->getKeyName(),
                $primaryKeyValue,
                'd',
                $oldRecord,
                []
            );
        }

        return $deleted;
    }

    /**
     * Default the ISPConfig system fields for inserts from the authenticated
     * request context, with the legacy tform auth_preset permission defaults.
     * Values already set (by the model's $attributes or explicitly) win.
     */
    protected function applySysFieldDefaults(): void
    {
        $context = App::make(IspContext::class);

        $defaults = [
            'sys_userid' => $context->sysUserId(),
            'sys_groupid' => $context->sysGroupId(),
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
        ];

        foreach ($defaults as $field => $value) {
            if (! array_key_exists($field, $this->getAttributes())) {
                $this->setAttribute($field, $value);
            }
        }
    }

    /**
     * Re-read this row from the database as a raw column => value array,
     * bypassing casts and scopes (equivalent to legacy's SELECT * re-read).
     *
     * @return array<string, mixed>|null
     */
    protected function freshRecordFromDatabase(): ?array
    {
        $row = $this->newQueryWithoutScopes()
            ->getQuery()
            ->where($this->getKeyName(), $this->getKey())
            ->first();

        return $row === null ? null : (array) $row;
    }
}
