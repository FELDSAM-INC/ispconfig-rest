<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebFolderRequest;
use App\Http\Requests\UpdateWebFolderRequest;
use App\Models\WebFolder;
use App\Services\SitesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Web Folders (contract: api/modules/sites/web-folders.yaml; legacy:
 * web_folder_edit.php). server_id/sys_groupid derive from the parent web
 * domain; the (parent_domain_id, path) pair is unique; deleting a folder
 * cascades to its folder users; only `active` is updatable (contract
 * restriction, stricter than legacy).
 */
class WebFolderController extends Controller
{
    use HandlesListQuery;

    public function __construct(protected SitesService $service) {}

    /**
     * GET /sites/web-folders — filters: parent_domain_id, active.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            WebFolder::query(),
            $request,
            sortable: ['web_folder_id', 'path', 'server_id', 'parent_domain_id', 'active'],
            defaultSort: 'path',
            filters: [
                'parent_domain_id' => 'integer',
                'active' => 'boolean',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /sites/web-folders/{id}.
     */
    public function show(WebFolder $webFolder): JsonResponse
    {
        return response()->json($webFolder);
    }

    /**
     * POST /sites/web-folders — 201; datalog `i` with the parent-derived
     * server/group.
     */
    public function store(StoreWebFolderRequest $request): JsonResponse
    {
        $payload = $request->payload();
        $parent = $this->service->parentDomain((int) $payload['parent_domain_id']);

        $this->assertPathNotProtected((int) $payload['parent_domain_id'], (string) $payload['path']);

        $folder = new WebFolder($payload);
        $this->service->deriveServerAndGroup($folder, $parent);

        DB::transaction(function () use ($folder): void {
            $folder->save();
        });

        return response()->json($folder->refresh(), 201);
    }

    /**
     * PUT /sites/web-folders/{id} — 200; only `active` is applied.
     */
    public function update(UpdateWebFolderRequest $request, WebFolder $webFolder): JsonResponse
    {
        $webFolder->fill($request->payload());

        DB::transaction(function () use ($webFolder): void {
            $webFolder->save();
        });

        return response()->json($webFolder->refresh());
    }

    /**
     * DELETE /sites/web-folders/{id} — 204; datalog `d` for every folder
     * user, then the folder (legacy cascade).
     */
    public function destroy(WebFolder $webFolder): Response
    {
        DB::transaction(function () use ($webFolder): void {
            $this->service->deleteFolderWithUsers($webFolder);
        });

        return response()->noContent();
    }

    /**
     * Legacy error_folder_already_protected: unique
     * (parent_domain_id, path).
     */
    protected function assertPathNotProtected(int $parentDomainId, string $path): void
    {
        $exists = DB::table('web_folder')
            ->where('parent_domain_id', $parentDomainId)
            ->where('path', $path)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'path' => 'This folder is already protected for the given web domain.',
            ]);
        }
    }
}
