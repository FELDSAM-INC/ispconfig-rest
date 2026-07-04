<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ServerSchema;
use Tests\TestCase;

class ServerApiTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'test-dev-key';

    protected function setUp(): void
    {
        parent::setUp();

        ServerSchema::create();

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

    protected function seedServer(array $overrides = []): int
    {
        return (int) DB::table('server')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_name' => 'server1',
            'mail_server' => 1,
            'web_server' => 1,
            'config' => "[global]\nwebserver=apache\n\n",
            'mirror_server_id' => 0,
            'active' => 1,
        ], $overrides), 'server_id');
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/servers')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Unauthorized', 'status' => 401]);

        $this->postJson('/api/v1/servers', ['server_name' => 'x'])
            ->assertStatus(401);
    }

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    public function test_list_returns_data_meta_envelope_without_config(): void
    {
        $this->seedServer(['server_name' => 'alpha']);
        $this->seedServer(['server_name' => 'beta', 'active' => 0]);

        $this->getJson('/api/v1/servers', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.server_name', 'alpha') // default sort: server_name asc
            ->assertJsonPath('data.0.active', 1)
            ->assertJsonPath('data.1.active', 0)
            ->assertJsonMissingPath('data.0.config'); // blob never leaks
    }

    public function test_list_pagination_and_sort_order(): void
    {
        foreach (['a', 'b', 'c'] as $name) {
            $this->seedServer(['server_name' => $name]);
        }

        $this->getJson('/api/v1/servers?limit=2&offset=1&sort=server_name&order=desc', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.server_name', 'b')
            ->assertJsonPath('data.1.server_name', 'a');
    }

    public function test_list_rejects_bad_parameters_with_400_problem(): void
    {
        $this->seedServer();

        foreach (['sort=evil', 'order=up', 'limit=0', 'offset=-1'] as $param) {
            $this->getJson('/api/v1/servers?'.$param, $this->authHeaders())
                ->assertStatus(400)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 400);
        }
    }

    // ------------------------------------------------------------------
    // Show
    // ------------------------------------------------------------------

    public function test_show_returns_contract_shape(): void
    {
        $id = $this->seedServer(['server_name' => 'shown']);

        $this->getJson('/api/v1/servers/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('server_name', 'shown')
            ->assertJsonPath('mail_server', 1)
            ->assertJsonPath('web_server', 1)
            ->assertJsonPath('dns_server', 0)
            ->assertJsonPath('mirror_server_id', 0)
            ->assertJsonPath('active', 1)
            ->assertJsonPath('dbversion', 1)
            ->assertJsonPath('sys_perm_user', 'riud')
            ->assertJsonMissingPath('config')
            ->assertJsonMissingPath('server_id');
    }

    public function test_show_missing_returns_404_problem(): void
    {
        $this->getJson('/api/v1/servers/999', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Not found', 'status' => 404]);
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    public function test_create_returns_201_with_defaults_and_datalogs(): void
    {
        $response = $this->postJson('/api/v1/servers', [
            'server_name' => 'web02',
            'web_server' => 1,
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('server_name', 'web02')
            ->assertJsonPath('web_server', 1)
            // legacy tform defaults: all other roles 0, active 1, mirror 0
            ->assertJsonPath('mail_server', 0)
            ->assertJsonPath('dns_server', 0)
            ->assertJsonPath('vserver_server', 0)
            ->assertJsonPath('xmpp_server', 0)
            ->assertJsonPath('proxy_server', 0)
            ->assertJsonPath('firewall_server', 0)
            ->assertJsonPath('mirror_server_id', 0)
            ->assertJsonPath('active', 1)
            // legacy auth_preset: perms riud/riud/'' (groupid 1 via context)
            ->assertJsonPath('sys_userid', 1)
            ->assertJsonPath('sys_groupid', 1)
            ->assertJsonPath('sys_perm_user', 'riud')
            ->assertJsonPath('sys_perm_group', 'riud')
            ->assertJsonPath('sys_perm_other', '');

        $id = $response->json('id');

        $row = DB::table('sys_datalog')->where('dbtable', 'server')->first();
        $this->assertNotNull($row);
        $this->assertSame('i', $row->action);
        $this->assertSame('server_id:'.$id, $row->dbidx);
        // For the server table the datalog server_id is the record's own PK.
        $this->assertSame($id, (int) $row->server_id);

        $data = unserialize($row->data);
        $this->assertSame(['new', 'old'], array_keys($data));
        $this->assertSame('web02', $data['new']['server_name']);
        $this->assertSame('1', $data['new']['web_server']);
        $this->assertSame('0', $data['new']['mail_server']);
    }

    public function test_create_strips_tags_and_newlines_from_server_name(): void
    {
        $this->postJson('/api/v1/servers', [
            'server_name' => "<b>web03</b>\n.example",
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('server_name', 'web03.example');
    }

    public function test_create_validation_failures_return_422_problem(): void
    {
        $cases = [
            'missing server_name' => [[], 'server_name'],
            'empty server_name' => [['server_name' => ''], 'server_name'],
            'bad flag value' => [['server_name' => 'x', 'web_server' => 2], 'web_server'],
            'negative mirror' => [['server_name' => 'x', 'mirror_server_id' => -1], 'mirror_server_id'],
        ];

        foreach ($cases as $label => [$payload, $errorField]) {
            $response = $this->postJson('/api/v1/servers', $payload, $this->authHeaders());
            $response->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json');
            $this->assertArrayHasKey($errorField, $response->json('errors'), "case: {$label}");
        }

        $this->assertSame(0, DB::table('server')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    public function test_update_returns_200_and_datalogs(): void
    {
        $id = $this->seedServer(['server_name' => 'server1']);

        $this->putJson('/api/v1/servers/'.$id, ['dns_server' => 1], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('dns_server', 1)
            ->assertJsonPath('server_name', 'server1');

        $row = DB::table('sys_datalog')->where('dbtable', 'server')->first();
        $this->assertNotNull($row);
        $this->assertSame('u', $row->action);

        $data = unserialize($row->data);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('0', $data['old']['dns_server']);
        $this->assertSame('1', $data['new']['dns_server']);
    }

    public function test_update_without_changes_writes_no_datalog_row(): void
    {
        $id = $this->seedServer();

        $this->putJson('/api/v1/servers/'.$id, ['mail_server' => 1, 'active' => 1], $this->authHeaders())->assertOk();

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_update_forces_mirror_to_zero_when_self_or_server_one(): void
    {
        // A server cannot mirror itself (legacy server_edit.php::onSubmit).
        $first = $this->seedServer(['server_name' => 'one']); // id 1
        $second = $this->seedServer(['server_name' => 'two']);

        $this->putJson('/api/v1/servers/'.$second, ['mirror_server_id' => $second], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('mirror_server_id', 0);

        // Server 1 (the master) can never be a mirror.
        $this->putJson('/api/v1/servers/'.$first, ['mirror_server_id' => $second], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('mirror_server_id', 0);

        // A regular mirror assignment sticks.
        $this->putJson('/api/v1/servers/'.$second, ['mirror_server_id' => $first], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('mirror_server_id', $first);
    }

    public function test_update_missing_returns_404_problem(): void
    {
        $this->putJson('/api/v1/servers/999', ['active' => 0], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Delete
    // ------------------------------------------------------------------

    public function test_delete_returns_204_datalogs_and_does_not_cascade(): void
    {
        $id = $this->seedServer();

        // Dependent records must survive (legacy server_del.php: plain
        // form delete, no cascade).
        $ipId = (int) DB::table('server_ip')->insertGetId([
            'server_id' => $id, 'ip_type' => 'IPv4', 'ip_address' => '10.0.0.5',
        ], 'server_ip_id');
        $fwId = (int) DB::table('firewall')->insertGetId([
            'server_id' => $id, 'tcp_port' => '22', 'udp_port' => '', 'active' => 'y',
        ], 'firewall_id');

        $this->deleteJson('/api/v1/servers/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('server', ['server_id' => $id]);
        $this->assertDatabaseHas('server_ip', ['server_ip_id' => $ipId]);
        $this->assertDatabaseHas('firewall', ['firewall_id' => $fwId]);

        $this->assertSame(1, DB::table('sys_datalog')->count());
        $row = DB::table('sys_datalog')->first();
        $this->assertSame('server', $row->dbtable);
        $this->assertSame('d', $row->action);
        $this->assertSame('server_id:'.$id, $row->dbidx);

        $data = unserialize($row->data);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('server1', $data['old']['server_name']);
    }

    public function test_delete_missing_returns_404_problem(): void
    {
        $this->deleteJson('/api/v1/servers/999', [], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }
}
