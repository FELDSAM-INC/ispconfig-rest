<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShellUserRequest;
use App\Http\Requests\UpdateShellUserRequest;
use App\Models\ShellUser;
use App\Services\SitesConfigService;
use App\Services\SitesService;
use App\Support\LegacyCrypt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shell Users (contract: api/modules/sites/shell-users.yaml; legacy:
 * shell_user_edit.php). Usernames are stored prefixed (shelluser_prefix,
 * prefixed name capped at 32 chars); server_id/dir/puser/pgroup/
 * sys_groupid always derive from the parent web domain; the system SSH
 * authentication mode clears ssh_rsa (password mode) or password (key
 * mode); passwords are stored as SHA-512 crypt hashes.
 */
class ShellUserController extends Controller
{
    use HandlesListQuery;

    public function __construct(
        protected SitesService $service,
        protected SitesConfigService $config,
    ) {}

    /**
     * GET /sites/shell-users — `search` matches the stored (full) username.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ShellUser::query();

        if (is_string($search = $request->query('search')) && $search !== '') {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $query->where('username', 'like', '%'.$escaped.'%');
        }

        $result = $this->listQuery(
            $query,
            $request,
            sortable: ['shell_user_id', 'username', 'server_id', 'parent_domain_id', 'active'],
            defaultSort: 'username',
            extra: ['search'],
        );

        return response()->json($result);
    }

    /**
     * GET /sites/shell-users/{id}.
     */
    public function show(ShellUser $shellUser): JsonResponse
    {
        return response()->json($shellUser);
    }

    /**
     * POST /sites/shell-users — 201; datalog `i` with the prefixed
     * username, CRYPT password hash and parent-derived fields.
     */
    public function store(StoreShellUserRequest $request): JsonResponse
    {
        $payload = $this->applySshAuthenticationMode($request->payload());
        $parent = $this->service->parentDomain((int) $payload['parent_domain_id']);

        $prefix = $this->config->sitesPrefix('shelluser_prefix', $payload);
        $fullUsername = $prefix.$payload['username'];
        $this->assertPrefixedLength($fullUsername);
        $this->assertUniqueUsername($fullUsername);

        if (array_key_exists('password', $payload) && ($payload['password'] === null || $payload['password'] === '')) {
            unset($payload['password']);
        }

        $user = new ShellUser($payload);
        $user->forceFill([
            'username' => $fullUsername,
            'username_prefix' => $prefix,
        ]);

        if (isset($payload['password'])) {
            $user->forceFill(['password' => LegacyCrypt::hash($payload['password'])]);
        }

        $this->service->deriveShellFields($user, $parent);

        DB::transaction(function () use ($user): void {
            $user->save();
        });

        return response()->json($user->refresh(), 201);
    }

    /**
     * PUT /sites/shell-users/{id} — 200; the stored username keeps its
     * original prefix; a parent change re-derives the fixed fields.
     */
    public function update(UpdateShellUserRequest $request, ShellUser $shellUser): JsonResponse
    {
        $payload = $this->applySshAuthenticationMode($request->payload());
        $attributes = $shellUser->getAttributes();

        if (array_key_exists('username', $payload)) {
            $fullUsername = ($attributes['username_prefix'] ?? '').$payload['username'];
            $this->assertPrefixedLength($fullUsername);

            if ($fullUsername !== $attributes['username']) {
                $this->assertUniqueUsername($fullUsername, (int) $shellUser->getKey());
            }

            $payload['username'] = $fullUsername;
        }

        if (array_key_exists('password', $payload)) {
            if ($payload['password'] === null || $payload['password'] === '') {
                unset($payload['password']);
            } else {
                $payload['password'] = LegacyCrypt::hash($payload['password']);
            }
        }

        $shellUser->forceFill($payload);

        $parent = $this->service->parentDomain((int) $shellUser->getAttributes()['parent_domain_id']);
        $this->service->deriveShellFields($shellUser, $parent);

        DB::transaction(function () use ($shellUser): void {
            $shellUser->save();
        });

        return response()->json($shellUser->refresh());
    }

    /**
     * DELETE /sites/shell-users/{id} — 204; datalog `d`.
     */
    public function destroy(ShellUser $shellUser): Response
    {
        DB::transaction(function () use ($shellUser): void {
            $shellUser->delete();
        });

        return response()->noContent();
    }

    /**
     * System config misc.ssh_authentication (shell_user_edit.php
     * onSubmit): 'password' clears ssh_rsa, 'key' clears password.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function applySshAuthenticationMode(array $payload): array
    {
        $mode = $this->config->sshAuthenticationMode();

        if (isset($payload['ssh_rsa']) && is_string($payload['ssh_rsa'])) {
            $payload['ssh_rsa'] = trim($payload['ssh_rsa']);
        }

        if ($mode === 'password') {
            $payload['ssh_rsa'] = null;
        } elseif ($mode === 'key') {
            $payload['password'] = null;
        }

        return $payload;
    }

    /**
     * Legacy "username_must_not_exceed_32_chars" on the PREFIXED name.
     */
    protected function assertPrefixedLength(string $fullUsername): void
    {
        if (strlen($fullUsername) > 32) {
            throw ValidationException::withMessages([
                'username' => 'The prefixed username must not exceed 32 characters.',
            ]);
        }
    }

    /**
     * The full (prefixed) username must be unique across all shell users.
     */
    protected function assertUniqueUsername(string $fullUsername, ?int $excludeId = null): void
    {
        $exists = DB::table('shell_user')
            ->where('username', $fullUsername)
            ->when($excludeId !== null, fn ($q) => $q->where('shell_user_id', '!=', $excludeId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'username' => "The shell username '{$fullUsername}' is already in use.",
            ]);
        }
    }
}
