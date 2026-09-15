<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\ReadsAccountQuery;
use App\Http\Controllers\Controller;
use App\Services\AccountCapabilitiesService;
use App\Services\AccountMailService;
use App\Support\IspContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /me/mail-settings — email program settings of the calling key's
 * account or a named client (contract: api/modules/me/mail-settings.yaml,
 * spec 025 US2). Available to every valid key; read-only.
 */
class MeMailSettingsController extends Controller
{
    use ReadsAccountQuery;

    public function __construct(
        private readonly AccountCapabilitiesService $capabilities,
        private readonly AccountMailService $mail,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->rejectUnknownParameters($request, ['client_id']);

        $clientId = $this->capabilities->resolveTarget(
            app(IspContext::class)->authScope(),
            $this->positiveIdParameter($request, 'client_id', 'client id')
        );

        return response()->json($this->mail->settings($clientId));
    }
}
