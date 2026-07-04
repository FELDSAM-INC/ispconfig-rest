<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DnsSchema;
use Tests\TestCase;

class DnsSoaApiTest extends TestCase
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

        // server_id 1 is a primary DNS server; server_id 2 is not.
        DB::table('server')->insert([
            ['server_id' => 1, 'server_name' => 'dns1', 'dns_server' => 1, 'mirror_server_id' => 0, 'active' => 1],
            ['server_id' => 2, 'server_name' => 'web1', 'dns_server' => 0, 'mirror_server_id' => 0, 'active' => 1],
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
            'origin' => 'example.com',
            'ns' => 'ns1.example.com',
            'mbox' => 'admin@example.com',
        ], $overrides);
    }

    protected function seedZone(array $overrides = []): int
    {
        return (int) DB::table('dns_soa')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'origin' => 'seeded.com.',
            'ns' => 'ns1.seeded.com.',
            'mbox' => 'admin.seeded.com.',
            'serial' => 2024010101,
            'refresh' => 28800,
            'retry' => 7200,
            'expire' => 604800,
            'minimum' => 3600,
            'ttl' => 3600,
            'active' => 'Y',
            'xfer' => '',
            'also_notify' => '',
            'update_acl' => '',
            'dnssec_initialized' => 'N',
            'dnssec_wanted' => 'N',
            'dnssec_algo' => 'ECDSAP256SHA256',
            'dnssec_last_signed' => 0,
        ], $overrides), 'id');
    }

    protected function seedRecord(int $zoneId, array $overrides = []): int
    {
        return (int) DB::table('dns_rr')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'zone' => $zoneId,
            'name' => 'www',
            'type' => 'A',
            'data' => '192.0.2.1',
            'aux' => 0,
            'ttl' => 3600,
            'active' => 'Y',
        ], $overrides), 'id');
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/dns/soa')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Unauthorized', 'status' => 401]);
    }

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    public function test_list_returns_data_meta_envelope(): void
    {
        $this->seedZone(['origin' => 'alpha.com.']);
        $this->seedZone(['origin' => 'beta.com.', 'active' => 'N']);

        $this->getJson('/api/v1/dns/soa', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.limit', 25)
            ->assertJsonPath('meta.offset', 0)
            ->assertJsonPath('data.0.origin', 'alpha.com.') // default sort: origin asc
            ->assertJsonPath('data.0.active', true)
            ->assertJsonPath('data.1.active', false);
    }

    public function test_list_filters(): void
    {
        $this->seedZone(['origin' => 'example.com.']);
        $this->seedZone(['origin' => 'example.org.', 'active' => 'N']);
        $this->seedZone(['origin' => 'other.net.']);

        $this->getJson('/api/v1/dns/soa?origin=example.*', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/dns/soa?active=true', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/dns/soa?active=false', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.origin', 'example.org.');
    }

    public function test_list_rejects_bad_parameters_with_400_problem(): void
    {
        foreach (['sort=evil_column', 'order=upwards', 'limit=0', 'offset=-1', 'active=maybe'] as $param) {
            $this->getJson('/api/v1/dns/soa?'.$param, $this->authHeaders())
                ->assertStatus(400)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 400);
        }
    }

    // ------------------------------------------------------------------
    // Show
    // ------------------------------------------------------------------

    public function test_show_returns_zone(): void
    {
        $id = $this->seedZone(['origin' => 'shown.com.']);

        $this->getJson('/api/v1/dns/soa/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('origin', 'shown.com.')
            ->assertJsonPath('active', true)
            ->assertJsonPath('serial', 2024010101)
            ->assertJsonPath('dnssec_wanted', false);
    }

    public function test_show_missing_returns_404_problem(): void
    {
        $this->getJson('/api/v1/dns/soa/999', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Not found', 'status' => 404]);
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    public function test_create_returns_201_generates_serial_and_datalogs(): void
    {
        $response = $this->postJson('/api/v1/dns/soa', $this->validPayload(), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('origin', 'example.com.') // dot-terminated (legacy)
            ->assertJsonPath('ns', 'ns1.example.com.')
            ->assertJsonPath('mbox', 'admin.example.com.') // '@' replaced with '.'
            ->assertJsonPath('serial', (int) (date('Ymd').'01')) // server-generated
            ->assertJsonPath('active', true)
            ->assertJsonPath('refresh', 28800)
            ->assertJsonPath('sys_userid', 1)
            ->assertJsonPath('sys_groupid', 1)
            ->assertJsonPath('sys_perm_user', 'riud')
            ->assertJsonPath('sys_perm_group', 'riud')
            ->assertJsonPath('sys_perm_other', '');

        $id = $response->json('id');
        $this->assertDatabaseHas('dns_soa', ['id' => $id, 'origin' => 'example.com.', 'active' => 'Y']);

        $row = DB::table('sys_datalog')->where('dbtable', 'dns_soa')->first();
        $this->assertNotNull($row);
        $this->assertSame('i', $row->action);
        $this->assertSame('id:'.$id, $row->dbidx);
        $this->assertSame(1, (int) $row->server_id);
        $this->assertSame('apiadmin', $row->user);

        $data = unserialize($row->data);
        $this->assertSame(['new', 'old'], array_keys($data)); // insert order
        $this->assertSame('example.com.', $data['new']['origin']);
        $this->assertSame('Y', $data['new']['active']); // UPPERCASE enum (dns_soa DDL)
        $this->assertSame('N', $data['new']['dnssec_wanted']);
        $this->assertSame(date('Ymd').'01', $data['new']['serial']);
        $this->assertNull($data['old']['origin']);
    }

    public function test_create_accepts_legacy_yn_flag_strings(): void
    {
        $this->postJson('/api/v1/dns/soa', $this->validPayload(['active' => 'N']), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('active', false);
    }

    public function test_create_validation_failures_return_422_problem(): void
    {
        $cases = [
            'missing origin' => [$this->validPayload(['origin' => null]), 'origin'],
            'malformed origin' => [$this->validPayload(['origin' => 'not_a_domain']), 'origin'],
            'non dns server' => [$this->validPayload(['server_id' => 2]), 'server_id'],
            'unknown server' => [$this->validPayload(['server_id' => 99]), 'server_id'],
            'refresh below legacy minimum' => [$this->validPayload(['refresh' => 30]), 'refresh'],
            'bad xfer ip list' => [$this->validPayload(['xfer' => 'not-an-ip']), 'xfer'],
            'also_notify rejects any' => [$this->validPayload(['also_notify' => 'any']), 'also_notify'],
        ];

        foreach ($cases as $label => [$payload, $errorField]) {
            $response = $this->postJson('/api/v1/dns/soa', $payload, $this->authHeaders());
            $response->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 422);
            $this->assertArrayHasKey($errorField, $response->json('errors'), "case: {$label}");
        }

        $this->assertSame(0, DB::table('dns_soa')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_create_duplicate_origin_returns_409_problem(): void
    {
        $this->seedZone(['origin' => 'example.com.']);

        $this->postJson('/api/v1/dns/soa', $this->validPayload(), $this->authHeaders())
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Conflict', 'status' => 409]);
    }

    public function test_create_rejects_collision_with_slave_zone(): void
    {
        DB::table('dns_slave')->insert([
            'server_id' => 1,
            'origin' => 'example.com.',
            'ns' => '203.0.113.1',
            'active' => 'Y',
        ]);

        $response = $this->postJson('/api/v1/dns/soa', $this->validPayload(), $this->authHeaders());
        $response->assertStatus(422);
        $this->assertArrayHasKey('origin', $response->json('errors'));
    }

    // ------------------------------------------------------------------
    // Update (G01 regression: serial bump must not 500)
    // ------------------------------------------------------------------

    public function test_update_without_serial_bumps_serial_and_returns_200(): void
    {
        $id = $this->seedZone(['origin' => 'example.com.', 'serial' => 2024010101]);

        // G01: this exact request 500ed in the legacy port (nonexistent
        // getNextSerialNumber()). It must succeed and bump the serial.
        $this->putJson('/api/v1/dns/soa/'.$id, ['refresh' => 14400], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('refresh', 14400)
            ->assertJsonPath('serial', (int) (date('Ymd').'01'));

        $row = DB::table('sys_datalog')->where('dbtable', 'dns_soa')->first();
        $this->assertNotNull($row);
        $this->assertSame('u', $row->action);
        $this->assertSame('id:'.$id, $row->dbidx);

        $data = unserialize($row->data);
        $this->assertSame(['old', 'new'], array_keys($data)); // update order
        $this->assertSame('2024010101', $data['old']['serial']);
        $this->assertSame(date('Ymd').'01', $data['new']['serial']);
        $this->assertSame('28800', $data['old']['refresh']);
        $this->assertSame('14400', $data['new']['refresh']);
    }

    public function test_update_ignores_client_supplied_serial(): void
    {
        $id = $this->seedZone(['origin' => 'example.com.', 'serial' => 2024010101]);

        // serial is not a validated input — a serial-only body is a no-op.
        $this->putJson('/api/v1/dns/soa/'.$id, ['serial' => 4000000000], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('serial', 2024010101);

        // And alongside a real change, the server-side bump wins.
        $this->putJson('/api/v1/dns/soa/'.$id, ['serial' => 4000000000, 'ttl' => 7200], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('serial', (int) (date('Ymd').'01'));
    }

    public function test_update_without_changes_writes_no_datalog_and_keeps_serial(): void
    {
        $id = $this->seedZone(['origin' => 'example.com.', 'serial' => 2024010101]);

        $this->putJson('/api/v1/dns/soa/'.$id, ['refresh' => 28800, 'active' => true], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('serial', 2024010101);

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_update_origin_collision_returns_409_problem(): void
    {
        $this->seedZone(['origin' => 'taken.com.']);
        $id = $this->seedZone(['origin' => 'example.com.']);

        $this->putJson('/api/v1/dns/soa/'.$id, ['origin' => 'taken.com'], $this->authHeaders())
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_update_missing_returns_404_problem(): void
    {
        $this->putJson('/api/v1/dns/soa/999', ['refresh' => 14400], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Delete
    // ------------------------------------------------------------------

    public function test_delete_empty_zone_returns_204_and_datalogs(): void
    {
        $id = $this->seedZone(['origin' => 'example.com.']);

        $this->deleteJson('/api/v1/dns/soa/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('dns_soa', ['id' => $id]);

        $row = DB::table('sys_datalog')->where('dbtable', 'dns_soa')->first();
        $this->assertNotNull($row);
        $this->assertSame('d', $row->action);
        $this->assertSame('id:'.$id, $row->dbidx);

        $data = unserialize($row->data);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('example.com.', $data['old']['origin']);
        $this->assertNull($data['new']['origin']);
    }

    public function test_delete_zone_with_records_returns_400_problem(): void
    {
        $id = $this->seedZone(['origin' => 'example.com.']);
        $this->seedRecord($id, ['name' => 'www']);
        $this->seedRecord($id, ['name' => 'mail', 'type' => 'MX', 'data' => 'mail.example.com.', 'aux' => 10]);

        $this->deleteJson('/api/v1/dns/soa/'.$id, [], $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 400)
            ->assertJsonPath('detail', 'Cannot delete zone that contains DNS records (2 associated records).');

        $this->assertDatabaseHas('dns_soa', ['id' => $id]);
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_delete_missing_returns_404_problem(): void
    {
        $this->deleteJson('/api/v1/dns/soa/999', [], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }
}
