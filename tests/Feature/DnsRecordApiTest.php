<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DnsSchema;
use Tests\TestCase;

class DnsRecordApiTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'test-dev-key';

    /** The parent zone every test works in. */
    protected int $zoneId;

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
        ]);

        // sys_groupid 5 proves group inheritance from the zone.
        $this->zoneId = (int) DB::table('dns_soa')->insertGetId([
            'sys_userid' => 1,
            'sys_groupid' => 5,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'origin' => 'example.com.',
            'ns' => 'ns1.example.com.',
            'mbox' => 'admin.example.com.',
            'serial' => 2024010101,
            'active' => 'Y',
        ], 'id');
    }

    protected function authHeaders(): array
    {
        return ['X-API-Key' => self::KEY];
    }

    protected function seedRecord(array $overrides = []): int
    {
        return (int) DB::table('dns_rr')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 5,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'zone' => $this->zoneId,
            'name' => 'www',
            'type' => 'A',
            'data' => '192.0.2.1',
            'aux' => 0,
            'ttl' => 3600,
            'active' => 'Y',
            'stamp' => '2024-01-01 00:00:00',
            'serial' => 2024010101,
        ], $overrides), 'id');
    }

    protected function zoneSerial(): string
    {
        return (string) DB::table('dns_soa')->where('id', $this->zoneId)->value('serial');
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/dns/records')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Unauthorized', 'status' => 401]);
    }

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    public function test_list_returns_data_meta_envelope_with_record_meta(): void
    {
        $this->seedRecord(['name' => 'mail', 'type' => 'MX', 'data' => 'mail.example.com.', 'aux' => 10]);
        $this->seedRecord(['name' => 'www']);

        $this->getJson('/api/v1/dns/records', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.name', 'mail') // default sort: name asc
            ->assertJsonPath('data.0.meta.priority', 10)
            ->assertJsonPath('data.0.meta.hostname', 'mail.example.com')
            ->assertJsonPath('data.1.active', true);
    }

    public function test_list_filters(): void
    {
        $this->seedRecord(['name' => 'www', 'type' => 'A', 'data' => '192.0.2.1']);
        $this->seedRecord(['name' => 'www2', 'type' => 'A', 'data' => '198.51.100.7', 'active' => 'N']);
        $this->seedRecord(['name' => 'mail', 'type' => 'MX', 'data' => 'mail.example.com.', 'aux' => 10]);
        $this->seedRecord(['name' => 'example.com.', 'type' => 'TXT', 'data' => 'v=spf1 mx ~all']);

        // type filter is case-insensitive.
        $this->getJson('/api/v1/dns/records?type=a', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        // SPF rows are stored as TXT and found via type=TXT (contract).
        $this->getJson('/api/v1/dns/records?type=TXT', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.meta.type', 'SPF');

        $this->getJson('/api/v1/dns/records?name=www*', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/dns/records?data=192.0', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/dns/records?active=false', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'www2');

        $this->getJson('/api/v1/dns/records?zone='.$this->zoneId, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 4);

        $this->getJson('/api/v1/dns/records?zone=999', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_list_rejects_bad_parameters_with_400_problem(): void
    {
        foreach (['sort=evil_column', 'type=BOGUS', 'zone=abc', 'active=maybe'] as $param) {
            $this->getJson('/api/v1/dns/records?'.$param, $this->authHeaders())
                ->assertStatus(400)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 400);
        }
    }

    // ------------------------------------------------------------------
    // Show
    // ------------------------------------------------------------------

    public function test_show_returns_record_with_computed_meta(): void
    {
        $id = $this->seedRecord(['name' => 'mail', 'type' => 'MX', 'data' => 'mail.example.com.', 'aux' => 10]);

        $this->getJson('/api/v1/dns/records/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('type', 'MX')
            ->assertJsonPath('aux', 10)
            ->assertJsonPath('meta.priority', 10)
            ->assertJsonPath('meta.hostname', 'mail.example.com');
    }

    public function test_show_missing_returns_404_problem(): void
    {
        $this->getJson('/api/v1/dns/records/999', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    public function test_create_a_record_inherits_zone_fields_and_bumps_zone_serial(): void
    {
        $response = $this->postJson('/api/v1/dns/records', [
            'zone' => $this->zoneId,
            'name' => 'www',
            'type' => 'A',
            'data' => '192.0.2.1',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('name', 'www')
            ->assertJsonPath('type', 'A')
            ->assertJsonPath('data', '192.0.2.1')
            ->assertJsonPath('ttl', 3600) // default
            ->assertJsonPath('active', true) // default
            ->assertJsonPath('server_id', 1) // inherited from the zone
            ->assertJsonPath('sys_groupid', 5) // inherited from the zone
            ->assertJsonPath('sys_userid', 1)
            ->assertJsonPath('serial', (int) (date('Ymd').'01'));

        $id = $response->json('id');
        $this->assertNotNull($response->json('stamp'));

        // dns_rr datalog 'i' with UPPERCASE Y (dns_rr DDL enum('N','Y')).
        $row = DB::table('sys_datalog')->where('dbtable', 'dns_rr')->first();
        $this->assertNotNull($row);
        $this->assertSame('i', $row->action);
        $this->assertSame('id:'.$id, $row->dbidx);

        $data = unserialize($row->data);
        $this->assertSame(['new', 'old'], array_keys($data));
        $this->assertSame('A', $data['new']['type']);
        $this->assertSame('192.0.2.1', $data['new']['data']);
        $this->assertSame('Y', $data['new']['active']);
        $this->assertSame('5', $data['new']['sys_groupid']);
        $this->assertSame('1', $data['new']['server_id']);

        // Parent zone serial bumped through the datalog (legacy parity).
        $this->assertSame(date('Ymd').'01', $this->zoneSerial());
        $soaLog = DB::table('sys_datalog')->where('dbtable', 'dns_soa')->where('action', 'u')->get();
        $this->assertCount(1, $soaLog);
        $soaDiff = unserialize($soaLog[0]->data);
        $this->assertSame('2024010101', $soaDiff['old']['serial']);
        $this->assertSame(date('Ymd').'01', $soaDiff['new']['serial']);
    }

    public function test_create_mx_record_composes_aux_and_data_from_meta_fields(): void
    {
        $this->postJson('/api/v1/dns/records', [
            'zone' => $this->zoneId,
            'name' => 'example.com.',
            'type' => 'MX',
            'priority' => 10,
            'hostname' => 'mail.example.com',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('type', 'MX')
            ->assertJsonPath('aux', 10)
            ->assertJsonPath('data', 'mail.example.com.') // dot-terminated
            ->assertJsonPath('meta.priority', 10)
            ->assertJsonPath('meta.hostname', 'mail.example.com');
    }

    public function test_create_caa_record_quotes_value(): void
    {
        $this->postJson('/api/v1/dns/records', [
            'zone' => $this->zoneId,
            'name' => 'example.com.',
            'type' => 'CAA',
            'caa_flag' => 0,
            'caa_type' => 'issue',
            'ca_issuer' => 'letsencrypt.org',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('type', 'CAA')
            ->assertJsonPath('data', '0 issue "letsencrypt.org"')
            ->assertJsonPath('meta.caa_type', 'issue')
            ->assertJsonPath('meta.ca_issuer', 'letsencrypt.org');
    }

    public function test_create_replaces_at_sign_name_with_zone_origin(): void
    {
        $this->postJson('/api/v1/dns/records', [
            'zone' => $this->zoneId,
            'name' => '@',
            'type' => 'A',
            'data' => '192.0.2.1',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('name', 'example.com.');
    }

    // ------------------------------------------------------------------
    // G02: SPF/DKIM/DMARC are stored as TXT rows
    // ------------------------------------------------------------------

    public function test_create_spf_record_is_stored_as_txt_and_classified_on_read(): void
    {
        $response = $this->postJson('/api/v1/dns/records', [
            'zone' => $this->zoneId,
            'name' => 'example.com.',
            'type' => 'SPF',
            'allow_mx' => true,
            'allow_a' => true,
            'ipv4_address' => '192.0.2.1',
            'policy' => 'softfail',
        ], $this->authHeaders())->assertStatus(201);

        $id = $response->json('id');

        // Stored as a TXT row (the dns_rr.type enum has no SPF member) —
        // the pre-fix code emitted an unstorable type value.
        $this->assertDatabaseHas('dns_rr', [
            'id' => $id,
            'type' => 'TXT',
            'data' => 'v=spf1 mx a ip4:192.0.2.1 ~all',
        ]);

        // Reads re-classify the row as SPF in the computed meta object.
        $this->getJson('/api/v1/dns/records/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('type', 'TXT')
            ->assertJsonPath('meta.type', 'SPF')
            ->assertJsonPath('meta.allow_mx', true)
            ->assertJsonPath('meta.allow_a', true)
            ->assertJsonPath('meta.ipv4_address', '192.0.2.1')
            ->assertJsonPath('meta.policy', 'softfail');
    }

    public function test_create_dmarc_record_is_stored_as_txt_with_forced_name(): void
    {
        // Spec 013 FR-020 (legacy dns_dmarc_edit.php:229-251): DMARC needs
        // an active DKIM record and exactly one active SPF in the zone.
        $this->seedRecord(['name' => 'default._domainkey.example.com.', 'type' => 'TXT', 'data' => 'v=DKIM1; t=s; p=MIIBIjANBg']);
        $this->seedRecord(['name' => 'example.com.', 'type' => 'TXT', 'data' => 'v=spf1 mx ~all']);

        $response = $this->postJson('/api/v1/dns/records', [
            'zone' => $this->zoneId,
            'name' => 'example.com.',
            'type' => 'DMARC',
            'policy' => 'quarantine',
            'rua' => 'dmarc@example.com',
            'pct' => 50,
        ], $this->authHeaders())->assertStatus(201);

        $id = $response->json('id');

        $this->assertDatabaseHas('dns_rr', [
            'id' => $id,
            'type' => 'TXT',
            'name' => '_dmarc.example.com.', // forced (legacy dns_dmarc_edit)
            'data' => 'v=DMARC1; p=quarantine; rua=mailto:dmarc@example.com; pct=50',
        ]);

        $this->getJson('/api/v1/dns/records/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.type', 'DMARC')
            ->assertJsonPath('meta.policy', 'quarantine')
            ->assertJsonPath('meta.pct', 50);
    }

    public function test_create_dkim_record_is_stored_as_txt(): void
    {
        $response = $this->postJson('/api/v1/dns/records', [
            'zone' => $this->zoneId,
            'name' => 'default._domainkey.example.com.',
            'type' => 'DKIM',
            'data' => 'v=DKIM1; t=s; p=MIIBIjANBg',
        ], $this->authHeaders())->assertStatus(201);

        $id = $response->json('id');

        $this->assertDatabaseHas('dns_rr', ['id' => $id, 'type' => 'TXT']);

        $this->getJson('/api/v1/dns/records/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.type', 'DKIM')
            ->assertJsonPath('meta.selector', 'default')
            ->assertJsonPath('meta.public_key', 'MIIBIjANBg');
    }

    // ------------------------------------------------------------------
    // G03: NAPTR pref round-trips
    // ------------------------------------------------------------------

    public function test_create_naptr_record_honors_pref(): void
    {
        $response = $this->postJson('/api/v1/dns/records', [
            'zone' => $this->zoneId,
            'name' => 'sip',
            'type' => 'NAPTR',
            'order' => 100,
            'pref' => 10,
            'naptr_flag' => 'U',
            'service' => 'E2U+sip',
            'regexp' => '!^.*$!sip:info@example.com!',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('aux', 100) // aux carries the order
            // pref lands in the data (the pre-fix code dropped it to 0).
            ->assertJsonPath('data', '10 "U" "E2U+sip" "!^.*$!sip:info@example.com!" .')
            ->assertJsonPath('meta.order', 100)
            ->assertJsonPath('meta.pref', 10)
            ->assertJsonPath('meta.naptr_flag', 'U')
            ->assertJsonPath('meta.service', 'E2U+sip')
            ->assertJsonPath('meta.regexp', '!^.*$!sip:info@example.com!');

        // Full GET round-trip keeps pref = 10.
        $this->getJson('/api/v1/dns/records/'.$response->json('id'), $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.pref', 10);
    }

    // ------------------------------------------------------------------
    // Per-type validation
    // ------------------------------------------------------------------

    public function test_create_validation_failures_return_422_problem(): void
    {
        $base = ['zone' => $this->zoneId, 'name' => 'www'];

        $cases = [
            'MX without hostname' => [$base + ['type' => 'MX', 'priority' => 10], 'hostname'],
            'A with malformed address' => [$base + ['type' => 'A', 'data' => 'not-an-ip'], 'data'],
            'AAAA with v4 address' => [$base + ['type' => 'AAAA', 'data' => '192.0.2.1'], 'data'],
            'CAA issue without ca_issuer' => [$base + ['type' => 'CAA', 'caa_type' => 'issue'], 'ca_issuer'],
            'TXT carrying SPF payload' => [$base + ['type' => 'TXT', 'data' => 'v=spf1 mx ~all'], 'data'],
            'unknown type' => [$base + ['type' => 'BOGUS', 'data' => 'x'], 'type'],
            'nonexistent zone' => [['zone' => 999, 'name' => 'www', 'type' => 'A', 'data' => '192.0.2.1'], 'zone'],
            'SRV without port' => [$base + ['type' => 'SRV', 'hostname' => 'sip.example.com', 'weight' => 1], 'port'],
        ];

        foreach ($cases as $label => [$payload, $errorField]) {
            $response = $this->postJson('/api/v1/dns/records', $payload, $this->authHeaders());
            $response->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 422);
            $this->assertArrayHasKey($errorField, $response->json('errors'), "case: {$label}");
        }

        $this->assertSame(0, DB::table('dns_rr')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
        $this->assertSame('2024010101', $this->zoneSerial()); // no bumps
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    public function test_update_refreshes_stamp_serial_and_bumps_zone_serial(): void
    {
        $id = $this->seedRecord(['data' => '192.0.2.1']);

        $this->putJson('/api/v1/dns/records/'.$id, ['data' => '198.51.100.7'], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data', '198.51.100.7')
            ->assertJsonPath('serial', (int) (date('Ymd').'01')); // record serial bumped

        $record = DB::table('dns_rr')->where('id', $id)->first();
        $this->assertNotSame('2024-01-01 00:00:00', $record->stamp); // stamp refreshed

        $row = DB::table('sys_datalog')->where('dbtable', 'dns_rr')->first();
        $this->assertNotNull($row);
        $this->assertSame('u', $row->action);

        $data = unserialize($row->data);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('192.0.2.1', $data['old']['data']);
        $this->assertSame('198.51.100.7', $data['new']['data']);

        // Zone serial bumped again (legacy dns_edit_base onAfterUpdate).
        $this->assertSame(date('Ymd').'01', $this->zoneSerial());
        $this->assertSame(1, DB::table('sys_datalog')->where('dbtable', 'dns_soa')->where('action', 'u')->count());
    }

    public function test_update_structured_record_merges_stored_meta_with_request(): void
    {
        $id = $this->seedRecord(['name' => 'mail', 'type' => 'MX', 'data' => 'mail.example.com.', 'aux' => 10]);

        // Partial update: only the priority — the hostname must survive.
        $this->putJson('/api/v1/dns/records/'.$id, ['priority' => 20], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('aux', 20)
            ->assertJsonPath('data', 'mail.example.com.')
            ->assertJsonPath('meta.priority', 20)
            ->assertJsonPath('meta.hostname', 'mail.example.com');
    }

    public function test_update_without_changes_writes_no_datalog_and_keeps_serials(): void
    {
        $id = $this->seedRecord(['data' => '192.0.2.1']);

        $this->putJson('/api/v1/dns/records/'.$id, ['data' => '192.0.2.1', 'ttl' => 3600, 'active' => true], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('serial', 2024010101); // untouched

        $record = DB::table('dns_rr')->where('id', $id)->first();
        $this->assertSame('2024-01-01 00:00:00', $record->stamp); // untouched

        $this->assertSame(0, DB::table('sys_datalog')->count());
        $this->assertSame('2024010101', $this->zoneSerial()); // no zone bump
    }

    public function test_update_missing_returns_404_problem(): void
    {
        $this->putJson('/api/v1/dns/records/999', ['data' => '192.0.2.1'], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Delete
    // ------------------------------------------------------------------

    public function test_delete_returns_204_datalogs_and_bumps_zone_serial(): void
    {
        $id = $this->seedRecord();

        $this->deleteJson('/api/v1/dns/records/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('dns_rr', ['id' => $id]);

        $row = DB::table('sys_datalog')->where('dbtable', 'dns_rr')->first();
        $this->assertNotNull($row);
        $this->assertSame('d', $row->action);
        $this->assertSame('id:'.$id, $row->dbidx);

        $data = unserialize($row->data);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('192.0.2.1', $data['old']['data']);

        // Zone serial bumped (legacy dns_rr_del.php onAfterDelete).
        $this->assertSame(date('Ymd').'01', $this->zoneSerial());
        $this->assertSame(1, DB::table('sys_datalog')->where('dbtable', 'dns_soa')->where('action', 'u')->count());
    }

    public function test_delete_missing_returns_404_problem(): void
    {
        $this->deleteJson('/api/v1/dns/records/999', [], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }
}
