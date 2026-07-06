<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDnsSlaveRequest;
use App\Http\Requests\UpdateDnsSlaveRequest;
use App\Models\DnsSlave;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * DNS slave (secondary) zones (contract: api/modules/dns/slave.yaml).
 *
 * Thin HTTP layer: validation lives in the Form Requests, datalogging in
 * BaseModel/DatalogService. The dns_slave (origin, server_id) UNIQUE key is
 * surfaced as a 409 problem (contract).
 */
class DnsSlaveController extends Controller
{
    use HandlesListQuery;

    /**
     * GET /dns/slaves — filtered, sorted, paginated list.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            DnsSlave::query(),
            $request,
            sortable: ['id', 'origin', 'server_id', 'active'],
            defaultSort: 'origin',
            filters: [
                'origin' => 'wildcard',
                'active' => 'boolean',
                'server_id' => 'integer',
                'client_id' => 'owning_client',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /dns/slaves/{id} — implicit binding 404s as problem+json.
     */
    public function show(DnsSlave $dnsSlave): JsonResponse
    {
        return response()->json($dnsSlave);
    }

    /**
     * POST /dns/slaves — 201 with the created slave zone; datalog 'i'.
     */
    public function store(StoreDnsSlaveRequest $request): JsonResponse
    {
        $payload = $request->payload();

        $this->guardUniqueOrigin($payload['origin'], (int) $payload['server_id']);

        $slave = new DnsSlave($payload);

        DB::transaction(function () use ($slave): void {
            $slave->save();
        });

        return response()->json($slave->refresh(), 201);
    }

    /**
     * PUT /dns/slaves/{id} — 200 with the updated slave zone; datalog 'u'
     * (suppressed when nothing changed).
     */
    public function update(UpdateDnsSlaveRequest $request, DnsSlave $dnsSlave): JsonResponse
    {
        $payload = $request->payload();

        $origin = $payload['origin'] ?? $dnsSlave->getRawOriginal('origin');
        $serverId = (int) ($payload['server_id'] ?? $dnsSlave->getRawOriginal('server_id'));
        $this->guardUniqueOrigin($origin, $serverId, (int) $dnsSlave->getKey());

        $dnsSlave->fill($payload);

        DB::transaction(function () use ($dnsSlave): void {
            $dnsSlave->save();
        });

        return response()->json($dnsSlave->refresh());
    }

    /**
     * DELETE /dns/slaves/{id} — 204; datalog action 'd'.
     */
    public function destroy(DnsSlave $dnsSlave): Response
    {
        DB::transaction(function () use ($dnsSlave): void {
            $dnsSlave->delete();
        });

        return response()->noContent();
    }

    /**
     * The dns_slave (origin, server_id) UNIQUE key -> 409 (contract).
     */
    protected function guardUniqueOrigin(string $origin, int $serverId, ?int $exceptId = null): void
    {
        $query = DnsSlave::query()
            ->where('origin', $origin)
            ->where('server_id', $serverId);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw new ConflictHttpException(
                "A DNS slave zone with origin '{$origin}' already exists on server {$serverId}."
            );
        }
    }
}
