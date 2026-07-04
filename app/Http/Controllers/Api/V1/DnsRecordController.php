<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Concerns\HandlesListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDnsRecordRequest;
use App\Http\Requests\UpdateDnsRecordRequest;
use App\Models\DnsRecord;
use App\Models\DnsSoa;
use App\Services\DnsRecordMetaService;
use App\Services\DnsSerialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * DNS resource records (contract: api/modules/dns/records.yaml).
 *
 * Thin HTTP layer: validation lives in the Form Requests, aux/data
 * composition and meta decomposition in DnsRecordMetaService, serial
 * arithmetic in DnsSerialService, datalogging in BaseModel/DatalogService.
 *
 * Legacy side effects mirrored from dns_edit_base.php / dns_rr_del.php:
 *  - server_id and sys_groupid are inherited from the parent zone;
 *  - every effective write refreshes the record's stamp + serial and bumps
 *    the parent zone's SOA serial (a second dns_soa 'u' datalog row);
 *  - SPF/DKIM/DMARC are stored as TXT rows (spec 002 gap G02) and NAPTR's
 *    preference field is `pref` end to end (gap G03).
 */
class DnsRecordController extends Controller
{
    use HandlesListQuery;

    /**
     * The dns_rr.type DB enum — the valid values for the `type` list filter
     * (records.yaml; SPF/DKIM/DMARC rows are stored and filtered as TXT).
     *
     * @var array<int, string>
     */
    protected const FILTER_TYPES = [
        'A', 'AAAA', 'ALIAS', 'CNAME', 'DNAME', 'CAA', 'DS', 'HINFO', 'LOC', 'MX',
        'NAPTR', 'NS', 'PTR', 'RP', 'SRV', 'SSHFP', 'TXT', 'TLSA', 'DNSKEY',
    ];

    public function __construct(
        protected DnsRecordMetaService $meta,
        protected DnsSerialService $serial,
    ) {}

    /**
     * GET /dns/records — filtered, sorted, paginated list; every row
     * carries the computed `meta` object.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DnsRecord::query();

        // `type` filter: case-insensitive, restricted to the DB enum.
        $type = $request->query('type');
        if ($type !== null) {
            if (! is_string($type) || ! in_array(strtoupper($type), self::FILTER_TYPES, true)) {
                throw new BadRequestHttpException(
                    "Invalid value for filter 'type'. Allowed: ".implode(', ', self::FILTER_TYPES).'.'
                );
            }
            $query->where('type', strtoupper($type));
        }

        // `data` filter: substring match (contract).
        $data = $request->query('data');
        if ($data !== null) {
            if (! is_string($data)) {
                throw new BadRequestHttpException("Invalid value for filter 'data'.");
            }
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $data);
            $query->where('data', 'like', '%'.$escaped.'%');
        }

        $result = $this->listQuery(
            $query,
            $request,
            sortable: ['id', 'zone', 'name', 'type', 'aux', 'ttl', 'serial'],
            defaultSort: 'name',
            filters: [
                'zone' => 'integer',
                'name' => 'wildcard',
                'active' => 'boolean',
            ]
        );

        return response()->json($result);
    }

    /**
     * GET /dns/records/{id} — implicit binding 404s as problem+json.
     */
    public function show(DnsRecord $dnsRecord): JsonResponse
    {
        return response()->json($dnsRecord);
    }

    /**
     * POST /dns/records — 201 with the created record; datalog action 'i'
     * for dns_rr plus a 'u' for the parent zone's serial bump.
     */
    public function store(StoreDnsRecordRequest $request): JsonResponse
    {
        $payload = $request->payload();

        $zone = DnsSoa::query()->findOrFail((int) $payload['zone']);
        $zoneAttributes = $zone->getRawOriginal();

        $type = strtoupper((string) $payload['type']);
        $storage = $this->meta->compose($payload, $type, $zoneAttributes);

        $record = new DnsRecord(array_intersect_key($payload, array_flip(['zone', 'name', 'ttl', 'active'])));

        $record->forceFill([
            'type' => $storage['type'],
            'aux' => $storage['aux'],
            'data' => $storage['data'],
            // Legacy dns_edit_base.php: server_id and sys_groupid always
            // come from the parent zone (an explicit sys_groupid wins, per
            // the DnsRecord schema description).
            'server_id' => (int) $zoneAttributes['server_id'],
            'sys_groupid' => (int) ($payload['sys_groupid'] ?? $zoneAttributes['sys_groupid']),
            // Legacy onSubmit: stamp = now, serial = increase_serial(old).
            'stamp' => $this->serial->timestamp(),
            'serial' => $this->serial->increaseSerial(null),
        ]);

        // DMARC forces the record name to _dmarc.<origin> (legacy).
        if (isset($storage['name'])) {
            $record->name = $storage['name'];
        }

        DB::transaction(function () use ($record, $zone): void {
            $record->save();
            $this->serial->bumpZoneSerial((int) $zone->getKey());
        });

        return response()->json($record->refresh(), 201);
    }

