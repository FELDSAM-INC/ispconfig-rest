<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServerIpRequest;
use App\Http\Requests\UpdateServerIpRequest;
use App\Models\Server;
use App\Models\ServerIp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Server IP addresses (contract: api/modules/server/ip-addresses.yaml).
 *
 * Nested under a server: {server} resolves via implicit binding (404 for a
 * missing parent), the child is always scoped by server_id (a record of
 * another server 404s — no cross-server leaks). server_id itself comes
 * from the path and is immutable (legacy server_ip_edit.php).
 *
 * Contract status codes: per-type IP validation 422 (Form Request),
 * table-wide ip_address uniqueness 409, client_id bad reference 400.
 */
class ServerIpController extends Controller
{
    use HandlesListQuery;

    /**
     * GET /servers/{id}/ip-addresses — sorted, paginated list.
     */
    public function index(Request $request, Server $server): JsonResponse
    {
        $result = $this->listQuery(
            ServerIp::query()->where('server_id', $server->getKey()),
            $request,
            sortable: ['server_ip_id', 'ip_address', 'ip_type', 'client_id', 'virtualhost'],
            defaultSort: 'ip_address',
        );

        return response()->json($result);
    }

    /**
     * GET /servers/{id}/ip-addresses/{ip_address_id}
     */
    public function show(Server $server, int $ipAddress): JsonResponse
    {
        return response()->json($this->findIp($server, $ipAddress));
    }

    /**
     * POST /servers/{id}/ip-addresses — 201; datalog action 'i' on
     * server_ip.
     */
    public function store(StoreServerIpRequest $request, Server $server): JsonResponse
    {
        $payload = $request->payload();

        $this->guardUniqueIpAddress((string) $payload['ip_address']);
        $this->guardClientReference($payload);

        $ip = new ServerIp($payload);
        $ip->forceFill(['server_id' => (int) $server->getKey()]);

        DB::transaction(function () use ($ip): void {
            $ip->save();
        });

        return response()->json($ip->refresh(), 201);
    }

    /**
     * PUT /servers/{id}/ip-addresses/{ip_address_id} — 200; datalog action
     * 'u' (suppressed when nothing changed).
     */
    public function update(UpdateServerIpRequest $request, Server $server, int $ipAddress): JsonResponse
    {
        $ip = $this->findIp($server, $ipAddress);
        $payload = $request->payload();

        if (isset($payload['ip_address'])) {
            $this->guardUniqueIpAddress((string) $payload['ip_address'], (int) $ip->getKey());
        }

        $this->guardClientReference($payload);

        $ip->fill($payload);

        DB::transaction(function () use ($ip): void {
            $ip->save();
        });

        return response()->json($ip->refresh());
    }

    /**
     * DELETE /servers/{id}/ip-addresses/{ip_address_id} — 204; datalog
     * action 'd'.
     */
    public function destroy(Server $server, int $ipAddress): Response
    {
        $ip = $this->findIp($server, $ipAddress);

        DB::transaction(function () use ($ip): void {
            $ip->delete();
        });

        return response()->noContent();
    }

    /**
     * Child lookup scoped to the parent server (404 when missing OR when
     * the record belongs to a different server).
     */
    protected function findIp(Server $server, int $ipAddressId): ServerIp
    {
        return ServerIp::query()
            ->where('server_id', $server->getKey())
            ->findOrFail($ipAddressId);
    }

    /**
     * ip_address is UNIQUE across ALL servers (legacy UNIQUE validator in
     * server_ip.tform.php) — a duplicate returns 409 Conflict per the
     * contract.
     */
    protected function guardUniqueIpAddress(string $ipAddress, ?int $ignoreId = null): void
    {
        $query = ServerIp::query()->where('ip_address', $ipAddress);

        if ($ignoreId !== null) {
            $query->where('server_ip_id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw new ConflictHttpException("The IP address '{$ipAddress}' is already registered.");
        }
    }

    /**
     * client_id must reference an existing client when nonzero (0 =
     * unassigned) — a bad reference returns 400 per the contract.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function guardClientReference(array $payload): void
    {
        $clientId = (int) ($payload['client_id'] ?? 0);

        if ($clientId > 0 && ! DB::table('client')->where('client_id', $clientId)->exists()) {
            throw new BadRequestHttpException("The client_id {$clientId} does not reference an existing client.");
        }
    }
}
