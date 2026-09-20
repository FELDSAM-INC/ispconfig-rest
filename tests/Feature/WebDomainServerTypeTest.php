<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SitesApiTestCase;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;

class WebDomainServerTypeTest extends SitesApiTestCase
{
    use TenantFixtures;

    public static function domainTypes(): array
    {
        return [
            ['vhost', 'web-domains'],
            ['vhostsubdomain', 'web-domains'],
            ['vhostalias', 'web-domains'],
            ['subdomain', 'web-child-domains'],
            ['alias', 'web-child-domains'],
        ];
    }

    #[DataProvider('domainTypes')]
    public function test_list_and_detail_resolve_the_engine_per_domain(string $type, string $resource): void
    {
        foreach ([1 => 'apache', 2 => 'nginx'] as $serverId => $engine) {
            $parentId = $type === 'vhost' ? 0 : $this->seedVhost(['server_id' => $serverId]);
            $id = $this->seedVhost([
                'domain' => "engine-{$engine}.test", 'server_id' => $serverId,
                'type' => $type, 'parent_domain_id' => $parentId,
            ]);
            $this->getJson("/api/v1/sites/{$resource}/{$id}", $this->authHeaders())
                ->assertOk()->assertJsonPath('web_server_type', $engine)
                ->assertJsonMissingPath('config')->assertJsonMissingPath('server_config');
        }

        $query = $resource === 'web-domains' ? '?search=engine-' : '';
        $this->getJson("/api/v1/sites/{$resource}{$query}", $this->authHeaders())
            ->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.web_server_type', 'apache')
            ->assertJsonPath('data.1.web_server_type', 'nginx');
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_missing_or_unsupported_configuration_is_explicitly_unknown(): void
    {
        $parentId = $this->seedVhost();
        $childId = $this->seedVhost(['type' => 'alias', 'parent_domain_id' => $parentId]);
        foreach (['', '[web]', "[web]\nserver_type=other", "[mail]\nserver_type=apache"] as $config) {
            DB::table('server')->where('server_id', 1)->update(['config' => $config]);
            foreach (['web-domains' => $parentId, 'web-child-domains' => $childId] as $resource => $id) {
                $this->getJson("/api/v1/sites/{$resource}/{$id}", $this->authHeaders())
                    ->assertOk()->assertJsonStructure(['web_server_type'])->assertJsonPath('web_server_type', null);
            }
        }
        DB::table('server')->where('server_id', 1)->delete();
        $this->getJson("/api/v1/sites/web-domains/{$parentId}", $this->authHeaders())
            ->assertOk()->assertJsonPath('web_server_type', null);
    }

    public function test_client_and_reseller_keys_receive_only_engines_of_readable_domains(): void
    {
        TenantSchema::create();
        $this->seedTenants();
        $ids = [];
        foreach (['clientA' => 1, 'clientB' => 2] as $owner => $serverId) {
            $ids[$owner]['web-domains'] = $this->seedVhost($this->ownedBy($owner, ['server_id' => $serverId]));
            $ids[$owner]['web-child-domains'] = $this->seedVhost($this->ownedBy($owner, [
                'server_id' => $serverId, 'type' => 'alias', 'parent_domain_id' => $ids[$owner]['web-domains'],
            ]));
        }
        foreach (['web-domains', 'web-child-domains'] as $resource) {
            $this->getJson("/api/v1/sites/{$resource}")->assertUnauthorized();
            foreach (['clientA', 'reseller'] as $viewer) {
                $this->getJson("/api/v1/sites/{$resource}", $this->tenantHeaders($viewer))
                    ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.web_server_type', 'apache');
                $this->getJson("/api/v1/sites/{$resource}/{$ids['clientB'][$resource]}", $this->tenantHeaders($viewer))
                    ->assertNotFound();
            }
            $this->getJson("/api/v1/sites/{$resource}/{$ids['clientB'][$resource]}", $this->tenantHeaders('clientB'))
                ->assertOk()->assertJsonPath('web_server_type', 'nginx');
        }
    }

    public function test_the_engine_is_response_only_and_never_written_to_datalog(): void
    {
        $parent = $this->postJson('/api/v1/sites/web-domains', [
            'domain' => 'parent.test', 'server_id' => 2, 'sys_groupid' => 5, 'web_server_type' => 'apache',
        ], $this->authHeaders())->assertCreated()->assertJsonPath('web_server_type', 'nginx');
        $child = $this->postJson('/api/v1/sites/web-child-domains', [
            'parent_domain_id' => $parent->json('id'), 'domain' => 'alias.test',
            'type' => 'alias', 'web_server_type' => 'apache',
        ], $this->authHeaders())->assertCreated()->assertJsonPath('web_server_type', 'nginx');

        foreach (['web-domains' => $parent->json('id'), 'web-child-domains' => $child->json('id')] as $resource => $id) {
            $this->putJson("/api/v1/sites/{$resource}/{$id}", [
                'active' => false, 'web_server_type' => 'apache',
            ], $this->authHeaders())->assertOk()->assertJsonPath('active', false)->assertJsonPath('web_server_type', 'nginx');
        }
        $rows = $this->datalogRows('web_domain');
        $this->assertCount(4, $rows);
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('web_server_type', unserialize($row->data)['new']);
        }
    }
}
