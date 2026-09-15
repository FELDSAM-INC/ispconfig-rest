<?php

namespace App\Http\Controllers\Api\V1\Usage;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Api\V1\Usage\Concerns\UsageListQuery;
use App\Http\Controllers\Controller;
use App\Models\WebDomain;
use App\Services\UsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Website usage (contract: api/modules/usage/web-domains.yaml, spec 017 US2).
 *
 * Lists run on the WebDomain model through HandlesListQuery (read predicate,
 * strict parameters, pagination); UsageService projects each page into the
 * WebDomainUsage shape. Only vhost-type websites carry usage figures.
 */
class WebDomainUsageController extends Controller
{
    use HandlesListQuery;
    use UsageListQuery;

    public function __construct(private readonly UsageService $usage) {}

    /**
     * GET /usage/web-domains
     */
    public function index(Request $request): JsonResponse
    {
        $this->rejectClientIdForClientKeys($request);

        $result = $this->listQuery(
            WebDomain::query()->whereIn('type', UsageService::WEB_TYPES),
            $request,
            sortable: ['domain'],
            defaultSort: 'domain',
            filters: [
                'domain' => 'wildcard',
                'parent_domain_id' => 'integer',
                'client_id' => 'owning_client',
            ],
        );

        $result['data'] = $this->usage->webDomainRows($result['data']);

        return response()->json($result);
    }

    /**
     * GET /usage/web-domains/{id} — 404 when unreadable or not a vhost-type website.
     */
    public function show(int $id): JsonResponse
    {
        $site = WebDomain::query()
            ->readable()
            ->whereIn('type', UsageService::WEB_TYPES)
            ->whereKey($id)
            ->firstOrFail();

        return response()->json($this->usage->webDomainRows([$site])[0]);
    }
}
