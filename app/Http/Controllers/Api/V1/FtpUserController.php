<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFtpUserRequest;
use App\Http\Requests\UpdateFtpUserRequest;
use App\Models\FtpUser;
use App\Services\SitesConfigService;
use App\Services\SitesService;
use App\Support\LegacyCrypt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FTP Users (contract: api/modules/sites/ftp-users.yaml; legacy:
 * ftp_user_edit.php). Usernames are stored prefixed (ftpuser_prefix);
 * server_id/dir/uid/gid/sys_groupid always derive from the parent web
 * domain; passwords are stored as SHA-512 crypt hashes.
 */
class FtpUserController extends Controller
{
    use HandlesListQuery;

    public function __construct(
        protected SitesService $service,
        protected SitesConfigService $config,
    ) {}

    /**
     * GET /sites/ftp-users — `search` matches the stored (full) username.
     */
    public function index(Request $request): JsonResponse
    {
        $query = FtpUser::query();

        if (is_string($search = $request->query('search')) && $search !== '') {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $query->where('username', 'like', '%'.$escaped.'%');
        }

        $result = $this->listQuery(
            $query,
            $request,
            sortable: ['ftp_user_id', 'username', 'server_id', 'parent_domain_id', 'active'],
            defaultSort: 'username',
            filters: [
                'parent_domain_id' => 'integer',
            ],
            extra: ['search'],
        );

        return response()->json($result);
    }

    /**
     * GET /sites/ftp-users/{id}.
     */
    public function show(FtpUser $ftpUser): JsonResponse
    {
        return response()->json($ftpUser);
    }

    /**
     * POST /sites/ftp-users — 201; datalog `i` with the prefixed username,
     * CRYPT password hash and parent-derived fields.
     */
    public function store(StoreFtpUserRequest $request): JsonResponse
    {
        $payload = $request->payload();
        $parent = $this->service->parentDomain((int) $payload['parent_domain_id']);

        $prefix = $this->config->sitesPrefix('ftpuser_prefix', $payload);
        $fullUsername = $prefix.$payload['username'];
        $this->assertUniqueUsername($fullUsername);

        $user = new FtpUser($payload);
        $user->forceFill([
            'username' => $fullUsername,
            'username_prefix' => $prefix,
            'password' => LegacyCrypt::hash($payload['password']),
        ]);
        $this->service->deriveFtpFields($user, $parent);

        DB::transaction(function () use ($user): void {
            $user->save();
        });

        return response()->json($user->refresh(), 201);
    }

    /**
     * PUT /sites/ftp-users/{id} — 200; the stored username keeps its
     * original prefix; a parent change re-derives the fixed fields.
     */
    public function update(UpdateFtpUserRequest $request, FtpUser $ftpUser): JsonResponse
    {
        $payload = $request->payload();
        $attributes = $ftpUser->getAttributes();

        if (array_key_exists('username', $payload)) {
            $fullUsername = ($attributes['username_prefix'] ?? '').$payload['username'];

            if ($fullUsername !== $attributes['username']) {
                $this->assertUniqueUsername($fullUsername, (int) $ftpUser->getKey());
            }

            $payload['username'] = $fullUsername;
        }

        if (array_key_exists('password', $payload)) {
            // Empty password input = keep the stored hash (legacy skips
            // empty password fields).
            if ($payload['password'] === null || $payload['password'] === '') {
                unset($payload['password']);
            } else {
                $payload['password'] = LegacyCrypt::hash($payload['password']);
            }
        }

        $ftpUser->forceFill($payload);

        // Re-derive fixed fields when the parent changed (legacy
        // onAfterUpdate) — and keep them authoritative on every write.
        $parent = $this->service->parentDomain((int) $ftpUser->getAttributes()['parent_domain_id']);
        $this->service->deriveFtpFields($ftpUser, $parent);

        DB::transaction(function () use ($ftpUser): void {
            $ftpUser->save();
        });

        return response()->json($ftpUser->refresh());
    }

    /**
     * DELETE /sites/ftp-users/{id} — 204; datalog `d`.
     */
    public function destroy(FtpUser $ftpUser): Response
    {
        DB::transaction(function () use ($ftpUser): void {
            $ftpUser->delete();
        });

        return response()->noContent();
    }

    /**
     * The full (prefixed) username must be unique across all FTP users
     * (legacy UNIQUE validator) — 422.
     */
    protected function assertUniqueUsername(string $fullUsername, ?int $excludeId = null): void
    {
        $exists = DB::table('ftp_user')
            ->where('username', $fullUsername)
            ->when($excludeId !== null, fn ($q) => $q->where('ftp_user_id', '!=', $excludeId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'username' => "The FTP username '{$fullUsername}' is already in use.",
            ]);
        }
    }
}
