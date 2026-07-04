<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebDomainRequest;
use App\Http\Requests\UpdateWebDomainRequest;
use App\Models\WebDomain;
use App\Services\WebDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Web Domains (contract: api/modules/sites/web-domains.yaml).
 *
 * Thin HTTP layer: validation lives in the Form Requests, legacy
 * provisioning (derived fields, LE two-step, delete cascade) in
 * WebDomainService, datalogging in BaseModel/DatalogService. Success
 * responses confirm the sys_datalog entry — ISPConfig provisions
 * asynchronously.
 */
class WebDomainController extends Controller
{
    use HandlesListQuery;

    public function __construct(protected WebDomainService $service) {}

    /**
     * GET /sites/web-domains — paginated list; `search` matches the
     * domain column.
     */
    public function index(Request $request): JsonResponse
    {
        $query = WebDomain::query();

        if (is_string($search = $request->query('search')) && $search !== '') {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $query->where('domain', 'like', '%'.$escaped.'%');
        }

        $result = $this->listQuery(
            $query,
            $request,
            sortable: ['domain_id', 'domain', 'server_id', 'type', 'parent_domain_id', 'active'],
            defaultSort: 'domain',
        );

        return response()->json($result);
    }

    /**
     * GET /sites/web-domains/{id} — implicit binding 404s as problem+json
     * (child-domain ids 404 here: disjoint resource).
     */
    public function show(WebDomain $webDomain): JsonResponse
    {
        return response()->json($webDomain);
    }

    /**
     * POST /sites/web-domains — 201; datalog `i` with the complete derived
     * record (+ the LE two-step `u` when ssl and ssl_letsencrypt are set).
     */
    public function store(StoreWebDomainRequest $request): JsonResponse
    {
        $domain = DB::transaction(fn () => $this->service->create($request->payload()));

        return response()->json($domain, 201);
    }

    /**
     * PUT /sites/web-domains/{id} — 200; datalog `u` (suppressed when
     * nothing changed).
     */
    public function update(UpdateWebDomainRequest $request, WebDomain $webDomain): JsonResponse
    {
        $domain = DB::transaction(fn () => $this->service->update($webDomain, $request->payload()));

        return response()->json($domain);
    }

    /**
     * DELETE /sites/web-domains/{id} — 204; legacy cascade (children,
     * ftp/shell/cron/webdav users, backups, folders; databases detached).
     */
    public function destroy(WebDomain $webDomain): Response
    {
        DB::transaction(function () use ($webDomain): void {
            $this->service->deleteWithCascade($webDomain);
        });

        return response()->noContent();
    }
}
