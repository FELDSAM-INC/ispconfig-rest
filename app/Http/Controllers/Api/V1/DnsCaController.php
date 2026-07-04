<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDnsCaRequest;
use App\Http\Requests\UpdateDnsCaRequest;
use App\Models\DnsSslCa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * DNS Certification Authorities — CAA policy records over dns_ssl_ca
 * (contract: api/modules/system/dns-cas.yaml).
 *
 * Datalog note (contract + specs/008-system-module): legacy maintains this
 * table with direct SQL and no sys_datalog journaling (its 3.2 INSERT is
 * even broken); the API journals every write through sys_datalog via
 * BaseModel — a deliberate, documented superset of legacy behavior
 * (harmless: the table is interface-only, no server daemon consumes it).
 */
class DnsCaController extends Controller
{
    use HandlesListQuery;

    /**
     * GET /system/dns-cas — sorted, paginated list with the `active` filter.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            DnsSslCa::query(),
            $request,
            sortable: ['id', 'ca_name', 'ca_issue', 'active'],
            defaultSort: 'ca_name',
            filters: [
                'active' => 'boolean',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /system/dns-cas/{id} — implicit binding 404s as problem+json.
     */
    public function show(DnsSslCa $dnsCa): JsonResponse
    {
        return response()->json($dnsCa);
    }

    /**
     * POST /system/dns-cas — 201 with the created record; datalog action
     * 'i'. Duplicate ca_issue (DB UNIQUE KEY) is 409.
     */
    public function store(StoreDnsCaRequest $request): JsonResponse
    {
        $payload = $request->payload();

        if (DnsSslCa::query()->where('ca_issue', $payload['ca_issue'])->exists()) {
            throw new ConflictHttpException("A DNS CA with ca_issue '{$payload['ca_issue']}' already exists.");
        }

        $ca = new DnsSslCa($payload);

        DB::transaction(function () use ($ca): void {
            $ca->save();
        });

        return response()->json($ca->refresh(), 201);
    }

    /**
     * PUT /system/dns-cas/{id} — 200 with the updated record; datalog
     * action 'u'. Changing ca_issue onto another record's value is 409.
     */
    public function update(UpdateDnsCaRequest $request, DnsSslCa $dnsCa): JsonResponse
    {
        $payload = $request->payload();

        if (array_key_exists('ca_issue', $payload)
            && DnsSslCa::query()
                ->where('ca_issue', $payload['ca_issue'])
                ->where('id', '!=', $dnsCa->getKey())
                ->exists()) {
            throw new ConflictHttpException("A DNS CA with ca_issue '{$payload['ca_issue']}' already exists.");
        }

        $dnsCa->fill($payload);

        DB::transaction(function () use ($dnsCa): void {
            $dnsCa->save();
        });

        return response()->json($dnsCa->refresh());
    }

    /**
     * DELETE /system/dns-cas/{id} — 204; datalog action 'd'. Already
     * auto-created CAA DNS records are not deleted retroactively (contract).
     */
    public function destroy(DnsSslCa $dnsCa): Response
    {
        DB::transaction(function () use ($dnsCa): void {
            $dnsCa->delete();
        });

        return response()->noContent();
    }
}
