<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ServerSchema;
use Tests\TestCase;

class ServerIpApiTest extends TestCase
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

        DB::table('client')->insert(['client_id' => 5, 'username' => 'client5']);
    }

    protected function authHeaders(): array
    {
        return ['X-API-Key' => self::KEY];
    }

    protected function seedIp(array $overrides = []): int
    {
        return (int) DB::table('server_ip')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'client_id' => 0,
            'ip_type' => 'IPv4',
            'ip_address' => '10.0.0.1',
            'virtualhost' => 'y',
            'virtualhost_port' => '80,443',
        ], $overrides), 'server_ip_id');
    }

    // ------------------------------------------------------------------
    // Auth + nesting
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/servers/1/ip-addresses')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_missing_parent_server_returns_404(): void
    {
        $this->getJson('/api/v1/servers/999/ip-addresses', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->postJson('/api/v1/servers/999/ip-addresses', ['ip_address' => '10.0.0.9'], $this->authHeaders())
            ->assertStatus(404);
    }

    public function test_child_of_another_server_returns_404(): void
    {
        $foreign = $this->seedIp(['server_id' => 2, 'ip_address' => '10.0.2.1']);

        $this->getJson('/api/v1/servers/1/ip-addresses/'.$foreign, $this->authHeaders())
            ->assertStatus(404);
        $this->putJson('/api/v1/servers/1/ip-addresses/'.$foreign, ['client_id' => 0], $this->authHeaders())
            ->assertStatus(404);
        $this->deleteJson('/api/v1/servers/1/ip-addresses/'.$foreign, [], $this->authHeaders())
            ->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // List / show
    // ------------------------------------------------------------------

    public function test_list_is_scoped_to_the_server(): void
    {
        $this->seedIp(['ip_address' => '10.0.0.1']);
        $this->seedIp(['ip_address' => '10.0.0.2']);
        $this->seedIp(['server_id' => 2, 'ip_address' => '10.0.2.1']);

        $this->getJson('/api/v1/servers/1/ip-addresses', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.ip_address', '10.0.0.1')
            ->assertJsonPath('data.0.virtualhost', true); // boolean per schema
    }

    public function test_list_rejects_bad_sort_with_400(): void
    {
        $this->getJson('/api/v1/servers/1/ip-addresses?sort=evil', $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_show_returns_contract_shape(): void
    {
        $id = $this->seedIp(['client_id' => 5]);

        $this->getJson('/api/v1/servers/1/ip-addresses/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('server_id', 1)
            ->assertJsonPath('client_id', 5)
            ->assertJsonPath('ip_type', 'IPv4')
            ->assertJsonPath('ip_address', '10.0.0.1')
            ->assertJsonPath('virtualhost', true)
            ->assertJsonPath('virtualhost_port', '80,443')
            ->assertJsonMissingPath('server_ip_id');
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    public function test_create_applies_legacy_defaults_and_datalogs(): void
    {
        $response = $this->postJson('/api/v1/servers/1/ip-addresses', [
            'ip_address' => '10.0.0.5',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('server_id', 1)
            ->assertJsonPath('ip_type', 'IPv4')
            ->assertJsonPath('virtualhost', true)
            ->assertJsonPath('virtualhost_port', '80,443')
            ->assertJsonPath('client_id', 0)
            ->assertJsonPath('sys_perm_user', 'riud');

        $id = $response->json('id');
        $this->assertDatabaseHas('server_ip', ['server_ip_id' => $id, 'virtualhost' => 'y']);

        $row = DB::table('sys_datalog')->where('dbtable', 'server_ip')->first();
        $this->assertNotNull($row);
        $this->assertSame('i', $row->action);
        $this->assertSame('server_ip_id:'.$id, $row->dbidx);
        $this->assertSame(1, (int) $row->server_id);

        $data = unserialize($row->data);
        $this->assertSame(['new', 'old'], array_keys($data));
        $this->assertSame('10.0.0.5', $data['new']['ip_address']);
        $this->assertSame('y', $data['new']['virtualhost']); // DB-native enum
    }

    public function test_create_accepts_ipv6_and_yn_flag_strings(): void
    {
        $this->postJson('/api/v1/servers/1/ip-addresses', [
            'ip_type' => 'IPv6',
            'ip_address' => 'fe80::1',
            'virtualhost' => 'n',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('ip_type', 'IPv6')
            ->assertJsonPath('virtualhost', false);
    }

    public function test_create_rejects_type_mismatched_address_with_422(): void
    {
        $cases = [
            'ipv6 address declared IPv4' => ['ip_type' => 'IPv4', 'ip_address' => 'fe80::1'],
            'ipv4 address declared IPv6' => ['ip_type' => 'IPv6', 'ip_address' => '10.0.0.5'],
            'garbage address' => ['ip_address' => 'not-an-ip'],
            'default type is IPv4' => ['ip_address' => 'fe80::1'],
        ];

        foreach ($cases as $label => $payload) {
            $response = $this->postJson('/api/v1/servers/1/ip-addresses', $payload, $this->authHeaders());
            $response->assertStatus(422);
            $this->assertArrayHasKey('ip_address', $response->json('errors'), "case: {$label}");
        }
    }

    public function test_create_rejects_bad_port_list_with_422(): void
    {
        $response = $this->postJson('/api/v1/servers/1/ip-addresses', [
            'ip_address' => '10.0.0.5',
            'virtualhost_port' => '80,,443x',
        ], $this->authHeaders());

        $response->assertStatus(422);
        $this->assertArrayHasKey('virtualhost_port', $response->json('errors'));
    }

    public function test_create_duplicate_ip_returns_409_even_across_servers(): void
    {
        $this->seedIp(['server_id' => 2, 'ip_address' => '10.0.0.5']);

        $this->postJson('/api/v1/servers/1/ip-addresses', [
            'ip_address' => '10.0.0.5',
        ], $this->authHeaders())
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 409);

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_create_bad_client_reference_returns_400(): void
    {
        $this->postJson('/api/v1/servers/1/ip-addresses', [
            'ip_address' => '10.0.0.5',
            'client_id' => 99,
        ], $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json');

        // A valid client passes.
        $this->postJson('/api/v1/servers/1/ip-addresses', [
            'ip_address' => '10.0.0.5',
            'client_id' => 5,
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('client_id', 5);
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    public function test_update_returns_200_and_datalogs(): void
    {
        $id = $this->seedIp();

        $this->putJson('/api/v1/servers/1/ip-addresses/'.$id, [
            'virtualhost_port' => '80,443,8080',
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('virtualhost_port', '80,443,8080');

        $row = DB::table('sys_datalog')->where('dbtable', 'server_ip')->first();
        $this->assertSame('u', $row->action);

        $data = unserialize($row->data);
        $this->assertSame('80,443', $data['old']['virtualhost_port']);
        $this->assertSame('80,443,8080', $data['new']['virtualhost_port']);
    }

    public function test_update_rejects_server_change_with_422(): void
    {
        $id = $this->seedIp();

        $response = $this->putJson('/api/v1/servers/1/ip-addresses/'.$id, [
            'server_id' => 2,
        ], $this->authHeaders());

        $response->assertStatus(422);
        $this->assertArrayHasKey('server_id', $response->json('errors'));

        // Re-sending the current server_id is fine (idempotent PUT).
        $this->putJson('/api/v1/servers/1/ip-addresses/'.$id, ['server_id' => 1], $this->authHeaders())
            ->assertOk();
    }

    public function test_update_revalidates_changed_ip_against_stored_type(): void
    {
        $id = $this->seedIp(['ip_type' => 'IPv6', 'ip_address' => 'fe80::1']);

        // Stored type IPv6 governs when ip_type is not sent.
        $this->putJson('/api/v1/servers/1/ip-addresses/'.$id, [
            'ip_address' => '10.0.0.5',
        ], $this->authHeaders())
            ->assertStatus(422);

        $this->putJson('/api/v1/servers/1/ip-addresses/'.$id, [
            'ip_address' => 'fe80::2',
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('ip_address', 'fe80::2');
    }

    public function test_update_duplicate_ip_returns_409_but_own_value_is_ok(): void
    {
        $id = $this->seedIp(['ip_address' => '10.0.0.1']);
        $this->seedIp(['server_id' => 2, 'ip_address' => '10.0.2.1']);

        $this->putJson('/api/v1/servers/1/ip-addresses/'.$id, [
            'ip_address' => '10.0.2.1',
        ], $this->authHeaders())
            ->assertStatus(409);

        // Idempotent PUT with its own address does not self-conflict.
        $this->putJson('/api/v1/servers/1/ip-addresses/'.$id, [
            'ip_address' => '10.0.0.1',
        ], $this->authHeaders())
            ->assertOk();
    }

    // ------------------------------------------------------------------
    // Delete
    // ------------------------------------------------------------------

    public function test_delete_returns_204_and_datalogs(): void
    {
        $id = $this->seedIp();

        $this->deleteJson('/api/v1/servers/1/ip-addresses/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('server_ip', ['server_ip_id' => $id]);

        $row = DB::table('sys_datalog')->where('dbtable', 'server_ip')->first();
        $this->assertSame('d', $row->action);
        $this->assertSame('server_ip_id:'.$id, $row->dbidx);

        $data = unserialize($row->data);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('10.0.0.1', $data['old']['ip_address']);
    }
}
