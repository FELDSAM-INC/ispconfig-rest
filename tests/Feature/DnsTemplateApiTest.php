<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DnsSchema;
use Tests\TestCase;

class DnsTemplateApiTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'test-dev-key';

    protected const TEMPLATE_TEXT = "[ZONE]\norigin={DOMAIN}.\nns={NS1}.\nmbox={EMAIL}.\n\n[DNS_RECORDS]\nA|{DOMAIN}.|{IP}|0|3600\n";

    protected function setUp(): void
    {
        parent::setUp();

        DnsSchema::create();

        config(['api.dev_key' => self::KEY]);

        DB::table('sys_user')->insert([
            'userid' => 1,
            'username' => 'apiadmin',
            'typ' => 'admin',
            'default_group' => 1,
        ]);
    }

    protected function authHeaders(): array
    {
        return ['X-API-Key' => self::KEY];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Standard Web Hosting',
            'fields' => 'DOMAIN,IP,NS1,NS2,EMAIL',
            'template' => self::TEMPLATE_TEXT,
        ], $overrides);
    }

    protected function seedTemplate(array $overrides = []): int
    {
        return (int) DB::table('dns_template')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'name' => 'Seeded',
            'fields' => 'DOMAIN,IP,NS1,NS2,EMAIL',
            'template' => self::TEMPLATE_TEXT,
            'visible' => 'Y',
        ], $overrides), 'template_id');
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/dns/templates')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Unauthorized', 'status' => 401]);
    }

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    public function test_list_returns_data_meta_envelope(): void
    {
        $this->seedTemplate(['name' => 'Alpha']);
        $this->seedTemplate(['name' => 'Beta', 'visible' => 'N']);

        $this->getJson('/api/v1/dns/templates', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.name', 'Alpha') // default sort: name asc
            ->assertJsonPath('data.0.visible', true)
            ->assertJsonPath('data.1.visible', false)
            ->assertJsonMissingPath('data.0.template_id'); // exposed as id
    }

    public function test_list_filters(): void
    {
        $this->seedTemplate(['name' => 'Standard A']);
        $this->seedTemplate(['name' => 'Standard B', 'visible' => 'N']);
        $this->seedTemplate(['name' => 'Custom']);

        $this->getJson('/api/v1/dns/templates?name=Standard*', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/dns/templates?visible=true', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/dns/templates?visible=false', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Standard B');
    }

    public function test_list_rejects_bad_parameters_with_400_problem(): void
    {
        foreach (['sort=evil_column', 'visible=maybe', 'limit=101'] as $param) {
            $this->getJson('/api/v1/dns/templates?'.$param, $this->authHeaders())
                ->assertStatus(400)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 400);
        }
    }

    // ------------------------------------------------------------------
    // Show
    // ------------------------------------------------------------------

    public function test_show_returns_template_with_id_mapping(): void
    {
        $id = $this->seedTemplate(['name' => 'Shown']);

        $this->getJson('/api/v1/dns/templates/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('name', 'Shown')
            ->assertJsonPath('fields', 'DOMAIN,IP,NS1,NS2,EMAIL')
            ->assertJsonPath('visible', true)
            ->assertJsonMissingPath('template_id');
    }

    public function test_show_missing_returns_404_problem(): void
    {
        $this->getJson('/api/v1/dns/templates/999', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    public function test_create_returns_201_and_datalogs(): void
    {
        $response = $this->postJson('/api/v1/dns/templates', $this->validPayload(), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('name', 'Standard Web Hosting')
            ->assertJsonPath('fields', 'DOMAIN,IP,NS1,NS2,EMAIL')
            ->assertJsonPath('visible', true) // default
            ->assertJsonPath('sys_userid', 1)
            ->assertJsonPath('sys_perm_user', 'riud');

        $id = $response->json('id');
        $this->assertDatabaseHas('dns_template', ['template_id' => $id, 'name' => 'Standard Web Hosting', 'visible' => 'Y']);

        $row = DB::table('sys_datalog')->where('dbtable', 'dns_template')->first();
        $this->assertNotNull($row);
        $this->assertSame('i', $row->action);
        $this->assertSame('template_id:'.$id, $row->dbidx); // pk template_id
        $this->assertSame('apiadmin', $row->user);

        $data = unserialize($row->data);
        $this->assertSame(['new', 'old'], array_keys($data));
        $this->assertSame('Standard Web Hosting', $data['new']['name']);
        $this->assertSame('Y', $data['new']['visible']); // UPPERCASE enum (dns_template DDL)
        $this->assertNull($data['old']['name']);
    }

    public function test_create_normalizes_lowercase_field_tokens(): void
    {
        $this->postJson('/api/v1/dns/templates', $this->validPayload(['fields' => 'domain, ip']), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('fields', 'DOMAIN,IP');
    }

    public function test_create_validation_failures_return_422_problem(): void
    {
        $cases = [
            'missing name' => [$this->validPayload(['name' => null]), 'name'],
            'name over db limit' => [$this->validPayload(['name' => str_repeat('x', 65)]), 'name'],
            'missing fields' => [$this->validPayload(['fields' => null]), 'fields'],
            'invalid field token' => [$this->validPayload(['fields' => 'DOMAIN,BOGUS']), 'fields'],
            'missing template' => [$this->validPayload(['template' => null]), 'template'],
        ];

        foreach ($cases as $label => [$payload, $errorField]) {
            $response = $this->postJson('/api/v1/dns/templates', $payload, $this->authHeaders());
            $response->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 422);
            $this->assertArrayHasKey($errorField, $response->json('errors'), "case: {$label}");
        }

        $this->assertSame(0, DB::table('dns_template')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    public function test_update_returns_200_and_datalogs_diff(): void
    {
        $id = $this->seedTemplate(['name' => 'Before']);

        $this->putJson('/api/v1/dns/templates/'.$id, ['name' => 'After', 'visible' => false], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('name', 'After')
            ->assertJsonPath('visible', false);

        $row = DB::table('sys_datalog')->where('dbtable', 'dns_template')->first();
        $this->assertNotNull($row);
        $this->assertSame('u', $row->action);
        $this->assertSame('template_id:'.$id, $row->dbidx);

        $data = unserialize($row->data);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('Before', $data['old']['name']);
        $this->assertSame('After', $data['new']['name']);
        $this->assertSame('Y', $data['old']['visible']);
        $this->assertSame('N', $data['new']['visible']);
    }

    public function test_update_rejects_invalid_field_tokens(): void
    {
        $id = $this->seedTemplate();

        $response = $this->putJson('/api/v1/dns/templates/'.$id, ['fields' => 'DOMAIN,BOGUS'], $this->authHeaders());
        $response->assertStatus(422);
        $this->assertArrayHasKey('fields', $response->json('errors'));
    }

    public function test_update_without_changes_writes_no_datalog_row(): void
    {
        $id = $this->seedTemplate(['name' => 'Same']);

        $payload = ['name' => 'Same', 'visible' => true];

        $this->putJson('/api/v1/dns/templates/'.$id, $payload, $this->authHeaders())->assertOk();
        $this->putJson('/api/v1/dns/templates/'.$id, $payload, $this->authHeaders())->assertOk();

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_update_missing_returns_404_problem(): void
    {
        $this->putJson('/api/v1/dns/templates/999', ['name' => 'X'], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Delete
    // ------------------------------------------------------------------

    public function test_delete_returns_204_and_datalogs(): void
    {
        $id = $this->seedTemplate(['name' => 'Doomed']);

        $this->deleteJson('/api/v1/dns/templates/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('dns_template', ['template_id' => $id]);

        $row = DB::table('sys_datalog')->where('dbtable', 'dns_template')->first();
        $this->assertNotNull($row);
        $this->assertSame('d', $row->action);
        $this->assertSame('template_id:'.$id, $row->dbidx);

        $data = unserialize($row->data);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('Doomed', $data['old']['name']);
        $this->assertNull($data['new']['name']);
    }

    public function test_delete_missing_returns_404_problem(): void
    {
        $this->deleteJson('/api/v1/dns/templates/999', [], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }
}
