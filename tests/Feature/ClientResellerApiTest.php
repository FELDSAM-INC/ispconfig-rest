<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\ClientApiTestCase;

class ClientResellerApiTest extends ClientApiTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Hosting Ltd',
            'contact_name' => 'Rick Seller',
            'email' => 'rick@hosting.tld',
            'username' => 'rickseller',
            'password' => 'res3ller-Pass1',
            'limit_client' => 10,
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/resellers')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Unauthorized', 'status' => 401]);
    }

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    public function test_list_only_returns_resellers(): void
    {
        $this->seedClient(['username' => 'plain', 'limit_client' => 0]);
        $this->seedClient(['username' => 'res1', 'limit_client' => 5, 'email' => 'r1@x.tld']);
        $this->seedClient(['username' => 'res2', 'limit_client' => -1]);

        $this->getJson('/api/v1/resellers', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.username', 'res1')
            ->assertJsonPath('data.1.username', 'res2');

        $this->getJson('/api/v1/resellers?email=r1@x.tld', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.username', 'res1');
    }

    public function test_list_rejects_bad_sort_with_400_problem(): void
    {
        $this->getJson('/api/v1/resellers?sort=evil', $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 400);
    }

    // ------------------------------------------------------------------
    // Show
    // ------------------------------------------------------------------

    public function test_show_returns_reseller_and_404s_for_plain_clients(): void
    {
        $resellerId = $this->seedClient(['username' => 'res1', 'limit_client' => -1]);
        $plainId = $this->seedClient(['username' => 'plain', 'limit_client' => 0]);

        $this->getJson('/api/v1/resellers/'.$resellerId, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $resellerId)
            ->assertJsonPath('limit_client', -1)
            ->assertJsonMissingPath('password');

        // A plain client id is outside the reseller scope -> 404 problem.
        $this->getJson('/api/v1/resellers/'.$plainId, $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->getJson('/api/v1/resellers/999', $this->authHeaders())
            ->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // Create (spec 001 gap G5: limit_client used to be silently dropped)
    // ------------------------------------------------------------------

    public function test_create_returns_201_and_persists_limit_client(): void
    {
        $response = $this->postJson('/api/v1/resellers', $this->validPayload(), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('username', 'rickseller')
            ->assertJsonPath('limit_client', 10)
            ->assertJsonMissingPath('password');

        $id = $response->json('id');
        $this->assertSame(10, (int) DB::table('client')->where('client_id', $id)->value('limit_client'));

        // The created reseller satisfies the scope and shows up in the list.
        $this->getJson('/api/v1/resellers', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $id);

        // Datalogged as a client insert with the persisted limit.
        ['row' => $row, 'data' => $data] = $this->datalogFor('client', 'i');
        $this->assertSame('client_id:'.$id, $row->dbidx);
        $this->assertSame('10', $data['new']['limit_client']);

        // Reseller sys_user gets the client module (reseller_edit.php parity).
        $user = DB::table('sys_user')->where('client_id', $id)->first();
        $this->assertNotNull($user);
        $this->assertStringContainsString('client', $user->modules);
    }

    public function test_create_rejects_non_reseller_limit_with_400_problem(): void
    {
        $this->postJson('/api/v1/resellers', $this->validPayload(['limit_client' => 0]), $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 400);

        $this->assertSame(0, DB::table('client')->count());
    }

    public function test_create_requires_limit_client(): void
    {
        $payload = $this->validPayload();
        unset($payload['limit_client']);

        $response = $this->postJson('/api/v1/resellers', $payload, $this->authHeaders());
        $response->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json');
        $this->assertArrayHasKey('limit_client', $response->json('errors'));
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    public function test_update_returns_200_and_datalogs(): void
    {
        $id = $this->seedClient(['username' => 'res1', 'limit_client' => 5]);
        $this->seedClientLogin($id, 'res1');

        $this->putJson('/api/v1/resellers/'.$id, ['limit_client' => 20], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('limit_client', 20);

        ['row' => $row, 'data' => $data] = $this->datalogFor('client', 'u');
        $this->assertSame('client_id:'.$id, $row->dbidx);
        $this->assertSame('5', $data['old']['limit_client']);
        $this->assertSame('20', $data['new']['limit_client']);
    }

    public function test_update_rejects_demotion_with_400_problem(): void
    {
        $id = $this->seedClient(['username' => 'res1', 'limit_client' => 5]);
        $this->seedClientLogin($id, 'res1');

        $this->putJson('/api/v1/resellers/'.$id, ['limit_client' => 0], $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->assertSame(5, (int) DB::table('client')->where('client_id', $id)->value('limit_client'));
    }

    public function test_update_missing_returns_404_problem(): void
    {
        $this->putJson('/api/v1/resellers/999', ['limit_client' => 5], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Delete
    // ------------------------------------------------------------------

    public function test_delete_with_assigned_clients_returns_409_problem(): void
    {
        $resellerId = $this->seedClient(['username' => 'res1', 'limit_client' => -1]);
        $this->seedClientLogin($resellerId, 'res1');
        $this->seedClient(['username' => 'child', 'parent_client_id' => $resellerId]);

        $this->deleteJson('/api/v1/resellers/'.$resellerId, [], $this->authHeaders())
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 409);

        $this->assertDatabaseHas('client', ['client_id' => $resellerId]);
    }

    public function test_delete_without_clients_returns_204_and_datalogs(): void
    {
        $resellerId = $this->seedClient(['username' => 'res1', 'limit_client' => -1]);
        $this->seedClientLogin($resellerId, 'res1');

        $this->deleteJson('/api/v1/resellers/'.$resellerId, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('client', ['client_id' => $resellerId]);
        $this->assertDatabaseMissing('sys_user', ['client_id' => $resellerId]);
        $this->assertDatabaseMissing('sys_group', ['client_id' => $resellerId]);

        ['row' => $row] = $this->datalogFor('client', 'd');
        $this->assertSame('client_id:'.$resellerId, $row->dbidx);
    }

    public function test_delete_missing_returns_404_problem(): void
    {
        $this->deleteJson('/api/v1/resellers/999', [], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }
}
