<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMailAliasDomainRequest;
use App\Http\Requests\UpdateMailAliasDomainRequest;
use App\Models\MailAliasDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Mail Alias Domains — mail_forwarding rows with the hidden discriminator
 * type='aliasdomain' (contract: api/modules/mail/alias-domains.yaml; legacy:
 * mail_aliasdomain_edit.php; C-1 — there is no mail_alias_domain table).
 *
 * server_id and sys_groupid are inherited from the DESTINATION mail domain
 * (legacy parity).
 */
class MailAliasDomainController extends Controller
{
    use HandlesListQuery;

    /**
     * GET /mail/alias-domains — filtered, sorted, paginated list.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            MailAliasDomain::query()->aliasDomains(),
            $request,
            sortable: ['forwarding_id', 'source', 'destination', 'active'],
            defaultSort: 'source',
            filters: [
                'source' => 'wildcard',
                'destination' => 'wildcard',
                'active' => 'boolean',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /mail/alias-domains/{id} — scoped binding 404s non-aliasdomain rows.
     */
    public function show(MailAliasDomain $mailAliasDomain): JsonResponse
    {
        return response()->json($mailAliasDomain);
    }

    /**
     * POST /mail/alias-domains — 201; datalog 'i' on mail_forwarding with
     * type='aliasdomain'. Both domains must exist as mail domains (400);
     * server_id + sys_groupid from the destination domain.
     */
    public function store(StoreMailAliasDomainRequest $request): JsonResponse
    {
        $payload = $request->payload();

        $this->resolveMailDomain((string) $payload['source']);
        $destination = $this->resolveMailDomain((string) $payload['destination']);

        $aliasDomain = new MailAliasDomain($payload);
        $aliasDomain->setAttribute('type', 'aliasdomain');
        $aliasDomain->setAttribute('server_id', (int) $destination->server_id);
        $aliasDomain->setAttribute('sys_groupid', (int) $destination->sys_groupid);

        DB::transaction(function () use ($aliasDomain): void {
            $aliasDomain->save();
        });

        return response()->json($aliasDomain->refresh(), 201);
    }

    /**
     * PUT /mail/alias-domains/{id} — 200; datalog 'u' (suppressed when
     * nothing changed). source is immutable; a changed destination must be
     * an existing mail domain and re-derives server_id (legacy onSubmit
     * takes it from the destination lookup).
     */
    public function update(UpdateMailAliasDomainRequest $request, MailAliasDomain $mailAliasDomain): JsonResponse
    {
        $payload = $request->payload();

        if (array_key_exists('destination', $payload)) {
            $destination = $this->resolveMailDomain((string) $payload['destination']);
            $mailAliasDomain->setAttribute('server_id', (int) $destination->server_id);
        }

        $mailAliasDomain->fill($payload);

        DB::transaction(function () use ($mailAliasDomain): void {
            $mailAliasDomain->save();
        });

        return response()->json($mailAliasDomain->refresh());
    }

    /**
     * DELETE /mail/alias-domains/{id} — 204; datalog 'd'.
     */
    public function destroy(MailAliasDomain $mailAliasDomain): Response
    {
        DB::transaction(function () use ($mailAliasDomain): void {
            $mailAliasDomain->delete();
        });

        return response()->noContent();
    }

    /**
     * The '@'-prefixed value's mail_domain row — 400 when missing (legacy
     * no_domain_perm / spec acceptance scenario "destination must exist").
     *
     * @return object{server_id: mixed, sys_groupid: mixed}
     */
    protected function resolveMailDomain(string $value): object
    {
        $domainName = ltrim($value, '@');

        $domain = DB::table('mail_domain')->where('domain', $domainName)->first();

        if ($domain === null) {
            throw new BadRequestHttpException("The domain '{$domainName}' is not an existing mail domain.");
        }

        return $domain;
    }
}
