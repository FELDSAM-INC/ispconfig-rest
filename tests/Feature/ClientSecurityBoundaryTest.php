<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ClientSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

class ClientSecurityBoundaryTest extends TestCase
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

    public function test_reseller_cannot_move_client_to_another_reseller(): void
    {
        $child = $this->tenant('clientA')['client_id'];
        $foreign = $this->tenant('clientB')['client_id'];
        DB::table('client')->where('client_id', $foreign)->update(['limit_client' => -1]);
        $before = DB::table('sys_user')->orderBy('userid')->get()->toJson();
        foreach ([$foreign, 0] as $target) {
            $this->putJson('/api/v1/clients/'.$child, ['parent_client_id' => $target], $this->tenantHeaders('reseller'))->assertForbidden();
        }
        $this->assertSame($before, DB::table('sys_user')->orderBy('userid')->get()->toJson());
        $this->assertDatabaseHas('client', ['client_id' => $child, 'parent_client_id' => $this->tenant('reseller')['client_id']]);
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_reseller_cannot_promote_owned_client(): void
    {
        $child = $this->tenant('clientA')['client_id'];
        foreach ([5, -1] as $limit) {
            $this->putJson('/api/v1/clients/'.$child, ['limit_client' => $limit], $this->tenantHeaders('reseller'))->assertForbidden();
        }
        $this->assertDatabaseHas('client', ['client_id' => $child, 'limit_client' => 0]);
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_reseller_cannot_create_reseller_via_clients(): void
    {
        $this->postJson('/api/v1/clients', [
            'contact_name' => 'Nested reseller', 'email' => 'nested@example.test',
            'username' => 'nested', 'password' => 'fixture-password-123', 'limit_client' => -1,
        ], $this->tenantHeaders('reseller'))->assertForbidden();
        $this->assertDatabaseMissing('client', ['username' => 'nested']);
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_reseller_cannot_assign_private_template(): void
    {
        $template = DB::table('client_template')->insertGetId($this->ownedBy('admin', ['template_name' => 'Private', 'template_type' => 'm', 'limit_mailbox' => -1]), 'template_id');
        $child = $this->tenant('clientA')['client_id'];
        $this->postJson('/api/v1/clients/'.$child.'/templates', ['client_template_id' => $template], $this->tenantHeaders('reseller'))->assertStatus(422);
    }

    public function test_reseller_cannot_set_private_master_in_payload(): void
    {
        $template = DB::table('client_template')->insertGetId($this->ownedBy('admin', ['template_name' => 'Private', 'template_type' => 'm', 'limit_mailbox' => -1]), 'template_id');
        $child = $this->tenant('clientA')['client_id'];
        $this->putJson('/api/v1/clients/'.$child, ['template_master' => $template], $this->tenantHeaders('reseller'))->assertStatus(422);
    }

    public function test_read_only_client_cannot_have_addon_assignments_changed(): void
    {
        $child = $this->tenant('clientA')['client_id'];
        DB::table('client')->where('client_id', $child)->update(['sys_perm_user' => 'r', 'sys_perm_group' => 'r']);
        $template = DB::table('client_template')->insertGetId($this->ownedBy('reseller', ['template_name' => 'Addon', 'template_type' => 'a']), 'template_id');
        $this->postJson('/api/v1/clients/'.$child.'/templates', ['client_template_id' => $template], $this->tenantHeaders('reseller'))->assertForbidden();
        $this->assertSame(0, DB::table('client_template_assigned')->count());
        $pivot = DB::table('client_template_assigned')->insertGetId(['client_id' => $child, 'client_template_id' => $template], 'assigned_template_id');
        $this->deleteJson('/api/v1/clients/'.$child.'/templates/'.$template, [], $this->tenantHeaders('reseller'))->assertForbidden();
        $this->assertDatabaseHas('client_template_assigned', ['assigned_template_id' => $pivot]);
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_reseller_may_use_shared_templates_and_keep_existing_authority_fields(): void
    {
        $child = $this->tenant('clientA')['client_id'];
        $template = DB::table('client_template')->insertGetId($this->ownedBy('admin', [
            'template_name' => 'Shared', 'template_type' => 'm', 'sys_perm_other' => 'r', 'limit_mailbox' => 7,
        ]), 'template_id');
        $this->postJson('/api/v1/clients/'.$child.'/templates', ['client_template_id' => $template], $this->tenantHeaders('reseller'))->assertCreated();
        $this->assertDatabaseHas('client', ['client_id' => $child, 'template_master' => $template, 'limit_mailbox' => 7]);
        $this->putJson('/api/v1/clients/'.$child, [
            'parent_client_id' => $this->tenant('reseller')['client_id'], 'limit_client' => 0, 'contact_name' => 'Updated',
        ], $this->tenantHeaders('reseller'))->assertOk();
    }

    public function test_admin_can_promote_and_move_a_customer(): void
    {
        $child = $this->tenant('clientA')['client_id'];
        $this->putJson('/api/v1/clients/'.$child, ['limit_client' => 5, 'parent_client_id' => 0], $this->tenantHeaders('admin'))->assertOk();
        $this->assertDatabaseHas('client', ['client_id' => $child, 'parent_client_id' => 0, 'limit_client' => 5]);
    }

    public function test_domain_owner_must_be_a_managed_client(): void
    {
        $domain = DB::table('domain')->insertGetId($this->ownedBy('reseller', ['domain' => 'owned.test']), 'domain_id');
        $foreign = $this->tenant('clientB')['client_id'];
        $this->putJson('/api/v1/clients/domains/'.$domain, ['client_id' => $foreign], $this->tenantHeaders('reseller'))->assertStatus(422);
        $this->postJson('/api/v1/clients/domains', ['domain' => 'foreign.test', 'client_id' => $foreign], $this->tenantHeaders('reseller'))->assertStatus(422);
        $this->assertDatabaseHas('domain', ['domain_id' => $domain, 'sys_groupid' => $this->tenant('reseller')['groupid']]);
        $this->assertSame(0, DB::table('sys_datalog')->count());
        $this->putJson('/api/v1/clients/domains/'.$domain, ['client_id' => $this->tenant('clientA')['client_id']], $this->tenantHeaders('reseller'))->assertOk();
        $this->assertDatabaseHas('domain', ['domain_id' => $domain, 'sys_groupid' => $this->tenant('clientA')['groupid']]);
        $this->putJson('/api/v1/clients/domains/'.$domain, ['client_id' => $foreign], $this->tenantHeaders('admin'))->assertOk();
    }

    public function test_unreadable_assigned_templates_do_not_disclose_details(): void
    {
        $child = $this->tenant('clientA')['client_id'];
        $master = DB::table('client_template')->insertGetId($this->ownedBy('admin', ['template_name' => 'Private master', 'template_type' => 'm']), 'template_id');
        $addon = DB::table('client_template')->insertGetId($this->ownedBy('admin', ['template_name' => 'Private addon', 'template_type' => 'a']), 'template_id');
        DB::table('client')->where('client_id', $child)->update(['template_master' => $master]);
        DB::table('client_template_assigned')->insert(['client_id' => $child, 'client_template_id' => $addon]);
        $url = '/api/v1/clients/'.$child.'/templates';
        $this->getJson($url, $this->tenantHeaders('reseller'))->assertOk()->assertJsonPath('data.0.template', null)->assertJsonPath('data.1.template', null);
        foreach ([$master, $addon] as $template) {
            $this->getJson($url.'/'.$template, $this->tenantHeaders('reseller'))->assertOk()->assertJsonPath('template', null);
            $this->assertNotNull($this->getJson($url.'/'.$template, $this->tenantHeaders('admin'))->assertOk()->json('template'));
        }
        DB::table('client_template')->where('template_id', $addon)->update(['sys_perm_other' => 'r']);
        $this->getJson($url.'/'.$addon, $this->tenantHeaders('reseller'))->assertOk()->assertJsonPath('template.template_name', 'Private addon');
    }

    public function test_template_update_cannot_convert_foreign_legacy_assignments(): void
    {
        $template = DB::table('client_template')->insertGetId($this->ownedBy('reseller', ['template_name' => 'Addon', 'template_type' => 'a']), 'template_id');
        $foreign = $this->tenant('clientB')['client_id'];
        DB::table('client')->where('client_id', $foreign)->update(['template_additional' => '/'.$template.'/', 'template_master' => 0]);
        $url = '/api/v1/clients/templates/'.$template;
        $this->putJson($url, ['template_name' => 'Changed'], $this->tenantHeaders('reseller'))->assertForbidden();
        $this->assertDatabaseHas('client', ['client_id' => $foreign, 'template_additional' => '/'.$template.'/']);
        $this->assertDatabaseHas('client_template', ['template_id' => $template, 'template_name' => 'Addon']);
        $this->assertSame(0, DB::table('client_template_assigned')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
        $this->putJson($url, ['template_name' => 'Changed'], $this->tenantHeaders('admin'))->assertOk();
        $this->assertDatabaseHas('client', ['client_id' => $foreign, 'template_additional' => '']);
        $this->assertDatabaseHas('client_template_assigned', ['client_id' => $foreign, 'client_template_id' => $template]);
    }
}
