<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebFolderUserRequest;
use App\Http\Requests\UpdateWebFolderUserRequest;
use App\Models\WebFolderUser;
use App\Services\SitesService;
use App\Support\LegacyCrypt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Web Folder Users (contract: api/modules/sites/web-folder-users.yaml;
 * legacy: web_folder_user_edit.php). server_id/sys_groupid derive from
 * the folder; the (web_folder_id, username) pair is unique; passwords are
 * stored as SHA-512 crypt hashes; only password/active are updatable
 * (contract restriction, stricter than legacy).
 */
class WebFolderUserController extends Controller
{
    use HandlesListQuery;

    public function __construct(protected SitesService $service) {}

    /**
     * GET /sites/web-folder-users — filters: web_folder_id, active.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            WebFolderUser::query(),
            $request,
            sortable: ['web_folder_user_id', 'username', 'server_id', 'web_folder_id', 'active'],
            defaultSort: 'username',
            filters: [
                'web_folder_id' => 'integer',
                'active' => 'boolean',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /sites/web-folder-users/{id}.
     */
    public function show(WebFolderUser $webFolderUser): JsonResponse
    {
        return response()->json($webFolderUser);
    }

    /**
     * POST /sites/web-folder-users — 201; datalog `i` with the CRYPT hash
     * and folder-derived server/group.
     */
    public function store(StoreWebFolderUserRequest $request): JsonResponse
    {
        $payload = $request->payload();
        $folder = $this->service->webFolder((int) $payload['web_folder_id']);

        $this->assertUniqueInFolder((int) $payload['web_folder_id'], (string) $payload['username']);

        $user = new WebFolderUser($payload);
        $user->forceFill([
            'password' => LegacyCrypt::hash($payload['password']),
            'server_id' => (int) $folder->server_id,
            'sys_groupid' => (int) $folder->sys_groupid,
        ]);

        DB::transaction(function () use ($user): void {
            $user->save();
        });

        return response()->json($user->refresh(), 201);
    }

    /**
     * PUT /sites/web-folder-users/{id} — 200; only password/active are
     * applied.
     */
    public function update(UpdateWebFolderUserRequest $request, WebFolderUser $webFolderUser): JsonResponse
    {
        $payload = $request->payload();

        if (array_key_exists('password', $payload)) {
            if ($payload['password'] === null || $payload['password'] === '') {
                unset($payload['password']);
            } else {
                $payload['password'] = LegacyCrypt::hash($payload['password']);
            }
        }

        $webFolderUser->forceFill($payload);

        DB::transaction(function () use ($webFolderUser): void {
            $webFolderUser->save();
        });

        return response()->json($webFolderUser->refresh());
    }

    /**
     * DELETE /sites/web-folder-users/{id} — 204; datalog `d`.
     */
    public function destroy(WebFolderUser $webFolderUser): Response
    {
        DB::transaction(function () use ($webFolderUser): void {
            $webFolderUser->delete();
        });

        return response()->noContent();
    }

    /**
     * Legacy error_user_exists_already: unique (web_folder_id, username).
     */
    protected function assertUniqueInFolder(int $webFolderId, string $username): void
    {
        $exists = DB::table('web_folder_user')
            ->where('web_folder_id', $webFolderId)
            ->where('username', $username)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'username' => 'This username already exists for the given web folder.',
            ]);
        }
    }
}
