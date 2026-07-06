<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\SitesApiTestCase;

class FtpUserApiTest extends SitesApiTestCase
{
    protected function seedFtpUser(int $parentId, array $overrides = []): int
    {
        return (int) DB::table('ftp_user')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 5,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'parent_domain_id' => $parentId,
            'username' => 'testclientjohn',
            'username_prefix' => 'testclient',
            'password' => '$6$rounds=5000$abcdefgh$hash',
            'quota_size' => -1,
            'active' => 'y',
            'uid' => 'web1',
            'gid' => 'client3',
            'dir' => '/var/www/clients/client3/web1',
        ], $overrides), 'ftp_user_id');
    }

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/sites/ftp-users')->assertStatus(401);
    }

    public function test_list_envelope_search_and_bad_sort(): void
    {
        $parentId = $this->seedVhost();
        $this->seedFtpUser($parentId, ['username' => 'testclientalpha']);
        $this->seedFtpUser($parentId, ['username' => 'testclientbeta']);

        $this->getJson('/api/v1/sites/ftp-users', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.username', 'alpha') // un-prefixed per contract
            ->assertJsonPath('data.0.username_full', 'testclientalpha')
            ->assertJsonMissingPath('data.0.password');

        $this->getJson('/api/v1/sites/ftp-users?search=beta', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/sites/ftp-users?sort=hax', $this->authHeaders())
            ->assertStatus(400);
    }

    public function test_list_filters_by_parent_domain_id(): void
    {
        $parentA = $this->seedVhost(['domain' => 'a.com']);
        $parentB = $this->seedVhost(['domain' => 'b.com']);
        $this->seedFtpUser($parentA, ['username' => 'testclientaaa']);
        $this->seedFtpUser($parentB, ['username' => 'testclientbbb']);

        $this->getJson('/api/v1/sites/ftp-users?parent_domain_id='.$parentA, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.username_full', 'testclientaaa');

        $this->getJson('/api/v1/sites/ftp-users?parent_domain_id=abc', $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 400);
    }

    public function test_show_200_and_404(): void
    {
        $parentId = $this->seedVhost(['domain' => 'site.com']);
        $id = $this->seedFtpUser($parentId);

        $this->getJson('/api/v1/sites/ftp-users/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('username', 'john')
            ->assertJsonPath('username_prefix', 'testclient')
            ->assertJsonPath('username_full', 'testclientjohn')
            ->assertJsonPath('parent_domain', 'site.com')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('ftp_user_id');

        $this->getJson('/api/v1/sites/ftp-users/999', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_create_prefixes_username_derives_fields_and_hashes_password(): void
    {
        $parentId = $this->seedVhost();

        $response = $this->postJson('/api/v1/sites/ftp-users', [
            'parent_domain_id' => $parentId,
            'username' => 'john',
            'password' => 'S3cretPass!',
            'quota_size' => 512,
        ], $this->authHeaders())
            ->assertStatus(201)
            // Prefix resolved from ftpuser_prefix=[CLIENTNAME] -> 'testclient'.
            ->assertJsonPath('username', 'john')
            ->assertJsonPath('username_prefix', 'testclient')
            ->assertJsonPath('username_full', 'testclientjohn')
            // Derived from the parent domain, never from input.
            ->assertJsonPath('server_id', 1)
            ->assertJsonPath('dir', "/var/www/clients/client3/web{$parentId}")
            ->assertJsonPath('uid', "web{$parentId}")
            ->assertJsonPath('gid', 'client3')
            ->assertJsonPath('sys_groupid', 5)
            ->assertJsonPath('quota_size', 512)
            ->assertJsonMissingPath('password');

        $id = (int) $response->json('id');

        // CRYPT (sha512-crypt) hash stored — never plaintext.
        $stored = DB::table('ftp_user')->where('ftp_user_id', $id)->value('password');
        $this->assertStringStartsWith('$6$rounds=5000$', $stored);
        $this->assertTrue(password_verify('S3cretPass!', $stored) || hash_equals($stored, crypt('S3cretPass!', $stored)));

        // Datalog i with the full derived record; plaintext nowhere.
        $rows = $this->datalogRows('ftp_user');
        $this->assertCount(1, $rows);
        $this->assertSame('i', $rows[0]->action);
        $this->assertSame('ftp_user_id:'.$id, $rows[0]->dbidx);

        $data = unserialize($rows[0]->data);
        $this->assertSame('testclientjohn', $data['new']['username']);
        $this->assertSame('testclient', $data['new']['username_prefix']);
        $this->assertSame("web{$parentId}", $data['new']['uid']);
        $this->assertStringStartsWith('$6$', $data['new']['password']);
        $this->assertStringNotContainsString('S3cretPass!', $rows[0]->data);
    }

    public function test_create_validation_failures(): void
    {
        $parentId = $this->seedVhost();

        $cases = [
            'missing parent' => [['username' => 'john', 'password' => 'x'], 'parent_domain_id'],
            'unknown parent' => [['parent_domain_id' => 999, 'username' => 'john', 'password' => 'x'], 'parent_domain_id'],
            'bad username chars' => [['parent_domain_id' => $parentId, 'username' => 'joh n!', 'password' => 'x'], 'username'],
            'missing password' => [['parent_domain_id' => $parentId, 'username' => 'john'], 'password'],
            'bad quota' => [['parent_domain_id' => $parentId, 'username' => 'john', 'password' => 'x', 'quota_size' => -2], 'quota_size'],
        ];

        foreach ($cases as $label => [$payload, $field]) {
            $response = $this->postJson('/api/v1/sites/ftp-users', $payload, $this->authHeaders());
            $response->assertStatus(422);
            $this->assertArrayHasKey($field, $response->json('errors'), "case: {$label}");
        }

        $this->assertSame(0, DB::table('ftp_user')->count());
    }

    public function test_create_rejects_duplicate_full_username(): void
    {
        $parentId = $this->seedVhost();
        $this->seedFtpUser($parentId, ['username' => 'testclientjohn']);

        $this->postJson('/api/v1/sites/ftp-users', [
            'parent_domain_id' => $parentId,
            'username' => 'john',
            'password' => 'x',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['username']]);
    }

    public function test_update_keeps_prefix_rehashes_password_and_suppresses_no_change(): void
    {
        $parentId = $this->seedVhost();
        $id = $this->seedFtpUser($parentId);
        $oldHash = DB::table('ftp_user')->where('ftp_user_id', $id)->value('password');

        // Rename: new name keeps the ORIGINAL prefix.
        $this->putJson('/api/v1/sites/ftp-users/'.$id, ['username' => 'jane'], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('username', 'jane')
            ->assertJsonPath('username_full', 'testclientjane');

        // Password change re-CRYPTs.
        $this->putJson('/api/v1/sites/ftp-users/'.$id, ['password' => 'NewPass123'], $this->authHeaders())
            ->assertOk();
        $newHash = DB::table('ftp_user')->where('ftp_user_id', $id)->value('password');
        $this->assertNotSame($oldHash, $newHash);
        $this->assertStringStartsWith('$6$rounds=5000$', $newHash);

        DB::table('sys_datalog')->delete();

        // No-change PUT writes no datalog row.
        $this->putJson('/api/v1/sites/ftp-users/'.$id, ['username' => 'jane', 'active' => true], $this->authHeaders())
            ->assertOk();
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_update_reparenting_rederives_fixed_fields(): void
    {
        $oldParent = $this->seedVhost(['sys_groupid' => 5]);
        $newParent = $this->seedVhost(['sys_groupid' => 6, 'server_id' => 2]);
        $id = $this->seedFtpUser($oldParent);

        $this->putJson('/api/v1/sites/ftp-users/'.$id, ['parent_domain_id' => $newParent], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('server_id', 2)
            ->assertJsonPath('dir', "/var/www/clients/client3/web{$newParent}")
            ->assertJsonPath('uid', "web{$newParent}")
            ->assertJsonPath('sys_groupid', 6);
    }

    public function test_delete_returns_204_with_datalog(): void
    {
        $parentId = $this->seedVhost();
        $id = $this->seedFtpUser($parentId);

        $this->deleteJson('/api/v1/sites/ftp-users/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('ftp_user', ['ftp_user_id' => $id]);
        $rows = $this->datalogRows('ftp_user');
        $this->assertCount(1, $rows);
        $this->assertSame('d', $rows[0]->action);

        $this->deleteJson('/api/v1/sites/ftp-users/'.$id, [], $this->authHeaders())
            ->assertStatus(404);
    }
}
