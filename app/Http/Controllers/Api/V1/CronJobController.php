<?php

namespace App\Http\Controllers\Api\V1;

use App\Extensions\Hooks;
use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCronJobRequest;
use App\Http\Requests\UpdateCronJobRequest;
use App\Models\CronJob;
use App\Services\ClientLimitService;
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
 * the parent web domain. For client and reseller keys the plan's task rules
 * apply on create and update (spec 035): `limit_cron_frequency` and the
 * URL-only case of `limit_cron_type`, checked by
 * ClientLimitService::checkCronLimits() before the write.
 */
class CronJobController extends Controller
{
    use HandlesListQuery;

    public function __construct(protected SitesService $service, protected ClientLimitService $limits, protected Hooks $hooks) {}

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
        [$fields, $payload] = $request->extensionSplit();
        $parent = $this->service->parentDomain((int) $payload['parent_domain_id']);

        $job = new CronJob($payload);
        $job->forceFill(['type' => $this->service->deriveCronType((string) $payload['command'], $parent)]);
        $this->service->deriveServerAndGroup($job, $parent);
        $this->limits->checkCronLimits($job);
        $this->hooks->runPreflight(CronJob::class, null, $fields, $payload);

        DB::transaction(function () use ($job, $fields): void {
            $owner = DB::table('sys_group')->where('groupid', $job->sys_groupid)->value('client_id');
            if ($owner) {
                DB::table('client')->where('client_id', $owner)->lockForUpdate()->first();
            }
            $this->hooks->runSaving($job, $fields, 'create');
            $job->save();
            $this->hooks->runSaved($job, $fields, 'create');
        });

        return response()->json($job->refresh(), 201);
    }

    /**
     * PUT /sites/cron-jobs/{id} — 200; type re-derived from the merged
     * command/parent (legacy onSubmit always derives).
     */
    public function update(UpdateCronJobRequest $request, CronJob $cronJob): JsonResponse
    {
        $this->hooks->runGuards($cronJob, 'update');
        [$fields, $payload] = $request->extensionSplit();
        // The task's own command (an extension's managed wrapper stays out of it), unless the request replaces it.
        $command = (string) ($payload['command'] ?? $cronJob->command);
        $cronJob->fill(['command' => $command] + $payload);

        $parent = $this->service->parentDomain((int) $cronJob->getAttributes()['parent_domain_id']);
        $cronJob->forceFill([
            'type' => $this->service->deriveCronType($command, $parent),
        ]);
        $this->service->deriveServerAndGroup($cronJob, $parent);
        $this->limits->checkCronLimits($cronJob);
        $this->hooks->runPreflight(CronJob::class, $cronJob, $fields, $payload);

        DB::transaction(function () use ($cronJob, $fields): void {
            $this->hooks->runSaving($cronJob, $fields, 'update');
            $cronJob->save();
            $this->hooks->runSaved($cronJob, $fields, 'update');
        });

        return response()->json($cronJob->refresh());
    }

    /**
     * DELETE /sites/cron-jobs/{id} — 204; datalog `d`.
     */
    public function destroy(CronJob $cronJob): Response
    {
        $this->hooks->runGuards($cronJob, 'delete');
        DB::transaction(function () use ($cronJob): void {
            $cronJob->delete();
        });

        return response()->noContent();
    }
}
