<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApiKeyRequest;
use App\Services\ApiKeyService;
use Illuminate\Http\JsonResponse;

/**
 * API Keys (contract: api/modules/system/api-keys.yaml, spec 014).
 *
 * Thin HTTP layer over ApiKeyService. Admin-only through the system module
 * gate. api_keys is API-owned, so no write is journaled to sys_datalog; the
 * plaintext key appears only in the create response.
 */
class ApiKeyController extends Controller
{
    public function __construct(protected ApiKeyService $service) {}

    /**
     * POST /system/api-keys — 201 with the key metadata plus the one-time
     * plaintext key.
     */
    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        [$key, $plaintext] = $this->service->mint((string) $request->input('name'), $request->clientId());

        return response()->json($this->service->present([$key])[0] + ['key' => $plaintext], 201);
    }
}
