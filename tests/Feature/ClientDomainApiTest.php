<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\ClientApiTestCase;

class ClientDomainApiTest extends ClientApiTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function seedDomain(array $overrides = []): int
    {
        return (int) DB::table('domain')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'ru',
            'sys_perm_other' => '',
            'domain' => 'seeded.tld',
        ], $overrides), 'domain_id');
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/clients/domains')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Unauthorized', 'status' => 401]);
    }

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    public function test_list_returns_data_meta_envelope(): void
    {
        $this->seedDomain(['domain' => 'alpha.tld']);
        $this->seedDomain(['domain' => 'beta.tld']);

        $this->getJson('/api/v1/clients/domains', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.domain', 'alpha.tld') // default sort: domain asc
            ->assertJsonPath('data.1.domain', 'beta.tld');
    }

    public function test_list_filters_by_client_through_sys_group(): void
    {
        $clientId = $this->seedClient(['username' => 'owner']);
        ['groupId' => $groupId] = $this->seedClientLogin($clientId, 'owner');
        $this->seedDomain(['domain' => 'owned.tld', 'sys_groupid' => $groupId]);
        $this->seedDomain(['domain' => 'other.tld', 'sys_groupid' => 1]);

        $this->getJson('/api/v1/clients/domains?client_id='.$clientId, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.domain', 'owned.tld');

        // A client without a sys_group owns nothing -> empty result.
        $groupless = $this->seedClient(['username' => 'groupless']);

        $this->getJson('/api/v1/clients/domains?client_id='.$groupless, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_list_rejects_bad_parameters_with_400_problem(): void
    {
        foreach (['sort=evil', 'client_id=abc'] as $param) {
            $this->getJson('/api/v1/clients/domains?'.$param, $this->authHeaders())
                ->assertStatus(400)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 400);
        }
    }

    // ------------------------------------------------------------------
    // Show
    // ------------------------------------------------------------------

    public function test_show_returns_domain_contract_shape(): void
    {
        $id = $this->seedDomain(['domain' => 'shown.tld']);

        $this->getJson('/api/v1/clients/domains/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('domain', 'shown.tld')
            ->assertJsonPath('sys_perm_group', 'ru')
            ->assertJsonMissingPath('domain_id');
    }

    public function test_show_missing_returns_404_problem(): void
    {
        $this->getJson('/api/v1/clients/domains/999', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Not found', 'status' => 404]);
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    public function test_create_returns_201_with_client_group_ownership_and_datalog(): void
    {
        $clientId = $this->seedClient(['username' => 'owner']);
        ['groupId' => $groupId] = $this->seedClientLogin($clientId, 'owner');

        $response = $this->postJson('/api/v1/clients/domains', [
            'client_id' => $clientId,
            'domain' => 'example.com',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('domain', 'example.com')
            ->assertJsonPath('sys_groupid', $groupId)
            ->assertJsonPath('sys_perm_group', 'ru') // domain_edit.php onAfterInsert fixup
            ->assertJsonPath('sys_perm_user', 'riud')
            ->assertJsonPath('sys_userid', 1);

        $id = $response->json('id');
        $this->assertDatabaseHas('domain', ['domain_id' => $id, 'domain' => 'example.com', 'sys_groupid' => $groupId]);

        ['row' => $row, 'data' => $data] = $this->datalogFor('domain', 'i');
        $this->assertSame('domain_id:'.$id, $row->dbidx);
        $this->assertSame(['new', 'old'], array_keys($data));
        $this->assertSame('example.com', $data['new']['domain']);
        $this->assertSame((string) $groupId, $data['new']['sys_groupid']);
        $this->assertSame('ru', $data['new']['sys_perm_group']);
    }

    public function test_create_normalizes_idn_and_uppercase_domains(): void
    {
        $clientId = $this->seedClient(['username' => 'owner']);
        $this->seedClientLogin($clientId, 'owner');

        $this->postJson('/api/v1/clients/domains', [
            'client_id' => $clientId,
            'domain' => 'MüLLER.De',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('domain', 'xn--mller-kva.de');
    }

    public function test_create_duplicate_domain_returns_409_problem(): void
    {
        $clientId = $this->seedClient(['username' => 'owner']);
        $this->seedClientLogin($clientId, 'owner');
        $this->seedDomain(['domain' => 'example.com']);

        $this->postJson('/api/v1/clients/domains', [
            'client_id' => $clientId,
            'domain' => 'example.com',
        ], $this->authHeaders())
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 409);
    }

    public function test_create_validation_failures_return_422_problem(): void
    {
        $clientId = $this->seedClient(['username' => 'owner']);
        $this->seedClientLogin($clientId, 'owner');

        $cases = [
            'missing domain' => [['client_id' => $clientId], 'domain'],
            'malformed domain' => [['client_id' => $clientId, 'domain' => 'not a domain'], 'domain'],
            'missing client' => [['domain' => 'ok.tld'], 'client_id'],
            'unknown client' => [['client_id' => 999, 'domain' => 'ok.tld'], 'client_id'],
        ];

        foreach ($cases as $label => [$payload, $errorField]) {
            $response = $this->postJson('/api/v1/clients/domains', $payload, $this->authHeaders());
            $response->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 422);
            $this->assertArrayHasKey($errorField, $response->json('errors'), "case: {$label}");
        }

        $this->assertSame(0, DB::table('domain')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_create_for_client_without_group_returns_400_problem(): void
    {
        $clientId = $this->seedClient(['username' => 'groupless']);

        $this->postJson('/api/v1/clients/domains', [
            'client_id' => $clientId,
            'domain' => 'ok.tld',
        ], $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 400);
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    public function test_update_returns_200_and_datalogs(): void
    {
        $id = $this->seedDomain(['domain' => 'before.tld']);

        $this->putJson('/api/v1/clients/domains/'.$id, ['domain' => 'after.tld'], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('domain', 'after.tld');

        ['row' => $row, 'data' => $data] = $this->datalogFor('domain', 'u');
        $this->assertSame('domain_id:'.$id, $row->dbidx);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('before.tld', $data['old']['domain']);
        $this->assertSame('after.tld', $data['new']['domain']);
    }

    public function test_update_can_reassign_owner_via_client_id(): void
    {
        $id = $this->seedDomain(['domain' => 'moving.tld', 'sys_groupid' => 1]);
        $clientId = $this->seedClient(['username' => 'newowner']);
        ['groupId' => $groupId] = $this->seedClientLogin($clientId, 'newowner');

        $this->putJson('/api/v1/clients/domains/'.$id, ['client_id' => $clientId], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('sys_groupid', $groupId)
            ->assertJsonPath('sys_perm_group', 'ru');
    }

    public function test_update_without_changes_writes_no_datalog_row(): void
    {
        $id = $this->seedDomain(['domain' => 'same.tld']);

        $this->putJson('/api/v1/clients/domains/'.$id, ['domain' => 'same.tld'], $this->authHeaders())->assertOk();
        $this->putJson('/api/v1/clients/domains/'.$id, ['domain' => 'same.tld'], $this->authHeaders())->assertOk();

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_update_rename_to_taken_domain_returns_409_problem(): void
    {
        $this->seedDomain(['domain' => 'taken.tld']);
        $id = $this->seedDomain(['domain' => 'mine.tld']);

        $this->putJson('/api/v1/clients/domains/'.$id, ['domain' => 'taken.tld'], $this->authHeaders())
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 409);
    }

    public function test_update_missing_returns_404_problem(): void
    {
        $this->putJson('/api/v1/clients/domains/999', ['domain' => 'x.tld'], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Delete
    // ------------------------------------------------------------------

    public function test_delete_returns_204_and_datalogs(): void
    {
        $id = $this->seedDomain(['domain' => 'doomed.tld']);

        $this->deleteJson('/api/v1/clients/domains/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('domain', ['domain_id' => $id]);

        ['row' => $row, 'data' => $data] = $this->datalogFor('domain', 'd');
        $this->assertSame('domain_id:'.$id, $row->dbidx);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('doomed.tld', $data['old']['domain']);
        $this->assertNull($data['new']['domain']);
    }

    public function test_delete_missing_returns_404_problem(): void
    {
        $this->deleteJson('/api/v1/clients/domains/999', [], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }
}
