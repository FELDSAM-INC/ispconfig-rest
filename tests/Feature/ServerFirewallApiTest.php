<?php

namespace Tests\Feature;

use App\Models\ServerFirewall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ServerSchema;
use Tests\TestCase;

/**
 * The firewall resource is a SINGLETON: firewall.server_id is UNIQUE
 * (legacy firewall.tform.php) — GET reads the server's one record, PUT
 * upserts it (201 create / 200 update), DELETE removes it.
 */
class ServerFirewallApiTest extends TestCase
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

    protected function seedFirewall(array $overrides = []): int
    {
        return (int) DB::table('firewall')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'tcp_port' => '22,80,443',
            'udp_port' => '53',
            'active' => 'y',
        ], $overrides), 'firewall_id');
    }

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/servers/1/firewall')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_missing_parent_server_returns_404(): void
    {
        $this->getJson('/api/v1/servers/999/firewall', $this->authHeaders())
            ->assertStatus(404);
        $this->putJson('/api/v1/servers/999/firewall', [], $this->authHeaders())
            ->assertStatus(404);
    }

    public function test_get_returns_404_when_server_has_no_firewall_record(): void
    {
        $this->getJson('/api/v1/servers/1/firewall', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_get_returns_the_singleton_record(): void
    {
        $id = $this->seedFirewall();
        $this->seedFirewall(['server_id' => 2, 'tcp_port' => '25']); // other server's record

        $this->getJson('/api/v1/servers/1/firewall', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('server_id', 1)
            ->assertJsonPath('tcp_port', '22,80,443')
            ->assertJsonPath('udp_port', '53')
            ->assertJsonPath('active', true)
            ->assertJsonMissingPath('firewall_id');
    }

    public function test_put_creates_with_201_defaults_and_datalog_insert(): void
    {
        $response = $this->putJson('/api/v1/servers/1/firewall', [], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('server_id', 1)
            // legacy tform defaults
            ->assertJsonPath('tcp_port', ServerFirewall::DEFAULT_TCP_PORTS)
            ->assertJsonPath('udp_port', '53')
            ->assertJsonPath('active', true)
            ->assertJsonPath('sys_perm_user', 'riud');

        $id = $response->json('id');
        $this->assertSame(1, DB::table('firewall')->count());

        $row = DB::table('sys_datalog')->where('dbtable', 'firewall')->first();
        $this->assertNotNull($row);
        $this->assertSame('i', $row->action);
        $this->assertSame('firewall_id:'.$id, $row->dbidx);
        $this->assertSame(1, (int) $row->server_id);

        $data = unserialize($row->data);
        $this->assertSame(['new', 'old'], array_keys($data));
        $this->assertSame('53', $data['new']['udp_port']);
    }

    public function test_put_updates_existing_record_with_200_and_datalog_update(): void
    {
        $id = $this->seedFirewall();

        $this->putJson('/api/v1/servers/1/firewall', [
            'tcp_port' => '22,443,40110:40210',
        ], $this->authHeaders())
            ->assertStatus(200)
            ->assertJsonPath('id', $id)
            ->assertJsonPath('tcp_port', '22,443,40110:40210');

        // Still exactly ONE record for the server (singleton upsert).
        $this->assertSame(1, DB::table('firewall')->where('server_id', 1)->count());

        $row = DB::table('sys_datalog')->where('dbtable', 'firewall')->first();
        $this->assertSame('u', $row->action);

        $data = unserialize($row->data);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('22,80,443', $data['old']['tcp_port']);
        $this->assertSame('22,443,40110:40210', $data['new']['tcp_port']);
    }

    public function test_put_without_changes_writes_no_datalog_row(): void
    {
        $this->seedFirewall();

        $this->putJson('/api/v1/servers/1/firewall', [
            'tcp_port' => '22,80,443',
            'udp_port' => '53',
            'active' => true,
        ], $this->authHeaders())->assertStatus(200);

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_put_accepts_empty_port_lists(): void
    {
        // The legacy regex allows '' = "no ports".
        $this->putJson('/api/v1/servers/1/firewall', [
            'tcp_port' => '',
            'udp_port' => '',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('tcp_port', '')
            ->assertJsonPath('udp_port', '');
    }

    public function test_put_rejects_bad_port_syntax_with_422(): void
    {
        foreach ([
            ['tcp_port' => '80,abc'],
            ['udp_port' => '53,'],
            ['tcp_port' => '80:'],
        ] as $payload) {
            $response = $this->putJson('/api/v1/servers/1/firewall', $payload, $this->authHeaders());
            $response->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json');
            $this->assertArrayHasKey(array_key_first($payload), $response->json('errors'));
        }

        $this->assertSame(0, DB::table('firewall')->count());
    }

    public function test_put_rejects_server_id_differing_from_path_with_422(): void
    {
        $this->seedFirewall();

        $response = $this->putJson('/api/v1/servers/1/firewall', [
            'server_id' => 2,
        ], $this->authHeaders());

        $response->assertStatus(422);
        $this->assertArrayHasKey('server_id', $response->json('errors'));

        // Matching the path is fine (idempotent full-body PUT).
        $this->putJson('/api/v1/servers/1/firewall', ['server_id' => 1], $this->authHeaders())
            ->assertStatus(200);
    }

    public function test_each_server_gets_its_own_singleton(): void
    {
        $this->putJson('/api/v1/servers/1/firewall', ['tcp_port' => '22'], $this->authHeaders())
            ->assertStatus(201);
        $this->putJson('/api/v1/servers/2/firewall', ['tcp_port' => '80'], $this->authHeaders())
            ->assertStatus(201);

        $this->assertSame('22', DB::table('firewall')->where('server_id', 1)->value('tcp_port'));
        $this->assertSame('80', DB::table('firewall')->where('server_id', 2)->value('tcp_port'));
    }

    public function test_delete_returns_204_and_datalogs(): void
    {
        $id = $this->seedFirewall();

        $this->deleteJson('/api/v1/servers/1/firewall', [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('firewall', ['firewall_id' => $id]);

        $row = DB::table('sys_datalog')->where('dbtable', 'firewall')->first();
        $this->assertSame('d', $row->action);
        $this->assertSame('firewall_id:'.$id, $row->dbidx);

        // Deleting again: the singleton is gone -> 404.
        $this->deleteJson('/api/v1/servers/1/firewall', [], $this->authHeaders())
            ->assertStatus(404);
    }
}
