<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebBackupRequest;
use App\Models\RemoteAction;
use App\Models\WebBackup;
use App\Models\WebDomain;
use App\Services\LockedClientGuard;
use App\Services\RemoteActionService;
use App\Services\WebBackupService;
use App\Support\IspContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
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
     * POST /sites/web-domains/{id}/backups — queue an on-demand backup: `web`
     * on the website's server, `mysql` once per database server (R2); 201 with
     * the queued jobs. Update permission is checked by the request.
     */
    public function store(StoreWebBackupRequest $request, WebDomain $webDomain): JsonResponse
    {
        app(LockedClientGuard::class)->checkBackupWrite($webDomain);

        if ($request->validated()['type'] === 'web') {
            $jobs = $this->actions->queue(
                $webDomain,
                'backup_web_files',
                (string) $webDomain->getKey(),
                [(int) $webDomain->getAttributes()['server_id']],
            );
        } else {
            $servers = $this->backups->databaseServerIds($webDomain);

            if ($servers === []) {
                throw ValidationException::withMessages(['type' => 'The website has no databases to back up.']);
            }

            $jobs = $this->actions->queue($webDomain, 'backup_database', (string) $webDomain->getKey(), $servers);
        }

        return response()->json([
            'data' => array_map(
                fn (RemoteAction $job): array => $this->backups->jobRepresentation($job, $webDomain, []),
                $jobs
            ),
        ], 201);
    }

    /**
     * DELETE /sites/web-domains/{id}/backups/{backup_id} — queue backup_delete
     * on the server that stores the backup; 204. Requires update permission.
     */
    public function destroy(WebDomain $webDomain, int $backup): Response
    {
        $this->requireUpdate($webDomain);
        app(LockedClientGuard::class)->checkBackupWrite($webDomain);

        $model = $this->backups->backupOfWebsite($webDomain, $backup);

        $this->actions->queue(
            $webDomain,
            'backup_delete',
            (string) $model->getKey(),
            [$this->backups->actionServerId($model, $webDomain)],
        );

        return response()->noContent();
    }

    /**
     * POST /sites/web-domains/{id}/backups/{backup_id}/restore — 201 job;
     * requires update permission on the website.
     */
    public function restore(WebDomain $webDomain, int $backup): JsonResponse
    {
        $this->requireUpdate($webDomain);
        app(LockedClientGuard::class)->checkBackupWrite($webDomain);

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
     * POST /sites/web-domains/{id}/backups/{backup_id}/download — 201 job.
     * Read permission is enough (legacy checks getAuthSQL('r'), enforced by
     * the website binding); only backups stored on the website's server can
     * be copied into its backup folder (download_available, R7).
     */
    public function download(WebDomain $webDomain, int $backup): JsonResponse
    {
        $model = $this->backups->backupOfWebsite($webDomain, $backup);

        if (! $this->backups->backupRepresentation($model, $webDomain)['download_available']) {
            throw ValidationException::withMessages([
                'backup_id' => 'The backup is stored on another server than the website and cannot be downloaded.',
            ]);
        }

        [$job] = $this->actions->queue(
            $webDomain,
            'backup_download',
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
