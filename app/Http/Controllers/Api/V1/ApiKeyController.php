<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApiKeyRequest;
use App\Http\Requests\UpdateApiKeyRequest;
use App\Models\ApiKey;
use App\Services\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * API Keys (contract: api/modules/system/api-keys.yaml, spec 014).
 *
 * Thin HTTP layer over ApiKeyService. Admin-only through the system module
 * gate. api_keys is API-owned, so no write is journaled to sys_datalog; the
 * plaintext key appears only in the create response, and no response ever
 * contains the stored hash.
 */
class ApiKeyController extends Controller
{
    use HandlesListQuery;

    public function __construct(protected ApiKeyService $service) {}

    /**
     * GET /system/api-keys — filtered, sorted, paginated list.
     *
     * client_id selects keys bound to the client's control-panel users
     * (client or reseller identity); an unknown client yields no rows.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ApiKey::query();

        $clientId = $request->query('client_id');

        if ($clientId !== null) {
            if (! is_string($clientId) || filter_var($clientId, FILTER_VALIDATE_INT) === false || (int) $clientId < 1) {
                throw new BadRequestHttpException("Invalid client id for filter 'client_id'.");
            }

            $this->service->whereBoundToClient($query, (int) $clientId);
        }

        $result = $this->listQuery(
            $query,
            $request,
            sortable: ['id', 'name', 'active', 'created_at', 'last_used_at'],
            defaultSort: 'id',
            filters: [
                'active' => 'boolean',
                'name' => 'wildcard',
            ],
            extra: ['client_id'],
        );

        $result['data'] = $this->service->present($result['data']);

        return response()->json($result);
    }

    /**
     * GET /system/api-keys/{id} — implicit binding 404s as problem+json.
     */
    public function show(ApiKey $apiKey): JsonResponse
    {
        return response()->json($this->service->present([$apiKey])[0]);
    }

    /**
     * POST /system/api-keys — 201 with the key metadata plus the one-time
     * plaintext key.
     */
    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        [$key, $plaintext] = $this->service->mint((string) $request->input('name'), $request->clientId());

        return response()->json($this->service->present([$key])[0] + ['key' => $plaintext], 201);
    }

    /**
     * PUT /system/api-keys/{id} — rename and/or activate/deactivate.
     * Deactivating the key that authenticates the request is 409.
     */
    public function update(UpdateApiKeyRequest $request, ApiKey $apiKey): JsonResponse
    {
        $changes = Arr::only($request->validated(), ['name', 'active']);

        if (array_key_exists('active', $changes) && ! $changes['active'] && $this->isCallingKey($request, $apiKey)) {
            throw new ConflictHttpException('The API key used for this request cannot revoke itself.');
        }

        $apiKey->fill($changes)->save();

        return response()->json($this->service->present([$apiKey->refresh()])[0]);
    }

    /**
     * DELETE /system/api-keys/{id} — 204. Deleting the key that
     * authenticates the request is 409.
     */
    public function destroy(Request $request, ApiKey $apiKey): Response
    {
        if ($this->isCallingKey($request, $apiKey)) {
            throw new ConflictHttpException('The API key used for this request cannot delete itself.');
        }

        $apiKey->delete();

        return response()->noContent();
    }

    /**
     * Whether the bound key is the one ApiKeyAuth authenticated this request
     * with (the dev key has no row and never matches).
     */
    protected function isCallingKey(Request $request, ApiKey $apiKey): bool
    {
        return (int) $request->attributes->get('api_key_id', 0) === (int) $apiKey->getKey();
    }
}
