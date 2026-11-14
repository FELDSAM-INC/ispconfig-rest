<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DnsSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Spec 060: PUT /dns/soa/{id} with client_id moves a zone with its records to another client, like the "Client"
 * select of the legacy zone form (interface/lib/plugins/dns_dns_soa_plugin.inc.php).
 */
class DnsZoneOwnerApiTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        DnsSchema::create();
        TenantSchema::create();
        $this->seedTenants();
        DB::table('server')->insert([
            'server_id' => 1, 'server_name' => 'ns1', 'dns_server' => 1, 'mirror_server_id' => 0, 'active' => 1,
        ]);
    }

    public function test_admin_moves_a_zone_with_its_records(): void
    {
        $zone = $this->zoneOf('clientA', 'moved.test.');
        $this->record($zone, 'clientA', 'www');
        $this->record($zone, 'admin', 'byadmin');
        $serial = DB::table('dns_soa')->where('id', $zone)->value('serial');
        $datalog = DB::table('sys_datalog')->count();

        $this->putJson('/api/v1/dns/soa/'.$zone, ['client_id' => $this->tenant('clientB')['client_id']], $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertJsonPath('id', $zone);

        $row = DB::table('dns_soa')->where('id', $zone)->first();
        $this->assertSame($this->tenant('clientB')['groupid'], (int) $row->sys_groupid);
        $this->assertSame($this->tenant('clientB')['userid'], (int) $row->sys_userid);
        $this->assertSame($serial, $row->serial, 'the owner alone is not part of the zone file');

        $records = DB::table('dns_rr')->where('zone', $zone)->orderBy('name')->get()->keyBy('name');
        $this->assertSame($this->tenant('clientB')['groupid'], (int) $records['www']->sys_groupid);
        $this->assertSame($this->tenant('clientB')['userid'], (int) $records['www']->sys_userid);
        $this->assertSame($this->tenant('clientB')['groupid'], (int) $records['byadmin']->sys_groupid);
        $this->assertSame(1, (int) $records['byadmin']->sys_userid, 'records of the administrator keep their login');

        // One datalog entry, for the zone; the records' owner is written as the legacy plugin writes it
        $this->assertSame($datalog + 1, DB::table('sys_datalog')->count());
        $entry = DB::table('sys_datalog')->orderByDesc('datalog_id')->first();
        $this->assertSame('dns_soa', $entry->dbtable);
        $this->assertSame('u', $entry->action);

        // The new owner sees the zone and its records, the old one no longer does
        $this->getJson('/api/v1/dns/soa/'.$zone, $this->tenantHeaders('clientB'))->assertOk();
        $this->getJson('/api/v1/dns/records?zone='.$zone, $this->tenantHeaders('clientB'))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/dns/soa/'.$zone, $this->tenantHeaders('clientA'))->assertNotFound();
    }

    public function test_settings_and_owner_together_bump_the_serial_once(): void
    {
        $zone = $this->zoneOf('clientA', 'both.test.');

        $this->putJson('/api/v1/dns/soa/'.$zone, ['client_id' => $this->tenant('clientB')['client_id'], 'ttl' => 7200], $this->tenantHeaders('admin'))
            ->assertOk();

        $row = DB::table('dns_soa')->where('id', $zone)->first();
        $this->assertSame(7200, (int) $row->ttl);
        $this->assertNotSame('1', (string) $row->serial);
        $this->assertSame($this->tenant('clientB')['groupid'], (int) $row->sys_groupid);
    }

    public function test_the_same_client_changes_nothing(): void
    {
        $zone = $this->zoneOf('clientA', 'same.test.');
        $datalog = DB::table('sys_datalog')->count();

        $this->putJson('/api/v1/dns/soa/'.$zone, ['client_id' => $this->tenant('clientA')['client_id']], $this->tenantHeaders('admin'))
            ->assertOk();

        $this->assertSame($datalog, DB::table('sys_datalog')->count());
    }

    public function test_a_reseller_moves_only_between_its_own_clients(): void
    {
        $zone = $this->zoneOf('clientA', 'reseller.test.');

        $this->putJson('/api/v1/dns/soa/'.$zone, ['client_id' => $this->tenant('clientB')['client_id']], $this->tenantHeaders('reseller'))
            ->assertForbidden();
        $this->assertSame($this->tenant('clientA')['groupid'], (int) DB::table('dns_soa')->where('id', $zone)->value('sys_groupid'));

        $this->putJson('/api/v1/dns/soa/'.$zone, ['client_id' => $this->tenant('reseller')['client_id']], $this->tenantHeaders('reseller'))
            ->assertOk();
        $this->assertSame($this->tenant('reseller')['groupid'], (int) DB::table('dns_soa')->where('id', $zone)->value('sys_groupid'));
    }

    public function test_a_client_key_cannot_give_its_zone_away(): void
    {
        $zone = $this->zoneOf('clientA', 'mine.test.');
        $this->record($zone, 'clientA', 'www');
        $datalog = DB::table('sys_datalog')->count();

        $this->putJson('/api/v1/dns/soa/'.$zone, ['client_id' => $this->tenant('clientB')['client_id']], $this->tenantHeaders('clientA'))
            ->assertForbidden();

        $this->assertSame($this->tenant('clientA')['groupid'], (int) DB::table('dns_soa')->where('id', $zone)->value('sys_groupid'));
        $this->assertSame($this->tenant('clientA')['groupid'], (int) DB::table('dns_rr')->where('zone', $zone)->value('sys_groupid'));
        $this->assertSame($datalog, DB::table('sys_datalog')->count());
    }

    public function test_an_unknown_client_is_refused(): void
    {
        $zone = $this->zoneOf('clientA', 'unknown.test.');

        $this->putJson('/api/v1/dns/soa/'.$zone, ['client_id' => 99999], $this->tenantHeaders('admin'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_id']);
    }

    private function zoneOf(string $tenant, string $origin): int
    {
        return (int) DB::table('dns_soa')->insertGetId($this->ownedBy($tenant, [
            'server_id' => 1, 'origin' => $origin, 'ns' => 'ns1.'.$origin,
            'mbox' => 'admin.'.$origin, 'serial' => '1', 'ttl' => 3600, 'active' => 'Y',
        ]), 'id');
    }

    private function record(int $zone, string $tenant, string $name): void
    {
        DB::table('dns_rr')->insert($this->ownedBy($tenant, [
            'server_id' => 1, 'zone' => $zone, 'name' => $name, 'type' => 'A', 'data' => '192.0.2.1', 'active' => 'Y',
        ]));
    }
}
