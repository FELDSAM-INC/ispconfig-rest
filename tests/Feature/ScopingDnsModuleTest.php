<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DnsSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * DNS-module authorization matrix (spec 011 SC-001/SC-002, FR-017): the
 * four-key matrix over dns/soa, dns/records and dns/slaves; template reads
 * stay row-scoped while template writes are admin-only.
 */
class ScopingDnsModuleTest extends TestCase
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

    protected function seedZone(string $owner, string $origin): int
    {
        return (int) DB::table('dns_soa')->insertGetId($this->ownedBy($owner, [
            'server_id' => 1,
            'origin' => $origin,
            'ns' => 'ns1.'.$origin,
            'mbox' => 'hostmaster.'.$origin,
            'active' => 'Y',
        ]));
    }

    public function test_four_key_matrix_over_zones_records_and_slaves(): void
    {
        $zoneA = $this->seedZone('clientA', 'a-zone.test.');
        $zoneB = $this->seedZone('clientB', 'b-zone.test.');

        $recordA = (int) DB::table('dns_rr')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 1, 'zone' => $zoneA, 'name' => 'www', 'type' => 'A', 'data' => '198.51.100.1',
        ]));
        $recordB = (int) DB::table('dns_rr')->insertGetId($this->ownedBy('clientB', [
            'server_id' => 1, 'zone' => $zoneB, 'name' => 'www', 'type' => 'A', 'data' => '198.51.100.2',
        ]));

        $slaveA = (int) DB::table('dns_slave')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 1, 'origin' => 'slave-a.test.', 'ns' => '203.0.113.1', 'active' => 'Y',
        ]));
        $slaveB = (int) DB::table('dns_slave')->insertGetId($this->ownedBy('clientB', [
            'server_id' => 1, 'origin' => 'slave-b.test.', 'ns' => '203.0.113.2', 'active' => 'Y',
        ]));

        foreach (['dns/soa', 'dns/records', 'dns/slaves'] as $route) {
            $this->getJson("/api/v1/{$route}", $this->tenantHeaders('admin'))
                ->assertOk()->assertJsonPath('meta.total', 2);
            $this->getJson("/api/v1/{$route}", $this->tenantHeaders('clientA'))
                ->assertOk()->assertJsonPath('meta.total', 1);
            $this->getJson("/api/v1/{$route}", $this->tenantHeaders('reseller'))
                ->assertOk()->assertJsonPath('meta.total', 1);
        }

        // B's zone (and its records) are invisible to A, incl. by id.
        $this->getJson("/api/v1/dns/soa/{$zoneB}", $this->tenantHeaders('clientA'))->assertStatus(404);
        $this->getJson("/api/v1/dns/records/{$recordB}", $this->tenantHeaders('clientA'))->assertStatus(404);
        $this->getJson("/api/v1/dns/slaves/{$slaveB}", $this->tenantHeaders('clientA'))->assertStatus(404);

        $this->getJson("/api/v1/dns/soa/{$zoneA}", $this->tenantHeaders('clientA'))->assertOk();
        $this->getJson("/api/v1/dns/records/{$recordA}", $this->tenantHeaders('clientA'))->assertOk();
        $this->getJson("/api/v1/dns/slaves/{$slaveA}", $this->tenantHeaders('reseller'))->assertOk();
    }

    public function test_create_slave_zone_is_stamped_with_the_key_identity(): void
    {
        $this->postJson('/api/v1/dns/slaves', [
            'server_id' => 1,
            'origin' => 'client-slave.test',
            'ns' => '203.0.113.9',
            // Forged ownership must be ignored (FR-012).
            'sys_userid' => 1,
            'sys_groupid' => 1,
        ], $this->tenantHeaders('clientA'))->assertStatus(201);

        $this->assertDatabaseHas('dns_slave', [
            'origin' => 'client-slave.test.',
            'sys_userid' => $this->tenant('clientA')['userid'],
            'sys_groupid' => $this->tenant('clientA')['groupid'],
        ]);
    }

    public function test_cross_tenant_zone_mutation_is_impossible(): void
    {
        $zoneB = $this->seedZone('clientB', 'b-zone.test.');
        $datalogCount = DB::table('sys_datalog')->count();

        $this->putJson("/api/v1/dns/soa/{$zoneB}", ['ttl' => 60], $this->tenantHeaders('clientA'))
            ->assertStatus(404);
        $this->deleteJson("/api/v1/dns/soa/{$zoneB}", [], $this->tenantHeaders('clientA'))
            ->assertStatus(404);

        $this->assertSame($datalogCount, DB::table('sys_datalog')->count());
        $this->assertDatabaseHas('dns_soa', ['id' => $zoneB, 'origin' => 'b-zone.test.']);
    }

    public function test_dns_template_reads_are_row_scoped_and_writes_admin_only(): void
    {
        // Admin-owned template (installer-style, no world read).
        $template = (int) DB::table('dns_template')->insertGetId([
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'name' => 'Default',
            'fields' => 'DOMAIN,IP,NS1,NS2,EMAIL',
            'template' => '[ZONE]',
            'visible' => 'Y',
        ]);

        $this->getJson('/api/v1/dns/templates', $this->tenantHeaders('admin'))
            ->assertOk()->assertJsonPath('meta.total', 1);
        // Row-scoped read: an admin-owned template is simply absent.
        $this->getJson('/api/v1/dns/templates', $this->tenantHeaders('clientA'))
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/dns/templates/{$template}", $this->tenantHeaders('clientA'))
            ->assertStatus(404);

        // Writes are hard-gated for non-admins (FR-017), even on ids the
        // client could never see.
        $this->postJson('/api/v1/dns/templates', ['name' => 'Mine'], $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json');
        $this->putJson("/api/v1/dns/templates/{$template}", ['name' => 'Hacked'], $this->tenantHeaders('reseller'))
            ->assertStatus(403);
        $this->deleteJson("/api/v1/dns/templates/{$template}", [], $this->tenantHeaders('clientA'))
            ->assertStatus(403);
    }
}
