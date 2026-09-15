<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Concerns\ResolvesClientOwnership;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDnsSoaFromTemplateRequest;
use App\Http\Requests\StoreDnsSoaRequest;
use App\Http\Requests\UpdateDnsSoaRequest;
use App\Models\DnsSoa;
use App\Services\DnsSerialService;
use App\Services\DnsZoneWizardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
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
     * POST /dns/soa/from-template — the DNS zone wizard (spec 029): 201 with
     * the created zone, its records written in the same change set.
     */
    public function storeFromTemplate(StoreDnsSoaFromTemplateRequest $request, DnsZoneWizardService $wizard): JsonResponse
    {
        $template = $request->template();

        $zone = $wizard->create(
            $template,
            $request->placeholderValues(),
            (int) $request->validated('server_id'),
            $request->filled('client_id')
                ? $this->resolveOwningClientGroup($request->integer('client_id'))
                : null,
        );

        return response()->json($zone, 201);
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
     * DELETE /dns/soa/{id} — 204; deletes the zone's records with the zone
     * (spec 034, legacy dns_soa_del.php): datalog 'u' marking the zone
     * inactive, one 'd' per dns_rr row, then 'd' for the zone — all in one
     * transaction and one change set.
     */
    public function destroy(DnsSoa $dnsSoa): Response
    {
        DB::transaction(function () use ($dnsSoa): void {
            // Legacy dns_soa_del.php:41-51 — the zone is journaled as inactive
            // first (the DNS server drops the zone file), then every resource
            // record of the zone is deleted, then the zone row itself
            // (tform_actions::onDelete). Spec 034: one transaction, one change
            // set; the per-record serial bump of dns_rr_del.php does not apply
            // to the cascade.
            $dnsSoa->active = false;
            $dnsSoa->save();

            foreach ($dnsSoa->records()->orderBy('id')->get() as $record) {
                $record->delete();
            }

            $dnsSoa->delete();
        });

        return response()->noContent();
    }
}
