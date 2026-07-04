<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\ClientApiTestCase;

class ClientTemplateAssignmentApiTest extends ClientApiTestCase
{
    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        // Route binding resolves before auth, so the client must exist for
        // the request to reach the api.key middleware.
        $clientId = $this->seedClient(['username' => 'jdoe']);

        $this->getJson('/api/v1/clients/'.$clientId.'/templates')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Unauthorized', 'status' => 401]);
    }

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    public function test_list_returns_master_and_additional_assignments(): void
    {
        $masterId = $this->seedTemplate(['template_name' => 'Master', 'template_type' => 'm']);
        $addonId = $this->seedTemplate(['template_name' => 'Addon', 'template_type' => 'a']);
        $clientId = $this->seedClient(['username' => 'jdoe', 'template_master' => $masterId]);
        $pivotId = (int) DB::table('client_template_assigned')->insertGetId([
            'client_id' => $clientId,
            'client_template_id' => $addonId,
        ], 'assigned_template_id');

        $this->getJson('/api/v1/clients/'.$clientId.'/templates', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 2)
            // Master assignment: no pivot row (id null), is_master true.
            ->assertJsonPath('data.0.id', null)
            ->assertJsonPath('data.0.client_id', $clientId)
            ->assertJsonPath('data.0.client_template_id', $masterId)
            ->assertJsonPath('data.0.is_master', true)
            ->assertJsonPath('data.0.template.template_name', 'Master')
            // Additional assignment: the pivot row.
            ->assertJsonPath('data.1.id', $pivotId)
            ->assertJsonPath('data.1.client_template_id', $addonId)
            ->assertJsonPath('data.1.is_master', false)
            ->assertJsonPath('data.1.template.template_name', 'Addon');
    }

    public function test_list_rejects_bad_sort_with_400_problem(): void
    {
        $clientId = $this->seedClient(['username' => 'jdoe']);

        $this->getJson('/api/v1/clients/'.$clientId.'/templates?sort=evil', $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 400);
    }

    public function test_list_unknown_client_returns_404_problem(): void
    {
        $this->getJson('/api/v1/clients/999/templates', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Assign (POST)
    // ------------------------------------------------------------------

    public function test_assign_additional_template_returns_201_and_recomputes_limits(): void
    {
        $masterId = $this->seedTemplate(['template_type' => 'm', 'limit_mailbox' => 10]);
        $addonId = $this->seedTemplate(['template_type' => 'a', 'limit_mailbox' => 5]);
        $clientId = $this->seedClient(['username' => 'jdoe', 'template_master' => $masterId]);

        $response = $this->postJson(
            '/api/v1/clients/'.$clientId.'/templates',
            ['client_template_id' => $addonId],
            $this->authHeaders()
        )->assertStatus(201)
            ->assertJsonPath('client_id', $clientId)
            ->assertJsonPath('client_template_id', $addonId)
            ->assertJsonPath('is_master', false)
            ->assertJsonPath('template.id', $addonId);

        $this->assertIsInt($response->json('id'));
        $this->assertDatabaseHas('client_template_assigned', [
            'client_id' => $clientId,
            'client_template_id' => $addonId,
        ]);

        // The pivot write goes through BaseModel -> datalogged (documented
        // Principle II surplus over legacy).
        ['row' => $pivotLog, 'data' => $pivotData] = $this->datalogFor('client_template_assigned', 'i');
        $this->assertSame('assigned_template_id:'.$response->json('id'), $pivotLog->dbidx);
        $this->assertSame((string) $addonId, $pivotData['new']['client_template_id']);

        // Effective limits recomputed: master 10 + addon 5 = 15 (legacy
        // client_templates.inc.php merge), datalogged as a client update.
        $this->assertSame(15, (int) DB::table('client')->where('client_id', $clientId)->value('limit_mailbox'));
        ['data' => $clientData] = $this->datalogFor('client', 'u');
        $this->assertSame('15', $clientData['new']['limit_mailbox']);
    }

    public function test_assign_master_template_sets_template_master(): void
    {
        $masterId = $this->seedTemplate(['template_type' => 'm', 'limit_mailbox' => 10]);
        $clientId = $this->seedClient(['username' => 'jdoe']);

        $this->postJson(
            '/api/v1/clients/'.$clientId.'/templates',
            ['client_template_id' => $masterId],
            $this->authHeaders()
        )->assertStatus(201)
            ->assertJsonPath('id', null)
            ->assertJsonPath('client_id', $clientId)
            ->assertJsonPath('client_template_id', $masterId)
            ->assertJsonPath('is_master', true);

        $this->assertSame($masterId, (int) DB::table('client')->where('client_id', $clientId)->value('template_master'));
        // template_master change + recomputed limits datalogged as client 'u'.
        $this->assertGreaterThan(0, DB::table('sys_datalog')->where('dbtable', 'client')->where('action', 'u')->count());
        $this->assertSame(10, (int) DB::table('client')->where('client_id', $clientId)->value('limit_mailbox'));
    }

    public function test_assign_duplicate_returns_409_problem(): void
    {
        $masterId = $this->seedTemplate(['template_type' => 'm']);
        $addonId = $this->seedTemplate(['template_type' => 'a']);
        $clientId = $this->seedClient(['username' => 'jdoe', 'template_master' => $masterId]);
        DB::table('client_template_assigned')->insert([
            'client_id' => $clientId,
            'client_template_id' => $addonId,
        ]);

        // Same additional template again -> 409.
        $this->postJson('/api/v1/clients/'.$clientId.'/templates', ['client_template_id' => $addonId], $this->authHeaders())
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 409);

        // Same master template again -> 409.
        $this->postJson('/api/v1/clients/'.$clientId.'/templates', ['client_template_id' => $masterId], $this->authHeaders())
            ->assertStatus(409)
            ->assertJsonPath('status', 409);
    }

    public function test_assign_unknown_template_returns_422_problem(): void
    {
        $clientId = $this->seedClient(['username' => 'jdoe']);

        $response = $this->postJson('/api/v1/clients/'.$clientId.'/templates', ['client_template_id' => 999], $this->authHeaders());
        $response->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json');
        $this->assertArrayHasKey('client_template_id', $response->json('errors'));
    }

    public function test_assign_to_unknown_client_returns_404_problem(): void
    {
        $templateId = $this->seedTemplate();

        $this->postJson('/api/v1/clients/999/templates', ['client_template_id' => $templateId], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Show
    // ------------------------------------------------------------------

    public function test_show_returns_assignment(): void
    {
        $masterId = $this->seedTemplate(['template_name' => 'Master', 'template_type' => 'm']);
        $clientId = $this->seedClient(['username' => 'jdoe', 'template_master' => $masterId]);

        $this->getJson('/api/v1/clients/'.$clientId.'/templates/'.$masterId, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('client_id', $clientId)
            ->assertJsonPath('client_template_id', $masterId)
            ->assertJsonPath('is_master', true)
            ->assertJsonPath('template.template_name', 'Master');
    }

    public function test_show_unassigned_returns_404_problem(): void
    {
        $templateId = $this->seedTemplate();
        $clientId = $this->seedClient(['username' => 'jdoe']);

        $this->getJson('/api/v1/clients/'.$clientId.'/templates/'.$templateId, $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 404);
    }

    // ------------------------------------------------------------------
    // Unassign (DELETE)
    // ------------------------------------------------------------------

    public function test_unassign_additional_returns_204_and_recomputes_limits(): void
    {
        $masterId = $this->seedTemplate(['template_type' => 'm', 'limit_mailbox' => 10]);
        $addonId = $this->seedTemplate(['template_type' => 'a', 'limit_mailbox' => 5]);
        $clientId = $this->seedClient(['username' => 'jdoe', 'template_master' => $masterId, 'limit_mailbox' => 15]);
        DB::table('client_template_assigned')->insert([
            'client_id' => $clientId,
            'client_template_id' => $addonId,
        ]);

        $this->deleteJson('/api/v1/clients/'.$clientId.'/templates/'.$addonId, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('client_template_assigned', [
            'client_id' => $clientId,
            'client_template_id' => $addonId,
        ]);

        // Pivot delete datalogged; limits recomputed back to the master's.
        ['row' => $pivotLog] = $this->datalogFor('client_template_assigned', 'd');
        $this->assertSame('d', $pivotLog->action);
        $this->assertSame(10, (int) DB::table('client')->where('client_id', $clientId)->value('limit_mailbox'));
    }

    public function test_unassign_master_clears_template_master(): void
    {
        $masterId = $this->seedTemplate(['template_type' => 'm']);
        $clientId = $this->seedClient(['username' => 'jdoe', 'template_master' => $masterId]);

        $this->deleteJson('/api/v1/clients/'.$clientId.'/templates/'.$masterId, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertSame(0, (int) DB::table('client')->where('client_id', $clientId)->value('template_master'));
    }

    public function test_unassign_unassigned_returns_404_problem(): void
    {
        $templateId = $this->seedTemplate();
        $clientId = $this->seedClient(['username' => 'jdoe']);

        $this->deleteJson('/api/v1/clients/'.$clientId.'/templates/'.$templateId, [], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }
}
