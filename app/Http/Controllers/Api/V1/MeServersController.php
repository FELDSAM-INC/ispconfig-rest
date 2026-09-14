<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ServerAssignmentService;
use App\Support\IspContext;
use Illuminate\Http\JsonResponse;

/**
 * GET /me/servers — servers the calling key may use per service
 * (contract: api/modules/me/servers.yaml, spec 016 US2). Available to every
 * valid key; not admin-gated.
 */
class MeServersController extends Controller
{
    public function __construct(
        protected ServerAssignmentService $servers,
        protected IspContext $context,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json($this->servers->assignedServersView($this->context->authScope()));
    }
}
