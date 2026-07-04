<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ClientSchema;
use Tests\Support\DnsSchema;
use Tests\Support\MailCompletionSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * The reseller double-cap (spec 012 FR-004 / checkResellerLimit) and the
 * bespoke limit_client count (FR-019, client_edit.php:68). clientA sits under
 * reseller R (R.groups = "groupR,groupA"), so a create that clears clientA's
 * own cap can still be denied by R's cap across R's group set.
 */
class ClientLimitResellerTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        // ClientSchema first (its sys_user carries app_theme for client
        // creation); the module schemas add their tables around it.
        ClientSchema::create();
        MailCompletionSchema::create();
        DnsSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        DB::table('server')->insert([
            'server_id' => 1, 'server_name' => 'srv1', 'mail_server' => 1, 'web_server' => 1,
            'dns_server' => 1, 'db_server' => 1, 'mirror_server_id' => 0, 'active' => 1,
        ]);
    }

    // ------------------------------------------------------------------
    // Reseller double-cap over row counts
    // ------------------------------------------------------------------

    public function test_reseller_maildomain_cap_denies_even_when_client_is_unlimited(): void
    {
        // clientA is unlimited for itself; the reseller allows only 1.
        $this->setClientLimit('clientA', 'limit_maildomain', -1);
        $this->setClientLimit('reseller', 'limit_maildomain', 1);

        // One mail domain owned by clientA already consumes R's group-set cap
        // (clientA's group is in R.groups).
        DB::table('mail_domain')->insert($this->ownedBy('clientA', [
            'server_id' => 1, 'domain' => 'a-owned.test', 'active' => 'y',
        ]));

        $datalog = DB::table('sys_datalog')->count();

        $this->postJson('/api/v1/mail/domains', [
            'server_id' => 1, 'domain' => 'over.test', 'active' => true, 'dkim' => false,
        ], $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('detail', 'Reseller: You have reached the maximum number of mail domains allowed for your account.');

        $this->assertSame($datalog, DB::table('sys_datalog')->count());

        // Free the reseller's slot -> the create succeeds.
        DB::table('mail_domain')->where('domain', 'a-owned.test')->delete();
        $this->postJson('/api/v1/mail/domains', [
            'server_id' => 1, 'domain' => 'over.test', 'active' => true, 'dkim' => false,
        ], $this->tenantHeaders('clientA'))->assertStatus(201);
    }

    public function test_reseller_dns_zone_cap_aggregates_across_the_reseller_group_set(): void
    {
        $this->setClientLimit('clientA', 'limit_dns_zone', -1);
        $this->setClientLimit('reseller', 'limit_dns_zone', 1);

        // The existing zone is owned by the reseller itself (also inside its
        // group set) — it still counts against clientA's create.
        DB::table('dns_soa')->insert($this->ownedBy('reseller', [
            'server_id' => 1, 'origin' => 'r-owned.test.', 'ns' => 'ns1.r-owned.test.',
            'mbox' => 'admin.r-owned.test.', 'serial' => '1', 'active' => 'Y',
        ]));

        $this->postJson('/api/v1/dns/soa', [
            'server_id' => 1, 'origin' => 'a-zone.test', 'ns' => 'ns1.a-zone.test', 'mbox' => 'admin@a-zone.test',
        ], $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('detail', 'Reseller: You have reached the maximum number of DNS zones allowed for your account.');

        $this->setClientLimit('reseller', 'limit_dns_zone', 5);
        $this->postJson('/api/v1/dns/soa', [
            'server_id' => 1, 'origin' => 'a-zone.test', 'ns' => 'ns1.a-zone.test', 'mbox' => 'admin@a-zone.test',
        ], $this->tenantHeaders('clientA'))->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // limit_client — bespoke sys_groupid count (reseller creating a client)
    // ------------------------------------------------------------------

    public function test_limit_client_uses_bespoke_group_count(): void
    {
        // The fixtures already own clientA under groupR (the reseller's
        // group), so the bespoke count `client WHERE sys_groupid = groupR`
        // is 1. limit_client = 1 -> the reseller's next client is denied.
        $this->setClientLimit('reseller', 'limit_client', 1);

        $datalog = DB::table('sys_datalog')->count();

        $this->postJson('/api/v1/clients', [
            'company_name' => 'Over Co', 'contact_name' => 'Over', 'email' => 'over@co.test',
            'username' => 'overco', 'password' => 'super-secret-1',
        ], $this->tenantHeaders('reseller'))
            ->assertStatus(403)
            ->assertJsonPath('detail', 'You have reached the maximum number of clients allowed for your account.');

        $this->assertSame($datalog, DB::table('sys_datalog')->count());
        $this->assertDatabaseMissing('client', ['username' => 'overco']);

        // Raise the reseller's client cap -> the create succeeds.
        $this->setClientLimit('reseller', 'limit_client', 5);
        $this->postJson('/api/v1/clients', [
            'company_name' => 'Over Co', 'contact_name' => 'Over', 'email' => 'over@co.test',
            'username' => 'overco', 'password' => 'super-secret-1',
        ], $this->tenantHeaders('reseller'))->assertStatus(201);

        // admin is never limited.
        $this->setClientLimit('reseller', 'limit_client', 1);
        $this->postJson('/api/v1/clients', [
            'company_name' => 'Admin Co', 'contact_name' => 'Admin', 'email' => 'admin@co.test',
            'username' => 'adminco', 'password' => 'super-secret-1', 'parent_client_id' => 0,
        ], $this->tenantHeaders('admin'))->assertStatus(201);
    }
}
