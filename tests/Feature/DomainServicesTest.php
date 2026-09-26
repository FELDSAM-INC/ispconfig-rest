<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\ClientSchema;
use Tests\Support\DnsSchema;
use Tests\Support\MailCompletionSchema;
use Tests\Support\SitesApiTestCase;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;

class DomainServicesTest extends SitesApiTestCase
{
    use TenantFixtures;

    private const URL = '/api/v1/sites/domain-services';

    protected function setUp(): void
    {
        parent::setUp();
        ClientSchema::create();
        DnsSchema::create();
        MailCompletionSchema::create();
        TenantSchema::create();
        $this->seedTenants();
        $this->assignServers('clientA', ['web' => [1], 'dns' => [1], 'mail' => [1]]);
        DB::table('server')->where('server_id', 1)->update(['dns_server' => 1, 'mail_server' => 1, 'server_name' => 'server.host.test']);
    }

    private function create(array $fields = [])
    {
        return $this->postJson(self::URL, $fields + ['domain' => 'new.test', 'hosting_type' => 'none', 'dns_service' => false, 'mail_service' => false], $this->tenantHeaders('clientA'));
    }

    public function test_unhosted_domain_uses_no_website_and_is_visible_only_to_its_owner(): void
    {
        $this->create()->assertCreated();
        $this->assertDatabaseHas('domain', ['domain' => 'new.test', 'sys_groupid' => $this->tenant('clientA')['groupid']]);
        $this->assertDatabaseMissing('web_domain', ['domain' => 'new.test']);
        $this->getJson(self::URL, $this->tenantHeaders('clientA'))->assertJsonPath('data.0.domain', 'new.test');
        $this->getJson(self::URL, $this->tenantHeaders('clientB'))->assertJsonCount(0, 'data');
    }

    public function test_mail_only_and_dns_are_created_together(): void
    {
        $this->create(['dns_service' => true, 'mail_service' => true])->assertCreated();
        $this->assertDatabaseMissing('web_domain', ['domain' => 'new.test']);
        $this->assertDatabaseHas('mail_domain', ['domain' => 'new.test', 'active' => 'y']);
        $this->assertDatabaseHas('dns_soa', ['origin' => 'new.test.']);
        $this->assertDatabaseHas('dns_rr', ['name' => 'new.test.', 'type' => 'MX', 'data' => 'server.host.test.']);
        $this->assertDatabaseMissing('dns_rr', ['name' => 'www.new.test.', 'type' => 'A']);
        $this->assertSame(1, DB::table('sys_datalog')->distinct()->count('session_id'));
    }

    public function test_website_with_both_services(): void
    {
        $this->create(['hosting_type' => 'webhosting', 'dns_service' => true, 'mail_service' => true, 'hd_quota' => 100])->assertCreated();
        $this->assertDatabaseHas('web_domain', ['domain' => 'new.test', 'type' => 'vhost']);
        $this->assertDatabaseHas('domain', ['domain' => 'new.test']);
    }

    public function test_mail_limit_rolls_back_the_website_registry_and_datalog(): void
    {
        DB::table('client')->where('client_id', $this->tenant('clientA')['client_id'])->update(['limit_maildomain' => 0]);
        $this->create(['hosting_type' => 'webhosting', 'mail_service' => true])->assertForbidden();
        $this->assertDatabaseMissing('web_domain', ['domain' => 'new.test']);
        $this->assertDatabaseMissing('domain', ['domain' => 'new.test']);
        $this->assertDatabaseCount('sys_datalog', 0);
    }

    public function test_foreign_parent_and_foreign_registered_domain_are_rejected(): void
    {
        $id = $this->seedVhost($this->ownedBy('clientB', ['domain' => 'foreign.test']));
        $this->create(['hosting_type' => 'alias', 'parent_domain_id' => $id])->assertUnprocessable();
        $this->create(['domain' => 'foreign.test'])->assertConflict();
        $this->assertDatabaseMissing('domain', ['domain' => 'new.test']);
    }

    public function test_activation_is_owned_and_idempotent(): void
    {
        $this->create()->assertCreated();
        $fields = ['domain' => 'new.test', 'service' => 'mail'];
        $this->postJson(self::URL.'/activate', $fields, $this->tenantHeaders('clientB'))->assertConflict();
        $this->postJson(self::URL.'/activate', ['domain' => 'unknown.test', 'service' => 'mail'], $this->tenantHeaders('clientA'))->assertNotFound();
        for ($i = 0; $i < 2; $i++) {
            $this->postJson(self::URL.'/activate', $fields, $this->tenantHeaders('clientA'))->assertOk();
        }
        $this->assertDatabaseCount('mail_domain', 1);
    }

