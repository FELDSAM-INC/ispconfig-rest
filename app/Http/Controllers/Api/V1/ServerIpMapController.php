<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServerIpMapRequest;
use App\Http\Requests\UpdateServerIpMapRequest;
use App\Models\Server;
use App\Models\ServerIpMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Server IP mappings — NAT source -> destination rewrites (contract:
 * api/modules/server/ip-mappings.yaml).
 *
 * Nested under a server: {server} resolves via implicit binding (404 for a
 * missing parent), the child is always scoped by server_id (a record of
 * another server 404s). server_id comes from the path and cannot be
 * changed.
 */
class ServerIpMapController extends Controller
{
    use HandlesListQuery;

    /**
     * GET /servers/{id}/ip-mappings — sorted, paginated list.
     */
    public function index(Request $request, Server $server): JsonResponse
    {
        $result = $this->listQuery(
            ServerIpMap::query()->where('server_id', $server->getKey()),
            $request,
            sortable: ['server_ip_map_id', 'source_ip', 'destination_ip', 'active'],
            defaultSort: 'source_ip',
        );

        return response()->json($result);
    }

    /**
     * GET /servers/{id}/ip-mappings/{mapping_id}
     */
    public function show(Server $server, int $mapping): JsonResponse
    {
        return response()->json($this->findMapping($server, $mapping));
    }

    /**
     * POST /servers/{id}/ip-mappings — 201; datalog action 'i' on
     * server_ip_map.
     */
    public function store(StoreServerIpMapRequest $request, Server $server): JsonResponse
    {
        $map = new ServerIpMap($request->payload());
        $map->forceFill(['server_id' => (int) $server->getKey()]);

        DB::transaction(function () use ($map): void {
            $map->save();
        });

        return response()->json($map->refresh(), 201);
    }

    /**
     * PUT /servers/{id}/ip-mappings/{mapping_id} — 200; datalog action 'u'
     * (suppressed when nothing changed).
     */
    public function update(UpdateServerIpMapRequest $request, Server $server, int $mapping): JsonResponse
    {
        $map = $this->findMapping($server, $mapping);
        $map->fill($request->payload());

        DB::transaction(function () use ($map): void {
            $map->save();
        });

        return response()->json($map->refresh());
    }

    /**
     * DELETE /servers/{id}/ip-mappings/{mapping_id} — 204; datalog action
     * 'd'.
     */
    public function destroy(Server $server, int $mapping): Response
    {
        $map = $this->findMapping($server, $mapping);

        DB::transaction(function () use ($map): void {
            $map->delete();
        });

        return response()->noContent();
    }

    /**
     * Child lookup scoped to the parent server (404 when missing OR when
     * the record belongs to a different server).
     */
    protected function findMapping(Server $server, int $mappingId): ServerIpMap
    {
        return ServerIpMap::query()
            ->where('server_id', $server->getKey())
            ->findOrFail($mappingId);
    }
}
