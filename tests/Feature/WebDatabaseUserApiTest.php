<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SitesApiTestCase;

class WebDatabaseUserApiTest extends SitesApiTestCase
{
    protected function seedDatabaseUser(array $overrides = []): int
    {
        return (int) DB::table('web_database_user')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 5,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 0,
            'database_user' => 'c0app',
            'database_user_prefix' => 'c0',
            'database_password' => '*OLDHASH',
            'database_password_sha2' => '$A$005$old',
            'database_password_postgres' => 'SCRAM-SHA-256$4096:old',
        ], $overrides), 'database_user_id');
    }

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/sites/database-users')->assertStatus(401);
    }

    public function test_list_envelope_search_and_bad_sort(): void
    {
        $this->seedDatabaseUser(['database_user' => 'c0alpha']);
        $this->seedDatabaseUser(['database_user' => 'c0beta']);

        $this->getJson('/api/v1/sites/database-users', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.database_user', 'alpha')
            ->assertJsonMissingPath('data.0.database_password')
            ->assertJsonMissingPath('data.0.database_password_sha2')
            ->assertJsonMissingPath('data.0.database_password_postgres');

        $this->getJson('/api/v1/sites/database-users?search=beta', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/sites/database-users?sort=hax', $this->authHeaders())
            ->assertStatus(400);
    }

    public function test_list_filters_by_owning_client(): void
    {
        // SitesApiTestCase seeds sys_group client 3 -> groupid 5, client 4
        // -> groupid 6.
        $this->seedDatabaseUser(['database_user' => 'c0client3', 'sys_groupid' => 5]);
        $this->seedDatabaseUser(['database_user' => 'c0client4', 'sys_groupid' => 6]);

        $this->getJson('/api/v1/sites/database-users?client_id=3', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.database_user', 'client3');

        $this->getJson('/api/v1/sites/database-users?client_id=999', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/v1/sites/database-users?client_id=abc', $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 400);
    }

    public function test_show_200_and_404(): void
    {
        $id = $this->seedDatabaseUser();

        $this->getJson('/api/v1/sites/database-users/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('database_user', 'app')
            ->assertJsonPath('database_user_prefix', 'c0')
            ->assertJsonPath('server_id', 0)
            ->assertJsonMissingPath('database_password');

        $this->getJson('/api/v1/sites/database-users/999', $this->authHeaders())
            ->assertStatus(404);
    }

    public function test_create_prefixes_forces_server_zero_and_stores_hash_trio(): void
    {
        $response = $this->postJson('/api/v1/sites/database-users', [
            'database_user' => 'appuser',
            'database_password' => 'Sup3rSecret',
        ], $this->authHeaders())
            ->assertStatus(201)
            // dbuser_prefix=c[CLIENTID]; the admin context group has
            // client_id 0 -> prefix 'c0'.
            ->assertJsonPath('database_user', 'appuser')
            ->assertJsonPath('database_user_prefix', 'c0')
            ->assertJsonPath('server_id', 0)
            ->assertJsonMissingPath('database_password');

        $id = (int) $response->json('id');
        $row = DB::table('web_database_user')->where('database_user_id', $id)->first();

        $this->assertSame('c0appuser', $row->database_user);

        // MySQL PASSWORD()-style: '*' + 40 uppercase hex chars — byte-exact.
        $this->assertSame('*'.strtoupper(sha1(sha1('Sup3rSecret', true))), $row->database_password);

        // caching_sha2_password: $A$005$<20-byte salt><43-char digest>.
        $this->assertMatchesRegularExpression('/^\$A\$005\$/', $row->database_password_sha2);
        $this->assertSame(3 + 4 + 20 + 43, strlen($row->database_password_sha2));

        // PostgreSQL SCRAM-SHA-256 verifier.
        $this->assertMatchesRegularExpression(
            '/^SCRAM-SHA-256\$4096:[A-Za-z0-9+\/=]+\$[A-Za-z0-9+\/=]+:[A-Za-z0-9+\/=]+$/',
            $row->database_password_postgres
        );

        // Datalog i carries hashes only — never the plaintext.
        $rows = $this->datalogRows('web_database_user');
        $this->assertCount(1, $rows);
        $this->assertSame('i', $rows[0]->action);
        $this->assertSame('database_user_id:'.$id, $rows[0]->dbidx);
        $this->assertStringNotContainsString('Sup3rSecret', $rows[0]->data);
        $data = unserialize($rows[0]->data);
        $this->assertSame('c0appuser', $data['new']['database_user']);
        $this->assertSame('0', $data['new']['server_id']);
    }

    public function test_create_with_client_id_assigns_owning_group(): void
    {
        // SitesApiTestCase seeds client 4 -> sys_group groupid 6. Admin
        // creating for that client: sys_groupid becomes the client's group,
        // sys_userid stays the acting admin, perms default.
        $response = $this->postJson('/api/v1/sites/database-users', [
            'database_user' => 'appuser',
            'database_password' => 'Sup3rSecret',
            'client_id' => 4,
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('sys_groupid', 6)
            ->assertJsonPath('sys_userid', 1)
            ->assertJsonMissingPath('database_password');

        $id = (int) $response->json('id');
        $row = DB::table('web_database_user')->where('database_user_id', $id)->first();
        $this->assertSame(6, (int) $row->sys_groupid);   // client 4's group
        $this->assertSame(1, (int) $row->sys_userid);    // acting admin
        $this->assertSame('riud', $row->sys_perm_user);
        $this->assertSame('c0appuser', $row->database_user);
        // client_id is not a web_database_user column and must not be persisted.
        $this->assertFalse(Schema::hasColumn('web_database_user', 'client_id'));
    }

    public function test_create_with_unknown_client_id_returns_422(): void
    {
        $this->postJson('/api/v1/sites/database-users', [
            'database_user' => 'appuser',
            'database_password' => 'Sup3rSecret',
            'client_id' => 999,
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonPath('errors.client_id.0', fn ($m) => is_string($m));

        $this->assertSame(0, DB::table('web_database_user')->count());
    }

    public function test_create_validation_failures(): void
    {
        $cases = [
            'missing user' => [['database_password' => 'x'], 'database_user'],
            'missing password' => [['database_user' => 'appuser'], 'database_password'],
            'bad chars' => [['database_user' => 'app-user', 'database_password' => 'x'], 'database_user'],
            'too short' => [['database_user' => 'a', 'database_password' => 'x'], 'database_user'],
        ];

        foreach ($cases as $label => [$payload, $field]) {
            $response = $this->postJson('/api/v1/sites/database-users', $payload, $this->authHeaders());
            $response->assertStatus(422);
            $this->assertArrayHasKey($field, $response->json('errors'), "case: {$label}");
        }

        // Blacklist applies to the PREFIXED name (legacy in_array($prefix .
        // $name, ...)) — with an empty prefix, 'root' is rejected.
        $config = (string) DB::table('sys_ini')->where('sysini_id', 1)->value('config');
        DB::table('sys_ini')->where('sysini_id', 1)->update([
            'config' => str_replace('dbuser_prefix=c[CLIENTID]', 'dbuser_prefix=', $config),
        ]);

        $this->postJson('/api/v1/sites/database-users', [
            'database_user' => 'root',
            'database_password' => 'x',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['database_user']]);

        // Prefixed name over 32 chars (33 with the now-empty prefix).
        $this->postJson('/api/v1/sites/database-users', [
            'database_user' => str_repeat('a', 33),
            'database_password' => 'x',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['database_user']]);

        $this->assertSame(0, DB::table('web_database_user')->count());
    }

    public function test_update_keeps_prefix_and_rehashes_on_password_change(): void
    {
        $id = $this->seedDatabaseUser(['database_user_prefix' => 'c3', 'database_user' => 'c3app']);

        $this->putJson('/api/v1/sites/database-users/'.$id, ['database_user' => 'renamed'], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('database_user', 'renamed')
            ->assertJsonPath('database_user_prefix', 'c3');

        $this->assertSame('c3renamed', DB::table('web_database_user')->where('database_user_id', $id)->value('database_user'));

        $this->putJson('/api/v1/sites/database-users/'.$id, ['database_password' => 'NewSecret1'], $this->authHeaders())
            ->assertOk();

        $row = DB::table('web_database_user')->where('database_user_id', $id)->first();
        $this->assertSame('*'.strtoupper(sha1(sha1('NewSecret1', true))), $row->database_password);
        $this->assertMatchesRegularExpression('/^\$A\$005\$/', $row->database_password_sha2);
        $this->assertStringStartsWith('SCRAM-SHA-256$4096:', $row->database_password_postgres);

        DB::table('sys_datalog')->delete();

        // No-change PUT writes no datalog row.
        $this->putJson('/api/v1/sites/database-users/'.$id, ['database_user' => 'renamed'], $this->authHeaders())
            ->assertOk();
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_delete_returns_204_with_datalog(): void
    {
        $id = $this->seedDatabaseUser();

        $this->deleteJson('/api/v1/sites/database-users/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('web_database_user', ['database_user_id' => $id]);
        $this->assertSame('d', $this->datalogRows('web_database_user')[0]->action);

        $this->deleteJson('/api/v1/sites/database-users/999', [], $this->authHeaders())
            ->assertStatus(404);
    }
}
