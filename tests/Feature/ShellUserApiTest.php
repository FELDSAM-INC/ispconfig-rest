<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\SitesApiTestCase;

class ShellUserApiTest extends SitesApiTestCase
{
    protected function seedShellUser(int $parentId, array $overrides = []): int
    {
        return (int) DB::table('shell_user')->insertGetId(array_merge([
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
            'puser' => 'web1',
            'pgroup' => 'client3',
            'shell' => '/bin/bash',
            'dir' => '/var/www/clients/client3/web1',
            'chroot' => 'no',
        ], $overrides), 'shell_user_id');
    }

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/sites/shell-users')->assertStatus(401);
    }

    public function test_list_envelope_and_bad_sort(): void
    {
        $parentId = $this->seedVhost();
        $this->seedShellUser($parentId);

        $this->getJson('/api/v1/sites/shell-users', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.username', 'john')
            ->assertJsonPath('data.0.username_full', 'testclientjohn')
            ->assertJsonMissingPath('data.0.password');

        $this->getJson('/api/v1/sites/shell-users?sort=hax', $this->authHeaders())
            ->assertStatus(400);
    }

    public function test_list_filters_by_parent_domain_id(): void
    {
        $parentA = $this->seedVhost(['domain' => 'a.com']);
        $parentB = $this->seedVhost(['domain' => 'b.com']);
        $this->seedShellUser($parentA, ['username' => 'testclientaaa']);
        $this->seedShellUser($parentB, ['username' => 'testclientbbb']);

        $this->getJson('/api/v1/sites/shell-users?parent_domain_id='.$parentA, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.username_full', 'testclientaaa');

        $this->getJson('/api/v1/sites/shell-users?parent_domain_id=abc', $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 400);
    }

    public function test_show_200_and_404(): void
    {
        $parentId = $this->seedVhost();
        $id = $this->seedShellUser($parentId);

        $this->getJson('/api/v1/sites/shell-users/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('puser', 'web1')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('shell_user_id');

        $this->getJson('/api/v1/sites/shell-users/999', $this->authHeaders())
            ->assertStatus(404);
    }

    public function test_create_prefixes_username_derives_fields_and_hashes_password(): void
    {
        $parentId = $this->seedVhost();

        $response = $this->postJson('/api/v1/sites/shell-users', [
            'parent_domain_id' => $parentId,
            'username' => 'deploy',
            'password' => 'Sup3rSecret',
            'chroot' => 'jailkit',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('username', 'deploy')
            ->assertJsonPath('username_prefix', 'testclient')
            ->assertJsonPath('username_full', 'testclientdeploy')
            ->assertJsonPath('server_id', 1)
            ->assertJsonPath('dir', "/var/www/clients/client3/web{$parentId}")
            ->assertJsonPath('puser', "web{$parentId}")
            ->assertJsonPath('pgroup', 'client3')
            ->assertJsonPath('sys_groupid', 5)
            ->assertJsonPath('chroot', 'jailkit')
            ->assertJsonPath('shell', '/bin/bash')
            ->assertJsonMissingPath('password');

        $id = (int) $response->json('id');
        $stored = DB::table('shell_user')->where('shell_user_id', $id)->value('password');
        $this->assertStringStartsWith('$6$rounds=5000$', $stored);
        $this->assertTrue(hash_equals($stored, crypt('Sup3rSecret', $stored)));

        $rows = $this->datalogRows('shell_user');
        $this->assertCount(1, $rows);
        $this->assertSame('i', $rows[0]->action);
        $data = unserialize($rows[0]->data);
        $this->assertSame('testclientdeploy', $data['new']['username']);
        $this->assertStringNotContainsString('Sup3rSecret', $rows[0]->data);
    }

    public function test_create_rejects_blacklisted_and_overlong_usernames(): void
    {
        $parentId = $this->seedVhost();

        // Blacklist (shelluser_blacklist file, e.g. root/mysql).
        foreach (['root', 'mysql', 'MySQL'] as $bad) {
            $this->postJson('/api/v1/sites/shell-users', [
                'parent_domain_id' => $parentId,
                'username' => $bad,
                'password' => 'x',
            ], $this->authHeaders())
                ->assertStatus(422)
                ->assertJsonStructure(['errors' => ['username']]);
        }

        // Prefixed name over 32 chars ('testclient' + 25 chars = 35).
        $this->postJson('/api/v1/sites/shell-users', [
            'parent_domain_id' => $parentId,
            'username' => str_repeat('a', 25),
            'password' => 'x',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['username']]);
    }

    public function test_create_rejects_duplicate_full_username(): void
    {
        $parentId = $this->seedVhost();
        $this->seedShellUser($parentId, ['username' => 'testclientjohn']);

        $this->postJson('/api/v1/sites/shell-users', [
            'parent_domain_id' => $parentId,
            'username' => 'john',
            'password' => 'x',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['username']]);
    }

    public function test_ssh_authentication_mode_clears_the_other_credential(): void
    {
        $parentId = $this->seedVhost();

        // password mode: ssh_rsa is discarded.
        $this->setSshAuthenticationMode('password');
        $response = $this->postJson('/api/v1/sites/shell-users', [
            'parent_domain_id' => $parentId,
            'username' => 'pwuser',
            'password' => 'Secret1',
            'ssh_rsa' => 'ssh-rsa AAAAB3Nza...',
        ], $this->authHeaders())->assertStatus(201);

        $row = DB::table('shell_user')->where('shell_user_id', $response->json('id'))->first();
        $this->assertNull($row->ssh_rsa);
        $this->assertStringStartsWith('$6$', $row->password);

        // key mode: the submitted password is never stored.
        $this->setSshAuthenticationMode('key');
        $response = $this->postJson('/api/v1/sites/shell-users', [
            'parent_domain_id' => $parentId,
            'username' => 'keyuser',
            'password' => 'IgnoredSecret',
            'ssh_rsa' => 'ssh-rsa AAAAB3Nza...',
        ], $this->authHeaders())->assertStatus(201);

        $row = DB::table('shell_user')->where('shell_user_id', $response->json('id'))->first();
        $this->assertSame('ssh-rsa AAAAB3Nza...', $row->ssh_rsa);
        $this->assertTrue($row->password === null || $row->password === '');
    }

    public function test_update_keeps_prefix_and_suppresses_no_change(): void
    {
        $parentId = $this->seedVhost();
        $id = $this->seedShellUser($parentId);

        $this->putJson('/api/v1/sites/shell-users/'.$id, ['username' => 'jane'], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('username_full', 'testclientjane');

        DB::table('sys_datalog')->delete();

        $this->putJson('/api/v1/sites/shell-users/'.$id, ['username' => 'jane', 'active' => true], $this->authHeaders())
            ->assertOk();
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_update_reparenting_rederives_fixed_fields(): void
    {
        $oldParent = $this->seedVhost(['sys_groupid' => 5]);
        $newParent = $this->seedVhost(['sys_groupid' => 6, 'server_id' => 2]);
        $id = $this->seedShellUser($oldParent);

        $this->putJson('/api/v1/sites/shell-users/'.$id, ['parent_domain_id' => $newParent], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('server_id', 2)
            ->assertJsonPath('puser', "web{$newParent}")
            ->assertJsonPath('pgroup', 'client3')
            ->assertJsonPath('sys_groupid', 6);
    }

    public function test_delete_returns_204_with_datalog(): void
    {
        $parentId = $this->seedVhost();
        $id = $this->seedShellUser($parentId);

        $this->deleteJson('/api/v1/sites/shell-users/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('shell_user', ['shell_user_id' => $id]);
        $this->assertSame('d', $this->datalogRows('shell_user')[0]->action);
    }
}
