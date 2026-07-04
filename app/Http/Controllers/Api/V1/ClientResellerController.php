<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientResellerRequest;
use App\Http\Requests\UpdateClientResellerRequest;
use App\Models\ClientReseller;
use App\Services\ClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Resellers (contract: api/modules/client/resellers.yaml) — clients whose
 * limit_client is > 0 or -1, enforced by the ClientReseller global scope
 * (route binding on a plain client id 404s). The reseller condition on
 * writes is answered with 400 per the contract's wording; deletion is
 * blocked with 409 while clients are still assigned.
 */
class ClientResellerController extends Controller
{
    use HandlesListQuery;

    public function __construct(protected ClientService $service) {}

    /**
     * GET /resellers — filtered, sorted, paginated list.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            ClientReseller::query(),
            $request,
            sortable: ['client_id', 'company_name', 'contact_name', 'email', 'username', 'customer_no', 'limit_client'],
            defaultSort: 'client_id',
            filters: [
                'contact_name' => 'string',
                'company_name' => 'string',
                'email' => 'string',
                'customer_no' => 'string',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /resellers/{id} — implicit binding (reseller scope) 404s as
     * problem+json for unknown ids AND for non-reseller clients.
     */
    public function show(ClientReseller $reseller): JsonResponse
    {
        return response()->json($reseller);
    }

    /**
     * POST /resellers — 201 with the created record; datalog action 'i'.
     * limit_client is persisted (fixes spec 001 gap G5) and must satisfy
     * the reseller condition (400 otherwise, per the contract).
     */
    public function store(StoreClientResellerRequest $request): JsonResponse
    {
        $payload = $request->payload();
        $this->assertResellerLimit((int) $payload['limit_client']);

        $reseller = DB::transaction(
            fn (): ClientReseller => $this->service->createClient(new ClientReseller, $payload, asReseller: true)
        );

        return response()->json($reseller, 201);
    }

    /**
     * PUT /resellers/{id} — 200 with the updated record; datalog action 'u'.
     * Demoting the reseller (limit_client = 0) is rejected with 400.
     */
    public function update(UpdateClientResellerRequest $request, ClientReseller $reseller): JsonResponse
    {
        $payload = $request->payload();

        if (array_key_exists('limit_client', $payload)) {
            $this->assertResellerLimit((int) $payload['limit_client']);
        }

        $reseller = DB::transaction(
            fn (): ClientReseller => $this->service->updateClient($reseller, $payload)
        );

        return response()->json($reseller);
    }

    /**
     * DELETE /resellers/{id} — 204 with the legacy client_del.php cascade;
     * 409 while clients are still assigned (parent_client_id).
     */
    public function destroy(ClientReseller $reseller): Response
    {
        if ($reseller->clients()->exists()) {
            throw new ConflictHttpException(
                'The reseller still has clients assigned to it and cannot be deleted.'
            );
        }

        DB::transaction(function () use ($reseller): void {
            $this->service->deleteClient($reseller);
        });

        return response()->noContent();
    }

    /**
     * The legacy reseller condition (reseller.tform.php limit_client
     * validator): > 0 or -1; anything else is 400 per the contract.
     */
    protected function assertResellerLimit(int $limitClient): void
    {
        if ($limitClient !== -1 && $limitClient <= 0) {
            throw new BadRequestHttpException(
                'A reseller must have limit_client > 0 or limit_client = -1.'
            );
        }
    }
}
