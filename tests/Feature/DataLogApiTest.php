<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MonitorSchema;
use Tests\TestCase;

/**
 * Monitor Data-Logs endpoints (contract: api/modules/monitor/data-logs.yaml).
 * Read-only module — sys_datalog rows are seeded directly.
 */
class DataLogApiTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'test-dev-key';

    protected function setUp(): void
    {
        parent::setUp();

        MonitorSchema::create();

        config(['api.dev_key' => self::KEY]);
    }

    protected function authHeaders(): array
    {
        return ['X-API-Key' => self::KEY];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function seedDataLog(array $overrides = []): int
    {
        return (int) DB::table('sys_datalog')->insertGetId(array_merge([
            'server_id' => 1,
            'dbtable' => 'mail_domain',
            'dbidx' => 'domain_id:1',
            'action' => 'u',
            'tstamp' => 1700000000,
            'user' => 'admin',
            'data' => serialize([
                'old' => ['active' => 'y'],
                'new' => ['active' => 'n'],
            ]),
            'status' => 'ok',
            'error' => null,
            'session_id' => 'sess-1',
        ], $overrides), 'datalog_id');
    }

    protected function seedServer(int $serverId, int $updated): void
    {
        DB::table('server')->insert([
            'server_id' => $serverId,
            'server_name' => 'server'.$serverId,
            'updated' => $updated,
            'mirror_server_id' => 0,
            'active' => 1,
        ]);
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        $id = $this->seedDataLog();

        $this->getJson('/api/v1/monitor/data-logs')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Unauthorized', 'status' => 401]);

        // Existing id: route binding resolves, then api.key rejects.
        $this->getJson('/api/v1/monitor/data-logs/'.$id)
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    public function test_list_returns_data_meta_envelope_newest_first(): void
    {
        $first = $this->seedDataLog(['dbidx' => 'domain_id:1', 'tstamp' => 1700000001]);
        $second = $this->seedDataLog(['dbidx' => 'domain_id:2', 'tstamp' => 1700000002]);
        $third = $this->seedDataLog(['dbidx' => 'domain_id:3', 'tstamp' => 1700000003]);

        $this->getJson('/api/v1/monitor/data-logs', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.limit', 25)
            ->assertJsonPath('meta.offset', 0)
            // Default sort=id (datalog_id), order=desc — newest entry first.
            ->assertJsonPath('data.0.id', $third)
            ->assertJsonPath('data.1.id', $second)
            ->assertJsonPath('data.2.id', $first)
            ->assertJsonPath('data.0.dbtable', 'mail_domain')
            ->assertJsonPath('data.0.dbidx', 'domain_id:3')
            ->assertJsonPath('data.0.action', 'u')
            ->assertJsonPath('data.0.tstamp', 1700000003)
            ->assertJsonPath('data.0.user', 'admin')
            ->assertJsonPath('data.0.status', 'ok')
            // The primary key is exposed as `id`, never as datalog_id.
            ->assertJsonMissingPath('data.0.datalog_id');
    }

    public function test_list_data_field_is_deserialized(): void
    {
        $this->seedDataLog();

        $this->getJson('/api/v1/monitor/data-logs', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.data.old.active', 'y')
            ->assertJsonPath('data.0.data.new.active', 'n');
    }

    public function test_list_pagination_bounds(): void
    {
        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[] = $this->seedDataLog(['dbidx' => 'domain_id:'.$i]);
        }

        // ids desc: [5,4,3,2,1] -> offset 2, limit 2 -> [3,2]
        $this->getJson('/api/v1/monitor/data-logs?limit=2&offset=2', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.limit', 2)
            ->assertJsonPath('meta.offset', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $ids[2])
            ->assertJsonPath('data.1.id', $ids[1]);
    }

    public function test_list_sorts_by_contract_field_names(): void
    {
        $first = $this->seedDataLog(['tstamp' => 1700000003]);
        $second = $this->seedDataLog(['tstamp' => 1700000001]);
        $third = $this->seedDataLog(['tstamp' => 1700000002]);

        // `id` is the contract's name for datalog_id.
        $this->getJson('/api/v1/monitor/data-logs?sort=id&order=asc', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.id', $first)
            ->assertJsonPath('data.1.id', $second)
            ->assertJsonPath('data.2.id', $third);

        $this->getJson('/api/v1/monitor/data-logs?sort=tstamp&order=asc', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.id', $second)
            ->assertJsonPath('data.1.id', $third)
            ->assertJsonPath('data.2.id', $first);

        // sort defaults to id — explicit order=asc must flip the direction.
        $this->getJson('/api/v1/monitor/data-logs?order=asc', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.id', $first);
    }

    public function test_list_rejects_bad_parameters_with_400_problem(): void
    {
        foreach ([
            'sort=evil_column',
            'sort=datalog_id',   // DB column name is not part of the contract enum
            'order=upwards',
            'limit=0',
            'limit=101',
            'limit=abc',
            'offset=-1',
            'server_id=abc',
            'action=x',
            'action=insert',
            'status=bogus',
            'start_date=notatime',
            'end_date=-5',
            'unprocessed_only=maybe',
        ] as $param) {
            $this->getJson('/api/v1/monitor/data-logs?'.$param, $this->authHeaders())
                ->assertStatus(400)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 400);
        }
    }

    // ------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------

    public function test_action_filter_uses_lowercase_values(): void
    {
        $insertId = $this->seedDataLog(['action' => 'i']);
        $this->seedDataLog(['action' => 'u']);
        $this->seedDataLog(['action' => 'd']);

        $this->getJson('/api/v1/monitor/data-logs?action=i', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $insertId)
            ->assertJsonPath('data.0.action', 'i');

        // Case-insensitive on input (legacy parity), stored/returned lowercase.
        $this->getJson('/api/v1/monitor/data-logs?action=I', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.action', 'i');
    }

    public function test_server_dbtable_and_status_filters(): void
    {
        $this->seedDataLog(['server_id' => 1, 'dbtable' => 'mail_domain', 'status' => 'ok']);
        $this->seedDataLog(['server_id' => 2, 'dbtable' => 'dns_soa', 'status' => 'error']);
        $this->seedDataLog(['server_id' => 2, 'dbtable' => 'dns_rr', 'status' => 'ok']);

        $this->getJson('/api/v1/monitor/data-logs?server_id=2', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/monitor/data-logs?dbtable=dns_soa', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.dbtable', 'dns_soa');

        $this->getJson('/api/v1/monitor/data-logs?status=error', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', 'error');

        $this->getJson('/api/v1/monitor/data-logs?server_id=2&dbtable=dns_rr', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.dbtable', 'dns_rr');
    }

    public function test_date_window_filter_is_inclusive(): void
    {
        $this->seedDataLog(['tstamp' => 100]);
        $inWindowLow = $this->seedDataLog(['tstamp' => 200]);
        $inWindowHigh = $this->seedDataLog(['tstamp' => 300]);
        $this->seedDataLog(['tstamp' => 400]);

        $this->getJson('/api/v1/monitor/data-logs?start_date=200&end_date=300&sort=tstamp&order=asc', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $inWindowLow)
            ->assertJsonPath('data.1.id', $inWindowHigh);
    }

    // ------------------------------------------------------------------
    // unprocessed_only (datalog-ID watermark, spec 004 gap G2)
    // ------------------------------------------------------------------

    public function test_unprocessed_only_filters_by_datalog_id_watermark(): void
    {
        // ids 1..4 on server 1; every tstamp (~1.7e9) dwarfs the watermark,
        // so the old tstamp>updated comparison would return ALL rows —
        // this proves the fixed datalog_id > server.updated semantics.
        $ids = [];
        foreach (range(1, 4) as $i) {
            $ids[] = $this->seedDataLog(['server_id' => 1, 'dbidx' => 'domain_id:'.$i]);
        }
        $otherServer = $this->seedDataLog(['server_id' => 2]);

        // Server 1 has processed the journal up to and including $ids[1].
        $this->seedServer(1, $ids[1]);
        $this->seedServer(2, 0);

        $this->getJson('/api/v1/monitor/data-logs?unprocessed_only=true&server_id=1&order=asc', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $ids[2])
            ->assertJsonPath('data.1.id', $ids[3]);

        // Watermark 0: everything for that server is still unprocessed.
        $this->getJson('/api/v1/monitor/data-logs?unprocessed_only=true&server_id=2', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $otherServer);

        // unprocessed_only=false behaves as if the flag were absent.
        $this->getJson('/api/v1/monitor/data-logs?unprocessed_only=false', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 5);
    }

    public function test_unprocessed_only_without_server_id_is_400_problem(): void
    {
        $this->seedDataLog();

        $this->getJson('/api/v1/monitor/data-logs?unprocessed_only=true', $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 400)
            ->assertJsonPath('detail', "The 'unprocessed_only' filter requires 'server_id'.");
    }

    // ------------------------------------------------------------------
    // Show
    // ------------------------------------------------------------------

    public function test_show_returns_entry_with_deserialized_data_object(): void
    {
        $id = $this->seedDataLog([
            'dbidx' => 'domain_id:42',
            'action' => 'i',
            'data' => serialize([
                'new' => ['domain' => 'example.com', 'active' => 'y'],
                'old' => ['domain' => null, 'active' => null],
            ]),
        ]);

        $response = $this->getJson('/api/v1/monitor/data-logs/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('dbtable', 'mail_domain')
            ->assertJsonPath('dbidx', 'domain_id:42')
            ->assertJsonPath('action', 'i')
            ->assertJsonPath('data.new.domain', 'example.com')
            ->assertJsonPath('data.new.active', 'y')
            ->assertJsonPath('data.old.domain', null)
            ->assertJsonPath('session_id', 'sess-1')
            ->assertJsonMissingPath('datalog_id');

        $this->assertIsInt($response->json('server_id'));
        $this->assertIsInt($response->json('tstamp'));
    }

    public function test_show_returns_corrupt_data_blob_verbatim(): void
    {
        $id = $this->seedDataLog(['data' => 'not-a-serialized-blob']);

        $this->getJson('/api/v1/monitor/data-logs/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data', 'not-a-serialized-blob');
    }

    public function test_show_returns_null_data_when_payload_empty(): void
    {
        $id = $this->seedDataLog(['data' => null]);

        $this->getJson('/api/v1/monitor/data-logs/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_show_missing_returns_404_problem(): void
    {
        $this->getJson('/api/v1/monitor/data-logs/999', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Not found', 'status' => 404]);
    }
}
