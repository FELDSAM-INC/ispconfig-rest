<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MailCompletionSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Scoped route-model binding (spec 011 FR-008/FR-009, SC-004): rows the
 * acting key cannot read resolve to nothing — 404 problem+json exactly like
 * a missing id (legacy getDataRecord ANDs getAuthSQL('r')), denied writes
 * leave sys_datalog untouched, and nested sub-resources inherit the parent
 * binding's scope.
 */
class ScopedBindingTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        MailCompletionSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        DB::table('server')->insert([
            'server_id' => 1, 'server_name' => 'mail1', 'mail_server' => 1, 'mirror_server_id' => 0, 'active' => 1,
        ]);
    }

    protected function seedDomain(string $owner, array $attrs = []): int
    {
        return (int) DB::table('mail_domain')->insertGetId($this->ownedBy($owner, array_merge([
            'server_id' => 1,
            'domain' => 'seed.test',
            'active' => 'y',
        ], $attrs)), 'domain_id');
    }

    public function test_show_of_foreign_row_returns_404_problem(): void
    {
        $foreign = $this->seedDomain('clientB', ['domain' => 'b.test']);

        $this->getJson("/api/v1/mail/domains/{$foreign}", $this->tenantHeaders('clientA'))
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Not found', 'status' => 404]);
    }

    public function test_update_and_delete_of_foreign_row_return_404_and_leave_no_datalog(): void
    {
        $foreign = $this->seedDomain('clientB', ['domain' => 'b.test']);
        $datalogCount = DB::table('sys_datalog')->count();

        $this->putJson("/api/v1/mail/domains/{$foreign}", ['active' => false], $this->tenantHeaders('clientA'))
            ->assertStatus(404);

        $this->deleteJson("/api/v1/mail/domains/{$foreign}", [], $this->tenantHeaders('clientA'))
            ->assertStatus(404);

        $this->assertSame($datalogCount, DB::table('sys_datalog')->count());
        $this->assertDatabaseHas('mail_domain', ['domain_id' => $foreign, 'active' => 'y']);
    }

    public function test_own_row_remains_fully_accessible(): void
    {
        $own = $this->seedDomain('clientA', ['domain' => 'a.test']);

        $this->getJson("/api/v1/mail/domains/{$own}", $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('domain', 'a.test');

        $this->putJson("/api/v1/mail/domains/{$own}", ['active' => false], $this->tenantHeaders('clientA'))
            ->assertOk();

        $this->assertDatabaseHas('mail_domain', ['domain_id' => $own, 'active' => 'n']);

        $this->deleteJson("/api/v1/mail/domains/{$own}", [], $this->tenantHeaders('clientA'))
            ->assertStatus(204);

        $this->assertDatabaseMissing('mail_domain', ['domain_id' => $own]);
    }

    public function test_admin_key_still_reaches_every_row(): void
    {
        $a = $this->seedDomain('clientA', ['domain' => 'a.test']);
        $b = $this->seedDomain('clientB', ['domain' => 'b.test']);

        $this->getJson("/api/v1/mail/domains/{$a}", $this->tenantHeaders('admin'))->assertOk();
        $this->getJson("/api/v1/mail/domains/{$b}", $this->tenantHeaders('admin'))->assertOk();
    }

    public function test_world_readable_row_is_visible_to_every_tenant(): void
    {
        $world = $this->seedDomain('clientB', ['domain' => 'world.test', 'sys_perm_other' => 'r']);

        $this->getJson("/api/v1/mail/domains/{$world}", $this->tenantHeaders('clientA'))->assertOk();
        $this->getJson("/api/v1/mail/domains/{$world}", $this->tenantHeaders('clientB'))->assertOk();
    }

    public function test_nested_subtree_404s_when_parent_is_not_visible(): void
    {
        $mailUserB = (int) DB::table('mail_user')->insertGetId($this->ownedBy('clientB', [
            'server_id' => 1,
            'email' => 'user@b.test',
            'login' => 'user@b.test',
        ]), 'mailuser_id');

        // The whole subtree is gone for A (scoping applied at the parent
        // binding), while B and the admin still reach it.
        $this->getJson("/api/v1/mail/users/{$mailUserB}/filters", $this->tenantHeaders('clientA'))
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->getJson("/api/v1/mail/users/{$mailUserB}/filters", $this->tenantHeaders('clientB'))->assertOk();
        $this->getJson("/api/v1/mail/users/{$mailUserB}/filters", $this->tenantHeaders('admin'))->assertOk();
    }
}