    /**
     * PUT /dns/records/{id} — 200 with the updated record.
     *
     * Partial updates: structured types are re-composed from the stored
     * record's decomposed meta merged with the request. Effective changes
     * refresh stamp + serial and bump the parent zone's serial; a no-change
     * update writes nothing (datalog no-change suppression).
     */
    public function update(UpdateDnsRecordRequest $request, DnsRecord $dnsRecord): JsonResponse
    {
        $payload = $request->payload();
        $stored = $dnsRecord->getRawOriginal();

        $oldZoneId = (int) $stored['zone'];
        $newZoneId = isset($payload['zone']) ? (int) $payload['zone'] : $oldZoneId;

        $zone = DnsSoa::query()->findOrFail($newZoneId);
        $zoneAttributes = $zone->getRawOriginal();

        // The effective (friendly) type: requested, or the stored record's
        // classification (TXT rows re-classify as SPF/DKIM/DMARC).
        $type = isset($payload['type'])
            ? strtoupper((string) $payload['type'])
            : $this->meta->classify((string) ($stored['type'] ?? 'TXT'), (string) ($stored['data'] ?? ''));

        // Re-compose aux/data from the stored state overlaid with the
        // request: simple types keep data/aux unless sent, structured types
        // merge their decomposed meta fields with the request's.
        $base = ['data' => (string) ($stored['data'] ?? ''), 'aux' => (int) ($stored['aux'] ?? 0)];
        if (in_array($type, DnsRecordMetaService::STRUCTURED_TYPES, true)) {
            $base = array_merge($base, $this->meta->meta($stored));
        }
        $storage = $this->meta->compose(array_merge($base, $payload), $type, $zoneAttributes);

        $dnsRecord->fill(array_intersect_key($payload, array_flip(['zone', 'name', 'ttl', 'active'])));

        $dnsRecord->forceFill([
            'type' => $storage['type'],
            'aux' => $storage['aux'],
            'data' => $storage['data'],
        ]);

        if (isset($storage['name'])) {
            $dnsRecord->name = $storage['name'];
        }

        // Zone reassignment re-inherits server_id from the new zone
        // (spec 002 FR-004).
        if ($newZoneId !== $oldZoneId) {
            $dnsRecord->forceFill(['server_id' => (int) $zoneAttributes['server_id']]);
        }

        if (isset($payload['sys_groupid'])) {
            $dnsRecord->forceFill(['sys_groupid' => (int) $payload['sys_groupid']]);
        }

        if ($dnsRecord->isDirty()) {
            // Legacy onSubmit: every effective write refreshes stamp and
            // bumps the per-record serial.
            $dnsRecord->forceFill([
                'stamp' => $this->serial->timestamp(),
                'serial' => $this->serial->increaseSerial($stored['serial'] ?? null),
            ]);

            DB::transaction(function () use ($dnsRecord, $newZoneId, $oldZoneId): void {
                $dnsRecord->save();
                $this->serial->bumpZoneSerial($newZoneId);

                if ($newZoneId !== $oldZoneId) {
                    // Both zones changed content — notify both serials.
                    $this->serial->bumpZoneSerial($oldZoneId);
                }
            });
        }

        return response()->json($dnsRecord->refresh());
    }

    /**
     * DELETE /dns/records/{id} — 204; datalog action 'd' for dns_rr plus a
     * 'u' for the parent zone's serial bump (legacy dns_rr_del.php).
     */
    public function destroy(DnsRecord $dnsRecord): Response
    {
        $zoneId = (int) $dnsRecord->getRawOriginal('zone');

        DB::transaction(function () use ($dnsRecord, $zoneId): void {
            $dnsRecord->delete();
            $this->serial->bumpZoneSerial($zoneId);
        });

        return response()->noContent();
    }
}
