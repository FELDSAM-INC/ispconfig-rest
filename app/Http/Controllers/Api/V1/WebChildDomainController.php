<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebChildDomainRequest;
use App\Http\Requests\UpdateWebChildDomainRequest;
use App\Models\WebChildDomain;
use App\Services\DatalogService;
use App\Services\SitesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Web Child Domains — subdomains and alias domains stored in web_domain
 * (contract: api/modules/sites/web-child-domains.yaml; legacy:
 * web_childdomain_edit.php). type/server_id/sys_groupid are
 * server-controlled; subdomain FQDNs are composed as
 * {label}.{parent domain}.
 */
class WebChildDomainController extends Controller
{
    use HandlesListQuery;

    public function __construct(
        protected SitesService $service,
        protected DatalogService $datalog,
    ) {}

    /**
     * GET /sites/web-child-domains — filters: type, parent_domain_id.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            WebChildDomain::query(),
            $request,
            sortable: ['domain_id', 'domain', 'server_id', 'type', 'parent_domain_id', 'active'],
            defaultSort: 'domain',
            filters: [
                'type' => 'string',
                'parent_domain_id' => 'integer',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /sites/web-child-domains/{id} — vhost-type ids 404 here
     * (disjoint resource).
     */
    public function show(WebChildDomain $webChildDomain): JsonResponse
    {
        return response()->json($webChildDomain);
    }

    /**
     * POST /sites/web-child-domains — 201; datalog `i` with the composed
     * FQDN and the parent-derived server/group.
     */
    public function store(StoreWebChildDomainRequest $request): JsonResponse
    {
        $payload = $request->payload();
        $parent = $this->service->parentDomain((int) $payload['parent_domain_id']);

        // Compose the subdomain FQDN server-side (legacy onSubmit).
        if ($payload['type'] === 'subdomain') {
            $payload['domain'] = $payload['domain'].'.'.$parent->domain;
        }

        $this->assertUniqueDomain((int) $parent->server_id, $payload['domain']);

        $child = new WebChildDomain($payload);
        $this->service->deriveServerAndGroup($child, $parent);

        DB::transaction(function () use ($child): void {
            $child->save();
        });

        return response()->json($child->refresh(), 201);
    }

    /**
     * PUT /sites/web-child-domains/{id} — 200. Reparenting re-syncs
     * server_id/sys_groupid, recomposes subdomain FQDNs against the new
     * parent, and writes a forced no-change datalog `u` for the OLD
     * parent so its vhost config is regenerated (legacy onAfterUpdate).
     */
    public function update(UpdateWebChildDomainRequest $request, WebChildDomain $webChildDomain): JsonResponse
    {
        $payload = $request->payload();
        $attributes = $webChildDomain->getAttributes();

        $oldParentId = (int) $attributes['parent_domain_id'];
        $newParentId = (int) ($payload['parent_domain_id'] ?? $oldParentId);
        $parent = $this->service->parentDomain($newParentId);
        $reparented = $newParentId !== $oldParentId;

        if ($attributes['type'] === 'subdomain') {
            // Determine the label: submitted, or the stored FQDN with the
            // old parent's domain stripped (legacy UI always posts the label).
            $label = $payload['domain'] ?? null;

            if ($label === null && $reparented) {
                $oldParentDomain = (string) DB::table('web_domain')
                    ->where('domain_id', $oldParentId)
                    ->value('domain');
                $label = preg_replace(
                    '/'.preg_quote('.'.$oldParentDomain, '/').'$/',
                    '',
                    (string) $attributes['domain']
                );
            }

            if ($label !== null) {
                $payload['domain'] = $label.'.'.$parent->domain;
            }
        }

        if (isset($payload['domain']) && $payload['domain'] !== $attributes['domain']) {
            $this->assertUniqueDomain((int) $parent->server_id, $payload['domain'], (int) $webChildDomain->getKey());
        }

        $webChildDomain->fill($payload);
        $this->service->deriveServerAndGroup($webChildDomain, $parent);

        DB::transaction(function () use ($webChildDomain, $reparented, $oldParentId): void {
            $webChildDomain->save();

            if ($reparented) {
                // Forced no-op update of the old parent vhost (legacy
                // datalogSave with $force_update) so its config regenerates.
                $oldParentRow = (array) DB::table('web_domain')->where('domain_id', $oldParentId)->first();

                if ($oldParentRow !== []) {
                    $this->datalog->log('web_domain', 'domain_id', $oldParentId, 'u', $oldParentRow, $oldParentRow, true);
                }
            }
        });

        return response()->json($webChildDomain->refresh());
    }

    /**
     * DELETE /sites/web-child-domains/{id} — 204; datalog `d`.
     */
    public function destroy(WebChildDomain $webChildDomain): Response
    {
        DB::transaction(function () use ($webChildDomain): void {
            $webChildDomain->delete();
        });

        return response()->noContent();
    }

    /**
     * web_domain unique key (server_id, ip_address, domain) — child
     * domains carry no IP; collisions return 409 per the contract.
     */
    protected function assertUniqueDomain(int $serverId, string $domain, ?int $excludeId = null): void
    {
        $exists = DB::table('web_domain')
            ->where('server_id', $serverId)
            ->where('domain', $domain)
            ->when($excludeId !== null, fn ($q) => $q->where('domain_id', '!=', $excludeId))
            ->exists();

        if ($exists) {
            throw new ConflictHttpException("The domain '{$domain}' already exists on this server.");
        }
    }
}
