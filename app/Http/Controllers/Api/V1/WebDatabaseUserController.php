<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebDatabaseUserRequest;
use App\Http\Requests\UpdateWebDatabaseUserRequest;
use App\Models\WebDatabaseUser;
use App\Services\SitesConfigService;
use App\Support\IspContext;
use App\Support\LegacyCrypt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Database Users (contract: api/modules/sites/database-users.yaml;
 * legacy: database_user_edit.php). Usernames are stored prefixed
 * (dbuser_prefix, prefixed name capped at 32 chars); server_id is forced
 * to 0 ("we need this on all servers"); the plaintext password populates
 * the MySQL PASSWORD()-style, SHA2 and PostgreSQL hash columns.
 */
class WebDatabaseUserController extends Controller
{
    use HandlesListQuery;

    public function __construct(
        protected SitesConfigService $config,
        protected IspContext $context,
    ) {}

    /**
     * GET /sites/database-users — `search` matches the stored (full)
     * username.
     */
    public function index(Request $request): JsonResponse
    {
        $query = WebDatabaseUser::query();

        if (is_string($search = $request->query('search')) && $search !== '') {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $query->where('database_user', 'like', '%'.$escaped.'%');
        }

        $result = $this->listQuery(
            $query,
            $request,
            sortable: ['database_user_id', 'database_user', 'server_id'],
            defaultSort: 'database_user',
        );

        return response()->json($result);
    }

    /**
     * GET /sites/database-users/{id}.
     */
    public function show(WebDatabaseUser $webDatabaseUser): JsonResponse
    {
        return response()->json($webDatabaseUser);
    }

    /**
     * POST /sites/database-users — 201; datalog `i` with the prefixed
     * username, server_id 0 and the three hash columns populated.
     */
    public function store(StoreWebDatabaseUserRequest $request): JsonResponse
    {
        $payload = $request->payload();

        // The API is admin-scoped: prefix placeholders resolve against the
        // acting context's group (legacy resolves the selected client).
        $prefix = $this->config->sitesPrefix('dbuser_prefix', [
            'sys_groupid' => $this->context->sysGroupId(),
        ]);
        $fullUsername = $prefix.$payload['database_user'];
        $this->assertPrefixedLength($fullUsername, $prefix);
        $this->assertNotBlacklisted($fullUsername);

        $user = new WebDatabaseUser;
        $user->forceFill([
            'database_user' => $fullUsername,
            'database_user_prefix' => $prefix,
            ...$this->passwordHashes($payload['database_password']),
        ]);

        DB::transaction(function () use ($user): void {
            $user->save();
        });

        return response()->json($user->refresh(), 201);
    }

    /**
     * PUT /sites/database-users/{id} — 200; the stored username keeps its
     * original prefix; a changed password re-populates all hash columns.
     */
    public function update(UpdateWebDatabaseUserRequest $request, WebDatabaseUser $webDatabaseUser): JsonResponse
    {
        $payload = $request->payload();
        $attributes = $webDatabaseUser->getAttributes();

        if (array_key_exists('database_user', $payload)) {
            $prefix = (string) ($attributes['database_user_prefix'] ?? '');
            $fullUsername = $prefix.$payload['database_user'];
            $this->assertPrefixedLength($fullUsername, $prefix);
            $this->assertNotBlacklisted($fullUsername);

            $webDatabaseUser->forceFill(['database_user' => $fullUsername]);
        }

        if (! empty($payload['database_password'])) {
            $webDatabaseUser->forceFill($this->passwordHashes($payload['database_password']));
        }

        DB::transaction(function () use ($webDatabaseUser): void {
            $webDatabaseUser->save();
        });

        return response()->json($webDatabaseUser->refresh());
    }

    /**
     * DELETE /sites/database-users/{id} — 204; datalog `d`.
     */
    public function destroy(WebDatabaseUser $webDatabaseUser): Response
    {
        DB::transaction(function () use ($webDatabaseUser): void {
            $webDatabaseUser->delete();
        });

        return response()->noContent();
    }

    /**
     * The legacy hash trio derived from one plaintext (tform encryption
     * MYSQL / MYSQLSHA2 / POSTGRESHA256).
     *
     * @return array<string, string>
     */
    protected function passwordHashes(string $plaintext): array
    {
        return [
            'database_password' => LegacyCrypt::mysqlPassword($plaintext),
            'database_password_sha2' => LegacyCrypt::mysqlSha2Password($plaintext),
            'database_password_postgres' => LegacyCrypt::postgresScramSha256($plaintext),
        ];
    }

    /**
     * Legacy database_user_error_len: the PREFIXED name is capped at 32
     * characters (MySQL user limit).
     */
    protected function assertPrefixedLength(string $fullUsername, string $prefix): void
    {
        if (strlen($fullUsername) > 32) {
            throw ValidationException::withMessages([
                'database_user' => "The database username '{$fullUsername}' (including the prefix '{$prefix}') must not exceed 32 characters.",
            ]);
        }
    }

    /**
     * Legacy blacklist: the control panel's own DB user, mysql, root.
     */
    protected function assertNotBlacklisted(string $fullUsername): void
    {
        $connection = (string) config('database.default');
        $apiDbUser = (string) config("database.connections.{$connection}.username", '');

        $blacklist = array_filter([$apiDbUser, 'mysql', 'root']);

        if (in_array($fullUsername, $blacklist, true)) {
            throw ValidationException::withMessages([
                'database_user' => 'This database username is not allowed.',
            ]);
        }
    }
}
