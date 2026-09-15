<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Concerns\ResolvesClientOwnership;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMailDomainRequest;
use App\Http\Requests\UpdateMailDomainRequest;
use App\Models\MailDomain;
use App\Services\MailDomainService;
use App\Services\SpamfilterUserService;
use App\Support\IspContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mail Domains (contract: api/modules/mail/domains.yaml).
 *
 * Thin HTTP layer: validation lives in the Form Requests, legacy side
 * effects (DKIM key derivation, DKIM DNS records, delete cascade) in
 * MailDomainService, datalogging in BaseModel/DatalogService. Success
 * responses confirm the sys_datalog entry — ISPConfig applies changes
 * asynchronously.
 *
 * Every domain response carries `spamfilter_policy_id` (spec 026), the level
 * from the domain's `@domain` spamfilter_users row; `dkim_private` is only
 * returned to admin keys (spec 027).
 */
class MailDomainController extends Controller
{
    use HandlesListQuery;
    use ResolvesClientOwnership;

    public function __construct(
        protected MailDomainService $service,
        protected SpamfilterUserService $spamfilterUsers,
    ) {}

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
                'client_id' => 'owning_client',
            ]
        );

        $result['data'] = $this->present($result['data']);

        return response()->json($result);
    }

    /**
     * GET /mail/domains/{id} — implicit binding 404s as problem+json.
     */
    public function show(MailDomain $mailDomain): JsonResponse
    {
        return response()->json($this->presentOne($mailDomain));
    }

    /**
     * POST /mail/domains — 201 with the created record; datalog action 'i'.
     */
    public function store(StoreMailDomainRequest $request): JsonResponse
    {
        $domain = new MailDomain($request->payload());
        $this->service->applyDkimKeys($domain);

        if ($request->filled('client_id')) {
            $this->assignOwningClient($domain, $request->integer('client_id'));
        }

        $policyId = $request->spamfilterPolicyId();

        DB::transaction(function () use ($domain, $policyId): void {
            $domain->save();
            $this->service->syncDnsAfterInsert($domain);

            if ($policyId !== null) {
                $this->spamfilterUsers->assignDomain($domain, $policyId);
            }
        });

        return response()->json($this->presentOne($domain->refresh()), 201);
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

        $policyId = $request->spamfilterPolicyId();

        DB::transaction(function () use ($mailDomain, $oldRecord, $policyId): void {
            // The save also enforces update permission when only the level changes.
            $mailDomain->save();
            $this->service->syncDnsAfterUpdate($mailDomain, $oldRecord);

            if ($policyId !== null) {
                $this->spamfilterUsers->assignDomain($mailDomain, $policyId);
            }
        });

        return response()->json($this->presentOne($mailDomain->refresh()));
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

    /**
     * A domain response with its spam filter level (spec 026).
     *
     * @return array<string, mixed>
     */
    protected function presentOne(MailDomain $domain): array
    {
        return $this->visibleFields($domain) + [
            'spamfilter_policy_id' => $this->spamfilterUsers->policyFor('@'.$domain->getAttributes()['domain']),
        ];
    }

    /**
     * A page of domains with their levels, read with one query.
     *
     * @param  Collection<int, MailDomain>  $domains
     * @return array<int, array<string, mixed>>
     */
    protected function present(Collection $domains): array
    {
        $key = fn (MailDomain $domain): string => '@'.$domain->getAttributes()['domain'];
        $policies = $this->spamfilterUsers->policiesFor($domains->map($key)->all());

        return $domains->map(fn (MailDomain $domain): array => $this->visibleFields($domain) + [
            'spamfilter_policy_id' => $policies[$key($domain)] ?? 0,
        ])->all();
    }

    /**
     * The serialized domain without the DKIM private key for client and
     * reseller keys (spec 027 FR-005).
     *
     * @return array<string, mixed>
     */
    protected function visibleFields(MailDomain $domain): array
    {
        $data = $domain->toArray();

        if (! app(IspContext::class)->authScope()->isAdmin) {
            unset($data['dkim_private']);
        }

        return $data;
    }
}
