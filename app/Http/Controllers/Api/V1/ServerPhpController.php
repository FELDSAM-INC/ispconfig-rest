<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServerPhpRequest;
use App\Http\Requests\UpdateServerPhpRequest;
use App\Models\Server;
use App\Models\ServerPhp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Server PHP versions (contract: api/modules/server/php-versions.yaml).
 *
 * Nested under a server: {server} resolves via implicit binding (404 for a
 * missing parent), the child is always scoped by server_id (a record of
 * another server 404s). server_id comes from the path and cannot be
 * changed.
 *
 * Legacy parity: server_php.tform.php only offers servers with
 * web_server = 1 AND mirror_server_id = 0 — creating a PHP version on any
 * other server returns 422 per the contract.
 */
class ServerPhpController extends Controller
{
    use HandlesListQuery;

    /**
     * GET /servers/{id}/php-versions — sorted, paginated list.
     */
    public function index(Request $request, Server $server): JsonResponse
    {
        $result = $this->listQuery(
            ServerPhp::query()->where('server_id', $server->getKey()),
            $request,
            sortable: ['server_php_id', 'name', 'sortprio', 'client_id', 'active'],
            defaultSort: 'sortprio',
        );

        return response()->json($result);
    }

    /**
     * GET /servers/{id}/php-versions/{php_version_id}
     */
    public function show(Server $server, int $phpVersion): JsonResponse
    {
        return response()->json($this->findPhpVersion($server, $phpVersion));
    }

    /**
     * POST /servers/{id}/php-versions — 201; datalog action 'i' on
     * server_php.
     */
    public function store(StoreServerPhpRequest $request, Server $server): JsonResponse
    {
        $this->guardWebServer($server);

        $php = new ServerPhp($request->payload());
        $php->forceFill(['server_id' => (int) $server->getKey()]);

        DB::transaction(function () use ($php): void {
            $php->save();
        });

        return response()->json($php->refresh(), 201);
    }

    /**
     * PUT /servers/{id}/php-versions/{php_version_id} — 200; datalog
     * action 'u' (suppressed when nothing changed).
     */
    public function update(UpdateServerPhpRequest $request, Server $server, int $phpVersion): JsonResponse
    {
        $php = $this->findPhpVersion($server, $phpVersion);
        $php->fill($request->payload());

        DB::transaction(function () use ($php): void {
            $php->save();
        });

        return response()->json($php->refresh());
    }

    /**
     * DELETE /servers/{id}/php-versions/{php_version_id} — 204; datalog
     * action 'd'.
     */
    public function destroy(Server $server, int $phpVersion): Response
    {
        $php = $this->findPhpVersion($server, $phpVersion);

        DB::transaction(function () use ($php): void {
            $php->delete();
        });

        return response()->noContent();
    }

    /**
     * Child lookup scoped to the parent server (404 when missing OR when
     * the record belongs to a different server).
     */
    protected function findPhpVersion(Server $server, int $phpVersionId): ServerPhp
    {
        return ServerPhp::query()
            ->where('server_id', $server->getKey())
            ->findOrFail($phpVersionId);
    }

    /**
     * Legacy datasource restriction: PHP versions belong on non-mirrored
     * web servers only (server_php.tform.php) — otherwise 422.
     */
    protected function guardWebServer(Server $server): void
    {
        if ((int) $server->web_server !== 1 || (int) $server->mirror_server_id !== 0) {
            throw ValidationException::withMessages([
                'server_id' => 'PHP versions can only be registered on a non-mirrored web server (web_server = 1, mirror_server_id = 0).',
            ]);
        }
    }
}