    public function test_alias_can_have_mail_without_dns_then_activate_dns(): void
    {
        $parent = $this->seedVhost($this->ownedBy('clientA', ['domain' => 'primary.test', 'seo_redirect' => 'www_to_non_www', 'ssl' => 'y']));
        DB::table('mail_domain')->insert($this->ownedBy('clientA', ['domain' => 'primary.test', 'server_id' => 1, 'active' => 'y']));
        $this->create(['hosting_type' => 'alias', 'parent_domain_id' => $parent, 'mail_service' => true])->assertCreated();
        $this->assertDatabaseHas('web_domain', ['domain' => 'new.test', 'type' => 'alias', 'seo_redirect' => 'www_to_non_www', 'ssl_letsencrypt_exclude' => 'n']);
        $this->assertDatabaseMissing('dns_soa', ['origin' => 'new.test.']);
        $this->assertDatabaseHas('mail_forwarding', ['source' => '@new.test', 'destination' => '@primary.test']);
        $this->postJson(self::URL.'/activate', ['domain' => 'new.test', 'service' => 'dns'], $this->tenantHeaders('clientA'))->assertOk();
        $this->assertDatabaseHas('dns_soa', ['origin' => 'new.test.']);
    }

    public function test_redirect_targets_follow_parent_ssl_www_and_server_engine(): void
    {
        foreach (['apache' => 'R=301,L', 'nginx' => 'permanent'] as $engine => $redirect) {
            DB::table('server')->where('server_id', 1)->update(['config' => "[web]\nserver_type=".$engine]);
            $parent = $this->seedVhost($this->ownedBy('clientA', ['domain' => $engine.'.test', 'seo_redirect' => 'non_www_to_www', 'ssl' => 'y']));
            $this->create(['domain' => 'alias-'.$engine.'.test', 'hosting_type' => 'alias', 'parent_domain_id' => $parent, 'redirect_301' => true])->assertCreated();
            $this->assertDatabaseHas('web_domain', ['domain' => 'alias-'.$engine.'.test', 'redirect_type' => $redirect, 'redirect_path' => 'https://www.'.$engine.'.test/', 'seo_redirect' => '']);
        }
    }

    public function test_later_mail_activation_adds_mx_and_does_not_change_an_existing_mx(): void
    {
        $this->create(['dns_service' => true])->assertCreated();
        $fields = ['domain' => 'new.test', 'service' => 'mail'];
        $this->postJson(self::URL.'/activate', $fields, $this->tenantHeaders('clientA'))->assertOk();
        $this->assertDatabaseHas('dns_rr', ['type' => 'MX', 'name' => 'new.test.', 'data' => 'server.host.test.']);
        DB::table('dns_rr')->where('type', 'MX')->update(['data' => 'external.test.']);
        $this->postJson(self::URL.'/activate', $fields, $this->tenantHeaders('clientA'))->assertOk();
        $this->assertSame(['external.test.'], DB::table('dns_rr')->where('type', 'MX')->pluck('data')->all());
    }

    public function test_a_locked_account_cannot_add_even_an_unhosted_domain(): void
    {
        DB::table('client')->where('client_id', $this->tenant('clientA')['client_id'])->update(['locked' => 'y']);
        $this->create()->assertForbidden();
        $this->assertDatabaseMissing('domain', ['domain' => 'new.test']);
    }

    public function test_alias_without_services_stays_without_dns_when_other_settings_change(): void
    {
        $parent = $this->seedVhost($this->ownedBy('clientA', ['domain' => 'primary.test']));
        $id = $this->create(['hosting_type' => 'alias', 'parent_domain_id' => $parent])->assertCreated()->json('website_id');
        $this->putJson('/api/v1/sites/web-child-domains/'.$id, ['mail_service' => false, 'dns_sync' => false], $this->tenantHeaders('clientA'))->assertOk();
        $this->assertDatabaseMissing('dns_soa', ['origin' => 'new.test.']);
    }

    public function test_hosted_subdomain_with_mail_is_not_listed_as_unhosted(): void
    {
        $this->seedVhost($this->ownedBy('clientA', ['type' => 'vhostsubdomain', 'domain' => 'sub.primary.test']));
        DB::table('mail_domain')->insert($this->ownedBy('clientA', ['domain' => 'sub.primary.test', 'server_id' => 1, 'active' => 'y']));
        $this->getJson(self::URL, $this->tenantHeaders('clientA'))->assertOk()->assertJsonPath('data.0.hosting_type', 'webhosting');
    }
}
