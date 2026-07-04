<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientCircleRequest;
use App\Http\Requests\UpdateClientCircleRequest;
use App\Models\ClientCircle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Client Circles (contract: api/modules/client/circles.yaml) — named,
 * comma-separated client-id lists (client_circle table) used by ISPConfig
 * to filter client lists. Every id in client_ids must exist (400 per the
 * contract); duplicate circle names fail the unique validation rule (422).
 * Unknown ids 404 as problem+json via implicit binding — including on
 * update (fixes spec 001 gap G3, which 500ed).
 */
class ClientCircleController extends Controller
{
    use HandlesListQuery;

    /**
     * GET /clients/circles — filtered, sorted, paginated list.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            ClientCircle::query(),
            $request,
            sortable: ['circle_id', 'circle_name', 'active'],
            defaultSort: 'circle_id',
            filters: [
                'active' => 'boolean',
                'circle_name' => 'string',
                'description' => 'string',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /clients/circles/{id} — implicit binding 404s as problem+json.
     */
    public function show(ClientCircle $circle): JsonResponse
    {
        return response()->json($circle);
    }

    /**
     * POST /clients/circles — 201 with the created record; datalog action
     * 'i'. Nonexistent ids in client_ids are rejected with 400.
     */
    public function store(StoreClientCircleRequest $request): JsonResponse
    {
        $payload = $this->normalizeClientIds($request->payload());
        $this->assertClientIdsExist($payload['client_ids']);

        $circle = new ClientCircle($payload);

        DB::transaction(function () use ($circle): void {
            $circle->save();
        });

        return response()->json($circle->refresh(), 201);
    }

    /**
     * PUT /clients/circles/{id} — 200 with the updated record; datalog
     * action 'u' (suppressed when nothing changed). Unknown ids 404 as
     * problem+json (spec 001 gap G3 fix).
     */
    public function update(UpdateClientCircleRequest $request, ClientCircle $circle): JsonResponse
    {
        $payload = $this->normalizeClientIds($request->payload());

        if (array_key_exists('client_ids', $payload)) {
            $this->assertClientIdsExist($payload['client_ids']);
        }

        $circle->fill($payload);

        DB::transaction(function () use ($circle): void {
            $circle->save();
        });

        return response()->json($circle->refresh());
    }

    /**
     * DELETE /clients/circles/{id} — 204; datalog action 'd'.
     */
    public function destroy(ClientCircle $circle): Response
    {
        DB::transaction(function () use ($circle): void {
            $circle->delete();
        });

        return response()->noContent();
    }

    /**
     * Canonicalize the CSV list ("1, 2" -> "1,2") before persisting.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function normalizeClientIds(array $payload): array
    {
        if (isset($payload['client_ids']) && is_string($payload['client_ids'])) {
            $ids = array_filter(
                array_map('trim', explode(',', $payload['client_ids'])),
                fn (string $id): bool => $id !== ''
            );

            $payload['client_ids'] = implode(',', $ids);
        }

        return $payload;
    }

    /**
     * Every id in the list must reference an existing client (spec 001
     * FR-016: 400 for invalid id lists).
     */
    protected function assertClientIdsExist(string $clientIds): void
    {
        $ids = array_values(array_unique(array_map(
            'intval',
            array_filter(explode(',', $clientIds), fn (string $id): bool => trim($id) !== '')
        )));

        if ($ids === []) {
            return;
        }

        $existing = DB::table('client')
            ->whereIn('client_id', $ids)
            ->pluck('client_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $missing = array_diff($ids, $existing);

        if ($missing !== []) {
            throw new BadRequestHttpException(
                'Invalid client IDs: '.implode(', ', $missing).' do not exist.'
            );
        }
    }
}
