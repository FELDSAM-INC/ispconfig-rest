<?php

namespace Tests\Feature;

use App\Models\ClientDomain;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ClientSchema;
use Tests\Support\SitesApiTestCase;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;

class AliasClientDomainTest extends SitesApiTestCase
{
    use TenantFixtures;

    private int $parentId;

    protected function setUp(): void
    {
        parent::setUp();
        ClientSchema::create();
        TenantSchema::create();
        $this->seedTenants();
        foreach (['clientA', 'clientB', 'reseller'] as $tenant) {
            $this->assignServers($tenant, ['web' => [1]]);
        }
        $this->parentId = $this->seedVhost($this->ownedBy('clientA', ['domain' => 'primary.test']));
        $this->enabled(true);
    }

    private function enabled(bool $enabled): void
    {
        DB::table('sys_ini')->where('sysini_id', 1)->update([
            'config' => "[domains]\nuse_domain_module=".($enabled ? 'y' : 'n'),
        ]);
    }

    public static function kinds(): array
    {
        return [['alias'], ['vhostalias']];
    }

    private function endpoint(string $type): string
    {
        return '/api/v1/sites/'.($type === 'alias' ? 'web-child-domains' : 'web-domains');
    }

    private function payload(string $type, array $extra = []): array
    {
        return array_merge([
            'type' => $type, 'parent_domain_id' => $this->parentId,
            'domain' => 'ALIAS.TEST', 'subdomain' => 'www',
        ], $type === 'vhostalias' ? ['web_folder' => 'alias'] : [], $extra);
    }

    private function register(string $owner): int
    {
        return (int) DB::table('domain')->insertGetId($this->ownedBy($owner, [
            'domain' => 'alias.test', 'sys_perm_group' => 'ru',
        ]), 'domain_id');
    }

    #[DataProvider('kinds')]
    public function test_client_alias_registers_normalized_name_and_datalogs_ownership(string $type): void
    {
        $response = $this->postJson($this->endpoint($type), $this->payload($type), $this->tenantHeaders('clientA'))
            ->assertCreated()->assertJsonPath('domain', 'alias.test');
        $this->assertDatabaseHas('domain', [
            'domain' => 'alias.test', 'sys_groupid' => $this->tenant('clientA')['groupid'], 'sys_perm_group' => 'ru',
        ]);
        $this->assertDatabaseCount('domain', 1);
        $rows = $this->datalogRows('domain');
        $this->assertCount(1, $rows);
        $this->assertSame('i', $rows[0]->action);
        $new = unserialize($rows[0]->data)['new'];
        $this->assertSame('alias.test', $new['domain']);
        $this->assertSame($this->tenant('clientA')['groupid'], (int) $new['sys_groupid']);
        $set = $response->headers->get('X-Change-Set-Id');
        $this->assertNotEmpty($set);
        $this->assertSame(2, DB::table('sys_datalog')->where('session_id', $set)->count());
        // This side effect does not grant access to the administrator's registry API.
        $this->getJson('/api/v1/clients/domains', $this->tenantHeaders('clientA'))->assertForbidden();
        $this->postJson('/api/v1/clients/domains', [
            'domain' => 'unrelated.test', 'client_id' => $this->tenant('clientA')['client_id'],
        ], $this->tenantHeaders('clientA'))->assertForbidden();
    }

    #[DataProvider('kinds')]
    public function test_disabled_setting_does_not_touch_registration(string $type): void
    {
        $this->enabled(false);
        $this->postJson($this->endpoint($type), $this->payload($type), $this->tenantHeaders('clientA'))->assertCreated();
        $this->assertDatabaseCount('domain', 0);
        $this->assertCount(0, $this->datalogRows('domain'));
    }

    #[DataProvider('kinds')]
    public function test_existing_same_owner_registration_is_reused_and_retained_after_alias_deletion(string $type): void
    {
        $id = $this->register('clientA');
        $before = (array) DB::table('domain')->where('domain_id', $id)->first();
        $response = $this->postJson($this->endpoint($type), $this->payload($type), $this->tenantHeaders('clientA'))->assertCreated();
        $this->assertDatabaseCount('domain', 1);
        $this->assertSame($before, (array) DB::table('domain')->where('domain_id', $id)->first());
        $this->assertCount(0, $this->datalogRows('domain'));
        $this->deleteJson($this->endpoint($type).'/'.$response->json('id'), [], $this->tenantHeaders('clientA'))->assertNoContent();
        $this->assertDatabaseHas('domain', ['domain_id' => $id]);
    }

    #[DataProvider('kinds')]
    public function test_other_owners_registration_conflicts_and_rolls_back_alias_and_datalog(string $type): void
    {
        $id = $this->register('clientB');
        $this->postJson($this->endpoint($type), $this->payload($type), $this->tenantHeaders('clientA'))
            ->assertConflict()->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('detail', 'This domain is already registered to another account.');
        $this->assertDatabaseHas('domain', ['domain_id' => $id, 'sys_groupid' => $this->tenant('clientB')['groupid']]);
        $this->assertDatabaseMissing('web_domain', ['domain' => 'alias.test']);
        $this->assertDatabaseCount('sys_datalog', 0);
    }

