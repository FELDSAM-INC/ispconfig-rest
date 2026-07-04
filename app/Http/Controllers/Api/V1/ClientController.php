<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Client;
use App\Services\ClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Clients (contract: api/modules/client/clients.yaml).
 *
 * Thin HTTP layer: validation lives in the Form Requests, legacy side
 * effects (CRYPT password hashing, sys_user/sys_group lifecycle, reseller
 * ownership, template application, delete cascade) in ClientService,
 * datalogging in BaseModel/DatalogService. Success responses confirm the
 * sys_datalog entry — ISPConfig applies changes asynchronously.
 */
class ClientController extends Controller
{
    use HandlesListQuery;

    public function __construct(protected ClientService $service)
    {
    }

    /**
     * GET /clients — filtered, sorted, paginated list.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            Client::query(),
            $request,
            sortable: ['client_id', 'company_name', 'contact_name', 'email', 'username', 'customer_no'],
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
     * GET /clients/{id} — implicit binding 404s as problem+json
     * (fixes spec 001 gap G1: this endpoint 500ed on a missing model class).
     */
    public function show(Client $client): JsonResponse
    {
        return response()->json($client);
    }

    /**
     * POST /clients — 201 with the created record; datalog action 'i' for
     * the client row and its sys_group (legacy onAfterInsert lifecycle).
     */
    public function store(StoreClientRequest $request): JsonResponse
    {
        $client = DB::transaction(
            fn (): Client => $this->service->createClient(new Client, $request->payload())
        );

        return response()->json($client, 201);
    }

    /**
     * PUT /clients/{id} — 200 with the updated record; datalog action 'u'
     * (suppressed when nothing changed) plus the legacy sys_user/sys_group
     * sync and re-parenting side effects.
     */
    public function update(UpdateClientRequest $request, Client $client): JsonResponse
    {
        $client = DB::transaction(
            fn (): Client => $this->service->updateClient($client, $request->payload())
        );

        return response()->json($client);
    }

    /**
     * DELETE /clients/{id} — 204; datalog action 'd' for the client and
     * every record owned by its group (legacy client_del.php cascade).
     */
    public function destroy(Client $client): Response
    {
        DB::transaction(function () use ($client): void {
            $this->service->deleteClient($client);
        });

        return response()->noContent();
    }
}
