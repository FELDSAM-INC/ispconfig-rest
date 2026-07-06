<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebDatabaseRequest;
use App\Http\Requests\UpdateWebDatabaseRequest;
use App\Models\WebDatabase;
use App\Services\SitesConfigService;
use App\Services\SitesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        protected SitesConfigService $config,
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
        $payload = $request->payload();
        $parent = $this->service->parentDomain((int) $payload['parent_domain_id']);
        $serverId = (int) $payload['server_id'];

        $this->assertLinkedUsersMatchSiteGroup($payload, $parent);

        $prefix = $this->config->sitesPrefix('dbname_prefix', $payload);
        $fullName = $prefix.$payload['database_name'];
        $this->assertValidDatabaseName($fullName, $prefix);
        $this->assertUniquePerServer($fullName, $serverId);
        $this->assertPostgresUsersUnused($payload, $serverId);

        $database = new WebDatabase($payload);
        $record = $database->getAttributes();
        $this->service->applyRemoteAccessAutoFix($record, $parent);

        $database->forceFill([
            'database_name' => $fullName,
            'database_name_prefix' => $prefix,
            'remote_access' => $record['remote_access'],
            'remote_ips' => $record['remote_ips'] ?? '',
            // Legacy sites_database_plugin: group AND backup_copies come
            // from the parent web domain.
            'sys_groupid' => (int) $parent->sys_groupid,
            'backup_copies' => (int) $parent->backup_copies,
        ]);

        DB::transaction(function () use ($database, $payload, $serverId): void {
            $database->save();
            $this->service->touchLinkedDatabaseUser((int) $payload['database_user_id'], $serverId);
            $this->service->touchLinkedDatabaseUser((int) ($payload['database_ro_user_id'] ?? 0), $serverId);
        });

        return response()->json($database->refresh(), 201);
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
        $this->assertLinkedUsersMatchSiteGroup($linkage, $parent);
        $this->assertPostgresUsersUnused(
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

    /**
     * Legacy database_client_differs: the linked users' sys_groupid must
     * match the parent domain's group.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function assertLinkedUsersMatchSiteGroup(array $payload, object $parent): void
    {
        foreach (['database_user_id', 'database_ro_user_id'] as $field) {
            $userId = (int) ($payload[$field] ?? 0);

            if ($userId <= 0) {
                continue;
            }

            $groupId = DB::table('web_database_user')
                ->where('database_user_id', $userId)
                ->value('sys_groupid');

            if ($groupId !== null && (int) $groupId !== (int) $parent->sys_groupid) {
                throw ValidationException::withMessages([
                    $field => 'The database user belongs to a different client than the site.',
                ]);
            }
        }
    }

    /**
     * Legacy database_name_error_len + blacklist (the control panel's own
     * database and `mysql`).
     */
    protected function assertValidDatabaseName(string $fullName, string $prefix): void
    {
        if (strlen($fullName) > 64) {
            throw ValidationException::withMessages([
                'database_name' => "The database name '{$fullName}' (including the prefix '{$prefix}') must not exceed 64 characters.",
            ]);
        }

        $connection = (string) config('database.default');
        $apiDbName = (string) config("database.connections.{$connection}.database", '');

        if (in_array($fullName, array_filter([$apiDbName, 'mysql']), true)) {
            throw ValidationException::withMessages([
                'database_name' => 'This database name is not allowed.',
            ]);
        }
    }

    /**
     * Legacy per-server duplicate check.
     */
    protected function assertUniquePerServer(string $fullName, int $serverId, ?int $excludeId = null): void
    {
        $exists = DB::table('web_database')
            ->where('database_name', $fullName)
            ->where('server_id', $serverId)
            ->when($excludeId !== null, fn ($q) => $q->where('database_id', '!=', $excludeId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'database_name' => "The database name '{$fullName}' already exists on this server.",
            ]);
        }
    }

    /**
     * Legacy PostgreSQL constraint: the rw and ro users must not be used
     * by another PostgreSQL database on the same server.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function assertPostgresUsersUnused(array $payload, int $serverId, ?int $excludeId = null): void
    {
        if (($payload['type'] ?? 'mysql') !== 'postgresql') {
            return;
        }

        $checks = [
            'database_user_id' => (int) ($payload['database_user_id'] ?? 0),
        ];

        $roUserId = (int) ($payload['database_ro_user_id'] ?? 0);
        if ($roUserId !== 0 && $roUserId !== $checks['database_user_id']) {
            $checks['database_ro_user_id'] = $roUserId;
        }

        foreach ($checks as $field => $userId) {
            if ($userId <= 0) {
                continue;
            }

            $inUse = DB::table('web_database')
                ->where('type', 'postgresql')
                ->where('server_id', $serverId)
                ->where(fn ($q) => $q->where('database_user_id', $userId)->orWhere('database_ro_user_id', $userId))
                ->when($excludeId !== null, fn ($q) => $q->where('database_id', '!=', $excludeId))
                ->exists();

            if ($inUse) {
                throw ValidationException::withMessages([
                    $field => 'This database user is already used by another PostgreSQL database on this server.',
                ]);
            }
        }
    }
}
