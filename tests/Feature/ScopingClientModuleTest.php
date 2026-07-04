<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ClientSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Client-module authorization matrix (spec 011 FR-016/FR-018, SC-001):
 * /clients/** requires admin-or-reseller; inside the gate the reseller is
 * row-scoped to its own clients; reseller client creation forces
 * parent_client_id and appends the new group to the reseller's CSV.
 */
class ScopingClientModuleTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        ClientSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        DB::table('server')->insert([
            'server_id' => 1, 'server_name' => 'srv1', 'mail_server' => 1, 'web_server' => 1,
            'dns_server' => 1, 'db_server' => 1, 'mirror_server_id' => 0, 'active' => 1,
        ]);
    }

    // ------------------------------------------------------------------
    // Module gate (FR-016)
    // ------------------------------------------------------------------

    public function test_plain_client_keys_are_gated_out_of_the_client_module(): void
    {
        foreach (['clients', 'clients/templates', 'clients/circles', 'clients/domains'] as $route) {
            $this->getJson("/api/v1/{$route}", $this->tenantHeaders('clientA'))
                ->assertStatus(403)
                ->assertHeader('Content-Type', 'application/problem+json');
        }

        $this->postJson('/api/v1/clients', [], $this->tenantHeaders('clientA'))->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Read half: reseller row scoping inside the gate
    // ------------------------------------------------------------------

    public function test_reseller_sees_only_its_own_clients(): void
    {
        $clientA = $this->tenant('clientA')['client_id'];
        $clientB = $this->tenant('clientB')['client_id'];

        // Admin sees every client row (3 seeded).
        $this->getJson('/api/v1/clients', $this->tenantHeaders('admin'))
            ->assertOk()->assertJsonPath('meta.total', 3);

        // The reseller owns clientA's row only.
        $this->getJson('/api/v1/clients', $this->tenantHeaders('reseller'))
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->getJson("/api/v1/clients/{$clientA}", $this->tenantHeaders('reseller'))->assertOk();
        $this->getJson("/api/v1/clients/{$clientB}", $this->tenantHeaders('reseller'))
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_reseller_templates_circles_and_domains_are_row_scoped(): void
    {
        $resources = [
            'clients/templates' => ['table' => 'client_template', 'admin' => ['template_name' => 'Admin plan'], 'reseller' => ['template_name' => 'R plan']],
            'clients/circles' => ['table' => 'client_circle', 'admin' => ['circle_name' => 'Admin circle'], 'reseller' => ['circle_name' => 'R circle']],
            'clients/domains' => ['table' => 'domain', 'admin' => ['domain' => 'admin-owned.test'], 'reseller' => ['domain' => 'r-owned.test']],
        ];

        foreach ($resources as $route => $definition) {
            DB::table($definition['table'])->insert($this->ownedBy('admin', $definition['admin']));
            DB::table($definition['table'])->insert($this->ownedBy('reseller', $definition['reseller']));

            $this->getJson("/api/v1/{$route}", $this->tenantHeaders('admin'))
                ->assertOk()->assertJsonPath('meta.total', 2);
            $this->getJson("/api/v1/{$route}", $this->tenantHeaders('reseller'))
                ->assertOk()->assertJsonPath('meta.total', 1);
        }
    }

    public function test_nested_template_assignments_scope_through_the_client_binding(): void
    {
        $clientA = $this->tenant('clientA')['client_id'];
        $clientB = $this->tenant('clientB')['client_id'];

        $this->getJson("/api/v1/clients/{$clientA}/templates", $this->tenantHeaders('reseller'))->assertOk();
        $this->getJson("/api/v1/clients/{$clientB}/templates", $this->tenantHeaders('reseller'))->assertStatus(404);
        $this->getJson("/api/v1/clients/{$clientA}/templates", $this->tenantHeaders('clientA'))->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Write half (FR-018)
    // ------------------------------------------------------------------

    public function test_reseller_created_client_is_parented_and_group_appended(): void
    {
        $reseller = $this->tenant('reseller');

        $this->postJson('/api/v1/clients', [
            'company_name' => 'New Co',
            'contact_name' => 'New Contact',
            'email' => 'new@newco.test',
            'username' => 'newco',
            'password' => 'super-secret-1',
            // Smuggled parent must be overridden (FR-018).
            'parent_client_id' => 0,
        ], $this->tenantHeaders('reseller'))->assertStatus(201);

        $newClient = DB::table('client')->where('username', 'newco')->first();
        $this->assertNotNull($newClient);
        $this->assertSame($reseller['client_id'], (int) $newClient->parent_client_id);
        $this->assertSame($reseller['userid'], (int) $newClient->sys_userid);
        $this->assertSame($reseller['groupid'], (int) $newClient->sys_groupid);

        $newGroup = DB::table('sys_group')->where('client_id', $newClient->client_id)->value('groupid');
        $this->assertNotNull($newGroup);

        // Legacy add_group_to_user: the reseller's CSV now grants the new
        // client's rows (auth.inc.php:100-121).
        $groups = explode(',', (string) DB::table('sys_user')->where('userid', $reseller['userid'])->value('groups'));
        $this->assertContains((string) $newGroup, $groups);

        // ... and the reseller immediately sees the new client.
        $this->getJson("/api/v1/clients/{$newClient->client_id}", $this->tenantHeaders('reseller'))->assertOk();
    }

    public function test_reseller_cannot_mutate_foreign_clients_or_reach_resellers(): void
    {
        $clientB = $this->tenant('clientB')['client_id'];
        $datalogCount = DB::table('sys_datalog')->count();

        $this->putJson("/api/v1/clients/{$clientB}", ['contact_name' => 'Stolen'], $this->tenantHeaders('reseller'))
            ->assertStatus(404);
        $this->deleteJson("/api/v1/clients/{$clientB}", [], $this->tenantHeaders('reseller'))
            ->assertStatus(404);

        $this->assertSame($datalogCount, DB::table('sys_datalog')->count());

        // /resellers/** stays admin-only even for reseller keys (FR-015).
        $this->getJson('/api/v1/resellers', $this->tenantHeaders('reseller'))
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json');
        $this->postJson('/api/v1/resellers', [], $this->tenantHeaders('reseller'))->assertStatus(403);
    }
}
