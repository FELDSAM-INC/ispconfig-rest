<?php

namespace App\Services;

use App\Models\RemoteAction;
use App\Models\WebDomain;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Queues backup remote actions exactly like legacy plugin_backuplist.inc.php
 * (spec 018 research R1-R3, R9): direct inserts into sys_remoteaction — the
 * documented Principle II exception (plan.md Complexity Tracking).
 */
class RemoteActionService
{
    public function __construct(protected WebBackupService $backups) {}

    /**
     * Queue one action row per target server.
     *
     * Inside a transaction the website row is locked (serialises concurrent
     * API requests for the same website; no-op on SQLite), then:
     *  - every target server must have backups configured → 409 (R9);
     *  - a pending action with the same type and parameter → 409 (R3).
     *
     * @param  array<int, int>  $serverIds
     * @return array<int, RemoteAction>
     */
    public function queue(WebDomain $website, string $actionType, string $param, array $serverIds): array
    {
        $serverIds = array_values(array_unique(array_map('intval', $serverIds)));

        return DB::transaction(function () use ($website, $actionType, $param, $serverIds): array {
            WebDomain::query()->whereKey($website->getKey())->lockForUpdate()->first();

            foreach ($serverIds as $serverId) {
                if (! $this->backups->backupsAvailable($serverId)) {
                    throw new ConflictHttpException('Backups are not configured on the server that processes this action.');
                }
            }

            $pending = RemoteAction::query()
                ->where('action_state', 'pending')
                ->where('action_type', $actionType)
                ->where('action_param', $param)
                ->exists();

            if ($pending) {
                throw new ConflictHttpException('The same backup action is already pending.');
            }

            $actions = [];

            foreach ($serverIds as $serverId) {
                $id = DB::table('sys_remoteaction')->insertGetId([
                    'server_id' => $serverId,
                    'tstamp' => time(),
                    'action_type' => $actionType,
                    'action_param' => $param,
                    'action_state' => 'pending',
                    'response' => '',
                ], 'action_id');

                $actions[] = RemoteAction::query()->findOrFail($id);
            }

            return $actions;
        });
    }
}
