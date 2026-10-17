<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ApiKeyService;
use App\Services\PanelUrlService;
use App\Services\ServerAssignmentService;
use App\Support\IspContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Me — identity of the calling API key (contract: api/modules/me/me.yaml,
 * spec 014). Available to every valid key; not admin-gated.
 */
class MeController extends Controller
{
    public function __construct(
        protected ApiKeyService $service,
        protected PanelUrlService $panel,
        protected ServerAssignmentService $servers,
    ) {}

    /**
     * GET /me — key id, name, scope, bound client and acted-as identity, and which services the account has a server
     * for (the lists of GET /me/servers are not empty), so a panel can leave out what the account cannot use.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->service->identity($request) + [
            'panel_url' => $this->panel->url(),
            'services' => $this->servers->availableServices(app(IspContext::class)->authScope()),
        ]);
    }
}
