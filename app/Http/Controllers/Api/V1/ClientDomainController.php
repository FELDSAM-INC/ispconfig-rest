<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientDomainRequest;
use App\Http\Requests\UpdateClientDomainRequest;
use App\Models\ClientDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Client Domains (contract: api/modules/client/domains.yaml) — the domain
 * module's `domain` table. The table has no client_id column: the write-only
 * client_id request field is resolved to the client's sys_group and stored
 * as sys_groupid with sys_perm_group 'ru', mirroring legacy
 * domain_edit.php::onAfterInsert. Duplicate domain names are 409 (the real
 * UNIQUE key), per the contract.
 */
class ClientDomainController extends Controller
{
    use HandlesListQuery;

    /**
     * GET /clients/domains — sorted, paginated list; the declared client_id
     * filter resolves through the client's sys_group (empty result for
     * clients without a group, spec 001 FR-015).
     */
    public function index(Request $request): JsonResponse
    {
        $query = ClientDomain::query();

        $clientId = $request->query('client_id');

        if ($clientId !== null) {
            if (! is_string($clientId) || filter_var($clientId, FILTER_VALIDATE_INT) === false) {
                throw new BadRequestHttpException("Invalid integer value for filter 'client_id'.");
            }

            $groupId = DB::table('sys_group')->where('client_id', (int) $clientId)->value('groupid');

            if ($groupId === null) {
                $query->whereRaw('1 = 0'); // client without a group owns nothing
            } else {
                $query->where('sys_groupid', (int) $groupId);
            }
        }

        $result = $this->listQuery(
            $query,
            $request,
            sortable: ['domain_id', 'domain', 'sys_groupid'],
            defaultSort: 'domain',
            extra: ['client_id'],
        );

        return response()->json($result);
    }

    /**
     * GET /clients/domains/{id} — implicit binding 404s as problem+json.
     */
    public function show(ClientDomain $clientDomain): JsonResponse
    {
        return response()->json($clientDomain);
    }

    /**
     * POST /clients/domains — 201 with the created record; datalog action
     * 'i' carrying the owning client's sys_groupid and sys_perm_group 'ru'.
     */
    public function store(StoreClientDomainRequest $request): JsonResponse
    {
        $payload = $request->payload();

        $this->assertDomainAvailable($payload['domain']);

        $domain = new ClientDomain(['domain' => $payload['domain']]);
        $domain->setAttribute('sys_groupid', $this->resolveClientGroup((int) $payload['client_id']));
        $domain->setAttribute('sys_perm_group', 'ru');

        DB::transaction(function () use ($domain): void {
            $domain->save();
        });

        return response()->json($domain->refresh(), 201);
    }

    /**
     * PUT /clients/domains/{id} — 200 with the updated record; datalog
     * action 'u' (suppressed when nothing changed). Renaming to a taken
     * name is 409; client_id re-owns the domain via its sys_group.
     */
    public function update(UpdateClientDomainRequest $request, ClientDomain $clientDomain): JsonResponse
    {
        $payload = $request->payload();

        if (array_key_exists('domain', $payload)) {
            $this->assertDomainAvailable($payload['domain'], (int) $clientDomain->getKey());
            $clientDomain->fill(['domain' => $payload['domain']]);
        }

        if (array_key_exists('client_id', $payload)) {
            $clientDomain->setAttribute('sys_groupid', $this->resolveClientGroup((int) $payload['client_id']));
            $clientDomain->setAttribute('sys_perm_group', 'ru');
        }

        DB::transaction(function () use ($clientDomain): void {
            $clientDomain->save();
        });

        return response()->json($clientDomain->refresh());
    }

    /**
     * DELETE /clients/domains/{id} — 204; datalog action 'd'.
     */
    public function destroy(ClientDomain $clientDomain): Response
    {
        DB::transaction(function () use ($clientDomain): void {
            $clientDomain->delete();
        });

        return response()->noContent();
    }

    /**
     * Duplicate domain names are 409 (contract; the DB carries the real
     * UNIQUE KEY `domain`).
     */
    protected function assertDomainAvailable(string $domain, ?int $exceptId = null): void
    {
        $query = DB::table('domain')->where('domain', $domain);

        if ($exceptId !== null) {
            $query->where('domain_id', '!=', $exceptId);
        }

        if ($query->exists()) {
            throw new ConflictHttpException("The domain '{$domain}' is already registered.");
        }
    }

    /**
     * Resolve the owning client's sys_group (legacy domain_edit.php
     * onAfterInsert fixup). The client's existence is guaranteed by the
     * request's exists rule; a client without a group cannot own domains.
     */
    protected function resolveClientGroup(int $clientId): int
    {
        $groupId = DB::table('sys_group')->where('client_id', $clientId)->value('groupid');

        if ($groupId === null) {
            throw new BadRequestHttpException(
                'The client has no system group and cannot own domains.'
            );
        }

        return (int) $groupId;
    }
}
