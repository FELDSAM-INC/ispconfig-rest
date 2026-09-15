<?php

namespace Tests\Feature;

use App\Support\ProblemType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\DnsSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Client resource-limit COUNTING on the DNS module (spec 012): limit_dns_zone
 * (P1) and limit_dns_slave_zone (P2) matrices, plus the limit_dns_record cap
 * of spec 030 (legacy dns_edit_base.php:105-118 — records carrying the key's
 * group, create only, no reseller cap; supersedes spec 012 SC-006).
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

        // Spec 016: non-admin keys may only create on servers assigned to their account.
        foreach (['clientA', 'clientB', 'reseller'] as $tenant) {
            $this->assignServers($tenant, ['dns' => [1]], 1);
        }

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

    public function test_dns_record_cap_matrix(): void
    {
        $zoneId = $this->zoneFor('clientA', 'zone.test.');
        $this->recordsIn($zoneId, 'clientA', 2);
        // another client's records never count
        $this->recordsIn($this->zoneFor('clientB', 'other.test.'), 'clientB', 3);
        $this->setClientLimit('clientA', 'limit_dns_record', 2);

        $datalog = DB::table('sys_datalog')->count();
        $serial = DB::table('dns_soa')->where('id', $zoneId)->value('serial');

        $this->postRecord('clientA', $zoneId, 'blocked')
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', ProblemType::BASE_URI.ProblemType::LIMIT_REACHED)
            ->assertJsonPath('detail', 'You have reached the maximum number of DNS records allowed for your account.')
            ->assertJsonPath('limit', ['name' => 'limit_dns_record', 'scope' => 'client', 'max' => 2, 'used' => 2]);

        $this->assertSame($datalog, DB::table('sys_datalog')->count());
        $this->assertSame(0, DB::table('dns_rr')->where('name', 'blocked')->count());
        $this->assertSame($serial, DB::table('dns_soa')->where('id', $zoneId)->value('serial'));

        // updates are never refused at the cap
        $recordId = (int) DB::table('dns_rr')->where('zone', $zoneId)->orderBy('id')->value('id');
        $this->putJson('/api/v1/dns/records/'.$recordId, ['ttl' => 7200], $this->tenantHeaders('clientA'))->assertOk();

        // admin keys are not limited; the record takes the zone's group and counts for the client
        $this->postRecord('admin', $zoneId, 'byadmin')->assertStatus(201);
        $this->postRecord('clientA', $zoneId, 'blocked')
            ->assertStatus(403)
            ->assertJsonPath('limit.used', 3);

        // deletes are never refused at the cap
        $this->deleteJson('/api/v1/dns/records/'.$recordId, [], $this->tenantHeaders('clientA'))->assertNoContent();

        // under the cap (2 of 3)
        $this->setClientLimit('clientA', 'limit_dns_record', 3);
        $this->postRecord('clientA', $zoneId, 'ok')->assertStatus(201);
        $this->postRecord('clientA', $zoneId, 'blocked')->assertStatus(403)->assertJsonPath('limit.max', 3);

        // unlimited
        $this->setClientLimit('clientA', 'limit_dns_record', -1);
        $this->postRecord('clientA', $zoneId, 'unl')->assertStatus(201);

        // 0 = no records at all
        $this->setClientLimit('clientA', 'limit_dns_record', 0);
        $this->postRecord('clientA', $zoneId, 'blocked')
            ->assertStatus(403)
            ->assertJsonPath('limit', ['name' => 'limit_dns_record', 'scope' => 'client', 'max' => 0, 'used' => 4]);
    }

    /**
     * A reseller key is checked against the reseller's own limit and the records
     * carrying the reseller's group (legacy counts by the acting user's default
     * group); the zone owner's cap does not bind it, and the reseller's cap is
     * not a second cap for its clients (no checkResellerLimit for records).
     */
    public function test_dns_record_cap_for_reseller_keys(): void
    {
        $zoneA = $this->zoneFor('clientA', 'client.test.');
        $this->recordsIn($zoneA, 'clientA', 1);
        $this->setClientLimit('clientA', 'limit_dns_record', 1);

        $zoneR = $this->zoneFor('reseller', 'reseller.test.');
        $this->recordsIn($zoneR, 'reseller', 1);
        $this->setClientLimit('reseller', 'limit_dns_record', 2);

        // clientA is at its cap, the reseller key is not bound by it
        $this->postRecord('reseller', $zoneA, 'r1')->assertStatus(201);
        $this->assertSame(
            $this->tenant('clientA')['groupid'],
            (int) DB::table('dns_rr')->where('name', 'r1')->value('sys_groupid')
        );

        // the record went to clientA's group: the reseller still counts 1 of 2
        $this->postRecord('reseller', $zoneR, 'r2')->assertStatus(201);

        $this->postRecord('reseller', $zoneA, 'r3')
            ->assertStatus(403)
            ->assertJsonPath('limit', ['name' => 'limit_dns_record', 'scope' => 'client', 'max' => 2, 'used' => 2]);

        // no reseller cap: the reseller's 0 does not bind its client
        $this->setClientLimit('reseller', 'limit_dns_record', 0);
        $this->setClientLimit('clientA', 'limit_dns_record', -1);
        $this->postRecord('clientA', $zoneA, 'c1')->assertStatus(201);
    }

    private function zoneFor(string $tenant, string $origin): int
    {
        return (int) DB::table('dns_soa')->insertGetId($this->ownedBy($tenant, [
            'server_id' => 1, 'origin' => $origin, 'ns' => 'ns1.'.$origin,
            'mbox' => 'admin.'.$origin, 'serial' => '1', 'active' => 'Y',
        ]), 'id');
    }

    private function recordsIn(int $zoneId, string $tenant, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            DB::table('dns_rr')->insert($this->ownedBy($tenant, [
                'server_id' => 1, 'zone' => $zoneId, 'name' => 'seed'.$i, 'type' => 'A',
                'data' => '192.0.2.'.$i, 'active' => 'Y',
            ]));
        }
    }

    private function postRecord(string $tenant, int $zoneId, string $name): TestResponse
    {
        return $this->postJson('/api/v1/dns/records', [
            'zone' => $zoneId, 'name' => $name, 'type' => 'A', 'data' => '192.0.2.200',
        ], $this->tenantHeaders($tenant));
    }
}
