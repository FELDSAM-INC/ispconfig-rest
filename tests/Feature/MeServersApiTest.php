<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SitesSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * GET /me/servers (spec 016 US2, api/modules/me/servers.yaml): the servers
 * the calling key may use per service, with the default marked and no other
 * server data.
 */
class MeServersApiTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        SitesSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        $servers = [
            [1, 'web1', 'web', 0],
            [2, 'web2', 'web', 0],
            [3, 'mail1', 'mail', 0],
            [4, 'ns1', 'dns', 0],
            [5, 'ns1-mirror', 'dns', 4],
            [6, 'db1', 'db', 0],
            [7, 'ns2', 'dns', 0],
        ];

        foreach ($servers as [$id, $name, $role, $mirror]) {
            DB::table('server')->insert([
                'server_id' => $id,
                'server_name' => $name,
                'web_server' => $role === 'web' ? 1 : 0,
                'mail_server' => $role === 'mail' ? 1 : 0,
                'dns_server' => $role === 'dns' ? 1 : 0,
                'db_server' => $role === 'db' ? 1 : 0,
                'mirror_server_id' => $mirror,
                'active' => 1,
            ]);
        }

        $this->setSystemDefaults(2, 6, 3, 4, 7);
    }

    protected function setSystemDefaults(int $web, int $db, int $mail, int $dns, int $slave): void
    {
        DB::table('sys_ini')->updateOrInsert(['sysini_id' => 1], [
            'config' => implode("\n", [
                '[sites]',
                "default_webserver={$web}",
                "default_dbserver={$db}",
                '[mail]',
                "default_mailserver={$mail}",
                '[dns]',
                "default_dnsserver={$dns}",
                "default_slave_dnsserver={$slave}",
            ]),
        ]);
    }

    public function test_client_key_gets_its_assigned_servers_in_list_order(): void
    {
        $this->assignServers('clientA', ['web' => [2, 1], 'mail' => [3], 'db' => [], 'dns' => [5, 4, 99]], 7);

        $this->getJson('/api/v1/me/servers', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertExactJson([
                'web' => [
                    ['server_id' => 2, 'server_name' => 'web2', 'is_default' => true],
                    ['server_id' => 1, 'server_name' => 'web1', 'is_default' => false],
                ],
                'mail' => [
                    ['server_id' => 3, 'server_name' => 'mail1', 'is_default' => true],
                ],
                'db' => [],
                'dns' => [
                    ['server_id' => 4, 'server_name' => 'ns1', 'is_default' => true],
                ],
                'dns_slave' => ['server_id' => 7, 'server_name' => 'ns2', 'is_default' => true],
            ]);
    }

    public function test_reseller_key_uses_its_own_lists_and_null_slave_when_unassigned(): void
    {
        $this->assignServers('reseller', ['mail' => [3]], 0);
        $this->assignServers('clientA', ['web' => [1]]);

        $this->getJson('/api/v1/me/servers', $this->tenantHeaders('reseller'))
            ->assertOk()
            ->assertExactJson([
                'web' => [],
                'mail' => [['server_id' => 3, 'server_name' => 'mail1', 'is_default' => true]],
                'db' => [],
                'dns' => [],
                'dns_slave' => null,
            ]);
    }

    public function test_invalid_slave_dns_server_is_reported_as_null(): void
    {
        $this->assignServers('clientA', [], 5);

        $this->getJson('/api/v1/me/servers', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('dns_slave', null);
    }

    public function test_admin_key_gets_all_eligible_servers_with_system_defaults(): void
    {
        $this->getJson('/api/v1/me/servers', $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertExactJson([
                'web' => [
                    ['server_id' => 1, 'server_name' => 'web1', 'is_default' => false],
                    ['server_id' => 2, 'server_name' => 'web2', 'is_default' => true],
                ],
                'mail' => [['server_id' => 3, 'server_name' => 'mail1', 'is_default' => true]],
                'db' => [['server_id' => 6, 'server_name' => 'db1', 'is_default' => true]],
                'dns' => [
                    ['server_id' => 4, 'server_name' => 'ns1', 'is_default' => true],
                    ['server_id' => 7, 'server_name' => 'ns2', 'is_default' => false],
                ],
                'dns_slave' => ['server_id' => 7, 'server_name' => 'ns2', 'is_default' => true],
            ]);
    }

    public function test_admin_defaults_that_are_not_eligible_mark_nothing(): void
    {
        $this->setSystemDefaults(99, 1, 4, 5, 5);

        $response = $this->getJson('/api/v1/me/servers', $this->tenantHeaders('admin'))->assertOk();

        foreach (['web', 'db', 'mail', 'dns'] as $service) {
            foreach ($response->json($service) as $entry) {
                $this->assertFalse($entry['is_default'], "{$service} server {$entry['server_id']} must not be default");
            }
        }

        $response->assertJsonPath('dns_slave', null);
    }

    public function test_invalid_key_is_rejected(): void
    {
        $this->getJson('/api/v1/me/servers', ['X-API-Key' => 'isp_invalid'])
            ->assertStatus(401);
    }
}
