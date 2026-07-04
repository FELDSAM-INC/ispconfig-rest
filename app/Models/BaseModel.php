<?php

namespace App\Models;

use App\Services\ClientLimitService;
use App\Services\DatalogService;
use App\Support\AuthScope;
use App\Support\IspContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
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
     * Whether this model's table carries the ISPConfig system fields (and is
     * therefore row-scopable — spec 011 FR-006).
     */
    public function hasSysFields(): bool
    {
        return $this->hasSysFields;
    }

    /**
     * Scope: rows the acting AuthScope may read — the legacy getAuthSQL('r')
     * triplet (spec 011 FR-006; parity tform_base.inc.php:1750-1765). No-op
     * for admin scopes and tables without sys fields.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeReadable($query)
    {
        if (! $this->hasSysFields) {
            return $query;
        }

        return $this->authScope()->applyReadPredicate($query, 'r');
    }

    /**
     * Route-model binding resolves through the read predicate: a row the
     * acting scope cannot read does not exist for it — ModelNotFoundException
     * ⇒ 404 problem+json, indistinguishable from a missing id (spec 011
     * FR-008/FR-009; parity tform_base.inc.php:1724-1731 getDataRecord()).
     * Nested sub-resources inherit this through their parent binding.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);

        if ($this->hasSysFields) {
            $this->authScope()->applyReadPredicate($query, 'r');
        }

        return $query;
    }

    /**
     * The acting authorization scope (request-scoped; admin by default from
     * CLI/tests — FR-025).
     */
    protected function authScope(): AuthScope
    {
        return App::make(IspContext::class)->authScope();
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

            // Client resource-limit enforcement (spec 012 FR-005): count- and
            // quota-limits are checked at this one create chokepoint (after
            // sys-field forcing, before any DB write) so a denial throws a 403
            // and writes no sys_datalog row. Admin scopes and unmapped tables
            // short-circuit inside the service (zero overhead — 011 regression
            // bar).
            $limits = App::make(ClientLimitService::class);
            $limits->checkCreate($this);
            $limits->checkQuotaSum($this);
        }

        // The 'old' record for updates: raw attributes as originally loaded
        // from the DB, captured before parent::save() syncs the original.
        $oldRecord = $wasUpdate ? $this->getRawOriginal() : [];

        // Write gate (spec 011 FR-011): a non-admin scope needs 'u' on the
        // ORIGINAL row under the legacy checkPerm triplet. Denied BEFORE any
        // DB write, so no datalog row is produced (parity
        // tform_base.inc.php:1626/1574 — legacy denies the UPDATE and its
        // WHERE getAuthSQL('u') would match nothing).
        if ($wasUpdate && $this->hasSysFields) {
            if (! $this->authScope()->allows($oldRecord, 'u')) {
                throw new AuthorizationException('You do not have permission to update this resource.');
            }

            // Quota-SUM limits are re-checked on update (spec 012 FR-027): a
            // quota bump alters the sum. Row-count limits are create-only.
            App::make(ClientLimitService::class)->checkQuotaSum($this);
        }

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

        // Delete gate (spec 011 FR-011): non-admin scopes need 'd' on the
        // row (parity tform_actions.inc.php:328-332 — legacy errors with
        // error_no_delete_permission). Denied before the DELETE runs, so no
        // datalog row is produced.
        if ($this->hasSysFields && ! $this->authScope()->allows($oldRecord, 'd')) {
            throw new AuthorizationException('You do not have permission to delete this resource.');
        }

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
     * ISPConfig system fields for inserts, with the legacy tform auth_preset
     * permission defaults.
     *
     * Admin scopes: today's defaults-if-absent behavior — values already set
     * (by the model's $attributes or a service pre-setting ownership, e.g.
     * ClientService reseller stamping) win.
     *
     * Non-admin scopes: the identity pair is FORCED from the acting scope,
     * overwriting anything pre-set — exactly as legacy INSERT always stamps
     * the session user (spec 011 FR-012; parity tform_base.inc.php:
     * 1548-1561). Permission letters keep defaults-if-absent so per-resource
     * presets survive (spamfilter_policy's sys_perm_other='r').
     */
    protected function applySysFieldDefaults(): void
    {
        $context = App::make(IspContext::class);
        $scope = $context->authScope();

        if (! $scope->isAdmin) {
            $this->setAttribute('sys_userid', $scope->sysUserId);
            $this->setAttribute('sys_groupid', $scope->sysGroupId);
        }

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
