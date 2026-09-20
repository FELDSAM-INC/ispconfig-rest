<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SitesApiTestCase;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;

class WebDomainAutoaliasTest extends SitesApiTestCase
{
    use TenantFixtures;

    private function configure(int $serverId, string $pattern): void
    {
        $config = (string) DB::table('server')->where('server_id', $serverId)->value('config');
        DB::table('server')->where('server_id', $serverId)->update(['config' => $config."\n[web]\nwebsite_autoalias=".$pattern]);
    }

    public static function vhosts(): array
    {
        return [['vhost', 1], ['vhostsubdomain', 1], ['vhostalias', 1], ['vhost', 2], ['vhostsubdomain', 2], ['vhostalias', 2]];
    }

    #[DataProvider('vhosts')]
    public function test_list_and_detail_resolve_all_placeholders_for_each_vhost(string $type, int $serverId): void
    {
        Schema::table('client', fn (Blueprint $table) => $table->string('username')->default(''));
        DB::table('client')->where('client_id', 3)->update(['username' => 'siteowner']);
        $this->configure($serverId, '[client_username]-[client_id]-[website_id].[website_domain].preview.test');
        $parent = $type === 'vhost' ? 0 : $this->seedVhost(['domain' => 'parent.test', 'server_id' => $serverId]);
        $id = $this->seedVhost(['domain' => 'child.test', 'type' => $type, 'parent_domain_id' => $parent, 'server_id' => $serverId]);
        $alias = "siteowner-3-{$id}.child.test.preview.test";
        $this->getJson("/api/v1/sites/web-domains/{$id}", $this->authHeaders())
            ->assertOk()->assertJsonPath('auto_alias', $alias)
            ->assertJsonMissingPath('config')->assertJsonMissingPath('website_autoalias');
        $this->getJson('/api/v1/sites/web-domains?search=child.test', $this->authHeaders())
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.auto_alias', $alias);
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_missing_or_non_hostname_alias_is_null_and_configuration_changes_are_not_cached(): void
    {
        $id = $this->seedVhost();
        foreach (['', '*.preview.test', '[unknown].test', 'https://preview.test/', 'two.test other.test', '-invalid.test', '<script>.test', str_repeat('a', 64).'.test'] as $pattern) {
            $this->configure(1, $pattern);
            $this->getJson("/api/v1/sites/web-domains/{$id}", $this->authHeaders())
                ->assertOk()->assertJsonStructure(['auto_alias'])->assertJsonPath('auto_alias', null);
        }
        $this->configure(1, 'web[website_id].preview.test');
        $this->getJson("/api/v1/sites/web-domains/{$id}", $this->authHeaders())->assertOk()->assertJsonPath('auto_alias', "web{$id}.preview.test");
        DB::table('server')->where('server_id', 1)->delete();
        $this->getJson("/api/v1/sites/web-domains/{$id}", $this->authHeaders())->assertOk()->assertJsonPath('auto_alias', null);
    }

    public function test_each_server_uses_its_own_template_and_shared_aliases_have_no_autoalias(): void
    {
        $this->configure(1, 'web[website_id].one.test');
        $this->configure(2, 'web[website_id].two.test');
        foreach ([1 => 'one', 2 => 'two'] as $server => $suffix) {
            $id = $this->seedVhost(['server_id' => $server]);
            $this->getJson("/api/v1/sites/web-domains/{$id}", $this->authHeaders())->assertOk()->assertJsonPath('auto_alias', "web{$id}.{$suffix}.test");
            foreach (['alias', 'subdomain'] as $type) {
                $child = $this->seedVhost(['server_id' => $server, 'type' => $type, 'parent_domain_id' => $id]);
                $this->getJson("/api/v1/sites/web-child-domains/{$child}", $this->authHeaders())->assertOk()->assertJsonMissingPath('auto_alias');
            }
        }
    }

    public function test_client_and_reseller_resolve_the_resource_owner_and_cannot_read_other_clients(): void
    {
        TenantSchema::create();
        $this->seedTenants();
        $this->configure(1, 'c[client_id]-web[website_id].preview.test');
        $own = $this->ownedBy('clientA');
        $clientId = DB::table('sys_group')->where('groupid', $own['sys_groupid'])->value('client_id');
        $id = $this->seedVhost($own);
        $other = $this->seedVhost($this->ownedBy('clientB'));
        $this->getJson('/api/v1/sites/web-domains')->assertUnauthorized();
        $this->getJson("/api/v1/sites/web-domains/{$id}")->assertUnauthorized();
        foreach (['clientA', 'reseller'] as $viewer) {
            $this->getJson('/api/v1/sites/web-domains', $this->tenantHeaders($viewer))
                ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.auto_alias', "c{$clientId}-web{$id}.preview.test");
            $this->getJson("/api/v1/sites/web-domains/{$other}", $this->tenantHeaders($viewer))->assertNotFound();
        }
    }

    public function test_autoalias_is_response_only_on_create_and_update(): void
    {
        $this->configure(1, 'web[website_id].preview.test');
        $response = $this->postJson('/api/v1/sites/web-domains', [
            'domain' => 'new.test', 'server_id' => 1, 'sys_groupid' => 5, 'auto_alias' => 'spoof.test',
        ], $this->authHeaders())->assertCreated();
        $id = $response->json('id');
        $response->assertJsonPath('auto_alias', "web{$id}.preview.test");
        $this->putJson("/api/v1/sites/web-domains/{$id}", ['active' => false, 'auto_alias' => 'spoof.test'], $this->authHeaders())
            ->assertOk()->assertJsonPath('auto_alias', "web{$id}.preview.test");
        $rows = $this->datalogRows('web_domain');
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('auto_alias', unserialize($row->data)['new']);
        }
    }
}
