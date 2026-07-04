<?php

namespace Tests\Feature;

use App\Support\LegacyCrypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\ClientApiTestCase;

class ClientApiTest extends ClientApiTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Acme Inc.',
            'contact_name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'username' => 'johndoe',
            'password' => 's3cr3tP@ssw0rd',
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/clients')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Unauthorized', 'status' => 401]);
    }

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    public function test_list_returns_data_meta_envelope_and_filters(): void
    {
        $this->seedClient(['username' => 'alpha', 'email' => 'a@x.tld', 'company_name' => 'Alpha Ltd', 'customer_no' => 'C-1']);
        $this->seedClient(['username' => 'beta', 'email' => 'b@x.tld', 'company_name' => 'Beta Ltd', 'contact_name' => 'Bea']);

        $this->getJson('/api/v1/clients', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.limit', 25)
            ->assertJsonPath('meta.offset', 0)
            ->assertJsonPath('data.0.username', 'alpha') // default sort client_id asc
            ->assertJsonMissingPath('data.0.password');

        $this->getJson('/api/v1/clients?email=b@x.tld', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.username', 'beta');

        $this->getJson('/api/v1/clients?company_name=Alpha Ltd', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/clients?contact_name=Bea', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/clients?customer_no=C-1', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.username', 'alpha');
    }

    public function test_list_rejects_bad_parameters_with_400_problem(): void
    {
        foreach (['sort=evil_column', 'order=upwards', 'limit=0', 'offset=-1'] as $param) {
            $this->getJson('/api/v1/clients?'.$param, $this->authHeaders())
                ->assertStatus(400)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 400);
        }
    }

    // ------------------------------------------------------------------
    // Show (spec 001 gap G1: this endpoint always 500ed)
    // ------------------------------------------------------------------

    public function test_show_returns_client_contract_shape(): void
    {
        $id = $this->seedClient(['username' => 'shown']);

        $this->getJson('/api/v1/clients/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('username', 'shown')
            ->assertJsonPath('company_name', 'Acme Inc.')
            ->assertJsonPath('can_use_api', false) // y/n exposed as boolean
            ->assertJsonPath('limit_maildomain', -1)
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('client_id')
            ->assertJsonMissingPath('tmp_data')
            ->assertJsonMissingPath('id_rsa');
    }

    public function test_show_missing_returns_404_problem(): void
    {
        $this->getJson('/api/v1/clients/999', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Not found', 'status' => 404]);
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    public function test_create_returns_201_and_writes_legacy_format_datalog(): void
    {
        $response = $this->postJson('/api/v1/clients', $this->validPayload(), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('username', 'johndoe')
            ->assertJsonPath('company_name', 'Acme Inc.')
            ->assertJsonPath('sys_perm_user', 'riud')
            ->assertJsonPath('sys_perm_group', 'riud')
            ->assertJsonPath('sys_userid', 1)
            ->assertJsonPath('sys_groupid', 1)
            ->assertJsonPath('parent_client_id', 0)
            ->assertJsonMissingPath('password');

        $id = $response->json('id');
        $this->assertIsInt($id);
        $this->assertDatabaseHas('client', ['client_id' => $id, 'username' => 'johndoe']);

        ['row' => $row, 'data' => $data] = $this->datalogFor('client', 'i');
        $this->assertSame('client_id:'.$id, $row->dbidx);
        $this->assertSame('apiadmin', $row->user);
        $this->assertSame('ok', $row->status);
        $this->assertGreaterThan(0, (int) $row->tstamp);

        // Insert payloads serialize 'new' before 'old' (legacy diffrec order)
        // and carry the complete row with DB-native values.
        $this->assertSame(['new', 'old'], array_keys($data));
        $this->assertSame('johndoe', $data['new']['username']);
        $this->assertSame((string) $id, $data['new']['client_id']);
        $this->assertSame('n', $data['new']['can_use_api']); // lowercase y/n
        $this->assertSame('y', $data['new']['limit_mail_backup']);
        $this->assertSame('-1', $data['new']['limit_maildomain']);
        $this->assertNull($data['old']['username']);

        // Default server lists resolved from the server table (legacy
        // client_edit.php overrides).
        $this->assertSame('1', $data['new']['mail_servers']);
        $this->assertSame('1', $data['new']['web_servers']);
    }

    public function test_create_provisions_sys_group_and_sys_user(): void
    {
        $id = $this->postJson('/api/v1/clients', $this->validPayload(), $this->authHeaders())
            ->assertStatus(201)
            ->json('id');

        // sys_group: created AND datalogged (legacy datalogInsert).
        $group = DB::table('sys_group')->where('client_id', $id)->first();
        $this->assertNotNull($group);
        $this->assertSame('johndoe', $group->name);

        ['row' => $groupLog, 'data' => $groupData] = $this->datalogFor('sys_group', 'i');
        $this->assertSame('groupid:'.$group->groupid, $groupLog->dbidx);
        $this->assertSame('johndoe', $groupData['new']['name']);

        // sys_user: created as the control-panel login (plain INSERT — legacy
        // does not datalog sys_user either).
        $user = DB::table('sys_user')->where('client_id', $id)->first();
        $this->assertNotNull($user);
        $this->assertSame('johndoe', $user->username);
        $this->assertSame((int) $group->groupid, (int) $user->default_group);
        $this->assertSame((string) $group->groupid, $user->groups);
        $this->assertSame('user', $user->typ);
        $this->assertSame('dashboard', $user->startmodule);
        $this->assertTrue(LegacyCrypt::verify('s3cr3tP@ssw0rd', $user->passwort));
        $this->assertSame(0, DB::table('sys_datalog')->where('dbtable', 'sys_user')->count());
    }

    public function test_create_stores_crypt_hash_never_plaintext(): void
    {
        $plain = 'sup3r-Secret-1';

        $this->postJson('/api/v1/clients', $this->validPayload(['password' => $plain]), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonMissingPath('password');

        $stored = DB::table('client')->where('username', 'johndoe')->value('password');
        $this->assertStringStartsWith('$6$', $stored);
        $this->assertNotSame($plain, $stored);
        $this->assertTrue(LegacyCrypt::verify($plain, $stored));

        // The datalog payload carries the HASH, never the plaintext.
        ['data' => $data, 'row' => $row] = $this->datalogFor('client', 'i');
        $this->assertSame($stored, $data['new']['password']);
        $this->assertStringNotContainsString($plain, $row->data);
    }

    public function test_create_validation_failures_return_422_problem(): void
    {
        $cases = [
            'missing username' => [$this->validPayload(['username' => null]), 'username'],
            'short password' => [$this->validPayload(['password' => 'short']), 'password'],
            'bad email' => [$this->validPayload(['email' => 'not-an-email']), 'email'],
            'bad username chars' => [$this->validPayload(['username' => 'no spaces!']), 'username'],
        ];

        foreach ($cases as $label => [$payload, $errorField]) {
            $response = $this->postJson('/api/v1/clients', $payload, $this->authHeaders());
            $response->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 422);
            $this->assertArrayHasKey($errorField, $response->json('errors'), "case: {$label}");
        }

        $this->assertSame(0, DB::table('client')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_create_rejects_duplicate_username(): void
    {
        $this->seedClient(['username' => 'johndoe']);

        $this->postJson('/api/v1/clients', $this->validPayload(), $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonPath('status', 422);
    }

    public function test_create_under_reseller_sets_reseller_ownership(): void
    {
        $resellerId = $this->seedClient(['username' => 'reseller1', 'limit_client' => -1]);
        ['groupId' => $resellerGroup, 'userId' => $resellerUser] = $this->seedClientLogin($resellerId, 'reseller1');

        $response = $this->postJson(
            '/api/v1/clients',
            $this->validPayload(['parent_client_id' => $resellerId]),
            $this->authHeaders()
        )->assertStatus(201)
            ->assertJsonPath('parent_client_id', $resellerId)
            ->assertJsonPath('sys_userid', $resellerUser)
            ->assertJsonPath('sys_groupid', $resellerGroup);

        // The new client's group is added to the reseller user's group list
        // (legacy add_group_to_user).
        $newGroup = DB::table('sys_group')->where('client_id', $response->json('id'))->value('groupid');
        $groups = explode(',', (string) DB::table('sys_user')->where('userid', $resellerUser)->value('groups'));
        $this->assertContains((string) $newGroup, $groups);
    }

    public function test_create_under_non_reseller_returns_400_problem(): void
    {
        $plainId = $this->seedClient(['username' => 'plain', 'limit_client' => 0]);

        $this->postJson('/api/v1/clients', $this->validPayload(['parent_client_id' => $plainId]), $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 400);

        // Reseller without a sys_user row: also 400 (legacy parity).
        $orphanId = $this->seedClient(['username' => 'orphan', 'limit_client' => 5]);

        $this->postJson('/api/v1/clients', $this->validPayload(['parent_client_id' => $orphanId]), $this->authHeaders())
            ->assertStatus(400)
            ->assertJsonPath('status', 400);
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    public function test_update_returns_200_and_datalogs_full_diff_record(): void
    {
        $id = $this->seedClient(['username' => 'jdoe']);
        $this->seedClientLogin($id, 'jdoe');

        $this->putJson('/api/v1/clients/'.$id, ['contact_name' => 'Jane Doe'], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('contact_name', 'Jane Doe')
            ->assertJsonPath('id', $id);

        ['row' => $row, 'data' => $data] = $this->datalogFor('client', 'u');
        $this->assertSame('client_id:'.$id, $row->dbidx);
        // Updates serialize 'old' before 'new' and both sides carry the
        // complete record.
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('John Doe', $data['old']['contact_name']);
        $this->assertSame('Jane Doe', $data['new']['contact_name']);
        $this->assertSame('jdoe', $data['old']['username']);
        $this->assertSame('jdoe', $data['new']['username']);
        $this->assertCount(count($data['old']), $data['new']);
    }

    public function test_update_without_changes_writes_no_datalog_row(): void
    {
        $id = $this->seedClient(['username' => 'jdoe']);
        $this->seedClientLogin($id, 'jdoe');

        $payload = ['contact_name' => 'John Doe', 'company_name' => 'Acme Inc.'];

        $this->putJson('/api/v1/clients/'.$id, $payload, $this->authHeaders())->assertOk();
        $this->putJson('/api/v1/clients/'.$id, $payload, $this->authHeaders())->assertOk();

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_update_syncs_username_and_password_to_sys_user(): void
    {
        $id = $this->seedClient(['username' => 'jdoe']);
        ['groupId' => $groupId] = $this->seedClientLogin($id, 'jdoe');

        $this->putJson('/api/v1/clients/'.$id, [
            'username' => 'renamed',
            'password' => 'brand-new-pass1',
        ], $this->authHeaders())->assertOk();

        // sys_user login renamed + password synced (CRYPT hash).
        $user = DB::table('sys_user')->where('client_id', $id)->first();
        $this->assertSame('renamed', $user->username);
        $this->assertTrue(LegacyCrypt::verify('brand-new-pass1', $user->passwort));

        // sys_group renamed AND datalogged (legacy datalogUpdate).
        $this->assertSame('renamed', DB::table('sys_group')->where('groupid', $groupId)->value('name'));
        ['data' => $groupData] = $this->datalogFor('sys_group', 'u');
        $this->assertSame('jdoe', $groupData['old']['name']);
        $this->assertSame('renamed', $groupData['new']['name']);

        // The client row's stored password is the hash, never plaintext.
        $stored = DB::table('client')->where('client_id', $id)->value('password');
        $this->assertStringStartsWith('$6$', $stored);
        $this->assertTrue(LegacyCrypt::verify('brand-new-pass1', $stored));
    }

    public function test_update_clearing_parent_resets_ownership_to_admin(): void
    {
        $resellerId = $this->seedClient(['username' => 'reseller1', 'limit_client' => -1]);
        ['groupId' => $resellerGroup, 'userId' => $resellerUser] = $this->seedClientLogin($resellerId, 'reseller1');

        $id = $this->seedClient([
            'username' => 'child',
            'parent_client_id' => $resellerId,
            'sys_userid' => $resellerUser,
            'sys_groupid' => $resellerGroup,
        ]);
        ['groupId' => $childGroup] = $this->seedClientLogin($id, 'child');

        // The reseller user manages the child's group.
        DB::table('sys_user')->where('userid', $resellerUser)
            ->update(['groups' => $resellerGroup.','.$childGroup]);

        $this->putJson('/api/v1/clients/'.$id, ['parent_client_id' => 0], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('parent_client_id', 0)
            ->assertJsonPath('sys_userid', 1)
            ->assertJsonPath('sys_groupid', 1);

        // The child's group is removed from the old reseller's user.
        $groups = explode(',', (string) DB::table('sys_user')->where('userid', $resellerUser)->value('groups'));
        $this->assertNotContains((string) $childGroup, $groups);
    }

    public function test_update_missing_returns_404_problem(): void
    {
        $this->putJson('/api/v1/clients/999', ['contact_name' => 'X'], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Delete
    // ------------------------------------------------------------------

    public function test_delete_returns_204_and_cascades_with_datalog(): void
    {
        $id = $this->seedClient(['username' => 'jdoe']);
        ['groupId' => $groupId] = $this->seedClientLogin($id, 'jdoe');

        // A domain-module record owned by the client's group — part of the
        // legacy client_del.php cascade.
        $domainId = (int) DB::table('domain')->insertGetId([
            'sys_userid' => 1,
            'sys_groupid' => $groupId,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'ru',
            'sys_perm_other' => '',
            'domain' => 'owned.tld',
        ], 'domain_id');

        $this->deleteJson('/api/v1/clients/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('client', ['client_id' => $id]);
        $this->assertDatabaseMissing('sys_group', ['client_id' => $id]);
        $this->assertDatabaseMissing('sys_user', ['client_id' => $id]);
        $this->assertDatabaseMissing('domain', ['domain_id' => $domainId]);

        // client + cascaded domain datalogged as 'd'; sys_group/sys_user are
        // plain DELETEs (legacy parity — no datalog).
        ['row' => $clientLog, 'data' => $clientData] = $this->datalogFor('client', 'd');
        $this->assertSame('client_id:'.$id, $clientLog->dbidx);
        $this->assertSame(['old', 'new'], array_keys($clientData));
        $this->assertSame('jdoe', $clientData['old']['username']);

        ['row' => $domainLog] = $this->datalogFor('domain', 'd');
        $this->assertSame('domain_id:'.$domainId, $domainLog->dbidx);

        $this->assertSame(0, DB::table('sys_datalog')->whereIn('dbtable', ['sys_user', 'sys_group'])->count());

        // One request groups the whole cascade under a single session id.
        $this->assertSame(1, DB::table('sys_datalog')->distinct()->count('session_id'));
    }

    public function test_delete_missing_returns_404_problem(): void
    {
        $this->deleteJson('/api/v1/clients/999', [], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }
}
