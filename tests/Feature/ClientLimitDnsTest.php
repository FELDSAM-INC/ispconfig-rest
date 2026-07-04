<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DnsSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Client resource-limit COUNTING on the DNS module (spec 012): limit_dns_zone
 * (P1) and limit_dns_slave_zone (P2) matrices, plus the SC-006 regression
 * guard that dns/records is NEVER count-limited (limit_dns_record has no
 * legacy call site and is deliberately not wired).
 */
class ClientLimitDnsTest extends TestCase
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

    public function test_dns_zone_count_matrix(): void
    {
        // at cap: one owned zone, limit_dns_zone = 1.
        DB::table('dns_soa')->insert($this->ownedBy('clientA', [
            'server_id' => 1, 'origin' => 'existing.test.', 'ns' => 'ns1.existing.test.',
            'mbox' => 'admin.existing.test.', 'serial' => '1', 'active' => 'Y',
        ]));
        $this->setClientLimit('clientA', 'limit_dns_zone', 1);

        $datalog = DB::table('sys_datalog')->count();

        $this->postJson('/api/v1/dns/soa', [
            'server_id' => 1, 'origin' => 'blocked.test', 'ns' => 'ns1.blocked.test', 'mbox' => 'admin@blocked.test',
        ], $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('detail', 'You have reached the maximum number of DNS zones allowed for your account.');

        $this->assertSame($datalog, DB::table('sys_datalog')->count());

        // admin bypass past the cap.
        $this->postJson('/api/v1/dns/soa', [
            'server_id' => 1, 'origin' => 'admin.test', 'ns' => 'ns1.admin.test', 'mbox' => 'admin@admin.test',
        ], $this->tenantHeaders('admin'))->assertStatus(201);

        // under cap.
        $this->setClientLimit('clientA', 'limit_dns_zone', 5);
        $this->postJson('/api/v1/dns/soa', [
            'server_id' => 1, 'origin' => 'ok.test', 'ns' => 'ns1.ok.test', 'mbox' => 'admin@ok.test',
        ], $this->tenantHeaders('clientA'))->assertStatus(201);

        // unlimited.
        $this->setClientLimit('clientA', 'limit_dns_zone', -1);
        $this->postJson('/api/v1/dns/soa', [
            'server_id' => 1, 'origin' => 'unl.test', 'ns' => 'ns1.unl.test', 'mbox' => 'admin@unl.test',
        ], $this->tenantHeaders('clientA'))->assertStatus(201);
    }

    public function test_dns_slave_zone_cap(): void
    {
        DB::table('dns_slave')->insert($this->ownedBy('clientA', [
            'server_id' => 1, 'origin' => 'slave1.test.', 'ns' => '203.0.113.1', 'active' => 'Y',
        ]));
        $this->setClientLimit('clientA', 'limit_dns_slave_zone', 1);

        $this->postJson('/api/v1/dns/slaves', [
            'server_id' => 1, 'origin' => 'slave2.test', 'ns' => '203.0.113.2',
        ], $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('detail', 'You have reached the maximum number of DNS slave zones allowed for your account.');

        $this->setClientLimit('clientA', 'limit_dns_slave_zone', 2);
        $this->postJson('/api/v1/dns/slaves', [
            'server_id' => 1, 'origin' => 'slave2.test', 'ns' => '203.0.113.2',
        ], $this->tenantHeaders('clientA'))->assertStatus(201);
    }

    /**
     * SC-006: DNS records are never count-limited. Even with the client's zone
     * limit exhausted (0), creating resource records under an owned zone
     * succeeds — limit_dns_record is not wired (no legacy call site).
     */
    public function test_dns_records_are_never_limited(): void
    {
        $zoneId = (int) DB::table('dns_soa')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 1, 'origin' => 'zone.test.', 'ns' => 'ns1.zone.test.',
            'mbox' => 'admin.zone.test.', 'serial' => '1', 'active' => 'Y',
        ]), 'id');

        // Every count limit set to 0 (disabled) — records must still create.
        $this->setClientLimit('clientA', 'limit_dns_zone', 0);

        foreach (['www', 'mail', 'ftp', 'shop', 'api'] as $name) {
            $this->postJson('/api/v1/dns/records', [
                'zone' => $zoneId, 'name' => $name, 'type' => 'A', 'data' => '192.0.2.10',
            ], $this->tenantHeaders('clientA'))->assertStatus(201);
        }

        $this->assertSame(5, DB::table('dns_rr')->where('zone', $zoneId)->count());
    }
}
