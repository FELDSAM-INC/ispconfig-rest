<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebDatabaseRequest;
use App\Http\Requests\UpdateWebDatabaseRequest;
use App\Models\WebDatabase;
use App\Services\DatabaseProvisioningService;
use App\Services\SitesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Databases (contract: api/modules/sites/databases.yaml; legacy:
 * database_edit.php + sites_database_plugin.inc.php). Names are stored
 * prefixed (dbname_prefix, ≤64 chars, unique per server, blacklisted
 * names rejected); sys_groupid and backup_copies sync from the parent web
 * domain; the remote-access auto-fix and the forced datalog touch of the
 * linked database users run on insert AND update.
 */
class WebDatabaseController extends Controller
{
    use HandlesListQuery;

    public function __construct(
        protected SitesService $service,
    ) {}

    /**
     * GET /sites/databases — `search` matches the stored (full) name.
     */
    public function index(Request $request): JsonResponse
    {
        $query = WebDatabase::query();

        if (is_string($search = $request->query('search')) && $search !== '') {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $query->where('database_name', 'like', '%'.$escaped.'%');
        }

        $result = $this->listQuery(
            $query,
            $request,
            sortable: ['database_id', 'database_name', 'server_id', 'parent_domain_id', 'type', 'active'],
            defaultSort: 'database_name',
            filters: [
                'parent_domain_id' => 'integer',
            ],
            extra: ['search'],
        );

        return response()->json($result);
    }

    /**
     * GET /sites/databases/{id}.
     */
    public function show(WebDatabase $webDatabase): JsonResponse
    {
        return response()->json($webDatabase);
    }

    /**
     * POST /sites/databases — 201; datalog `i` plus a forced datalog `u`
     * on the linked rw/ro database users syncing their server_id.
     */
    public function store(StoreWebDatabaseRequest $request): JsonResponse
    {
        return response()->json(app(DatabaseProvisioningService::class)->create($request->payload()), 201);
    }

    /**
     * PUT /sites/databases/{id} — 200; immutability enforced in the Form
     * Request; the remote-access auto-fix and linked-user sync run again.
     */
    public function update(UpdateWebDatabaseRequest $request, WebDatabase $webDatabase): JsonResponse
    {
        $payload = $request->payload();
        $attributes = $webDatabase->getAttributes();

        // The un-prefixed database_name is immutable; drop it so the model
        // never sees the raw (un-prefixed) value.
        unset($payload['database_name']);

        $parentId = (int) ($payload['parent_domain_id'] ?? $attributes['parent_domain_id']);
        $parent = $this->service->parentDomain($parentId);
        $serverId = (int) $attributes['server_id'];

        $linkage = [
            'database_user_id' => (int) ($payload['database_user_id'] ?? $attributes['database_user_id']),
            'database_ro_user_id' => (int) ($payload['database_ro_user_id'] ?? $attributes['database_ro_user_id'] ?? 0),
        ];
        app(DatabaseProvisioningService::class)->assertLinkedUsersMatchSiteGroup($linkage, $parent);
        app(DatabaseProvisioningService::class)->assertPostgresUsersUnused(
            $linkage + ['type' => $payload['type'] ?? $attributes['type']],
            $serverId,
            (int) $webDatabase->getKey()
        );

        $webDatabase->fill($payload);
        $record = $webDatabase->getAttributes();
        $this->service->applyRemoteAccessAutoFix($record, $parent);

        $webDatabase->forceFill([
            'remote_access' => $record['remote_access'],
            'remote_ips' => $record['remote_ips'] ?? '',
            'sys_groupid' => (int) $parent->sys_groupid,
            'backup_copies' => (int) $parent->backup_copies,
        ]);

        DB::transaction(function () use ($webDatabase, $linkage, $serverId): void {
            $webDatabase->save();
            $this->service->touchLinkedDatabaseUser($linkage['database_user_id'], $serverId);
            $this->service->touchLinkedDatabaseUser($linkage['database_ro_user_id'], $serverId);
        });

        return response()->json($webDatabase->refresh());
    }

    /**
     * DELETE /sites/databases/{id} — 204; datalog `d` (the daemons drop
     * the database and grants asynchronously).
     */
    public function destroy(WebDatabase $webDatabase): Response
    {
        DB::transaction(function () use ($webDatabase): void {
            $webDatabase->delete();
        });

        return response()->noContent();
    }
}
