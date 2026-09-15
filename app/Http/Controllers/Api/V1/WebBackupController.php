<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Models\WebBackup;
use App\Models\WebDomain;
use App\Services\RemoteActionService;
use App\Services\WebBackupService;
use App\Support\IspContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Website backups (contract: api/modules/sites/web-backups.yaml, feature 018).
 * The website binding (read predicate) and the scope.backup gate run before
 * every action; actions are queued as sys_remoteaction rows.
 */
class WebBackupController extends Controller
{
    use HandlesListQuery;

    public function __construct(
        protected WebBackupService $backups,
        protected RemoteActionService $actions,
    ) {}

    /**
     * GET /sites/web-domains/{id}/backups — newest first; filters type, job.
     */
    public function index(Request $request, WebDomain $webDomain): JsonResponse
    {
        $query = $this->backups->visibleBackups($webDomain);

        $type = $request->query('type');
        if ($type !== null) {
            if (! in_array($type, ['web', 'mysql', 'mongodb'], true)) {
                throw new BadRequestHttpException("Invalid value for filter 'type'. Allowed: web, mysql, mongodb.");
            }
            $query->where('backup_type', $type);
        }

        $job = $request->query('job');
        if ($job !== null) {
            if (! in_array($job, ['manual', 'auto'], true)) {
                throw new BadRequestHttpException("Invalid value for filter 'job'. Allowed: manual, auto.");
            }
            $query->where('filename', $job === 'manual' ? 'like' : 'not like', 'manual-%');
        }

        $result = $this->listQuery(
            $query,
            $request,
            sortable: ['created_at', 'id'],
            defaultSort: 'created_at',
            extra: ['type', 'job'],
            defaultOrder: 'desc',
            sortAliases: ['created_at' => 'tstamp', 'id' => 'backup_id'],
        );

        $result['data'] = collect($result['data'])
            ->map(fn (WebBackup $backup): array => $this->backups->backupRepresentation($backup, $webDomain))
            ->values()
            ->all();

        return response()->json($result);
    }

    /**
     * GET /sites/web-domains/{id}/backups/{backup_id}
     */
    public function show(WebDomain $webDomain, int $backup): JsonResponse
    {
        $model = $this->backups->visibleBackups($webDomain)->whereKey($backup)->firstOrFail();

        return response()->json($this->backups->backupRepresentation($model, $webDomain));
    }

    /**
     * POST /sites/web-domains/{id}/backups/{backup_id}/restore — 201 job;
     * requires update permission on the website.
     */
    public function restore(WebDomain $webDomain, int $backup): JsonResponse
    {
        $this->requireUpdate($webDomain);

        $model = $this->backups->backupOfWebsite($webDomain, $backup);

        [$job] = $this->actions->queue(
            $webDomain,
            'backup_restore',
            (string) $model->getKey(),
            [$this->backups->actionServerId($model, $webDomain)],
        );

        return response()->json(
            $this->backups->jobRepresentation($job, $webDomain, [(int) $model->getKey() => $model]),
            201
        );
    }

    /**
     * Legacy plugin_backuplist checks getAuthSQL('u') for restore, delete and
     * on-demand backups (research R8 step 4).
     */
    protected function requireUpdate(WebDomain $website): void
    {
        if (! app(IspContext::class)->authScope()->allows($website->getAttributes(), 'u')) {
            throw new AuthorizationException('You do not have permission to update this resource.');
        }
    }
}
