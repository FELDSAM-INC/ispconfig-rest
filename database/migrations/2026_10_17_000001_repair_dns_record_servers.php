<?php

use App\Services\AliasServicesService;
use App\Services\DatalogService;
use App\Services\DnsSerialService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ISPConfig keeps every DNS record on its zone's server (dns_rr.server_id = dns_soa.server_id), and a server of a
 * multiserver setup only receives the datalog rows of its own server_id. Before 1.2.1, records created with a new zone
 * by POST /sites/domain-services (and domain activation) stayed on the column default, server 1, so the zone's name
 * server got the zone without them. This moves such records to their zone's server through the datalog, which hands
 * them to the name server, and gives each zone a new serial for its secondaries. Data only; nothing to undo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dns_rr') || ! Schema::hasTable('dns_soa') || ! Schema::hasTable('sys_datalog')) {
            return;
        }

        $records = DB::table('dns_rr')
            ->join('dns_soa', 'dns_soa.id', '=', 'dns_rr.zone')
            ->where('dns_soa.server_id', '>', 0)
            ->whereColumn('dns_rr.server_id', '!=', 'dns_soa.server_id')
            ->orderBy('dns_rr.id')
            ->get(['dns_rr.id', 'dns_rr.zone', 'dns_soa.server_id']);

        if ($records->isEmpty()) {
            return;
        }

        $datalog = app(DatalogService::class);
        $serial = app(DnsSerialService::class);

        app(AliasServicesService::class)->withoutSynchronization(function () use ($records, $datalog, $serial): void {
            DB::transaction(function () use ($records, $datalog, $serial): void {
                foreach ($records as $record) {
                    $datalog->updateRecord('dns_rr', 'id', $record->id, ['server_id' => (int) $record->server_id]);
                }
                foreach ($records->pluck('zone')->unique() as $zone) {
                    $serial->bumpZoneSerial((int) $zone);
                }
            });
        });
    }

    public function down(): void
    {
        // The records stay on their zone's server.
    }
};
