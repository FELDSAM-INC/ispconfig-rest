<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ClientSchema;
use Tests\Support\DnsSchema;
use Tests\Support\MailCompletionSchema;
use Tests\Support\SitesApiTestCase;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;

class AliasServicesTest extends SitesApiTestCase
{
    use TenantFixtures;

    private int $parent;

    private int $zone;

    private int $record;

    protected function setUp(): void
    {
        parent::setUp();
        ClientSchema::create();
        DnsSchema::create();
        MailCompletionSchema::create();
        TenantSchema::create();
        $this->seedTenants();
        $this->assignServers('clientA', ['web' => [1], 'dns' => [1], 'mail' => [1]]);
        DB::table('server')->where('server_id', 1)->update(['dns_server' => 1, 'mail_server' => 1]);
        $this->parent = $this->seedVhost($this->ownedBy('clientA', ['domain' => 'primary.test']));
        $this->zone = DB::table('dns_soa')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 1, 'origin' => 'primary.test.', 'ns' => 'ns1.host.test.', 'mbox' => 'hostmaster.primary.test.',
            'active' => 'Y', 'serial' => 2026092001,
        ]));
        $this->record = $this->record('primary.test.', 'A', '192.0.2.10');
        $this->record('www.primary.test.', 'CNAME', 'primary.test.');
        $this->record('primary.test.', 'MX', 'mail.primary.test.');
        $this->record('primary.test.', 'TXT', 'opaque primary.test. verification');
        DB::table('mail_domain')->insert($this->ownedBy('clientA', ['domain' => 'primary.test', 'server_id' => 1, 'active' => 'y']));
    }

    private function record(string $name, string $type, string $data, ?int $zone = null): int
    {
        return DB::table('dns_rr')->insertGetId($this->ownedBy('clientA', [
            'zone' => $zone ?? $this->zone, 'server_id' => 1, 'name' => $name,
            'type' => $type, 'data' => $data, 'aux' => 0, 'ttl' => 3600, 'active' => 'Y',
        ]));
    }

    private function endpoint(string $type = 'alias'): string
    {
        return '/api/v1/sites/'.($type === 'alias' ? 'web-child-domains' : 'web-domains');
    }

    private function create(string $type = 'alias', array $extra = [], string $actor = 'clientA')
    {
        return $this->postJson($this->endpoint($type), array_merge([
            'type' => $type, 'parent_domain_id' => $this->parent, 'domain' => 'alias.test',
            'dns_sync' => true, 'mail_service' => true,
        ], $type === 'vhostalias' ? ['web_folder' => 'alias'] : [], $extra), $this->tenantHeaders($actor));
    }

    public static function kinds(): array
    {
        return [['alias'], ['vhostalias']];
    }

    #[DataProvider('kinds')]
    public function test_creation_coordinates_owned_zone_records_and_mail(string $type): void
    {
        $response = $this->create($type)->assertCreated()->assertJsonPath('alias_services.dns_sync', true)->assertJsonPath('alias_services.mail_service', true);
        $zone = $response->json('alias_services.dns_zone_id');
        $this->assertDatabaseHas('dns_soa', ['id' => $zone, 'origin' => 'alias.test.', 'mbox' => 'hostmaster.alias.test.', 'sys_groupid' => $this->tenant('clientA')['groupid']]);
        $this->assertDatabaseHas('dns_rr', ['zone' => $zone, 'name' => 'www.alias.test.', 'data' => 'alias.test.']);
        $this->assertDatabaseHas('dns_rr', ['zone' => $zone, 'type' => 'MX', 'data' => 'mail.alias.test.']);
        $this->assertDatabaseHas('dns_rr', ['zone' => $zone, 'type' => 'TXT', 'data' => 'opaque primary.test. verification']);
        $this->assertDatabaseHas('mail_forwarding', ['source' => '@alias.test', 'destination' => '@primary.test', 'type' => 'aliasdomain', 'active' => 'y']);
        $this->getJson('/api/v1/dns/soa/'.$zone, $this->tenantHeaders('clientA'))->assertOk()->assertJsonPath('alias_sync.enabled', true);
        $this->getJson('/api/v1/mail/alias-domains?destination=@primary.test', $this->tenantHeaders('clientA'))->assertOk()->assertJsonPath('data.0.source', '@alias.test');
        $this->assertSame(1, DB::table('sys_datalog')->distinct()->count('session_id'));
    }

    public function test_primary_add_update_delete_and_soa_settings_propagate(): void
    {
        $alias = $this->create()->assertCreated()->json();
        $zone = $alias['alias_services']['dns_zone_id'];
        $h = $this->tenantHeaders('clientA');
        $this->putJson('/api/v1/dns/records/'.$this->record, ['data' => '192.0.2.20'], $h)->assertOk();
        $this->assertDatabaseHas('dns_rr', ['zone' => $zone, 'type' => 'A', 'data' => '192.0.2.20']);
        $new = $this->postJson('/api/v1/dns/records', ['zone' => $this->zone, 'name' => 'new.primary.test.', 'type' => 'CNAME', 'data' => 'external.test.'], $h)->assertCreated()->json('id');
        $this->assertDatabaseHas('dns_rr', ['zone' => $zone, 'name' => 'new.alias.test.', 'data' => 'external.test.']);
        $this->deleteJson('/api/v1/dns/records/'.$new, [], $h)->assertNoContent();
        $this->assertDatabaseMissing('dns_rr', ['zone' => $zone, 'name' => 'new.alias.test.']);
        $this->putJson('/api/v1/dns/soa/'.$this->zone, ['ttl' => 7200], $h)->assertOk();
        $this->assertDatabaseHas('dns_soa', ['id' => $zone, 'ttl' => 7200]);
    }

    public function test_synced_zone_refuses_all_direct_writes_and_moves(): void
    {
        $alias = $this->create()->assertCreated()->json();
        $zone = $alias['alias_services']['dns_zone_id'];
        $rr = DB::table('dns_rr')->where('zone', $zone)->value('id');
        $h = $this->tenantHeaders('clientA');
        $before = DB::table('sys_datalog')->count();
        $this->postJson('/api/v1/dns/records', ['zone' => $zone, 'name' => 'new', 'type' => 'A', 'data' => '192.0.2.30'], $h)->assertConflict();
        $this->putJson('/api/v1/dns/records/'.$rr, ['data' => '192.0.2.30'], $h)->assertConflict();
        $this->putJson('/api/v1/dns/records/'.$this->record, ['zone' => $zone], $h)->assertConflict();
        $this->putJson('/api/v1/dns/records/'.$rr, ['zone' => $this->zone], $h)->assertConflict();
        $this->deleteJson('/api/v1/dns/records/'.$rr, [], $h)->assertConflict();
        $this->putJson('/api/v1/dns/soa/'.$zone, ['active' => false], $h)->assertConflict();
        $this->deleteJson('/api/v1/dns/soa/'.$zone, [], $h)->assertConflict();
        $this->deleteJson('/api/v1/dns/soa/'.$this->zone, [], $h)->assertConflict();
        $this->assertSame($before, DB::table('sys_datalog')->count());
    }

    public function test_disabling_sync_retains_records_and_unlocks_editing(): void
    {
        $alias = $this->create()->assertCreated()->json();
        $zone = $alias['alias_services']['dns_zone_id'];
        $h = $this->tenantHeaders('clientA');
        $this->putJson($this->endpoint().'/'.$alias['id'], ['dns_sync' => false], $h)->assertOk()->assertJsonPath('alias_services.dns_sync', false);
        $this->assertSame(4, DB::table('dns_rr')->where('zone', $zone)->count());
        $this->putJson('/api/v1/dns/records/'.$this->record, ['data' => '192.0.2.50'], $h)->assertOk();
        $this->assertDatabaseHas('dns_rr', ['zone' => $zone, 'type' => 'A', 'data' => '192.0.2.10']);
        $this->postJson('/api/v1/dns/records', ['zone' => $zone, 'name' => 'own', 'type' => 'A', 'data' => '192.0.2.40'], $h)->assertCreated();
    }

    public function test_mail_switch_is_idempotent_and_does_not_delete_dns(): void
    {
        $alias = $this->create()->assertCreated()->json();
        $url = $this->endpoint().'/'.$alias['id'];
        $h = $this->tenantHeaders('clientA');
        $this->putJson($url, ['mail_service' => false], $h)->assertOk()->assertJsonPath('alias_services.mail_service', false);
        $this->assertDatabaseHas('mail_forwarding', ['source' => '@alias.test', 'active' => 'n']);
        $this->assertDatabaseHas('mail_domain', ['domain' => 'alias.test', 'active' => 'n']);
        $this->assertDatabaseHas('dns_soa', ['origin' => 'alias.test.']);
        $this->putJson($url, ['mail_service' => true], $h)->assertOk()->assertJsonPath('alias_services.mail_service', true);
        $before = DB::table('sys_datalog')->count();
        $this->putJson($url, ['mail_service' => true, 'dns_sync' => true], $h)->assertOk();
        $this->assertSame($before, DB::table('sys_datalog')->count());
        $this->assertDatabaseCount('mail_forwarding', 1);
    }

    public function test_external_primary_changes_are_reconciled_without_rewriting_unchanged_records(): void
    {
        $alias = $this->create()->assertCreated()->json();
        $zone = $alias['alias_services']['dns_zone_id'];
        DB::table('dns_rr')->where('id', $this->record)->update(['data' => '192.0.2.99']);
        $this->artisan('aliases:sync-dns')->assertSuccessful();
        $this->assertDatabaseHas('dns_rr', ['zone' => $zone, 'type' => 'A', 'data' => '192.0.2.99']);
        $before = DB::table('sys_datalog')->count();
        $this->artisan('aliases:sync-dns')->assertSuccessful();
        $this->assertSame($before, DB::table('sys_datalog')->count());
    }

    public function test_missing_primary_zone_or_mail_fails_atomically(): void
    {
        DB::table('mail_domain')->delete();
        $this->create()->assertUnprocessable()->assertJsonValidationErrors('mail_service');
        $this->assertDatabaseMissing('web_domain', ['domain' => 'alias.test']);
        $this->assertDatabaseMissing('dns_soa', ['origin' => 'alias.test.']);
        $this->assertDatabaseCount('sys_datalog', 0);
        DB::table('dns_soa')->delete();
        $this->create(extra: ['mail_service' => false])->assertUnprocessable()->assertJsonValidationErrors('dns_sync');
        $this->assertDatabaseCount('sys_datalog', 0);
    }

    public function test_dns_and_mail_limits_roll_back_all_resources(): void
    {
        $this->setClientLimit('clientA', 'limit_dns_zone', 1);
        $this->create()->assertForbidden();
        $this->setClientLimit('clientA', 'limit_dns_zone', -1);
        $this->setClientLimit('clientA', 'limit_mailaliasdomain', 0);
        $this->create()->assertForbidden();
        $this->assertDatabaseMissing('web_domain', ['domain' => 'alias.test']);
        $this->assertDatabaseMissing('dns_soa', ['origin' => 'alias.test.']);
        $this->assertDatabaseMissing('mail_domain', ['domain' => 'alias.test']);
        $this->assertDatabaseCount('sys_datalog', 0);
    }

    public function test_foreign_dns_mail_and_readable_parent_cannot_be_adopted(): void
    {
        DB::table('dns_soa')->insert($this->ownedBy('clientB', ['origin' => 'alias.test.', 'ns' => 'ns1.test.', 'mbox' => 'hostmaster.alias.test.']));
        $this->create()->assertConflict();
        DB::table('dns_soa')->where('origin', 'alias.test.')->delete();
        DB::table('mail_domain')->insert($this->ownedBy('clientB', ['domain' => 'alias.test']));
        $this->create()->assertConflict();
        DB::table('web_domain')->where('domain_id', $this->parent)->update(['sys_perm_other' => 'r']);
        $this->create(actor: 'clientB')->assertForbidden();
        $this->assertDatabaseCount('sys_datalog', 0);
    }

    public function test_alias_deletion_detaches_zone_and_disables_mail_routing(): void
    {
        $alias = $this->create()->assertCreated()->json();
        $this->deleteJson($this->endpoint().'/'.$alias['id'], [], $this->tenantHeaders('clientA'))->assertNoContent();
        $this->assertDatabaseCount('api_alias_services', 0);
        $this->assertDatabaseHas('dns_soa', ['origin' => 'alias.test.']);
        $this->assertDatabaseHas('mail_forwarding', ['source' => '@alias.test', 'active' => 'n']);
    }

    public function test_independent_alias_creates_dns_even_without_primary_zone_or_mail(): void
    {
        DB::table('dns_rr')->delete();
        DB::table('dns_soa')->delete();
        DB::table('mail_domain')->delete();
        DB::table('web_domain')->where('domain_id', $this->parent)->update(['ip_address' => '192.0.2.10']);
        $alias = $this->create(extra: ['dns_sync' => false, 'mail_service' => false])->assertCreated()->json();
        $zone = $alias['alias_services']['dns_zone_id'];
        $this->assertDatabaseHas('dns_soa', ['id' => $zone, 'origin' => 'alias.test.']);
        $this->assertDatabaseHas('dns_rr', ['zone' => $zone, 'type' => 'NS']);
        $this->assertDatabaseHas('dns_rr', ['zone' => $zone, 'type' => 'A', 'data' => '192.0.2.10']);
        $this->assertDatabaseCount('mail_forwarding', 0);
    }

    public function test_mail_list_identifies_alias_and_target_without_per_row_reads(): void
    {
        $this->create()->assertCreated();
        $this->getJson('/api/v1/mail/domains?domain=alias.test', $this->tenantHeaders('clientA'))
            ->assertOk()->assertJsonPath('data.0.domain_alias.domain', 'primary.test')
            ->assertJsonPath('data.0.domain_alias.active', true);
    }

    public function test_managed_alias_cannot_silently_change_its_service_identity(): void
    {
        $alias = $this->create()->assertCreated()->json();
        $this->putJson($this->endpoint().'/'.$alias['id'], ['domain' => 'renamed.test'], $this->tenantHeaders('clientA'))->assertConflict();
        $this->assertDatabaseHas('web_domain', ['domain_id' => $alias['id'], 'domain' => 'alias.test']);
    }

    public function test_sync_reenable_replaces_independent_records_and_retains_valid_record_ids_afterwards(): void
    {
        $alias = $this->create(extra: ['dns_sync' => false])->assertCreated()->json();
        $zone = $alias['alias_services']['dns_zone_id'];
        $this->record('independent', 'TXT', 'own value', $zone);
        $h = $this->tenantHeaders('clientA');
        $this->putJson($this->endpoint().'/'.$alias['id'], ['dns_sync' => true], $h)->assertOk();
        $this->assertDatabaseMissing('dns_rr', ['zone' => $zone, 'name' => 'independent']);
        $id = DB::table('dns_rr')->where('zone', $zone)->where('type', 'A')->value('id');
        $this->putJson('/api/v1/dns/records/'.$this->record, ['data' => '192.0.2.44'], $h)->assertOk();
        $this->assertDatabaseHas('dns_rr', ['id' => $id, 'data' => '192.0.2.44']);
        DB::table('dns_rr')->where('zone', $this->zone)->delete();
        $this->artisan('aliases:sync-dns')->assertSuccessful();
        $this->assertSame(0, DB::table('dns_rr')->where('zone', $zone)->count());
    }
}
