<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\ClientApiTestCase;

class ClientCircleApiTest extends ClientApiTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function seedCircle(array $overrides = []): int
    {
        return (int) DB::table('client_circle')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'circle_name' => 'Circle A',
            'client_ids' => '1',
            'description' => '',
            'active' => 'y',
        ], $overrides), 'circle_id');
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/clients/circles')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Unauthorized', 'status' => 401]);
    }

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    public function test_list_returns_data_meta_envelope_and_filters(): void
    {
        $this->seedCircle(['circle_name' => 'Actives', 'active' => 'y']);
        $this->seedCircle(['circle_name' => 'Dormant', 'active' => 'n', 'description' => 'paused']);

        $this->getJson('/api/v1/clients/circles', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.circle_name', 'Actives')
            ->assertJsonPath('data.0.active', true) // y/n exposed as boolean
            ->assertJsonPath('data.1.active', false);

        $this->getJson('/api/v1/clients/circles?active=false', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.circle_name', 'Dormant');

        $this->getJson('/api/v1/clients/circles?circle_name=Actives', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/clients/circles?description=paused', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.circle_name', 'Dormant');
    }

    public function test_list_rejects_bad_sort_with_400_problem(): void
    {
        $this->getJson('/api/v1/clients/circles?sort=evil', $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 400);
    }

    // ------------------------------------------------------------------
    // Show
    // ------------------------------------------------------------------

    public function test_show_returns_circle_contract_shape(): void
    {
        $id = $this->seedCircle(['circle_name' => 'Shown', 'client_ids' => '1,2']);

        $this->getJson('/api/v1/clients/circles/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('circle_name', 'Shown')
            ->assertJsonPath('client_ids', '1,2')
            ->assertJsonPath('active', true)
            ->assertJsonMissingPath('circle_id');
    }

    public function test_show_missing_returns_404_problem(): void
    {
        $this->getJson('/api/v1/clients/circles/999', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Not found', 'status' => 404]);
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    public function test_create_returns_201_and_writes_datalog(): void
    {
        $a = $this->seedClient(['username' => 'a']);
        $b = $this->seedClient(['username' => 'b']);

        $response = $this->postJson('/api/v1/clients/circles', [
            'circle_name' => 'Fresh',
            'client_ids' => $a.', '.$b, // spaces are normalized away
            'description' => 'test circle',
            'active' => true,
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('circle_name', 'Fresh')
            ->assertJsonPath('client_ids', $a.','.$b)
            ->assertJsonPath('active', true)
            ->assertJsonPath('sys_perm_user', 'riud');

        $id = $response->json('id');
        $this->assertDatabaseHas('client_circle', ['circle_id' => $id, 'circle_name' => 'Fresh', 'active' => 'y']);

        ['row' => $row, 'data' => $data] = $this->datalogFor('client_circle', 'i');
        $this->assertSame('circle_id:'.$id, $row->dbidx);
        $this->assertSame(['new', 'old'], array_keys($data));
        $this->assertSame('Fresh', $data['new']['circle_name']);
        $this->assertSame('y', $data['new']['active']); // lowercase y/n
        $this->assertSame($a.','.$b, $data['new']['client_ids']);
    }

    public function test_create_accepts_legacy_yn_flag_strings(): void
    {
        $a = $this->seedClient(['username' => 'a']);

        $this->postJson('/api/v1/clients/circles', [
            'circle_name' => 'YN',
            'client_ids' => (string) $a,
            'active' => 'n',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('active', false);
    }

    public function test_create_with_unknown_client_ids_returns_400_problem(): void
    {
        $a = $this->seedClient(['username' => 'a']);

        $this->postJson('/api/v1/clients/circles', [
            'circle_name' => 'Broken',
            'client_ids' => $a.',999',
        ], $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 400);

        $this->assertSame(0, DB::table('client_circle')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_create_validation_failures_return_422_problem(): void
    {
        $this->seedCircle(['circle_name' => 'Taken']);

        $cases = [
            'missing name' => [['client_ids' => '1'], 'circle_name'],
            'missing client_ids' => [['circle_name' => 'X'], 'client_ids'],
            'malformed client_ids' => [['circle_name' => 'X', 'client_ids' => '1,abc'], 'client_ids'],
            'duplicate name' => [['circle_name' => 'Taken', 'client_ids' => '1'], 'circle_name'],
        ];

        foreach ($cases as $label => [$payload, $errorField]) {
            $response = $this->postJson('/api/v1/clients/circles', $payload, $this->authHeaders());
            $response->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 422);
            $this->assertArrayHasKey($errorField, $response->json('errors'), "case: {$label}");
        }
    }

    // ------------------------------------------------------------------
    // Update (spec 001 gap G3: unknown ids used to 500)
    // ------------------------------------------------------------------

    public function test_update_returns_200_and_datalogs(): void
    {
        $id = $this->seedCircle(['circle_name' => 'Before', 'active' => 'y']);

        $this->putJson('/api/v1/clients/circles/'.$id, ['circle_name' => 'After', 'active' => false], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('circle_name', 'After')
            ->assertJsonPath('active', false);

        ['row' => $row, 'data' => $data] = $this->datalogFor('client_circle', 'u');
        $this->assertSame('circle_id:'.$id, $row->dbidx);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('Before', $data['old']['circle_name']);
        $this->assertSame('After', $data['new']['circle_name']);
        $this->assertSame('y', $data['old']['active']);
        $this->assertSame('n', $data['new']['active']);
    }

    public function test_update_without_changes_writes_no_datalog_row(): void
    {
        $id = $this->seedCircle(['circle_name' => 'Same']);

        $payload = ['circle_name' => 'Same', 'active' => true];

        $this->putJson('/api/v1/clients/circles/'.$id, $payload, $this->authHeaders())->assertOk();
        $this->putJson('/api/v1/clients/circles/'.$id, $payload, $this->authHeaders())->assertOk();

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_update_with_unknown_client_ids_returns_400_problem(): void
    {
        $id = $this->seedCircle();

        $this->putJson('/api/v1/clients/circles/'.$id, ['client_ids' => '999'], $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_update_missing_returns_404_problem(): void
    {
        // Regression for spec 001 gap G3 — this path threw an un-imported
        // NotFoundException and 500ed.
        $this->putJson('/api/v1/clients/circles/999', ['circle_name' => 'X'], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Not found', 'status' => 404]);
    }

    // ------------------------------------------------------------------
    // Delete
    // ------------------------------------------------------------------

    public function test_delete_returns_204_and_datalogs(): void
    {
        $id = $this->seedCircle(['circle_name' => 'Doomed']);

        $this->deleteJson('/api/v1/clients/circles/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('client_circle', ['circle_id' => $id]);

        ['row' => $row, 'data' => $data] = $this->datalogFor('client_circle', 'd');
        $this->assertSame('circle_id:'.$id, $row->dbidx);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('Doomed', $data['old']['circle_name']);
    }

    public function test_delete_missing_returns_404_problem(): void
    {
        $this->deleteJson('/api/v1/clients/circles/999', [], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }
}
