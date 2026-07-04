<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\ResyncRequest;
use App\Services\ResyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Resync (contract: api/modules/system/resync.yaml).
 *
 * POST /system/resync is an action endpoint, not CRUD: it stores nothing of
 * its own — ResyncService writes forced sys_datalog re-emissions for every
 * matching record (DNS: serial bumps instead), all inside one transaction
 * (all-or-nothing). The 200 ResyncResult summarizes the datalog emission —
 * ISPConfig's server daemons process the entries asynchronously, so the
 * response confirms the queued datalog rows, not applied changes
 * (constitution Principle II semantics).
 *
 * GET /system/resync/servers lists the resync candidates. It deliberately
 * queries the `server` table directly instead of using a model — the server
 * module owns the Server model surface, and this helper needs a read-only
 * projection only (zero cross-module coupling).
 */
class ResyncController extends Controller
{
    use HandlesListQuery;

    protected const SERVER_TYPES = ['web', 'mail', 'dns', 'db', 'file', 'vserver'];

    public function __construct(protected ResyncService $resync)
    {
    }

    /**
     * POST /system/resync — run the resync, return the 200 ResyncResult.
     */
    public function store(ResyncRequest $request): JsonResponse
    {
        $result = DB::transaction(fn () => $this->resync->resync($request->validated()));

        return response()->json($result);
    }

    /**
     * GET /system/resync/servers — paginated list of resync candidates.
     * Default rule (legacy): active = 1 AND mirror_server_id = 0; the
     * `active` filter overrides the active part, `server_type` narrows to
     * one server role.
     */
    public function servers(Request $request): JsonResponse
    {
        $query = DB::table('server')->where('mirror_server_id', 0);

        $type = $request->query('server_type');

        if ($type !== null) {
            if (! is_string($type) || ! in_array($type, self::SERVER_TYPES, true)) {
                throw new BadRequestHttpException(
                    sprintf("Invalid server_type '%s'. Allowed: %s.", is_string($type) ? $type : gettype($type), implode(', ', self::SERVER_TYPES))
                );
            }

            $query->where($type.'_server', 1);
        }

        $active = $request->query('active');

        if ($active === null) {
            $query->where('active', 1);
        } else {
            $bool = is_string($active) ? filter_var($active, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;

            if ($bool === null) {
                throw new BadRequestHttpException("Invalid boolean value for filter 'active'. Use true/false or 1/0.");
            }

            $query->where('active', $bool ? 1 : 0);
        }

        $sortable = ['server_id', 'server_name', 'active'];
        $sort = $request->query('sort', 'server_name');

        if (! is_string($sort) || ! in_array($sort, $sortable, true)) {
            throw new BadRequestHttpException(
                sprintf("Invalid sort column '%s'. Allowed: %s.", is_string($sort) ? $sort : gettype($sort), implode(', ', $sortable))
            );
        }

        $order = $request->query('order', 'asc');

        if (! is_string($order) || ! in_array(strtolower($order), ['asc', 'desc'], true)) {
            throw new BadRequestHttpException("Invalid order value. Allowed: 'asc', 'desc'.");
        }

        $limit = $this->positiveIntParam($request, 'limit', 25, min: 1, max: 100);
        $offset = $this->positiveIntParam($request, 'offset', 0, min: 0);

        $total = (clone $query)->count();

        $rows = $query->orderBy($sort, strtolower($order))
            ->skip($offset)
            ->take($limit)
            ->get()
            ->map(fn (object $row) => $this->presentServer($row))
            ->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ]);
    }

    /**
     * Project a raw server row into the Server contract shape
     * (api/components/schemas/Server.yaml) — server_id exposed as `id`,
     * flag/counter columns as integers, the config blob never exposed.
     *
     * @return array<string, mixed>
     */
    protected function presentServer(object $row): array
    {
        $intFields = [
            'sys_userid', 'sys_groupid', 'mail_server', 'web_server',
            'dns_server', 'file_server', 'db_server', 'vserver_server',
            'proxy_server', 'firewall_server', 'xmpp_server',
            'mirror_server_id', 'updated', 'dbversion', 'active',
        ];

        $out = ['id' => (int) $row->server_id];

        foreach (['sys_perm_user', 'sys_perm_group', 'sys_perm_other', 'server_name'] as $field) {
            if (property_exists($row, $field)) {
                $out[$field] = (string) $row->{$field};
            }
        }

        foreach ($intFields as $field) {
            if (property_exists($row, $field)) {
                $out[$field] = (int) $row->{$field};
            }
        }

        return $out;
    }
}
