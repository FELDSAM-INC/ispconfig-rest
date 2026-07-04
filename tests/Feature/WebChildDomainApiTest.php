<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\SitesApiTestCase;

class WebChildDomainApiTest extends SitesApiTestCase
{
    protected function seedChild(int $parentId, array $overrides = []): int
    {
        return $this->seedVhost(array_merge([
            'domain' => 'blog.parent.com',
            'type' => 'subdomain',
            'parent_domain_id' => $parentId,
            'document_root' => '',
        ], $overrides));
    }

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/sites/web-child-domains')->assertStatus(401);
    }

    public function test_list_filters_by_type_and_parent(): void
    {
        $parentId = $this->seedVhost(['domain' => 'parent.com']);
        $otherId = $this->seedVhost(['domain' => 'other.com']);
        $this->seedChild($parentId, ['domain' => 'blog.parent.com', 'type' => 'subdomain']);
        $this->seedChild($parentId, ['domain' => 'alias-of-parent.com', 'type' => 'alias']);
        $this->seedChild($otherId, ['domain' => 'www2.other.com', 'type' => 'subdomain']);

        $this->getJson('/api/v1/sites/web-child-domains', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonMissingPath('data.0.document_root'); // projection: contract fields only

        $this->getJson('/api/v1/sites/web-child-domains?type=alias', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.type', 'alias');

        $this->getJson('/api/v1/sites/web-child-domains?parent_domain_id='.$parentId, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/sites/web-child-domains?sort=nope', $this->authHeaders())
            ->assertStatus(400);
    }

    public function test_show_returns_child_but_404s_for_vhost_ids(): void
    {
        $parentId = $this->seedVhost(['domain' => 'parent.com']);
        $childId = $this->seedChild($parentId);

        $this->getJson('/api/v1/sites/web-child-domains/'.$childId, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $childId)
            ->assertJsonPath('parent_domain', 'parent.com')
            ->assertJsonPath('server_name', 'web1');

        $this->getJson('/api/v1/sites/web-child-domains/'.$parentId, $this->authHeaders())
            ->assertStatus(404);
    }

    public function test_create_subdomain_composes_fqdn_and_derives_from_parent(): void
    {
        $parentId = $this->seedVhost(['domain' => 'parent.com', 'sys_groupid' => 5]);

        $response = $this->postJson('/api/v1/sites/web-child-domains', [
            'parent_domain_id' => $parentId,
            'domain' => 'Blog',
            'type' => 'subdomain',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('domain', 'blog.parent.com') // composed + lower-cased
            ->assertJsonPath('type', 'subdomain')
            ->assertJsonPath('server_id', 1)
            ->assertJsonPath('sys_groupid', 5)
            ->assertJsonPath('active', true);

        $rows = $this->datalogRows('web_domain');
        $this->assertCount(1, $rows);
        $this->assertSame('i', $rows[0]->action);
        $this->assertSame('domain_id:'.$response->json('id'), $rows[0]->dbidx);

        $data = unserialize($rows[0]->data);
        $this->assertSame('blog.parent.com', $data['new']['domain']);
        $this->assertSame('subdomain', $data['new']['type']);
        $this->assertSame('5', $data['new']['sys_groupid']);
    }

    public function test_create_alias_uses_full_domain_as_is(): void
    {
        $parentId = $this->seedVhost(['domain' => 'parent.com']);

        $this->postJson('/api/v1/sites/web-child-domains', [
            'parent_domain_id' => $parentId,
            'domain' => 'my-alias.net',
            'type' => 'alias',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('domain', 'my-alias.net')
            ->assertJsonPath('type', 'alias');
    }

    public function test_create_validation_failures(): void
    {
        $parentId = $this->seedVhost(['domain' => 'parent.com']);

        // Proxy redirect with a path (legacy error_proxy_requires_url).
        $this->postJson('/api/v1/sites/web-child-domains', [
            'parent_domain_id' => $parentId,
            'domain' => 'blog',
            'type' => 'subdomain',
            'redirect_type' => 'proxy',
            'redirect_path' => '/local/path/',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['redirect_path']]);

        // redirect_path failing the legacy regex.
        $this->postJson('/api/v1/sites/web-child-domains', [
            'parent_domain_id' => $parentId,
            'domain' => 'blog',
            'type' => 'subdomain',
            'redirect_path' => 'not a url',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['redirect_path']]);

        // Nonexistent parent.
        $this->postJson('/api/v1/sites/web-child-domains', [
            'parent_domain_id' => 999,
            'domain' => 'blog',
            'type' => 'subdomain',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['parent_domain_id']]);

        // A child domain cannot be the parent (parent must be type vhost).
        $childId = $this->seedChild($parentId);
        $this->postJson('/api/v1/sites/web-child-domains', [
            'parent_domain_id' => $childId,
            'domain' => 'deep',
            'type' => 'subdomain',
        ], $this->authHeaders())
            ->assertStatus(422);
    }

    public function test_create_duplicate_domain_returns_409(): void
    {
        $parentId = $this->seedVhost(['domain' => 'parent.com']);
        $this->seedChild($parentId, ['domain' => 'blog.parent.com']);

        $this->postJson('/api/v1/sites/web-child-domains', [
            'parent_domain_id' => $parentId,
            'domain' => 'blog',
            'type' => 'subdomain',
        ], $this->authHeaders())
            ->assertStatus(409);
    }

    public function test_update_without_changes_writes_no_datalog(): void
    {
        $parentId = $this->seedVhost(['domain' => 'parent.com']);
        $childId = $this->seedChild($parentId, ['sys_groupid' => 5]);

        $this->putJson('/api/v1/sites/web-child-domains/'.$childId, ['active' => true], $this->authHeaders())
            ->assertOk();

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_update_rejects_type_flip(): void
    {
        $parentId = $this->seedVhost(['domain' => 'parent.com']);
        $childId = $this->seedChild($parentId);

        $this->putJson('/api/v1/sites/web-child-domains/'.$childId, ['type' => 'alias'], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['type']]);

        // Sending the current type is fine.
        $this->putJson('/api/v1/sites/web-child-domains/'.$childId, ['type' => 'subdomain'], $this->authHeaders())
            ->assertOk();
    }

    public function test_reparenting_recomposes_domain_and_touches_old_parent(): void
    {
        $oldParentId = $this->seedVhost(['domain' => 'old-parent.com', 'sys_groupid' => 5]);
        $newParentId = $this->seedVhost(['domain' => 'new-parent.com', 'sys_groupid' => 6]);
        $childId = $this->seedChild($oldParentId, ['domain' => 'blog.old-parent.com', 'sys_groupid' => 5]);

        $this->putJson('/api/v1/sites/web-child-domains/'.$childId, [
            'parent_domain_id' => $newParentId,
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('domain', 'blog.new-parent.com') // label recomposed
            ->assertJsonPath('parent_domain_id', $newParentId)
            ->assertJsonPath('sys_groupid', 6); // re-synced to the new parent's group

        // Child row updated + forced no-op u for the OLD parent vhost so
        // its config regenerates (legacy onAfterUpdate).
        $rows = $this->datalogRows('web_domain');
        $this->assertCount(2, $rows);
        $this->assertSame('u', $rows[0]->action);
        $this->assertSame('domain_id:'.$childId, $rows[0]->dbidx);
        $this->assertSame('u', $rows[1]->action);
        $this->assertSame('domain_id:'.$oldParentId, $rows[1]->dbidx);

        $touch = unserialize($rows[1]->data);
        $this->assertSame($touch['new'], $touch['old']); // forced, no change
        $this->assertSame('old-parent.com', $touch['new']['domain']);
    }

    public function test_delete_returns_204_with_datalog(): void
    {
        $parentId = $this->seedVhost(['domain' => 'parent.com']);
        $childId = $this->seedChild($parentId);

        $this->deleteJson('/api/v1/sites/web-child-domains/'.$childId, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('web_domain', ['domain_id' => $childId]);
        $rows = $this->datalogRows('web_domain');
        $this->assertCount(1, $rows);
        $this->assertSame('d', $rows[0]->action);

        // A vhost id 404s on the child resource.
        $this->deleteJson('/api/v1/sites/web-child-domains/'.$parentId, [], $this->authHeaders())
            ->assertStatus(404);
    }
}
