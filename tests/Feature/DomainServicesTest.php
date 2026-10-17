<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\ClientSchema;
use Tests\Support\DnsSchema;
use Tests\Support\MailCompletionSchema;
use Tests\Support\ServerSchema;
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

    /**
     * Multiserver: a server only receives datalog rows of its own server_id, so the records of a new zone must carry
     * the zone's name server, not the column default (server 1).
     */
    public function test_records_of_a_new_zone_go_to_its_name_server(): void
    {
        DB::table('server')->insert(['server_id' => 4, 'server_name' => 'ns.host.test', 'dns_server' => 1, 'mirror_server_id' => 0, 'active' => 1]);
        $this->assignServers('clientA', ['web' => [1], 'dns' => [4], 'mail' => [1]]);

        $this->create(['dns_service' => true, 'mail_service' => true])->assertCreated();

        $zone = DB::table('dns_soa')->where('origin', 'new.test.')->first();
        $this->assertSame(4, (int) $zone->server_id);
        $records = DB::table('dns_rr')->where('zone', $zone->id)->get();
        $this->assertNotEmpty($records);
        foreach ($records as $record) {
            $this->assertSame(4, (int) $record->server_id, $record->type.' '.$record->name);
            $this->assertSame((int) $zone->sys_groupid, (int) $record->sys_groupid);
            $this->assertNotEmpty($record->serial);
        }
        $this->assertSame([4], DB::table('sys_datalog')->whereIn('dbtable', ['dns_soa', 'dns_rr'])->distinct()->pluck('server_id')->map(fn ($id) => (int) $id)->all());
        $last = DB::table('sys_datalog')->where('dbtable', 'dns_soa')->orderByDesc('datalog_id')->first();
        $this->assertSame('u', $last->action, 'the zone gets a new serial once its records exist');
        $this->assertGreaterThan(DB::table('sys_datalog')->where('dbtable', 'dns_rr')->max('datalog_id'), $last->datalog_id);
    }

    public function test_new_zone_from_a_dns_template_with_values_of_the_account_servers(): void
    {
        $template = $this->template('DOMAIN,IP,NS1,EMAIL,DKIM', "A|{DOMAIN}.|{IP}|0|3600\nA|www|{IP}|0|3600\nNS|{DOMAIN}.|{NS1}.|0|3600\nTXT|{DOMAIN}.|v=spf1 -all|0|3600\n");

        $this->create(['dns_service' => true, 'dns_template_id' => $template])->assertCreated();

        $zone = DB::table('dns_soa')->where('origin', 'new.test.')->first();
        $this->assertSame('Y', $zone->active);
        $this->assertSame('server.host.test.', $zone->ns);
        $this->assertSame('hostmaster.new.test.', $zone->mbox);
        $this->assertSame((int) $this->tenant('clientA')['groupid'], (int) $zone->sys_groupid);
        $records = DB::table('dns_rr')->where('zone', $zone->id)->orderBy('id')->get();
        $this->assertSame([['new.test.', 'A', '192.0.2.10'], ['www', 'A', '192.0.2.10'], ['new.test.', 'NS', 'server.host.test.'], ['new.test.', 'TXT', 'v=spf1 -all']],
            $records->map(fn ($row) => [$row->name, $row->type, $row->data])->all());
        $this->assertSame([1], $records->pluck('server_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
    }

    public function test_activating_dns_uses_the_template_too(): void
    {
        $template = $this->template('DOMAIN,IP,NS1', "A|{DOMAIN}.|{IP}|0|3600\nNS|{DOMAIN}.|{NS1}.|0|3600\n");
        $this->create()->assertCreated();

        $this->postJson(self::URL.'/activate', ['domain' => 'new.test', 'service' => 'dns', 'dns_template_id' => $template], $this->tenantHeaders('clientA'))->assertOk();

        $zone = DB::table('dns_soa')->where('origin', 'new.test.')->first();
        $this->assertSame(['A', 'NS'], DB::table('dns_rr')->where('zone', $zone->id)->orderBy('id')->pluck('type')->all());
    }

    public function test_a_template_needing_a_value_the_servers_lack_creates_nothing(): void
    {
        $template = $this->template('DOMAIN,IPV6,NS1', "AAAA|{DOMAIN}.|{IPV6}|0|3600\nNS|{DOMAIN}.|{NS1}.|0|3600\n");

        $this->create(['dns_service' => true, 'dns_template_id' => $template])
            ->assertStatus(422)
            ->assertJsonValidationErrors('dns_template_id');

        $this->assertDatabaseMissing('domain', ['domain' => 'new.test']);
        $this->assertDatabaseMissing('dns_soa', ['origin' => 'new.test.']);
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_hidden_templates_cannot_be_used(): void
    {
        $template = $this->template('DOMAIN,NS1', "NS|{DOMAIN}.|{NS1}.|0|3600\n", 'N');

        $this->create(['dns_service' => true, 'dns_template_id' => $template])
            ->assertStatus(422)
            ->assertJsonValidationErrors('dns_template_id');
    }

    public function test_removing_a_dns_only_domain_deletes_its_zone_records_and_registration(): void
    {
        $this->create(['dns_service' => true])->assertCreated();
        $zone = DB::table('dns_soa')->where('origin', 'new.test.')->value('id');
        $this->assertGreaterThan(0, DB::table('dns_rr')->where('zone', $zone)->count());
        $before = (int) DB::table('sys_datalog')->max('datalog_id');

        $this->deleteJson(self::URL.'/new.test', [], $this->tenantHeaders('clientA'))->assertNoContent();

        $this->assertDatabaseMissing('dns_soa', ['origin' => 'new.test.']);
        $this->assertSame(0, DB::table('dns_rr')->where('zone', $zone)->count());
        $this->assertDatabaseMissing('domain', ['domain' => 'new.test']);
        $rows = DB::table('sys_datalog')->where('datalog_id', '>', $before)->orderBy('datalog_id')->get();
        $this->assertSame(['dns_soa', 'u'], [$rows->first()->dbtable, $rows->first()->action], 'the zone goes inactive first');
        $this->assertSame(['domain', 'd'], [$rows->last()->dbtable, $rows->last()->action]);
        $this->assertSame(1, $rows->pluck('session_id')->unique()->count(), 'one change set');
        $this->getJson(self::URL, $this->tenantHeaders('clientA'))->assertJsonCount(0, 'data');
    }

    public function test_removing_a_domain_deletes_its_mail_domain_with_mailboxes(): void
    {
        $this->create(['dns_service' => true, 'mail_service' => true])->assertCreated();
        DB::table('mail_user')->insert($this->ownedBy('clientA', ['email' => 'info@new.test', 'server_id' => 1]));

        $this->deleteJson(self::URL.'/new.test', [], $this->tenantHeaders('clientA'))->assertNoContent();

        $this->assertDatabaseMissing('mail_domain', ['domain' => 'new.test']);
        $this->assertDatabaseMissing('mail_user', ['email' => 'info@new.test']);
        $this->assertDatabaseMissing('dns_soa', ['origin' => 'new.test.']);
        $this->assertDatabaseMissing('domain', ['domain' => 'new.test']);
    }

    public function test_a_domain_with_a_website_or_of_another_account_is_not_removed(): void
    {
        $this->create(['hosting_type' => 'webhosting', 'dns_service' => true, 'hd_quota' => 100])->assertCreated();

        $this->deleteJson(self::URL.'/new.test', [], $this->tenantHeaders('clientA'))->assertStatus(409);
        $this->deleteJson(self::URL.'/new.test', [], $this->tenantHeaders('clientB'))->assertStatus(409);
        $this->deleteJson(self::URL.'/unknown.test', [], $this->tenantHeaders('clientA'))->assertNotFound();

        $this->assertDatabaseHas('web_domain', ['domain' => 'new.test']);
        $this->assertDatabaseHas('dns_soa', ['origin' => 'new.test.']);
        $this->assertDatabaseHas('domain', ['domain' => 'new.test']);
    }

    public function test_a_locked_account_cannot_remove_a_domain(): void
    {
        $this->create(['dns_service' => true])->assertCreated();
        DB::table('client')->where('client_id', $this->tenant('clientA')['client_id'])->update(['locked' => 'y']);

        $this->deleteJson(self::URL.'/new.test', [], $this->tenantHeaders('clientA'))->assertForbidden();

        $this->assertDatabaseHas('dns_soa', ['origin' => 'new.test.']);
    }

    private function template(string $fields, string $records, string $visible = 'Y'): int
    {
        ServerSchema::create();
        DB::table('server_ip')->insert(['server_id' => 1, 'client_id' => 0, 'ip_type' => 'IPv4', 'ip_address' => '192.0.2.10', 'virtualhost' => 'y']);

        return (int) DB::table('dns_template')->insertGetId([
            'sys_userid' => 1, 'sys_groupid' => 1, 'sys_perm_user' => 'riud', 'sys_perm_group' => 'riud', 'sys_perm_other' => '',
            'name' => 'Web only', 'fields' => $fields, 'visible' => $visible,
            'template' => "[ZONE]\norigin={DOMAIN}.\nns={NS1}.\nmbox={EMAIL}.\nrefresh=7200\nretry=540\nexpire=604800\nminimum=3600\nttl=3600\n\n[DNS_RECORDS]\n".$records,
        ], 'template_id');
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
