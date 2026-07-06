<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DnsSchema;
use Tests\TestCase;

class DnsSlaveApiTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'test-dev-key';

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

        DB::table('server')->insert([
            ['server_id' => 1, 'server_name' => 'dns1', 'dns_server' => 1, 'mirror_server_id' => 0, 'active' => 1],
            ['server_id' => 2, 'server_name' => 'web1', 'dns_server' => 0, 'mirror_server_id' => 0, 'active' => 1],
            ['server_id' => 3, 'server_name' => 'dns2', 'dns_server' => 1, 'mirror_server_id' => 0, 'active' => 1],
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
            'server_id' => 1,
            'origin' => 'slave.example.com',
            'ns' => '203.0.113.1',
        ], $overrides);
    }

    protected function seedSlave(array $overrides = []): int
    {
        return (int) DB::table('dns_slave')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'origin' => 'seeded.com.',
            'ns' => '203.0.113.1',
            'active' => 'Y',
            'xfer' => '',
        ], $overrides), 'id');
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/dns/slaves')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Unauthorized', 'status' => 401]);
    }

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    public function test_list_returns_data_meta_envelope(): void
    {
        $this->seedSlave(['origin' => 'alpha.com.']);
        $this->seedSlave(['origin' => 'beta.com.', 'active' => 'N', 'server_id' => 3]);

        $this->getJson('/api/v1/dns/slaves', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.origin', 'alpha.com.') // default sort: origin asc
            ->assertJsonPath('data.0.active', true)
            ->assertJsonPath('data.1.active', false);
    }

    public function test_list_filters(): void
    {
        $this->seedSlave(['origin' => 'example.com.']);
        $this->seedSlave(['origin' => 'example.org.', 'active' => 'N']);
        $this->seedSlave(['origin' => 'other.net.', 'server_id' => 3]);

        $this->getJson('/api/v1/dns/slaves?origin=example.*', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/dns/slaves?active=true', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/dns/slaves?server_id=3', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.origin', 'other.net.');
    }

    public function test_list_filters_by_owning_client(): void
    {
        DB::table('sys_group')->insert([
            ['groupid' => 12, 'name' => 'client5', 'client_id' => 5],
            ['groupid' => 13, 'name' => 'client6', 'client_id' => 6],
        ]);

        $this->seedSlave(['origin' => 'client5.com.', 'sys_groupid' => 12]);
        $this->seedSlave(['origin' => 'client6.com.', 'sys_groupid' => 13]);

        $this->getJson('/api/v1/dns/slaves?client_id=5', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.origin', 'client5.com.');

        $this->getJson('/api/v1/dns/slaves?client_id=999', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/v1/dns/slaves?client_id=abc', $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 400);
    }

    public function test_list_rejects_bad_parameters_with_400_problem(): void
    {
        foreach (['sort=evil_column', 'server_id=abc', 'active=maybe'] as $param) {
            $this->getJson('/api/v1/dns/slaves?'.$param, $this->authHeaders())
                ->assertStatus(400)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 400);
        }
    }

    // ------------------------------------------------------------------
    // Show
    // ------------------------------------------------------------------

    public function test_show_returns_slave_zone(): void
    {
        $id = $this->seedSlave(['origin' => 'shown.com.']);

        $this->getJson('/api/v1/dns/slaves/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('origin', 'shown.com.')
            ->assertJsonPath('ns', '203.0.113.1')
            ->assertJsonPath('active', true);
    }

    public function test_show_missing_returns_404_problem(): void
    {
        $this->getJson('/api/v1/dns/slaves/999', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    public function test_create_returns_201_and_datalogs(): void
    {
        $response = $this->postJson('/api/v1/dns/slaves', $this->validPayload(), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('origin', 'slave.example.com.') // dot-terminated
            ->assertJsonPath('ns', '203.0.113.1')
            ->assertJsonPath('active', true) // default
            ->assertJsonPath('sys_userid', 1)
            ->assertJsonPath('sys_perm_user', 'riud');

        $id = $response->json('id');
        $this->assertDatabaseHas('dns_slave', ['id' => $id, 'origin' => 'slave.example.com.', 'active' => 'Y']);

        $row = DB::table('sys_datalog')->where('dbtable', 'dns_slave')->first();
        $this->assertNotNull($row);
        $this->assertSame('i', $row->action);
        $this->assertSame('id:'.$id, $row->dbidx);
        $this->assertSame('apiadmin', $row->user);

        $data = unserialize($row->data);
        $this->assertSame(['new', 'old'], array_keys($data));
        $this->assertSame('slave.example.com.', $data['new']['origin']);
        $this->assertSame('Y', $data['new']['active']); // UPPERCASE enum (dns_slave DDL)
        $this->assertNull($data['old']['origin']);
    }

    public function test_create_validation_failures_return_422_problem(): void
    {
        $cases = [
            'missing origin' => [$this->validPayload(['origin' => null]), 'origin'],
            'malformed origin' => [$this->validPayload(['origin' => '!!!']), 'origin'],
            'ns not an ip' => [$this->validPayload(['ns' => 'not an ip']), 'ns'],
            'non dns server' => [$this->validPayload(['server_id' => 2]), 'server_id'],
            'bad xfer list' => [$this->validPayload(['xfer' => 'nope']), 'xfer'],
        ];

        foreach ($cases as $label => [$payload, $errorField]) {
            $response = $this->postJson('/api/v1/dns/slaves', $payload, $this->authHeaders());
            $response->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 422);
            $this->assertArrayHasKey($errorField, $response->json('errors'), "case: {$label}");
        }

        $this->assertSame(0, DB::table('dns_slave')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_create_duplicate_origin_on_same_server_returns_409(): void
    {
        $this->seedSlave(['origin' => 'slave.example.com.', 'server_id' => 1]);

        $this->postJson('/api/v1/dns/slaves', $this->validPayload(), $this->authHeaders())
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Conflict', 'status' => 409]);

        // The same origin on ANOTHER server is fine (composite unique key).
        $this->postJson('/api/v1/dns/slaves', $this->validPayload(['server_id' => 3]), $this->authHeaders())
            ->assertStatus(201);
    }

    public function test_create_rejects_collision_with_primary_zone(): void
    {
        DB::table('dns_soa')->insert([
            'server_id' => 1,
            'origin' => 'slave.example.com.',
            'ns' => 'ns1.example.com.',
            'mbox' => 'admin.example.com.',
            'serial' => 2024010101,
            'active' => 'Y',
        ]);

        $response = $this->postJson('/api/v1/dns/slaves', $this->validPayload(), $this->authHeaders());
        $response->assertStatus(422);
        $this->assertArrayHasKey('origin', $response->json('errors'));
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    public function test_update_returns_200_and_datalogs_diff(): void
    {
        $id = $this->seedSlave(['origin' => 'example.com.', 'ns' => '203.0.113.1']);

        $this->putJson('/api/v1/dns/slaves/'.$id, ['ns' => '203.0.113.2', 'active' => false], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('ns', '203.0.113.2')
            ->assertJsonPath('active', false);

        $row = DB::table('sys_datalog')->where('dbtable', 'dns_slave')->first();
        $this->assertNotNull($row);
        $this->assertSame('u', $row->action);
        $this->assertSame('id:'.$id, $row->dbidx);

        $data = unserialize($row->data);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('203.0.113.1', $data['old']['ns']);
        $this->assertSame('203.0.113.2', $data['new']['ns']);
        $this->assertSame('Y', $data['old']['active']);
        $this->assertSame('N', $data['new']['active']);
    }

    public function test_update_without_changes_writes_no_datalog_row(): void
    {
        $id = $this->seedSlave(['origin' => 'example.com.']);

        $payload = ['origin' => 'example.com.', 'ns' => '203.0.113.1', 'active' => true];

        $this->putJson('/api/v1/dns/slaves/'.$id, $payload, $this->authHeaders())->assertOk();
        $this->putJson('/api/v1/dns/slaves/'.$id, $payload, $this->authHeaders())->assertOk();

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_update_origin_collision_returns_409_problem(): void
    {
        $this->seedSlave(['origin' => 'taken.com.', 'server_id' => 1]);
        $id = $this->seedSlave(['origin' => 'example.com.', 'server_id' => 1]);

        $this->putJson('/api/v1/dns/slaves/'.$id, ['origin' => 'taken.com'], $this->authHeaders())
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_update_missing_returns_404_problem(): void
    {
        $this->putJson('/api/v1/dns/slaves/999', ['active' => false], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Delete
    // ------------------------------------------------------------------

    public function test_delete_returns_204_and_datalogs(): void
    {
        $id = $this->seedSlave(['origin' => 'example.com.']);

        $this->deleteJson('/api/v1/dns/slaves/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('dns_slave', ['id' => $id]);

        $row = DB::table('sys_datalog')->where('dbtable', 'dns_slave')->first();
        $this->assertNotNull($row);
        $this->assertSame('d', $row->action);
        $this->assertSame('id:'.$id, $row->dbidx);

        $data = unserialize($row->data);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('example.com.', $data['old']['origin']);
        $this->assertNull($data['new']['origin']);
    }

    public function test_delete_missing_returns_404_problem(): void
    {
        $this->deleteJson('/api/v1/dns/slaves/999', [], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }
}
