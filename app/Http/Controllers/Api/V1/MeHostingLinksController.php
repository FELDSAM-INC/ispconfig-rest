<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ReadsAccountQuery;
use App\Http\Controllers\Controller;
use App\Services\AccountCapabilitiesService;
use App\Services\HostingLinkService;
use App\Support\IspContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /me/hosting-links — the installation's database administration and web
 * file manager addresses as they apply to the calling key's account or a named
 * client (contract: api/modules/me/hosting-links.yaml, spec 036). Available to
 * every valid key; read-only.
 */
class MeHostingLinksController extends Controller
{
    use ReadsAccountQuery;

    public function __construct(
        private readonly AccountCapabilitiesService $capabilities,
        private readonly HostingLinkService $links,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->rejectUnknownParameters($request, ['client_id']);

        $clientId = $this->capabilities->resolveTarget(
            app(IspContext::class)->authScope(),
            $this->positiveIdParameter($request, 'client_id', 'client id')
        );

        return response()->json($this->links->links($clientId));
    }
}
