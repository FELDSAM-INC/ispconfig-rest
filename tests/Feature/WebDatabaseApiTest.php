<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\SitesApiTestCase;

class WebDatabaseApiTest extends SitesApiTestCase
{
    protected function seedDbUser(array $overrides = []): int
    {
        return (int) DB::table('web_database_user')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 5,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 0,
            'database_user' => 'c3app',
            'database_user_prefix' => 'c3',
            'database_password' => '*HASH',
        ], $overrides), 'database_user_id');
    }

    protected function seedDatabase(int $parentId, int $userId, array $overrides = []): int
    {
        return (int) DB::table('web_database')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 5,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'parent_domain_id' => $parentId,
            'type' => 'mysql',
            'database_name' => 'c3mydb',
            'database_name_prefix' => 'c3',
            'database_user_id' => $userId,
            'database_ro_user_id' => 0,
            'database_charset' => '',
            'remote_access' => 'n',
            'active' => 'y',
        ], $overrides), 'database_id');
    }

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/sites/databases')->assertStatus(401);
    }

    public function test_list_envelope_search_and_bad_sort(): void
    {
        $parentId = $this->seedVhost();
        $userId = $this->seedDbUser();
        $this->seedDatabase($parentId, $userId, ['database_name' => 'c3alpha']);
        $this->seedDatabase($parentId, $userId, ['database_name' => 'c3beta']);

        $this->getJson('/api/v1/sites/databases', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.database_name', 'alpha')
            ->assertJsonPath('data.0.database_name_full', 'c3alpha');

        $this->getJson('/api/v1/sites/databases?search=beta', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/sites/databases?sort=hax', $this->authHeaders())
            ->assertStatus(400);
    }

    public function test_list_filters_by_parent_domain_id(): void
    {
        $parentA = $this->seedVhost(['domain' => 'a.com']);
        $parentB = $this->seedVhost(['domain' => 'b.com']);
        $userId = $this->seedDbUser();
        $this->seedDatabase($parentA, $userId, ['database_name' => 'c3adb']);
        $this->seedDatabase($parentB, $userId, ['database_name' => 'c3bdb']);

        $this->getJson('/api/v1/sites/databases?parent_domain_id='.$parentA, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.database_name_full', 'c3adb');

        $this->getJson('/api/v1/sites/databases?parent_domain_id=abc', $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 400);
    }

    public function test_show_200_and_404(): void
    {
        $parentId = $this->seedVhost(['domain' => 'site.com']);
        $userId = $this->seedDbUser();
        $id = $this->seedDatabase($parentId, $userId);

        $this->getJson('/api/v1/sites/databases/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('database_name', 'mydb')
            ->assertJsonPath('database_name_prefix', 'c3')
            ->assertJsonPath('parent_domain', 'site.com')
            ->assertJsonMissingPath('database_id');

        $this->getJson('/api/v1/sites/databases/999', $this->authHeaders())
            ->assertStatus(404);
    }

    public function test_create_prefixes_name_syncs_group_and_touches_linked_user(): void
    {
        $parentId = $this->seedVhost(['sys_groupid' => 5, 'backup_copies' => 7]);
        $userId = $this->seedDbUser(['sys_groupid' => 5]);

        $response = $this->postJson('/api/v1/sites/databases', [
            'server_id' => 1,
            'parent_domain_id' => $parentId,
            'database_name' => 'mydb',
            'database_user_id' => $userId,
        ], $this->authHeaders())
            ->assertStatus(201)
            // dbname_prefix=c[CLIENTID] with the site's client (3) -> 'c3'.
            ->assertJsonPath('database_name', 'mydb')
            ->assertJsonPath('database_name_prefix', 'c3')
            ->assertJsonPath('database_name_full', 'c3mydb')
            ->assertJsonPath('sys_groupid', 5)
            // Legacy quirk: backup_copies forced from the parent web domain.
            ->assertJsonPath('backup_copies', 7)
            ->assertJsonPath('remote_access', false);

        $id = (int) $response->json('id');

        // Datalog i for the database + the forced datalog u touching the
        // linked user's server_id (grant creation trigger). The user table
        // row itself is untouched (legacy writes only the datalog).
        $dbRows = $this->datalogRows('web_database');
        $this->assertCount(1, $dbRows);
        $this->assertSame('i', $dbRows[0]->action);
        $this->assertSame('database_id:'.$id, $dbRows[0]->dbidx);

        $userRows = $this->datalogRows('web_database_user');
        $this->assertCount(1, $userRows);
        $this->assertSame('u', $userRows[0]->action);
        $this->assertSame('database_user_id:'.$userId, $userRows[0]->dbidx);
        $touch = unserialize($userRows[0]->data);
        $this->assertSame('0', $touch['old']['server_id']);
        $this->assertSame('1', $touch['new']['server_id']);
        $this->assertSame(0, (int) DB::table('web_database_user')->where('database_user_id', $userId)->value('server_id'));
    }

    public function test_create_remote_access_autofix_for_cross_server_database(): void
    {
        // Site on server 1 (ip 10.0.0.1), database on server 2.
        $parentId = $this->seedVhost(['server_id' => 1]);
        $userId = $this->seedDbUser();

        $response = $this->postJson('/api/v1/sites/databases', [
            'server_id' => 2,
            'parent_domain_id' => $parentId,
            'database_name' => 'remote',
            'database_user_id' => $userId,
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('remote_access', true)
            ->assertJsonPath('remote_ips', '10.0.0.1');

        $this->assertDatabaseHas('web_database', [
            'database_id' => $response->json('id'),
            'remote_access' => 'y',
            'remote_ips' => '10.0.0.1',
        ]);
    }

    public function test_create_validation_failures(): void
    {
        $parentId = $this->seedVhost();
        $userId = $this->seedDbUser();

        $valid = [
            'server_id' => 1,
            'parent_domain_id' => $parentId,
            'database_name' => 'mydb',
            'database_user_id' => $userId,
        ];

        $cases = [
            'missing parent' => [array_diff_key($valid, ['parent_domain_id' => 1]), 'parent_domain_id'],
            'zero parent (site mandatory)' => [array_merge($valid, ['parent_domain_id' => 0]), 'parent_domain_id'],
            'missing user' => [array_diff_key($valid, ['database_user_id' => 1]), 'database_user_id'],
            'unknown user' => [array_merge($valid, ['database_user_id' => 999]), 'database_user_id'],
            'bad name chars' => [array_merge($valid, ['database_name' => 'my-db']), 'database_name'],
            'bad charset' => [array_merge($valid, ['database_charset' => 'ascii']), 'database_charset'],
            'bad type' => [array_merge($valid, ['type' => 'mongo']), 'type'],
        ];

        foreach ($cases as $label => [$payload, $field]) {
            $response = $this->postJson('/api/v1/sites/databases', $payload, $this->authHeaders());
            $response->assertStatus(422);
            $this->assertArrayHasKey($field, $response->json('errors'), "case: {$label}");
        }

        $this->assertSame(0, DB::table('web_database')->count());
    }

    public function test_create_enforces_per_server_uniqueness(): void
    {
        $parentId = $this->seedVhost();
        $userId = $this->seedDbUser();

        // Per-server duplicate: same prefixed name on server 1 -> 422 …
        $this->seedDatabase($parentId, $userId, ['database_name' => 'c3mydb', 'server_id' => 1]);

        $this->postJson('/api/v1/sites/databases', [
            'server_id' => 1,
            'parent_domain_id' => $parentId,
            'database_name' => 'mydb',
            'database_user_id' => $userId,
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['database_name']]);

        // … but the same name on ANOTHER server is fine (legacy per-server check).
        $this->postJson('/api/v1/sites/databases', [
            'server_id' => 2,
            'parent_domain_id' => $parentId,
            'database_name' => 'mydb',
            'database_user_id' => $userId,
        ], $this->authHeaders())->assertStatus(201);
    }

    public function test_create_rejects_user_of_a_different_client_group(): void
    {
        $parentId = $this->seedVhost(['sys_groupid' => 5]);
        $foreignUserId = $this->seedDbUser(['sys_groupid' => 6]);

        $this->postJson('/api/v1/sites/databases', [
            'server_id' => 1,
            'parent_domain_id' => $parentId,
            'database_name' => 'mydb',
            'database_user_id' => $foreignUserId,
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['database_user_id']]);
    }

    public function test_postgresql_user_reuse_is_rejected_per_server(): void
    {
        $parentId = $this->seedVhost();
        $userId = $this->seedDbUser();
        $this->seedDatabase($parentId, $userId, [
            'database_name' => 'c3pgone', 'type' => 'postgresql', 'server_id' => 1,
        ]);

        $this->postJson('/api/v1/sites/databases', [
            'server_id' => 1,
            'parent_domain_id' => $parentId,
            'database_name' => 'pgtwo',
            'type' => 'postgresql',
            'database_user_id' => $userId,
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['database_user_id']]);
    }

    public function test_update_enforces_immutable_fields_and_requires_user(): void
    {
        $parentId = $this->seedVhost();
        $userId = $this->seedDbUser();
        $id = $this->seedDatabase($parentId, $userId);

        // database_name immutable.
        $this->putJson('/api/v1/sites/databases/'.$id, [
            'database_name' => 'renamed', 'database_user_id' => $userId,
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['database_name']]);

        // database_charset immutable.
        $this->putJson('/api/v1/sites/databases/'.$id, [
            'database_charset' => 'utf8mb4', 'database_user_id' => $userId,
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['database_charset']]);

        // server_id immutable.
        $this->putJson('/api/v1/sites/databases/'.$id, [
            'server_id' => 2, 'database_user_id' => $userId,
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['server_id']]);

        // database_user_id required on update (legacy database_user_missing).
        $this->putJson('/api/v1/sites/databases/'.$id, ['active' => false], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['database_user_id']]);

        // Sending current values is accepted; the linked user is touched
        // again (forced grant re-sync) even without changes.
        $this->putJson('/api/v1/sites/databases/'.$id, [
            'database_name' => 'mydb',
            'database_charset' => '',
            'server_id' => 1,
            'database_user_id' => $userId,
            'active' => false,
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('active', false);

        $this->assertSame(1, DB::table('sys_datalog')
            ->where('dbtable', 'web_database_user')->where('action', 'u')->count());
    }

    public function test_delete_returns_204_with_datalog(): void
    {
        $parentId = $this->seedVhost();
        $userId = $this->seedDbUser();
        $id = $this->seedDatabase($parentId, $userId);

        $this->deleteJson('/api/v1/sites/databases/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('web_database', ['database_id' => $id]);
        $this->assertSame('d', $this->datalogRows('web_database')[0]->action);

        // The linked user record survives a database delete.
        $this->assertDatabaseHas('web_database_user', ['database_user_id' => $userId]);
    }
}
