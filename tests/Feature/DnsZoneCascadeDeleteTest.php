<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Support\DnsSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Spec 034 — DELETE /dns/soa/{id} removes the zone's records with the zone,
 * in the legacy order (dns_soa_del.php: deactivate, delete every dns_rr of the
 * zone, delete the zone) and in one change set. Scoping (spec 011/024) is
 * unchanged: a zone the key cannot see stays a 404.
 */
class DnsZoneCascadeDeleteTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

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
    }

    public function test_deleting_a_zone_removes_its_records_and_the_zone(): void
    {
        $zoneId = $this->zoneFor('clientA', 'cascade.test.');
        $recordIds = $this->recordsIn($zoneId, 'clientA', 3);
        $before = $this->lastDatalogId();

        $response = $this->deleteJson('/api/v1/dns/soa/'.$zoneId, [], $this->tenantHeaders('clientA'))
            ->assertNoContent()
            ->assertHeader('X-Change-Set-Id');

        $this->assertSame(0, DB::table('dns_rr')->where('zone', $zoneId)->count());
        $this->assertSame(0, DB::table('dns_soa')->where('id', $zoneId)->count());

        // Legacy journal: zone deactivated, one delete per record, zone deleted.
        $entries = $this->entriesAfter($before);

        $this->assertSame([
            ['dns_soa', 'u'],
            ['dns_rr', 'd'],
            ['dns_rr', 'd'],
            ['dns_rr', 'd'],
            ['dns_soa', 'd'],
        ], $entries->map(fn (object $row): array => [$row->dbtable, $row->action])->all());

        // Records are journaled in id order, and the zone is marked inactive.
        $this->assertSame(
            $recordIds,
            $entries->where('dbtable', 'dns_rr')->map(fn (object $row): int => (int) filter_var($row->dbidx, FILTER_SANITIZE_NUMBER_INT))->values()->all()
        );
        $this->assertStringContainsString('"active"', (string) $entries->first()->data);

        // One change set for the whole deletion.
        $this->assertSame(
            [$response->headers->get('X-Change-Set-Id')],
            $entries->pluck('session_id')->unique()->values()->all()
        );
    }

    public function test_deleting_an_empty_zone_writes_only_the_zone_entries(): void
    {
        $zoneId = $this->zoneFor('clientA', 'empty.test.');
        $before = $this->lastDatalogId();

        $this->deleteJson('/api/v1/dns/soa/'.$zoneId, [], $this->tenantHeaders('clientA'))->assertNoContent();

        $this->assertSame(
            [['dns_soa', 'u'], ['dns_soa', 'd']],
            $this->entriesAfter($before)->map(fn (object $row): array => [$row->dbtable, $row->action])->all()
        );

        $this->deleteJson('/api/v1/dns/soa/'.$zoneId, [], $this->tenantHeaders('clientA'))->assertNotFound();
    }

    public function test_scoping_is_unchanged(): void
    {
        // Another client's zone: invisible, nothing written.
        $foreign = $this->zoneFor('clientB', 'foreign.test.');
        $this->recordsIn($foreign, 'clientB', 2);
        $before = $this->lastDatalogId();

        $this->deleteJson('/api/v1/dns/soa/'.$foreign, [], $this->tenantHeaders('clientA'))->assertNotFound();

        $this->assertSame(1, DB::table('dns_soa')->where('id', $foreign)->count());
        $this->assertSame(2, DB::table('dns_rr')->where('zone', $foreign)->count());
        $this->assertSame($before, $this->lastDatalogId());

        // The reseller manages clientA and may remove its zone with the records.
        $managed = $this->zoneFor('clientA', 'managed.test.');
        $this->recordsIn($managed, 'clientA', 2);

        $this->deleteJson('/api/v1/dns/soa/'.$managed, [], $this->tenantHeaders('reseller'))->assertNoContent();
        $this->assertSame(0, DB::table('dns_rr')->where('zone', $managed)->count());

        // Admin keys delete any zone.
        $this->deleteJson('/api/v1/dns/soa/'.$foreign, [], $this->tenantHeaders('admin'))->assertNoContent();
        $this->assertSame(0, DB::table('dns_rr')->where('zone', $foreign)->count());
    }

    private function lastDatalogId(): int
    {
        return (int) DB::table('sys_datalog')->max('datalog_id');
    }

    /**
     * @return Collection<int, object>
     */
    private function entriesAfter(int $datalogId)
    {
        return DB::table('sys_datalog')
            ->where('datalog_id', '>', $datalogId)
            ->orderBy('datalog_id')
            ->get(['dbtable', 'dbidx', 'action', 'data', 'session_id']);
    }

    private function zoneFor(string $tenant, string $origin): int
    {
        return (int) DB::table('dns_soa')->insertGetId($this->ownedBy($tenant, [
            'server_id' => 1, 'origin' => $origin, 'ns' => 'ns1.'.$origin,
            'mbox' => 'admin.'.$origin, 'serial' => '1', 'active' => 'Y',
        ]), 'id');
    }

    /**
     * @return array<int, int>
     */
    private function recordsIn(int $zoneId, string $tenant, int $count): array
    {
        $ids = [];

        for ($i = 1; $i <= $count; $i++) {
            $ids[] = (int) DB::table('dns_rr')->insertGetId($this->ownedBy($tenant, [
                'server_id' => 1, 'zone' => $zoneId, 'name' => 'host'.$i, 'type' => 'A',
                'data' => '192.0.2.'.$i, 'active' => 'Y',
            ]), 'id');
        }

        return $ids;
    }
}
