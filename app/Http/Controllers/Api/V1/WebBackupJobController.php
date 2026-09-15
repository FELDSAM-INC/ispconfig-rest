<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Models\RemoteAction;
use App\Models\WebDomain;
use App\Services\WebBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Backup jobs of a website (contract: api/modules/sites/web-backups.yaml,
 * feature 018) — sys_remoteaction rows attributed to the website (R5).
 */
class WebBackupJobController extends Controller
{
    use HandlesListQuery;

    public function __construct(protected WebBackupService $backups) {}

    /**
     * GET /sites/web-domains/{id}/backup-jobs — newest first; filters state, action.
     */
    public function index(Request $request, WebDomain $webDomain): JsonResponse
    {
        $query = $this->backups->jobsOf($webDomain);

        $state = $request->query('state');
        if ($state !== null) {
            if (! in_array($state, WebBackupService::JOB_STATES, true)) {
                throw new BadRequestHttpException("Invalid value for filter 'state'. Allowed: pending, ok, warning, error.");
            }

            // Any state outside the enum (in practice '') is exposed as error (R4).
            $state === 'error'
                ? $query->whereNotIn('action_state', ['pending', 'ok', 'warning'])
                : $query->where('action_state', $state);
        }

        $action = $request->query('action');
        if ($action !== null) {
            if (! is_string($action) || ! array_key_exists($action, WebBackupService::ACTION_TYPES)) {
                throw new BadRequestHttpException("Invalid value for filter 'action'. Allowed: backup, restore, download, delete.");
            }
            $query->whereIn('action_type', WebBackupService::ACTION_TYPES[$action]);
        }

        $result = $this->listQuery(
            $query,
            $request,
            sortable: ['created_at', 'id'],
            defaultSort: 'created_at',
            extra: ['state', 'action'],
            defaultOrder: 'desc',
            sortAliases: ['created_at' => 'tstamp', 'id' => 'action_id'],
        );

        $jobs = collect($result['data']);
        $backups = $this->backups->referencedBackups($webDomain, $jobs);

        $result['data'] = $jobs
            ->map(fn (RemoteAction $job): array => $this->backups->jobRepresentation($job, $webDomain, $backups))
            ->values()
            ->all();

        return response()->json($result);
    }

    /**
     * GET /sites/web-domains/{id}/backup-jobs/{job_id}
     */
    public function show(WebDomain $webDomain, int $job): JsonResponse
    {
        $model = $this->backups->jobsOf($webDomain)->whereKey($job)->firstOrFail();

        return response()->json(
            $this->backups->jobRepresentation($model, $webDomain, $this->backups->referencedBackups($webDomain, [$model]))
        );
    }
}
