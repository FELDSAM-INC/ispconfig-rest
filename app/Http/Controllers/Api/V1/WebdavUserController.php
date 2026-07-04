<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebdavUserRequest;
use App\Http\Requests\UpdateWebdavUserRequest;
use App\Models\WebdavUser;
use App\Services\SitesConfigService;
use App\Services\SitesService;
use App\Support\LegacyCrypt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * WebDAV Users (contract: api/modules/sites/webdav-users.yaml; legacy:
 * webdav_user_edit.php). Usernames are stored prefixed
 * (webdavuser_prefix, unique table-wide); server_id/sys_groupid derive
 * from the parent web domain; the stored password is the Apache
 * digest-auth hash md5(username:dir:password); username and dir are
 * immutable — password changes re-digest with the STORED values.
 */
class WebdavUserController extends Controller
{
    use HandlesListQuery;

    public function __construct(
        protected SitesService $service,
        protected SitesConfigService $config,
    ) {}

    /**
     * GET /sites/webdav-users — filters: parent_domain_id, active.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            WebdavUser::query(),
            $request,
            sortable: ['webdav_user_id', 'username', 'server_id', 'parent_domain_id', 'active'],
            defaultSort: 'username',
            filters: [
                'parent_domain_id' => 'integer',
                'active' => 'boolean',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /sites/webdav-users/{id}.
     */
    public function show(WebdavUser $webdavUser): JsonResponse
    {
        return response()->json($webdavUser);
    }

    /**
     * POST /sites/webdav-users — 201; datalog `i` with the prefixed
     * username, digest hash and parent-derived server/group.
     */
    public function store(StoreWebdavUserRequest $request): JsonResponse
    {
        $payload = $request->payload();
        $parent = $this->service->parentDomain((int) $payload['parent_domain_id']);

        $prefix = $this->config->sitesPrefix('webdavuser_prefix', $payload);
        $fullUsername = $prefix.$payload['username'];
        $this->assertUniqueUsername($fullUsername);

        $user = new WebdavUser($payload);
        $user->forceFill([
            'username' => $fullUsername,
            'username_prefix' => $prefix,
            // Legacy webdav_user_edit.php:166 — digest over the PREFIXED
            // username, the dir and the plaintext.
            'password' => LegacyCrypt::webdavDigest($fullUsername, (string) $payload['dir'], (string) $payload['password']),
        ]);
        $this->service->deriveServerAndGroup($user, $parent);

        DB::transaction(function () use ($user): void {
            $user->save();
        });

        return response()->json($user->refresh(), 201);
    }

    /**
     * PUT /sites/webdav-users/{id} — 200; only password/active are
     * applied; a changed password is re-digested with the stored
     * username/dir.
     */
    public function update(UpdateWebdavUserRequest $request, WebdavUser $webdavUser): JsonResponse
    {
        $payload = $request->payload();
        $attributes = $webdavUser->getAttributes();

        if (array_key_exists('password', $payload)) {
            if ($payload['password'] === null || $payload['password'] === '') {
                unset($payload['password']);
            } else {
                $payload['password'] = LegacyCrypt::webdavDigest(
                    (string) $attributes['username'],
                    (string) $attributes['dir'],
                    (string) $payload['password']
                );
            }
        }

        $webdavUser->forceFill($payload);

        DB::transaction(function () use ($webdavUser): void {
            $webdavUser->save();
        });

        return response()->json($webdavUser->refresh());
    }

    /**
     * DELETE /sites/webdav-users/{id} — 204; datalog `d`.
     */
    public function destroy(WebdavUser $webdavUser): Response
    {
        DB::transaction(function () use ($webdavUser): void {
            $webdavUser->delete();
        });

        return response()->noContent();
    }

    /**
     * The full (prefixed) username must be unique across all WebDAV users.
     */
    protected function assertUniqueUsername(string $fullUsername, ?int $excludeId = null): void
    {
        $exists = DB::table('webdav_user')
            ->where('username', $fullUsername)
            ->when($excludeId !== null, fn ($q) => $q->where('webdav_user_id', '!=', $excludeId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'username' => "The WebDAV username '{$fullUsername}' is already in use.",
            ]);
        }
    }
}
