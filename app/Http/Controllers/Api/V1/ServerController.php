<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServerRequest;
use App\Http\Requests\UpdateServerRequest;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Servers (contract: api/modules/server/servers.yaml).
 *
 * Thin HTTP layer over the `server` table: validation in the Form
 * Requests, datalogging in BaseModel/DatalogService. Success responses
 * confirm the sys_datalog entry — ISPConfig applies changes
 * asynchronously.
 */
class ServerController extends Controller
{
    use HandlesListQuery;

    /**
     * GET /servers — sorted, paginated list (no documented filters).
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            Server::query(),
            $request,
            sortable: ['server_id', 'server_name', 'mail_server', 'web_server', 'dns_server', 'mirror_server_id', 'active'],
            defaultSort: 'server_name',
        );

        return response()->json($result);
    }

    /**
     * GET /servers/{id} — implicit binding 404s as problem+json.
     */
    public function show(Server $server): JsonResponse
    {
        return response()->json($server);
    }

    /**
     * POST /servers — 201 with the created record; datalog action 'i'.
     *
     * Legacy parity (server_edit.php::onSubmit): mirror_server_id is
     * silently forced to 0 when it equals the record's own id — for a new
     * record the id only exists after the insert, so the correction is
     * applied post-insert (an additional 'u' datalog row in that edge
     * case).
     */
    public function store(StoreServerRequest $request): JsonResponse
    {
        $server = new Server($request->payload());

        DB::transaction(function () use ($server): void {
            $server->save();

            if ((int) $server->mirror_server_id === (int) $server->getKey()) {
                $server->mirror_server_id = 0;
                $server->save();
            }
        });

        return response()->json($server->refresh(), 201);
    }

    /**
     * PUT /servers/{id} — 200 with the updated record; datalog action 'u'
     * (suppressed when nothing changed).
     *
     * Legacy parity (server_edit.php::onSubmit): the effective
     * mirror_server_id is silently forced to 0 when it equals the record's
     * own id (a server cannot mirror itself) or when the record being
     * edited is server 1 (the master cannot be a mirror).
     */
    public function update(UpdateServerRequest $request, Server $server): JsonResponse
    {
        $server->fill($request->payload());

        $id = (int) $server->getKey();

        if ((int) $server->mirror_server_id === $id || $id === 1) {
            $server->mirror_server_id = 0;
        }

        DB::transaction(function () use ($server): void {
            $server->save();
        });

        return response()->json($server->refresh());
    }

    /**
     * DELETE /servers/{id} — 204; datalog action 'd'.
     *
     * No cascade: dependent resources (websites, mail domains, DNS zones,
     * server IPs, mirrors pointing at this server, ...) are NOT touched —
     * parity with legacy server_del.php, which runs a plain form delete.
     */
    public function destroy(Server $server): Response
    {
        DB::transaction(function () use ($server): void {
            $server->delete();
        });

        return response()->noContent();
    }
}
