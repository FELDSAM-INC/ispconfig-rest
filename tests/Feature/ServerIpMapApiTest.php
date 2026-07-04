<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ServerSchema;
use Tests\TestCase;

class ServerIpMapApiTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'test-dev-key';

    protected function setUp(): void
    {
        parent::setUp();

        ServerSchema::create();

        config(['api.dev_key' => self::KEY]);

        DB::table('sys_user')->insert([
            'userid' => 1, 'username' => 'apiadmin', 'typ' => 'admin', 'default_group' => 1,
        ]);

        DB::table('server')->insert([
            ['server_id' => 1, 'server_name' => 'web01', 'web_server' => 1, 'mirror_server_id' => 0, 'active' => 1],
            ['server_id' => 2, 'server_name' => 'web02', 'web_server' => 1, 'mirror_server_id' => 0, 'active' => 1],
        ]);
    }

    protected function authHeaders(): array
    {
        return ['X-API-Key' => self::KEY];
    }

    protected function seedMapping(array $overrides = []): int
    {
        return (int) DB::table('server_ip_map')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'source_ip' => '10.0.0.5',
            'destination_ip' => '203.0.113.9',
            'active' => 'y',
        ], $overrides), 'server_ip_map_id');
    }

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/servers/1/ip-mappings')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_missing_parent_server_returns_404(): void
    {
        $this->getJson('/api/v1/servers/999/ip-mappings', $this->authHeaders())
            ->assertStatus(404);
    }

    public function test_child_of_another_server_returns_404(): void
    {
        $foreign = $this->seedMapping(['server_id' => 2]);

        $this->getJson('/api/v1/servers/1/ip-mappings/'.$foreign, $this->authHeaders())
            ->assertStatus(404);
    }

    public function test_list_is_scoped_and_rejects_bad_sort(): void
    {
        $this->seedMapping(['source_ip' => '10.0.0.1']);
        $this->seedMapping(['source_ip' => '10.0.0.2', 'destination_ip' => '203.0.113.10']);
        $this->seedMapping(['server_id' => 2, 'source_ip' => '10.0.2.1']);

        $this->getJson('/api/v1/servers/1/ip-mappings', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.source_ip', '10.0.0.1')
            ->assertJsonPath('data.0.active', true);

        $this->getJson('/api/v1/servers/1/ip-mappings?sort=evil', $this->authHeaders())
            ->assertStatus(400);
    }

    public function test_show_returns_contract_shape(): void
    {
        $id = $this->seedMapping();

        $this->getJson('/api/v1/servers/1/ip-mappings/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('server_id', 1)
            ->assertJsonPath('source_ip', '10.0.0.5')
            ->assertJsonPath('destination_ip', '203.0.113.9')
            ->assertJsonPath('active', true)
            ->assertJsonMissingPath('server_ip_map_id');
    }

    public function test_create_applies_default_active_and_datalogs(): void
    {
        $response = $this->postJson('/api/v1/servers/1/ip-mappings', [
            'source_ip' => '10.0.0.5',
            'destination_ip' => '203.0.113.9',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('server_id', 1)
            ->assertJsonPath('active', true); // legacy default 'y'

        $id = $response->json('id');
        $this->assertDatabaseHas('server_ip_map', ['server_ip_map_id' => $id, 'active' => 'y']);

        $row = DB::table('sys_datalog')->where('dbtable', 'server_ip_map')->first();
        $this->assertNotNull($row);
        $this->assertSame('i', $row->action);
        $this->assertSame('server_ip_map_id:'.$id, $row->dbidx);
        $this->assertSame(1, (int) $row->server_id);

        $data = unserialize($row->data);
        $this->assertSame(['new', 'old'], array_keys($data));
        $this->assertSame('203.0.113.9', $data['new']['destination_ip']);
    }

    public function test_create_validation_failures_return_422(): void
    {
        $cases = [
            'empty source_ip' => [['source_ip' => '', 'destination_ip' => '203.0.113.9'], 'source_ip'],
            'missing source_ip' => [['destination_ip' => '203.0.113.9'], 'source_ip'],
            'source_ip too long' => [['source_ip' => '1234567890123456', 'destination_ip' => '203.0.113.9'], 'source_ip'],
            'ipv6 destination' => [['source_ip' => '10.0.0.5', 'destination_ip' => 'fe80::1'], 'destination_ip'],
            'garbage destination' => [['source_ip' => '10.0.0.5', 'destination_ip' => 'not-an-ip'], 'destination_ip'],
            'missing destination' => [['source_ip' => '10.0.0.5'], 'destination_ip'],
        ];

        foreach ($cases as $label => [$payload, $errorField]) {
            $response = $this->postJson('/api/v1/servers/1/ip-mappings', $payload, $this->authHeaders());
            $response->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json');
            $this->assertArrayHasKey($errorField, $response->json('errors'), "case: {$label}");
        }

        $this->assertSame(0, DB::table('server_ip_map')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_update_returns_200_datalogs_and_keeps_server_immutable(): void
    {
        $id = $this->seedMapping();

        $this->putJson('/api/v1/servers/1/ip-mappings/'.$id, [
            'active' => false,
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('active', false);

        $row = DB::table('sys_datalog')->where('dbtable', 'server_ip_map')->first();
        $this->assertSame('u', $row->action);
        $data = unserialize($row->data);
        $this->assertSame('y', $data['old']['active']);
        $this->assertSame('n', $data['new']['active']);

        $response = $this->putJson('/api/v1/servers/1/ip-mappings/'.$id, [
            'server_id' => 2,
        ], $this->authHeaders());
        $response->assertStatus(422);
        $this->assertArrayHasKey('server_id', $response->json('errors'));
    }

    public function test_delete_returns_204_and_datalogs(): void
    {
        $id = $this->seedMapping();

        $this->deleteJson('/api/v1/servers/1/ip-mappings/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('server_ip_map', ['server_ip_map_id' => $id]);
        $this->assertSame(1, DB::table('sys_datalog')->where('dbtable', 'server_ip_map')->where('action', 'd')->count());
    }
}
