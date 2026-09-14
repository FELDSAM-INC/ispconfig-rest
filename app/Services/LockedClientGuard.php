<?php

namespace App\Services;

use App\Models\BaseModel;
use App\Support\IspContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * Locked-client write guard (spec 019 FR-013, owner decision 2026-09-14 —
 * an intentional deviation from legacy, which allows these writes).
 *
 * While a client is locked, non-admin keys (client and reseller scopes) may
 * not re-enable a lock-managed column of a record owned by that client, nor
 * create a record of the lock table list for it. Denials throw before any
 * DB write, so no sys_datalog row is produced (403 problem+json, same path
 * as the spec 011 write gate). Admin scopes are never restricted.
 */
class LockedClientGuard
{
    public const MESSAGE = 'The account is locked; its services cannot be enabled or added.';

    /**
     * @param  array<string, mixed>  $original  raw record as loaded (updates)
     */
    public function check(BaseModel $model, bool $isCreate, array $original = []): void
    {
        if (App::make(IspContext::class)->authScope()->isAdmin || ! $model->hasSysFields()) {
            return;
        }

        $columns = ClientLockService::lockColumns($model->getTable());

        if ($columns === []) {
            return;
        }

        $attributes = $model->getAttributes();

        if ($isCreate) {
            $groupId = (int) ($attributes['sys_groupid'] ?? 0);
        } else {
            if (! $this->reenables($columns, $original, $attributes)) {
                return;
            }

            $groupId = (int) ($original['sys_groupid'] ?? 0);
        }

        if ($groupId > 0 && $this->ownerIsLocked($groupId)) {
            throw new AuthorizationException(self::MESSAGE);
        }
    }

    /**
     * Whether the update moves a lock-managed column from its disabled to its
     * enabled value.
     *
     * @param  array<string, bool>  $columns  column => reversed
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $attributes
     */
    protected function reenables(array $columns, array $original, array $attributes): bool
    {
        foreach ($columns as $column => $reversed) {
            if (! array_key_exists($column, $attributes)) {
                continue;
            }

            $old = strtolower((string) ($original[$column] ?? ''));
            $new = strtolower((string) $attributes[$column]);

            $wasDisabled = $reversed ? $old === 'y' : $old !== 'y';
            $isEnabled = $reversed ? $new !== 'y' : $new === 'y';

            if ($wasDisabled && $isEnabled) {
                return true;
            }
        }

        return false;
    }

    protected function ownerIsLocked(int $groupId): bool
    {
        return DB::table('sys_group')
            ->join('client', 'client.client_id', '=', 'sys_group.client_id')
            ->where('sys_group.groupid', $groupId)
            ->where('client.locked', 'y')
            ->exists();
    }
}
