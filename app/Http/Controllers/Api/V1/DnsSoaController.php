<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Concerns\ResolvesClientOwnership;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDnsSoaRequest;
use App\Http\Requests\UpdateDnsSoaRequest;
use App\Models\DnsSoa;
use App\Services\DnsSerialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * DNS zones — SOA records (contract: api/modules/dns/soa.yaml).
 *
 * Thin HTTP layer: validation lives in the Form Requests, serial arithmetic
 * in DnsSerialService, datalogging in BaseModel/DatalogService. Success
 * responses confirm the sys_datalog entry — ISPConfig applies changes
 * asynchronously.
 *
 * The SOA `serial` is server-managed (contract): generated on create and
 * bumped on every effective update — client-supplied values never reach the
 * model (spec 002 gap G01, the legacy port's update path 500ed on a
 * nonexistent getNextSerialNumber() method).
 */
class DnsSoaController extends Controller
{
    use HandlesListQuery;
    use ResolvesClientOwnership;

    public function __construct(protected DnsSerialService $serial) {}

    /**
     * GET /dns/soa — filtered, sorted, paginated list.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->listQuery(
            DnsSoa::query(),
            $request,
            sortable: ['id', 'origin', 'server_id', 'active', 'serial', 'ttl', 'refresh', 'expire'],
            defaultSort: 'origin',
            filters: [
                'origin' => 'wildcard',
                'active' => 'boolean',
                'client_id' => 'owning_client',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /dns/soa/{id} — implicit binding 404s as problem+json.
     */
    public function show(DnsSoa $dnsSoa): JsonResponse
    {
        return response()->json($dnsSoa);
    }

    /**
     * POST /dns/soa — 201 with the created zone; datalog action 'i'.
     * A duplicate origin is a 409 (dns_soa `origin` UNIQUE key, contract).
     */
    public function store(StoreDnsSoaRequest $request): JsonResponse
    {
        $payload = $request->payload();

        if (DnsSoa::query()->where('origin', $payload['origin'])->exists()) {
            throw new ConflictHttpException("A DNS zone with origin '{$payload['origin']}' already exists.");
        }

        $zone = new DnsSoa($payload);
        // Server-side serial generation (contract; legacy YYYYMMDDnn form).
        $zone->serial = $this->serial->increaseSerial(null);

        if ($request->filled('client_id')) {
            $this->assignOwningClient($zone, $request->integer('client_id'));
        }

        DB::transaction(function () use ($zone): void {
            $zone->save();
        });

        return response()->json($zone->refresh(), 201);
    }

    /**
     * PUT /dns/soa/{id} — 200 with the updated zone; datalog action 'u'.
     *
     * The serial is bumped automatically whenever the update actually
     * changes a column (legacy parity, spec 002 G01); a no-change update
     * writes nothing (datalog no-change suppression).
     */
    public function update(UpdateDnsSoaRequest $request, DnsSoa $dnsSoa): JsonResponse
    {
        $payload = $request->payload();

        if (isset($payload['origin'])
            && DnsSoa::query()->where('origin', $payload['origin'])->whereKeyNot($dnsSoa->getKey())->exists()) {
            throw new ConflictHttpException("A DNS zone with origin '{$payload['origin']}' already exists.");
        }

        $dnsSoa->fill($payload);

        if ($dnsSoa->isDirty()) {
            $dnsSoa->serial = $this->serial->increaseSerial($dnsSoa->getRawOriginal('serial'));

            DB::transaction(function () use ($dnsSoa): void {
                $dnsSoa->save();
            });
        }

        return response()->json($dnsSoa->refresh());
    }

    /**
     * DELETE /dns/soa/{id} — 204; datalog action 'd'.
     *
     * A zone that still contains records is refused with 400 problem+json
     * (contract's documented intentional deviation from legacy ISPConfig's
     * cascade delete — consumers must empty the zone first).
     */
    public function destroy(DnsSoa $dnsSoa): Response
    {
        $recordCount = $dnsSoa->records()->count();

        if ($recordCount > 0) {
            throw new BadRequestHttpException(
                "Cannot delete zone that contains DNS records ({$recordCount} associated records)."
            );
        }

        DB::transaction(function () use ($dnsSoa): void {
            $dnsSoa->delete();
        });

        return response()->noContent();
    }
}
