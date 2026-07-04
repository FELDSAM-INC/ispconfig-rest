<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\SitesApiTestCase;

class WebdavUserApiTest extends SitesApiTestCase
{
    protected function seedWebdavUser(int $parentId, array $overrides = []): int
    {
        return (int) DB::table('webdav_user')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 5,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'parent_domain_id' => $parentId,
            'username' => 'testclientdav',
            'username_prefix' => 'testclient',
            'password' => md5('testclientdav:webdav:oldpass'),
            'dir' => 'webdav',
            'active' => 'y',
        ], $overrides), 'webdav_user_id');
    }

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/sites/webdav-users')->assertStatus(401);
    }

    public function test_list_filters_and_bad_sort(): void
    {
        $parentA = $this->seedVhost();
        $parentB = $this->seedVhost();
        $this->seedWebdavUser($parentA, ['username' => 'testclientone']);
        $this->seedWebdavUser($parentB, ['username' => 'testclienttwo', 'active' => 'n']);

        $this->getJson('/api/v1/sites/webdav-users', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonMissingPath('data.0.password');

        $this->getJson('/api/v1/sites/webdav-users?parent_domain_id='.$parentA, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.username', 'one');

        $this->getJson('/api/v1/sites/webdav-users?active=false', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/sites/webdav-users?sort=hax', $this->authHeaders())
            ->assertStatus(400);
    }

    public function test_show_200_and_404(): void
    {
        $parentId = $this->seedVhost(['domain' => 'davsite.com']);
        $id = $this->seedWebdavUser($parentId);

        $this->getJson('/api/v1/sites/webdav-users/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('username', 'dav')
            ->assertJsonPath('username_prefix', 'testclient')
            ->assertJsonPath('dir', 'webdav')
            ->assertJsonPath('parent_domain', 'davsite.com')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('webdav_user_id');

        $this->getJson('/api/v1/sites/webdav-users/999', $this->authHeaders())
            ->assertStatus(404);
    }

    public function test_create_prefixes_username_and_stores_digest_hash(): void
    {
        $parentId = $this->seedVhost();

        $response = $this->postJson('/api/v1/sites/webdav-users', [
            'parent_domain_id' => $parentId,
            'username' => 'davuser',
            'password' => 'DavSecret1',
            'dir' => 'webdav',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('username', 'davuser')
            ->assertJsonPath('username_prefix', 'testclient')
            ->assertJsonPath('server_id', 1)
            ->assertJsonPath('sys_groupid', 5)
            ->assertJsonMissingPath('password');

        $id = (int) $response->json('id');
        $stored = DB::table('webdav_user')->where('webdav_user_id', $id)->value('password');

        // Byte-exact legacy digest: md5(full_username:dir:password).
        $this->assertSame(md5('testclientdavuser:webdav:DavSecret1'), $stored);

        $rows = $this->datalogRows('webdav_user');
        $this->assertCount(1, $rows);
        $this->assertSame('i', $rows[0]->action);
        $this->assertSame('webdav_user_id:'.$id, $rows[0]->dbidx);
        $this->assertStringNotContainsString('DavSecret1', $rows[0]->data);
        $data = unserialize($rows[0]->data);
        $this->assertSame('testclientdavuser', $data['new']['username']);
    }

    public function test_create_validation_failures(): void
    {
        $parentId = $this->seedVhost();

        $cases = [
            'missing dir' => [['parent_domain_id' => $parentId, 'username' => 'dav', 'password' => 'x'], 'dir'],
            'traversal dir' => [['parent_domain_id' => $parentId, 'username' => 'dav', 'password' => 'x', 'dir' => '../etc'], 'dir'],
            'slashdot dir' => [['parent_domain_id' => $parentId, 'username' => 'dav', 'password' => 'x', 'dir' => 'a/./b'], 'dir'],
            'bad username' => [['parent_domain_id' => $parentId, 'username' => 'bad user', 'password' => 'x', 'dir' => 'webdav'], 'username'],
            'unknown parent' => [['parent_domain_id' => 999, 'username' => 'dav', 'password' => 'x', 'dir' => 'webdav'], 'parent_domain_id'],
        ];

        foreach ($cases as $label => [$payload, $field]) {
            $response = $this->postJson('/api/v1/sites/webdav-users', $payload, $this->authHeaders());
            $response->assertStatus(422);
            $this->assertArrayHasKey($field, $response->json('errors'), "case: {$label}");
        }

        // Duplicate full username (table-wide).
        $this->seedWebdavUser($parentId, ['username' => 'testclientdav']);
        $this->postJson('/api/v1/sites/webdav-users', [
            'parent_domain_id' => $parentId,
            'username' => 'dav',
            'password' => 'x',
            'dir' => 'webdav',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['username']]);
    }

    public function test_update_keeps_username_and_dir_immutable_and_redigests_password(): void
    {
        $parentId = $this->seedVhost();
        $id = $this->seedWebdavUser($parentId);

        // username / dir immutable -> 422 (current values pass).
        $this->putJson('/api/v1/sites/webdav-users/'.$id, ['username' => 'other'], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['username']]);

        $this->putJson('/api/v1/sites/webdav-users/'.$id, ['dir' => 'elsewhere'], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['dir']]);

        $this->putJson('/api/v1/sites/webdav-users/'.$id, ['username' => 'dav', 'dir' => 'webdav'], $this->authHeaders())
            ->assertOk();

        // A changed password re-digests with the STORED username/dir.
        $this->putJson('/api/v1/sites/webdav-users/'.$id, ['password' => 'NewDav1'], $this->authHeaders())
            ->assertOk();

        $stored = DB::table('webdav_user')->where('webdav_user_id', $id)->value('password');
        $this->assertSame(md5('testclientdav:webdav:NewDav1'), $stored);

        DB::table('sys_datalog')->delete();

        $this->putJson('/api/v1/sites/webdav-users/'.$id, ['active' => true], $this->authHeaders())
            ->assertOk();
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_delete_returns_204_with_datalog(): void
    {
        $parentId = $this->seedVhost();
        $id = $this->seedWebdavUser($parentId);

        $this->deleteJson('/api/v1/sites/webdav-users/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('webdav_user', ['webdav_user_id' => $id]);
        $this->assertSame('d', $this->datalogRows('webdav_user')[0]->action);
    }
}
