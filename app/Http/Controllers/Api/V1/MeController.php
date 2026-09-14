<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Me — identity of the calling API key (contract: api/modules/me/me.yaml,
 * spec 014). Available to every valid key; not admin-gated.
 */
class MeController extends Controller
{
    public function __construct(protected ApiKeyService $service) {}

    /**
     * GET /me — key id, name, scope, bound client and acted-as identity.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->service->identity($request));
    }
}
