<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DnsSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Spec 024 US2 (DNS): a record's zone must be readable by a non-admin key
 * (legacy dns_edit_base.php:100-103, no_zone_perm). A foreign zone fails
 * exactly like a nonexistent one and writes nothing.
 */
class ScopedReferenceDnsTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected const MISSING = 999999;

    protected int $zoneA;

    protected int $zoneB;

    public function test_read_only_zone_cannot_receive_records_or_reassignments(): void
    {
        DB::table('dns_soa')->where('id', $this->zoneB)->update(['sys_perm_other' => 'r']);
        $record = (int) DB::table('dns_rr')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 1, 'zone' => $this->zoneA, 'name' => 'www', 'type' => 'A', 'data' => '198.51.100.1', 'active' => 'Y',
        ]));
        $before = DB::table('sys_datalog')->count();
        $zones = DB::table('dns_soa')->orderBy('id')->get()->toJson();

        $this->postJson('/api/v1/dns/records', [
            'zone' => $this->zoneB, 'type' => 'A', 'name' => 'blocked', 'data' => '198.51.100.2',
        ], $this->tenantHeaders('clientA'))->assertForbidden();
        $this->putJson('/api/v1/dns/records/'.$record, ['zone' => $this->zoneB], $this->tenantHeaders('clientA'))->assertForbidden();

        $this->assertDatabaseMissing('dns_rr', ['zone' => $this->zoneB]);
        $this->assertDatabaseHas('dns_rr', ['id' => $record, 'zone' => $this->zoneA]);
        $this->assertSame($before, DB::table('sys_datalog')->count());
        $this->assertSame($zones, DB::table('dns_soa')->orderBy('id')->get()->toJson());
    }

    public function test_record_ownership_does_not_allow_changing_a_read_only_parent_zone(): void
    {
        DB::table('dns_soa')->where('id', $this->zoneB)->update(['sys_perm_other' => 'r']);
        $record = (int) DB::table('dns_rr')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 1, 'zone' => $this->zoneB, 'name' => 'www', 'type' => 'A', 'data' => '198.51.100.1', 'active' => 'Y',
        ]));
        $before = DB::table('sys_datalog')->count();

        $this->putJson('/api/v1/dns/records/'.$record, ['data' => '198.51.100.2'], $this->tenantHeaders('clientA'))->assertForbidden();
        $this->putJson('/api/v1/dns/records/'.$record, ['zone' => $this->zoneA], $this->tenantHeaders('clientA'))->assertForbidden();
        $this->deleteJson('/api/v1/dns/records/'.$record, [], $this->tenantHeaders('clientA'))->assertForbidden();

        $this->assertDatabaseHas('dns_rr', ['id' => $record, 'zone' => $this->zoneB, 'data' => '198.51.100.1']);
        $this->assertSame($before, DB::table('sys_datalog')->count());
    }

    protected function setUp(): void
    {
        parent::setUp();

        DnsSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        foreach (['clientA', 'clientB', 'reseller'] as $tenant) {
            $this->assignServers($tenant, ['dns' => [1]], 1);
        }

        DB::table('server')->insert([
            'server_id' => 1, 'server_name' => 'ns1', 'dns_server' => 1, 'mirror_server_id' => 0, 'active' => 1,
        ]);

        $this->zoneA = $this->seedZone('clientA', 'a-zone.test.');
        $this->zoneB = $this->seedZone('clientB', 'b-zone.test.');
    }

    protected function seedZone(string $owner, string $origin): int
    {
        return (int) DB::table('dns_soa')->insertGetId($this->ownedBy($owner, [
            'server_id' => 1, 'origin' => $origin, 'ns' => 'ns1.'.$origin, 'mbox' => 'hostmaster.'.$origin, 'active' => 'Y',
        ]));
    }

    public function test_record_create_in_a_foreign_zone_is_rejected_like_a_missing_zone(): void
    {
        $payload = fn (int $zone): array => ['zone' => $zone, 'type' => 'A', 'name' => 'hijack', 'data' => '198.51.100.9'];
        $datalog = DB::table('sys_datalog')->count();

        $foreign = $this->postJson('/api/v1/dns/records', $payload($this->zoneB), $this->tenantHeaders('clientA'))->assertStatus(422);
        $missing = $this->postJson('/api/v1/dns/records', $payload(self::MISSING), $this->tenantHeaders('clientA'))->assertStatus(422);

        $this->assertNotEmpty($foreign->json('errors.zone'));
        $this->assertSame($missing->json('errors.zone'), $foreign->json('errors.zone'));
        $this->assertSame($datalog, DB::table('sys_datalog')->count());
        $this->assertDatabaseMissing('dns_rr', ['zone' => $this->zoneB, 'name' => 'hijack']);

        $this->postJson('/api/v1/dns/records', $payload($this->zoneA), $this->tenantHeaders('clientA'))->assertStatus(201);
        $this->postJson('/api/v1/dns/records', ['zone' => $this->zoneB, 'type' => 'A', 'name' => 'admin', 'data' => '198.51.100.10'], $this->tenantHeaders('admin'))
            ->assertStatus(201);
    }

    public function test_record_cannot_be_moved_into_a_foreign_zone(): void
    {
        $record = (int) DB::table('dns_rr')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 1, 'zone' => $this->zoneA, 'name' => 'www', 'type' => 'A', 'data' => '198.51.100.1', 'active' => 'Y',
        ]));
        $datalog = DB::table('sys_datalog')->count();

        $foreign = $this->putJson("/api/v1/dns/records/{$record}", ['zone' => $this->zoneB], $this->tenantHeaders('clientA'))->assertStatus(422);
        $missing = $this->putJson("/api/v1/dns/records/{$record}", ['zone' => self::MISSING], $this->tenantHeaders('clientA'))->assertStatus(422);

        $this->assertNotEmpty($foreign->json('errors.zone'));
        $this->assertSame($missing->json('errors.zone'), $foreign->json('errors.zone'));
        $this->assertSame($datalog, DB::table('sys_datalog')->count());
        $this->assertDatabaseHas('dns_rr', ['id' => $record, 'zone' => $this->zoneA]);
    }

    public function test_dns_record_cannot_escape_its_owner_count_via_group_update(): void
    {
        $client = $this->tenant('clientA');
        DB::table('client')->where('client_id', $client['client_id'])->update(['limit_dns_record' => 1]);
        $payload = ['zone' => $this->zoneA, 'type' => 'A', 'name' => 'first', 'data' => '198.51.100.1'];
        $record = $this->postJson('/api/v1/dns/records', $payload, $this->tenantHeaders('clientA'))->assertCreated()->json('id');
        $before = DB::table('sys_datalog')->count();
        foreach ([$this->tenant('clientB')['groupid'], self::MISSING] as $group) {
            $this->putJson('/api/v1/dns/records/'.$record, ['sys_groupid' => $group], $this->tenantHeaders('clientA'))->assertForbidden();
        }
        $this->assertDatabaseHas('dns_rr', ['id' => $record, 'sys_groupid' => $client['groupid']]);
        $this->postJson('/api/v1/dns/records', array_merge($payload, ['name' => 'second']), $this->tenantHeaders('clientA'))->assertForbidden();
        $this->assertSame($before, DB::table('sys_datalog')->count());
        $this->putJson('/api/v1/dns/records/'.$record, ['sys_groupid' => $client['groupid']], $this->tenantHeaders('clientA'))->assertOk();
        $this->putJson('/api/v1/dns/records/'.$record, ['sys_groupid' => $this->tenant('clientB')['groupid']], $this->tenantHeaders('admin'))->assertOk();
    }
}
