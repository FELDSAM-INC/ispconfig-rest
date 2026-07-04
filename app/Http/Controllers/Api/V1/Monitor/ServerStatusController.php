<?php

namespace App\Http\Controllers\Api\V1\Monitor;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Services\MonitorDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Server Status (contract: api/modules/monitor/server-status.yaml).
 *
 * Read-only, computed resource: one aggregated ServerStatus projection per
 * `server` row, assembled by MonitorDataService from the newest
 * monitor_data blob per monitor type (spec 009). Servers without monitor
 * data still appear, with status "unknown" and null metrics — the 404 on
 * the single-server endpoint is only for a missing `server` row.
 *
 * Legacy parity: source_code/interface/web/monitor/show_sys_state.php
 * (iterates servers ordered by server_name; newest-row-per-type state
 * aggregation).
 */
class ServerStatusController extends Controller
{
    use HandlesListQuery;

    public function __construct(private readonly MonitorDataService $monitorData) {}

    /**
     * GET /monitor/servers/status — aggregated status of all servers,
     * ordered by server_name ascending (fixed, per the contract — the
     * only query parameters are the shared limit/offset pair).
     */
    public function index(Request $request): JsonResponse
    {
        $limit = $this->positiveIntParam($request, 'limit', 25, min: 1, max: 100);
        $offset = $this->positiveIntParam($request, 'offset', 0, min: 0);

        $servers = DB::table('server');

        $total = (clone $servers)->count();

        $data = $servers
            ->orderBy('server_name')
            ->skip($offset)
            ->take($limit)
            ->get(['server_id', 'server_name'])
            ->map(fn (object $server): array => $this->monitorData->buildServerStatus($server))
            ->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ]);
    }

    /**
     * GET /monitor/servers/{id}/status — one bare ServerStatus object;
     * 404 problem+json only when the server row itself does not exist.
     */
    public function show(int $id): JsonResponse
    {
        $server = DB::table('server')
            ->where('server_id', $id)
            ->first(['server_id', 'server_name']);

        if ($server === null) {
            throw new NotFoundHttpException('Server not found.');
        }

        return response()->json($this->monitorData->buildServerStatus($server));
    }
}
