<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ReadsAccountQuery;
use App\Http\Controllers\Controller;
use App\Services\AccountCapabilitiesService;
use App\Support\IspContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /me/capabilities — website capabilities of the calling key's account or
 * a named client (contract: api/modules/me/capabilities.yaml, spec 021 US1).
 * Available to every valid key; read-only.
 */
class MeCapabilitiesController extends Controller
{
    use ReadsAccountQuery;

    public function __construct(private readonly AccountCapabilitiesService $capabilities) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->rejectUnknownParameters($request, ['client_id']);

        $clientId = $this->capabilities->resolveTarget(
            app(IspContext::class)->authScope(),
            $this->positiveIdParameter($request, 'client_id', 'client id')
        );

        return response()->json($this->capabilities->capabilities($clientId));
    }
}
