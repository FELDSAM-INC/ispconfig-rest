<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\ClientApiTestCase;

class ClientTemplateApiTest extends ClientApiTestCase
{
    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/clients/templates')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Unauthorized', 'status' => 401]);
    }

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    public function test_list_returns_data_meta_envelope_and_filters(): void
    {
        $this->seedTemplate(['template_name' => 'Master A', 'template_type' => 'm']);
        $this->seedTemplate(['template_name' => 'Addon B', 'template_type' => 'a']);

        $this->getJson('/api/v1/clients/templates', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.template_name', 'Master A'); // default sort template_id asc

        $this->getJson('/api/v1/clients/templates?template_type=a', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.template_name', 'Addon B');

        $this->getJson('/api/v1/clients/templates?template_name=Master A', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.template_type', 'm');
    }

    public function test_list_rejects_bad_sort_with_400_problem(): void
    {
        $this->getJson('/api/v1/clients/templates?sort=evil', $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 400);
    }

    // ------------------------------------------------------------------
    // Show
    // ------------------------------------------------------------------

    public function test_show_returns_template_contract_shape(): void
    {
        $id = $this->seedTemplate(['template_name' => 'Shown', 'limit_mailbox' => 100]);

        $this->getJson('/api/v1/clients/templates/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('template_name', 'Shown')
            ->assertJsonPath('template_type', 'm')
            ->assertJsonPath('limit_mailbox', 100)
            ->assertJsonPath('limit_mail_backup', true) // y/n exposed as boolean
            ->assertJsonMissingPath('template_id');
    }

    public function test_show_missing_returns_404_problem(): void
    {
        $this->getJson('/api/v1/clients/templates/999', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Not found', 'status' => 404]);
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    public function test_create_returns_201_and_writes_datalog(): void
    {
        $response = $this->postJson('/api/v1/clients/templates', [
            'template_name' => 'Reseller Basic',
            'template_type' => 'm',
            'limit_mailbox' => 50,
            'limit_ssl' => true,
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('template_name', 'Reseller Basic')
            ->assertJsonPath('limit_mailbox', 50)
            ->assertJsonPath('limit_ssl', true)
            ->assertJsonPath('sys_perm_user', 'riud');

        $id = $response->json('id');
        $this->assertDatabaseHas('client_template', ['template_id' => $id, 'template_name' => 'Reseller Basic']);

        ['row' => $row, 'data' => $data] = $this->datalogFor('client_template', 'i');
        $this->assertSame('template_id:'.$id, $row->dbidx);
        $this->assertSame(['new', 'old'], array_keys($data));
        $this->assertSame('Reseller Basic', $data['new']['template_name']);
        $this->assertSame('y', $data['new']['limit_ssl']); // lowercase y/n
        $this->assertSame('50', $data['new']['limit_mailbox']);
    }

    public function test_create_defaults_template_type_to_master(): void
    {
        $this->postJson('/api/v1/clients/templates', ['template_name' => 'Bare'], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('template_type', 'm');
    }

    public function test_create_validation_failures_return_422_problem(): void
    {
        $cases = [
            'missing name' => [['template_type' => 'm'], 'template_name'],
            'bad type' => [['template_name' => 'X', 'template_type' => 'z'], 'template_type'],
        ];

        foreach ($cases as $label => [$payload, $errorField]) {
            $response = $this->postJson('/api/v1/clients/templates', $payload, $this->authHeaders());
            $response->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 422);
            $this->assertArrayHasKey($errorField, $response->json('errors'), "case: {$label}");
        }

        $this->assertSame(0, DB::table('client_template')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    public function test_update_returns_200_and_datalogs(): void
    {
        $id = $this->seedTemplate(['template_name' => 'Before', 'limit_mailbox' => 10]);

        $this->putJson('/api/v1/clients/templates/'.$id, ['limit_mailbox' => 42], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('limit_mailbox', 42)
            ->assertJsonPath('template_name', 'Before');

        ['row' => $row, 'data' => $data] = $this->datalogFor('client_template', 'u');
        $this->assertSame('template_id:'.$id, $row->dbidx);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('10', $data['old']['limit_mailbox']);
        $this->assertSame('42', $data['new']['limit_mailbox']);
    }

    public function test_update_without_changes_writes_no_datalog_row(): void
    {
        $id = $this->seedTemplate(['template_name' => 'Same', 'limit_mailbox' => 10]);

        $payload = ['template_name' => 'Same', 'limit_mailbox' => 10];

        $this->putJson('/api/v1/clients/templates/'.$id, $payload, $this->authHeaders())->assertOk();
        $this->putJson('/api/v1/clients/templates/'.$id, $payload, $this->authHeaders())->assertOk();

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_update_rejects_template_type_change(): void
    {
        $id = $this->seedTemplate(['template_type' => 'm']);

        $response = $this->putJson('/api/v1/clients/templates/'.$id, ['template_type' => 'a'], $this->authHeaders());
        $response->assertStatus(422);
        $this->assertArrayHasKey('template_type', $response->json('errors'));

        // Re-sending the current value is fine.
        $this->putJson('/api/v1/clients/templates/'.$id, ['template_type' => 'm'], $this->authHeaders())
            ->assertOk();
    }

    public function test_update_reapplies_limits_to_assigned_clients(): void
    {
        // spec 001 gap G14: legacy client_template_edit.php::onAfterUpdate
        // re-applies changed limits to every client using the template.
        $templateId = $this->seedTemplate(['template_type' => 'm', 'limit_mailbox' => 10]);
        $clientId = $this->seedClient(['username' => 'jdoe', 'template_master' => $templateId, 'limit_mailbox' => 10]);

        $this->putJson('/api/v1/clients/templates/'.$templateId, ['limit_mailbox' => 77], $this->authHeaders())
            ->assertOk();

        $this->assertSame(77, (int) DB::table('client')->where('client_id', $clientId)->value('limit_mailbox'));

        // The recomputed client limits are datalogged as a client update.
        ['row' => $clientLog, 'data' => $clientData] = $this->datalogFor('client', 'u');
        $this->assertSame('client_id:'.$clientId, $clientLog->dbidx);
        $this->assertSame('10', $clientData['old']['limit_mailbox']);
        $this->assertSame('77', $clientData['new']['limit_mailbox']);
    }

    public function test_update_missing_returns_404_problem(): void
    {
        $this->putJson('/api/v1/clients/templates/999', ['template_name' => 'X'], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Delete (spec 001 gap G2: the in-use check used to 500)
    // ------------------------------------------------------------------

    public function test_delete_unused_returns_204_and_datalogs(): void
    {
        $id = $this->seedTemplate(['template_name' => 'Unused']);

        $this->deleteJson('/api/v1/clients/templates/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('client_template', ['template_id' => $id]);

        ['row' => $row, 'data' => $data] = $this->datalogFor('client_template', 'd');
        $this->assertSame('template_id:'.$id, $row->dbidx);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('Unused', $data['old']['template_name']);
    }

    public function test_delete_in_use_as_master_returns_409_problem(): void
    {
        $templateId = $this->seedTemplate(['template_type' => 'm']);
        $this->seedClient(['username' => 'jdoe', 'template_master' => $templateId]);

        $this->deleteJson('/api/v1/clients/templates/'.$templateId, [], $this->authHeaders())
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 409);

        $this->assertDatabaseHas('client_template', ['template_id' => $templateId]);
    }

    public function test_delete_in_use_as_additional_returns_409_problem(): void
    {
        $templateId = $this->seedTemplate(['template_type' => 'a']);
        $clientId = $this->seedClient(['username' => 'jdoe']);
        DB::table('client_template_assigned')->insert([
            'client_id' => $clientId,
            'client_template_id' => $templateId,
        ]);

        $this->deleteJson('/api/v1/clients/templates/'.$templateId, [], $this->authHeaders())
            ->assertStatus(409)
            ->assertJsonPath('status', 409);

        $this->assertDatabaseHas('client_template', ['template_id' => $templateId]);
    }

    public function test_delete_missing_returns_404_problem(): void
    {
        $this->deleteJson('/api/v1/clients/templates/999', [], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }
}
