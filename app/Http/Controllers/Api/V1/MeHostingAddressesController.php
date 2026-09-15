<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ReadsAccountQuery;
use App\Http\Controllers\Controller;
use App\Services\AccountCapabilitiesService;
use App\Services\HostingAddressService;
use App\Support\IspContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /me/hosting-addresses — public addresses of the account's web and mail
 * servers and the name servers of its DNS servers, for the calling key's
 * account or a named client (contract: api/modules/me/hosting-addresses.yaml,
 * spec 031). Available to every valid key; read-only.
 */
class MeHostingAddressesController extends Controller
{
    use ReadsAccountQuery;

    public function __construct(
        private readonly AccountCapabilitiesService $capabilities,
        private readonly HostingAddressService $addresses,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->rejectUnknownParameters($request, ['client_id']);

        $clientId = $this->capabilities->resolveTarget(
            app(IspContext::class)->authScope(),
            $this->positiveIdParameter($request, 'client_id', 'client id')
        );

        return response()->json($this->addresses->addresses($clientId));
    }
}
