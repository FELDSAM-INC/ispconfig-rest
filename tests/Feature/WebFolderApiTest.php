<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\SitesApiTestCase;

/**
 * Web folders AND their users (US6) — the two resources are exercised
 * together because folder users depend on folders.
 */
class WebFolderApiTest extends SitesApiTestCase
{
    protected function seedFolder(int $parentId, array $overrides = []): int
    {
        return (int) DB::table('web_folder')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 5,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'parent_domain_id' => $parentId,
            'path' => '/protected',
            'active' => 'y',
        ], $overrides), 'web_folder_id');
    }

    protected function seedFolderUser(int $folderId, array $overrides = []): int
    {
        return (int) DB::table('web_folder_user')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 5,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'web_folder_id' => $folderId,
            'username' => 'folderuser',
            'password' => '$6$rounds=5000$abcdefgh$hash',
            'active' => 'y',
        ], $overrides), 'web_folder_user_id');
    }

    // ------------------------------------------------------------------
    // Folders
    // ------------------------------------------------------------------

    public function test_folder_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/sites/web-folders')->assertStatus(401);
        $this->getJson('/api/v1/sites/web-folder-users')->assertStatus(401);
    }

    public function test_folder_list_filters_and_bad_sort(): void
    {
        $parentA = $this->seedVhost();
        $parentB = $this->seedVhost();
        $this->seedFolder($parentA, ['path' => '/a']);
        $this->seedFolder($parentB, ['path' => '/b', 'active' => 'n']);

        $this->getJson('/api/v1/sites/web-folders', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/sites/web-folders?parent_domain_id='.$parentA, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.path', '/a');

        $this->getJson('/api/v1/sites/web-folders?active=false', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.path', '/b');

        $this->getJson('/api/v1/sites/web-folders?sort=hax', $this->authHeaders())
            ->assertStatus(400);
    }

    public function test_folder_show_200_and_404(): void
    {
        $parentId = $this->seedVhost(['domain' => 'foldersite.com']);
        $id = $this->seedFolder($parentId);

        $this->getJson('/api/v1/sites/web-folders/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('path', '/protected')
            ->assertJsonPath('parent_domain', 'foldersite.com')
            ->assertJsonMissingPath('web_folder_id');

        $this->getJson('/api/v1/sites/web-folders/999', $this->authHeaders())
            ->assertStatus(404);
    }

    public function test_folder_create_derives_from_parent_and_rejects_duplicates(): void
    {
        $parentId = $this->seedVhost();

        $response = $this->postJson('/api/v1/sites/web-folders', [
            'parent_domain_id' => $parentId,
            'path' => '/members',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('path', '/members')
            ->assertJsonPath('server_id', 1)
            ->assertJsonPath('sys_groupid', 5);

        $rows = $this->datalogRows('web_folder');
        $this->assertCount(1, $rows);
        $this->assertSame('i', $rows[0]->action);
        $this->assertSame('web_folder_id:'.$response->json('id'), $rows[0]->dbidx);

        // Duplicate (parent, path) -> 422 (legacy already-protected check).
        $this->postJson('/api/v1/sites/web-folders', [
            'parent_domain_id' => $parentId,
            'path' => '/members',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['path']]);

        // Bad path chars -> 422.
        $this->postJson('/api/v1/sites/web-folders', [
            'parent_domain_id' => $parentId,
            'path' => '/spaced path!',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['path']]);
    }

    public function test_folder_update_allows_only_active(): void
    {
        $parentId = $this->seedVhost();
        $id = $this->seedFolder($parentId, ['path' => '/locked']);

        // Attempting to change the path -> 422 (contract immutability).
        $this->putJson('/api/v1/sites/web-folders/'.$id, ['path' => '/other'], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['path']]);

        // active flip works; current path may be re-sent.
        $this->putJson('/api/v1/sites/web-folders/'.$id, ['path' => '/locked', 'active' => false], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('active', false)
            ->assertJsonPath('path', '/locked');

        DB::table('sys_datalog')->delete();

        // No-change PUT writes no datalog row.
        $this->putJson('/api/v1/sites/web-folders/'.$id, ['active' => false], $this->authHeaders())
            ->assertOk();
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_folder_delete_cascades_its_users(): void
    {
        $parentId = $this->seedVhost();
        $folderId = $this->seedFolder($parentId);
        $userA = $this->seedFolderUser($folderId, ['username' => 'a']);
        $userB = $this->seedFolderUser($folderId, ['username' => 'b']);

        $this->deleteJson('/api/v1/sites/web-folders/'.$folderId, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('web_folder', ['web_folder_id' => $folderId]);
        $this->assertDatabaseMissing('web_folder_user', ['web_folder_user_id' => $userA]);
        $this->assertDatabaseMissing('web_folder_user', ['web_folder_user_id' => $userB]);

        // Users datalogged BEFORE the folder, everything action d.
        $all = DB::table('sys_datalog')->orderBy('datalog_id')->get();
        $this->assertCount(3, $all);
        $this->assertSame('web_folder_user', $all[0]->dbtable);
        $this->assertSame('web_folder_user', $all[1]->dbtable);
        $this->assertSame('web_folder', $all[2]->dbtable);
        $this->assertSame(['d'], array_values(array_unique($all->pluck('action')->all())));
    }

    // ------------------------------------------------------------------
    // Folder users
    // ------------------------------------------------------------------

    public function test_folder_user_list_filters(): void
    {
        $parentId = $this->seedVhost();
        $folderA = $this->seedFolder($parentId, ['path' => '/a']);
        $folderB = $this->seedFolder($parentId, ['path' => '/b']);
        $this->seedFolderUser($folderA, ['username' => 'usera']);
        $this->seedFolderUser($folderB, ['username' => 'userb', 'active' => 'n']);

        $this->getJson('/api/v1/sites/web-folder-users?web_folder_id='.$folderA, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.username', 'usera')
            ->assertJsonPath('data.0.web_folder_path', '/a')
            ->assertJsonMissingPath('data.0.password');

        $this->getJson('/api/v1/sites/web-folder-users?active=false', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.username', 'userb');
    }

    public function test_folder_user_create_hashes_password_and_derives_from_folder(): void
    {
        $parentId = $this->seedVhost();
        $folderId = $this->seedFolder($parentId, ['server_id' => 1, 'sys_groupid' => 5]);

        $response = $this->postJson('/api/v1/sites/web-folder-users', [
            'web_folder_id' => $folderId,
            'username' => 'member',
            'password' => 'FolderSecret1',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('username', 'member')
            ->assertJsonPath('server_id', 1)
            ->assertJsonPath('sys_groupid', 5)
            ->assertJsonMissingPath('password');

        $id = (int) $response->json('id');
        $stored = DB::table('web_folder_user')->where('web_folder_user_id', $id)->value('password');
        $this->assertStringStartsWith('$6$rounds=5000$', $stored);
        $this->assertTrue(hash_equals($stored, crypt('FolderSecret1', $stored)));

        $rows = $this->datalogRows('web_folder_user');
        $this->assertCount(1, $rows);
        $this->assertSame('i', $rows[0]->action);
        $this->assertSame('web_folder_user_id:'.$id, $rows[0]->dbidx);
        $this->assertStringNotContainsString('FolderSecret1', $rows[0]->data);

        // Duplicate (folder, username) -> 422.
        $this->postJson('/api/v1/sites/web-folder-users', [
            'web_folder_id' => $folderId,
            'username' => 'member',
            'password' => 'x',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['username']]);

        // Unknown folder -> 422.
        $this->postJson('/api/v1/sites/web-folder-users', [
            'web_folder_id' => 999,
            'username' => 'member2',
            'password' => 'x',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['web_folder_id']]);
    }

    public function test_folder_user_update_allows_only_password_and_active(): void
    {
        $parentId = $this->seedVhost();
        $folderId = $this->seedFolder($parentId);
        $otherFolderId = $this->seedFolder($parentId, ['path' => '/other']);
        $id = $this->seedFolderUser($folderId, ['username' => 'member']);
        $oldHash = DB::table('web_folder_user')->where('web_folder_user_id', $id)->value('password');

        // username / web_folder_id immutable -> 422.
        $this->putJson('/api/v1/sites/web-folder-users/'.$id, ['username' => 'renamed'], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['username']]);

        $this->putJson('/api/v1/sites/web-folder-users/'.$id, ['web_folder_id' => $otherFolderId], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['web_folder_id']]);

        // password + active updatable.
        $this->putJson('/api/v1/sites/web-folder-users/'.$id, [
            'password' => 'NewSecret9',
            'active' => false,
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('active', false);

        $newHash = DB::table('web_folder_user')->where('web_folder_user_id', $id)->value('password');
        $this->assertNotSame($oldHash, $newHash);
        $this->assertTrue(hash_equals($newHash, crypt('NewSecret9', $newHash)));

        DB::table('sys_datalog')->delete();

        $this->putJson('/api/v1/sites/web-folder-users/'.$id, ['active' => false], $this->authHeaders())
            ->assertOk();
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_folder_user_delete_returns_204_with_datalog(): void
    {
        $parentId = $this->seedVhost();
        $folderId = $this->seedFolder($parentId);
        $id = $this->seedFolderUser($folderId);

        $this->deleteJson('/api/v1/sites/web-folder-users/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('web_folder_user', ['web_folder_user_id' => $id]);
        $this->assertSame('d', $this->datalogRows('web_folder_user')[0]->action);
    }
}
