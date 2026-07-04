<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMailDomainRequest;
use App\Http\Requests\UpdateMailDomainRequest;
use App\Models\MailDomain;
use App\Services\MailDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Mail Domains (contract: api/modules/mail/domains.yaml).
 *
 * Thin HTTP layer: validation lives in the Form Requests, legacy side
 * effects (DKIM key derivation, DKIM DNS records, delete cascade) in
 * MailDomainService, datalogging in BaseModel/DatalogService. Success
 * responses confirm the sys_datalog entry — ISPConfig applies changes
 * asynchronously.
 */
class MailDomainController extends Controller
{
    use HandlesListQuery;

    public function __construct(protected MailDomainService $service) {}

    /**
     * GET /mail/domains — filtered, sorted, paginated list.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            MailDomain::query(),
            $request,
            sortable: ['domain_id', 'domain', 'server_id', 'active', 'dkim', 'local_delivery'],
            defaultSort: 'domain',
            filters: [
                'domain' => 'wildcard',
                'active' => 'boolean',
                'local_delivery' => 'boolean',
                'dkim' => 'boolean',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /mail/domains/{id} — implicit binding 404s as problem+json.
     */
    public function show(MailDomain $mailDomain): JsonResponse
    {
        return response()->json($mailDomain);
    }

    /**
     * POST /mail/domains — 201 with the created record; datalog action 'i'.
     */
    public function store(StoreMailDomainRequest $request): JsonResponse
    {
        $domain = new MailDomain($request->payload());
        $this->service->applyDkimKeys($domain);

        DB::transaction(function () use ($domain): void {
            $domain->save();
            $this->service->syncDnsAfterInsert($domain);
        });

        return response()->json($domain->refresh(), 201);
    }

    /**
     * PUT /mail/domains/{id} — 200 with the updated record; datalog action
     * 'u' (suppressed when nothing changed).
     */
    public function update(UpdateMailDomainRequest $request, MailDomain $mailDomain): JsonResponse
    {
        $oldRecord = $mailDomain->getRawOriginal();

        $mailDomain->fill($request->payload());
        $this->service->applyDkimKeys($mailDomain);

        DB::transaction(function () use ($mailDomain, $oldRecord): void {
            $mailDomain->save();
            $this->service->syncDnsAfterUpdate($mailDomain, $oldRecord);
        });

        return response()->json($mailDomain->refresh());
    }

    /**
     * DELETE /mail/domains/{id} — 204; datalog action 'd' for the domain
     * and every dependent record (legacy cascade).
     */
    public function destroy(MailDomain $mailDomain): Response
    {
        DB::transaction(function () use ($mailDomain): void {
            $this->service->deleteWithCascade($mailDomain);
        });

        return response()->noContent();
    }
}
