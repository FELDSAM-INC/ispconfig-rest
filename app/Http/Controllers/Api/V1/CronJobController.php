<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCronJobRequest;
use App\Http\Requests\UpdateCronJobRequest;
use App\Models\CronJob;
use App\Services\SitesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Cron Jobs (contract: api/modules/sites/cron-jobs.yaml; legacy:
 * cron_edit.php — table `cron`, PK `id`). `type` is derived server-side
 * (http(s) commands → url, otherwise the owning client's limit_cron_type,
 * `full` for admin-owned sites); server_id/sys_groupid always come from
 * the parent web domain. Per-client cron frequency/type limits are out of
 * scope for the admin-scoped API key (spec 006 Assumption 4).
 */
class CronJobController extends Controller
{
    use HandlesListQuery;

    public function __construct(protected SitesService $service) {}

    /**
     * GET /sites/cron-jobs — `search` matches the command.
     */
    public function index(Request $request): JsonResponse
    {
        $query = CronJob::query();

        if (is_string($search = $request->query('search')) && $search !== '') {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $query->where('command', 'like', '%'.$escaped.'%');
        }

        $result = $this->listQuery(
            $query,
            $request,
            sortable: ['id', 'server_id', 'parent_domain_id', 'type', 'active'],
            defaultSort: 'id',
            filters: [
                'parent_domain_id' => 'integer',
            ],
            extra: ['search'],
        );

        return response()->json($result);
    }

    /**
     * GET /sites/cron-jobs/{id}.
     */
    public function show(CronJob $cronJob): JsonResponse
    {
        return response()->json($cronJob);
    }

    /**
     * POST /sites/cron-jobs — 201; datalog `i` on table `cron` with the
     * derived type and parent-derived server/group.
     */
    public function store(StoreCronJobRequest $request): JsonResponse
    {
        $payload = $request->payload();
        $parent = $this->service->parentDomain((int) $payload['parent_domain_id']);

        $job = new CronJob($payload);
        $job->forceFill(['type' => $this->service->deriveCronType((string) $payload['command'], $parent)]);
        $this->service->deriveServerAndGroup($job, $parent);

        DB::transaction(function () use ($job): void {
            $job->save();
        });

        return response()->json($job->refresh(), 201);
    }

    /**
     * PUT /sites/cron-jobs/{id} — 200; type re-derived from the merged
     * command/parent (legacy onSubmit always derives).
     */
    public function update(UpdateCronJobRequest $request, CronJob $cronJob): JsonResponse
    {
        $cronJob->fill($request->payload());

        $parent = $this->service->parentDomain((int) $cronJob->getAttributes()['parent_domain_id']);
        $cronJob->forceFill([
            'type' => $this->service->deriveCronType((string) $cronJob->getAttributes()['command'], $parent),
        ]);
        $this->service->deriveServerAndGroup($cronJob, $parent);

        DB::transaction(function () use ($cronJob): void {
            $cronJob->save();
        });

        return response()->json($cronJob->refresh());
    }

    /**
     * DELETE /sites/cron-jobs/{id} — 204; datalog `d`.
     */
    public function destroy(CronJob $cronJob): Response
    {
        DB::transaction(function () use ($cronJob): void {
            $cronJob->delete();
        });

        return response()->noContent();
    }
}
