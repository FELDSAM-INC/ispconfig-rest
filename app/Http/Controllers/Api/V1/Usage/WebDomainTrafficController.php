<?php

namespace App\Http\Controllers\Api\V1\Usage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Usage\TrafficHistoryRequest;
use App\Models\WebDomain;
use App\Services\TrafficPeriodService;
use App\Services\UsageService;
use Illuminate\Http\JsonResponse;

/**
 * Website traffic history (contract: api/modules/usage/web-domains.yaml
 * `/usage/web-domains/{id}/traffic`, spec 017 US3).
 */
class WebDomainTrafficController extends Controller
{
    public function __construct(private readonly TrafficPeriodService $traffic) {}

    /**
     * GET /usage/web-domains/{id}/traffic — monthly points, or daily points of
     * the current month with `granularity=day`; 404 when unreadable.
     */
    public function show(TrafficHistoryRequest $request, int $id): JsonResponse
    {
        $site = WebDomain::query()
            ->readable()
            ->whereIn('type', UsageService::WEB_TYPES)
            ->whereKey($id)
            ->firstOrFail();

        return response()->json($this->traffic->webHistory(
            (string) $site->getAttributes()['domain'],
            $request->months(),
            $request->granularity(),
        ));
    }
}