    #[DataProvider('kinds')]
    public function test_owner_comes_from_parent_even_if_ownership_fields_are_forged(string $type): void
    {
        $this->postJson($this->endpoint($type), $this->payload($type, [
            'sys_groupid' => $this->tenant('clientB')['groupid'],
            'client_id' => $this->tenant('clientB')['client_id'],
        ]), $this->tenantHeaders('clientA'))->assertCreated();
        $this->assertDatabaseHas('domain', ['domain' => 'alias.test', 'sys_groupid' => $this->tenant('clientA')['groupid']]);
    }

    #[DataProvider('kinds')]
    public function test_reseller_registers_for_managed_client_not_its_own_group(string $type): void
    {
        $this->postJson($this->endpoint($type), $this->payload($type), $this->tenantHeaders('reseller'))->assertCreated();
        $this->assertDatabaseHas('domain', ['domain' => 'alias.test', 'sys_groupid' => $this->tenant('clientA')['groupid']]);
    }

    #[DataProvider('kinds')]
    public function test_foreign_parent_is_refused_without_writes(string $type): void
    {
        $this->postJson($this->endpoint($type), $this->payload($type), $this->tenantHeaders('clientB'))
            ->assertUnprocessable()->assertJsonStructure(['errors' => ['parent_domain_id']]);
        $this->assertDatabaseCount('domain', 0);
        $this->assertDatabaseCount('sys_datalog', 0);
    }

    #[DataProvider('kinds')]
    public function test_read_access_to_foreign_parent_does_not_grant_registration(string $type): void
    {
        DB::table('web_domain')->where('domain_id', $this->parentId)->update(['sys_perm_other' => 'r']);
        $this->postJson($this->endpoint($type), $this->payload($type), $this->tenantHeaders('clientB'))->assertForbidden();
        $this->assertDatabaseCount('domain', 0);
        $this->assertDatabaseMissing('web_domain', ['domain' => 'alias.test']);
        $this->assertDatabaseCount('sys_datalog', 0);
    }

    #[DataProvider('kinds')]
    public function test_alias_limit_and_lock_still_prevent_both_writes(string $type): void
    {
        $this->setClientLimit('clientA', 'limit_web_aliasdomain', 0);
        $this->postJson($this->endpoint($type), $this->payload($type), $this->tenantHeaders('clientA'))->assertForbidden();
        $this->setClientLimit('clientA', 'limit_web_aliasdomain', -1);
        DB::table('client')->where('client_id', $this->tenant('clientA')['client_id'])->update(['locked' => 'y']);
        $this->postJson($this->endpoint($type), $this->payload($type), $this->tenantHeaders('clientA'))->assertForbidden();
        $this->assertDatabaseCount('domain', 0);
        $this->assertDatabaseMissing('web_domain', ['domain' => 'alias.test']);
        $this->assertDatabaseCount('sys_datalog', 0);
    }

    #[DataProvider('kinds')]
    public function test_registration_failure_rolls_back_alias_creation(string $type): void
    {
        ClientDomain::creating(static function (): void {
            throw new \RuntimeException('Injected registry failure');
        });
        try {
            $this->postJson($this->endpoint($type), $this->payload($type), $this->tenantHeaders('clientA'))->assertStatus(500);
            $this->assertDatabaseCount('domain', 0);
            $this->assertDatabaseMissing('web_domain', ['domain' => 'alias.test']);
            $this->assertDatabaseCount('sys_datalog', 0);
        } finally {
            ClientDomain::flushEventListeners();
        }
    }

    public function test_subdomains_do_not_get_separate_client_domain_entries(): void
    {
        $this->postJson('/api/v1/sites/web-child-domains', [
            'type' => 'subdomain', 'parent_domain_id' => $this->parentId, 'domain' => 'blog',
        ], $this->tenantHeaders('clientA'))->assertCreated();
        $this->postJson('/api/v1/sites/web-domains', [
            'type' => 'vhostsubdomain', 'parent_domain_id' => $this->parentId,
            'domain' => 'shop.primary.test', 'web_folder' => 'shop',
        ], $this->tenantHeaders('clientA'))->assertCreated();
        $this->assertDatabaseCount('domain', 0);
    }

    #[DataProvider('kinds')]
    public function test_admin_can_register_for_a_client_but_skips_unassigned_parent(string $type): void
    {
        $this->postJson($this->endpoint($type), $this->payload($type, ['server_id' => 1]), $this->tenantHeaders('admin'))->assertCreated();
        $this->assertDatabaseHas('domain', ['domain' => 'alias.test', 'sys_groupid' => $this->tenant('clientA')['groupid']]);
        $this->parentId = $this->seedVhost(['sys_groupid' => 1]);
        $this->postJson($this->endpoint($type), $this->payload($type, ['server_id' => 1, 'domain' => 'unassigned.test']), $this->tenantHeaders('admin'))->assertCreated();
        $this->assertDatabaseCount('domain', 1);
    }
}
