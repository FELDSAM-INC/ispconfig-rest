<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMailRelayDomainRequest;
use App\Http\Requests\UpdateMailRelayDomainRequest;
use App\Models\MailRelayDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Mail Relay Domains (contract: api/modules/mail/relay-domains.yaml; legacy:
 * mail_relay_domain.tform.php). The (domain, server_id) UNIQUE key is
 * surfaced as 409; access defaults to 'OK' server-side (C-4).
 */
class MailRelayDomainController extends Controller
{
    use HandlesListQuery;

    /**
     * GET /mail/relay-domains — filtered, sorted, paginated list.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            MailRelayDomain::query(),
            $request,
            sortable: ['relay_domain_id', 'domain', 'server_id', 'active'],
            defaultSort: 'domain',
            filters: [
                'domain' => 'wildcard',
                'active' => 'boolean',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /mail/relay-domains/{id} — implicit binding 404s as problem+json.
     */
    public function show(MailRelayDomain $mailRelayDomain): JsonResponse
    {
        return response()->json($mailRelayDomain);
    }

    /**
     * POST /mail/relay-domains — 201; datalog 'i'; duplicate domain per
     * server => 409.
     */
    public function store(StoreMailRelayDomainRequest $request): JsonResponse
    {
        $payload = $request->payload();

        $duplicate = MailRelayDomain::query()
            ->where('domain', (string) $payload['domain'])
            ->where('server_id', (int) $payload['server_id'])
            ->exists();

        if ($duplicate) {
            throw new ConflictHttpException(
                "A relay domain '{$payload['domain']}' already exists on server {$payload['server_id']}."
            );
        }

        $relayDomain = new MailRelayDomain($payload);

        DB::transaction(function () use ($relayDomain): void {
            $relayDomain->save();
        });

        return response()->json($relayDomain->refresh(), 201);
    }

    /**
     * PUT /mail/relay-domains/{id} — 200; datalog 'u' (suppressed when
     * nothing changed). domain immutable; only access and active mutable.
     */
    public function update(UpdateMailRelayDomainRequest $request, MailRelayDomain $mailRelayDomain): JsonResponse
    {
        $mailRelayDomain->fill($request->payload());

        DB::transaction(function () use ($mailRelayDomain): void {
            $mailRelayDomain->save();
        });

        return response()->json($mailRelayDomain->refresh());
    }

    /**
     * DELETE /mail/relay-domains/{id} — 204; datalog 'd'.
     */
    public function destroy(MailRelayDomain $mailRelayDomain): Response
    {
        DB::transaction(function () use ($mailRelayDomain): void {
            $mailRelayDomain->delete();
        });

        return response()->noContent();
    }
}
