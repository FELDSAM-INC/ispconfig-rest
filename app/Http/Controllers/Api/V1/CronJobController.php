<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCronJobRequest;
use App\Http\Requests\UpdateCronJobRequest;
use App\Models\CronJob;
use App\Services\ClientLimitService;
use App\Services\SitesService;
use App\Services\WebLogService;
use App\Services\WordPressCronService;
use App\Support\CronOutputLog;
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

    public function __construct(protected SitesService $service, protected ClientLimitService $limits) {}

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
        $outputLog = (bool) ($payload['output_log'] ?? false);
        unset($payload['output_log']);
        $parent = $this->service->parentDomain((int) $payload['parent_domain_id']);

        $job = new CronJob($payload);
        $job->forceFill(['type' => $this->service->deriveCronType((string) $payload['command'], $parent)]);
        $this->outputLog($job, (string) $payload['command'], $outputLog, null, $parent);
        $this->service->deriveServerAndGroup($job, $parent);
        $this->limits->checkCronLimits($job);

        DB::transaction(function () use ($job): void {
            $owner = DB::table('sys_group')->where('groupid', $job->sys_groupid)->value('client_id');
            if ($owner) {
                DB::table('client')->where('client_id', $owner)->lockForUpdate()->first();
            }
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
        app(WordPressCronService::class)->guard($cronJob);
        $current = CronOutputLog::parse((string) $cronJob->getAttributes()['command']);
        $payload = $request->payload();
        $outputLog = array_key_exists('output_log', $payload) ? (bool) $payload['output_log'] : $current !== null;
        unset($payload['output_log']);
        // The task's own command, without the managed prefix, unless the request replaces it
        $command = (string) ($payload['command'] ?? $current['command'] ?? $cronJob->getAttributes()['command']);
        $cronJob->fill(['command' => $command] + $payload);

        $parent = $this->service->parentDomain((int) $cronJob->getAttributes()['parent_domain_id']);
        $cronJob->forceFill([
            'type' => $this->service->deriveCronType($command, $parent),
        ]);
        $this->outputLog($cronJob, $command, $outputLog, $current['token'] ?? null, $parent);
        $this->service->deriveServerAndGroup($cronJob, $parent);
        $this->limits->checkCronLimits($cronJob);

        DB::transaction(function () use ($cronJob): void {
            $cronJob->save();
        });

        return response()->json($cronJob->refresh());
    }

    /**
     * GET /sites/cron-jobs/{id}/log — the last lines of the task's own output log, read by the web-log worker on the
     * website's server (`pending` until it answered; the client asks again).
     */
    public function log(Request $request, CronJob $cronJob): JsonResponse
    {
        $lines = $request->validate(['lines' => ['sometimes', 'integer', 'min:1', 'max:1000']])['lines'] ?? 200;

        return response()->json(app(WebLogService::class)->cron($cronJob, (int) $lines), 200, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * Own output log (spec 054): the native command gets the managed prefix and ISPConfig's shared log is turned off,
     * since it would take the output of the command's last part. URL tasks run through ISPConfig's wget line and keep
     * its options.
     */
    private function outputLog(CronJob $job, string $command, bool $enabled, ?string $token, object $parent): void
    {
        if (! $enabled || $job->getAttributes()['type'] === 'url') {
            $job->forceFill(['command' => $command]);

            return;
        }
        $job->forceFill(['command' => CronOutputLog::wrap($command, $token ?? CronOutputLog::token(), $job->getAttributes()['type'], (string) $parent->document_root), 'log' => false]);
    }

    /**
     * DELETE /sites/cron-jobs/{id} — 204; datalog `d`.
     */
    public function destroy(CronJob $cronJob): Response
    {
        app(WordPressCronService::class)->guard($cronJob);
        DB::transaction(function () use ($cronJob): void {
            $cronJob->delete();
        });

        return response()->noContent();
    }
}
