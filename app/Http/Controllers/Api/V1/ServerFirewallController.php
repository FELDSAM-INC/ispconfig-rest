<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PutServerFirewallRequest;
use App\Models\Server;
use App\Models\ServerFirewall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Server firewall — the SINGLETON firewall record of a server (contract:
 * api/modules/server/firewall.yaml).
 *
 * The DB declares firewall.server_id UNIQUE (firewall.tform.php): a server
 * has AT MOST ONE firewall record. The API therefore models the resource
 * as a singleton at /servers/{id}/firewall:
 *
 *  - GET reads it (404 when the server has none),
 *  - PUT upserts it — 201 + datalog 'i' when no record existed,
 *    200 + datalog 'u' when one did,
 *  - DELETE removes it (404 when the server has none).
 *
 * server_id always comes from the path and is immutable (legacy
 * firewall_edit.php::onBeforeUpdate; 422 on a differing body value —
 * enforced in PutServerFirewallRequest).
 */
class ServerFirewallController extends Controller
{
    /**
     * GET /servers/{id}/firewall
     */
    public function show(Server $server): JsonResponse
    {
        return response()->json($this->firewallOf($server)->firstOrFail());
    }

    /**
     * PUT /servers/{id}/firewall — upsert: 201 create / 200 update.
     */
    public function put(PutServerFirewallRequest $request, Server $server): JsonResponse
    {
        $firewall = $this->firewallOf($server)->first();
        $created = $firewall === null;

        if ($created) {
            $firewall = new ServerFirewall($request->payload());
            $firewall->forceFill(['server_id' => (int) $server->getKey()]);
        } else {
            $firewall->fill($request->payload());
        }

        DB::transaction(function () use ($firewall): void {
            $firewall->save();
        });

        return response()->json($firewall->refresh(), $created ? 201 : 200);
    }

    /**
     * DELETE /servers/{id}/firewall — 204; datalog action 'd'. 404 when
     * the server has no firewall record.
     */
    public function destroy(Server $server): Response
    {
        $firewall = $this->firewallOf($server)->firstOrFail();

        DB::transaction(function () use ($firewall): void {
            $firewall->delete();
        });

        return response()->noContent();
    }

    /**
     * The singleton query, scoped by the UNIQUE server_id.
     */
    protected function firewallOf(Server $server)
    {
        return ServerFirewall::query()->where('server_id', $server->getKey());
    }
}
