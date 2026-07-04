<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Module gates (spec 011 FR-013…FR-016, SC-003): every operation under
 * /servers, /system, /monitor and /resellers is admin-only — client and
 * reseller keys get 403 problem+json before any query runs (no module
 * tables are even created here); /clients/** admits admin and reseller
 * keys only. Admin keys pass every gate.
 */
class ModuleGateTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        TenantSchema::create();
        $this->seedTenants();
    }

    /**
     * >= 1 operation per admin-only contract file (spec API Contract table).
     *
     * @return array<int, array{0: string, 1: string}>
     */
    protected function adminOnlyOperations(): array
    {
        return [
            // server module
            ['GET', '/api/v1/servers'],                        // servers.yaml
            ['GET', '/api/v1/servers/1/configs'],              // server-config.yaml
            ['PUT', '/api/v1/servers/1/configs/server'],       // server-config.yaml (write)
            ['GET', '/api/v1/servers/1/ip-addresses'],         // ip-addresses.yaml
            ['GET', '/api/v1/servers/1/ip-mappings'],          // ip-mappings.yaml
            ['GET', '/api/v1/servers/1/php-versions'],         // php-versions.yaml
            ['GET', '/api/v1/servers/1/firewall'],             // firewall.yaml
            // system module
            ['GET', '/api/v1/system/config'],                  // system-config.yaml
            ['GET', '/api/v1/system/config/sites'],            // sites-config.yaml
            ['GET', '/api/v1/system/config/mail'],             // mail-config.yaml
            ['GET', '/api/v1/system/config/dns'],              // dns-config.yaml
            ['GET', '/api/v1/system/config/domains'],          // domains-config.yaml
            ['GET', '/api/v1/system/config/misc'],             // misc-config.yaml
            ['GET', '/api/v1/system/dns-cas'],                 // dns-cas.yaml
            ['GET', '/api/v1/system/directive-snippets'],      // directive-snippets.yaml
            ['POST', '/api/v1/system/resync'],                 // resync.yaml
            ['GET', '/api/v1/system/resync/servers'],          // resync.yaml
            // monitor module (incl. the sys_datalog journal — FR-014)
            ['GET', '/api/v1/monitor/data-logs'],              // data-logs.yaml
            ['GET', '/api/v1/monitor/servers/status'],         // server-status.yaml
            ['GET', '/api/v1/monitor/system-logs'],            // system-logs.yaml
            // resellers (FR-015)
            ['GET', '/api/v1/resellers'],                      // resellers.yaml
            ['POST', '/api/v1/resellers'],                     // resellers.yaml (write)
        ];
    }

    public function test_client_and_reseller_keys_get_403_on_every_admin_only_operation(): void
    {
        foreach ($this->adminOnlyOperations() as [$method, $uri]) {
            foreach (['clientA', 'reseller'] as $identity) {
                $this->json($method, $uri, [], $this->tenantHeaders($identity))
                    ->assertStatus(403)
                    ->assertHeader('Content-Type', 'application/problem+json')
                    ->assertJson(['title' => 'Forbidden', 'status' => 403]);
            }
        }
    }

    public function test_gates_deny_before_any_module_query_runs(): void
    {
        // None of the monitor/server/system tables exist in this test's
        // schema — a 403 (not a 500) proves the gate fires pre-query.
        $this->getJson('/api/v1/monitor/data-logs', $this->tenantHeaders('clientA'))
            ->assertStatus(403);
        $this->getJson('/api/v1/servers/1/firewall', $this->tenantHeaders('reseller'))
            ->assertStatus(403);
    }

    public function test_clients_module_requires_admin_or_reseller(): void
    {
        // Plain client: 403; reseller passes the gate (row scoping applies
        // inside); admin unrestricted (FR-016).
        $this->getJson('/api/v1/clients', $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json');
        $this->getJson('/api/v1/clients', $this->tenantHeaders('clientB'))
            ->assertStatus(403);

        $this->getJson('/api/v1/clients', $this->tenantHeaders('reseller'))->assertOk();
        $this->getJson('/api/v1/clients', $this->tenantHeaders('admin'))->assertOk();
    }

    public function test_admin_key_passes_the_gates(): void
    {
        DB::table('server')->insert([
            'server_id' => 1, 'server_name' => 'srv1', 'mail_server' => 1, 'mirror_server_id' => 0, 'active' => 1,
        ]);

        $this->getJson('/api/v1/servers', $this->tenantHeaders('admin'))->assertOk();
        $this->getJson('/api/v1/resellers', $this->tenantHeaders('admin'))->assertOk();
    }
}
