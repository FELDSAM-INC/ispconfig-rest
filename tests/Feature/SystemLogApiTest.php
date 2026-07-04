<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MonitorCompletionSchema;
use Tests\TestCase;

/**
 * Monitor System-Logs endpoint (contract: api/modules/monitor/system-logs.yaml).
 * Read-only module — sys_log rows are seeded directly.
 */
class SystemLogApiTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'test-dev-key';

    protected function setUp(): void
    {
        parent::setUp();

        MonitorCompletionSchema::create();

        config(['api.dev_key' => self::KEY]);
    }

    protected function authHeaders(): array
    {
        return ['X-API-Key' => self::KEY];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function seedLog(array $overrides = []): int
    {
        return (int) DB::table('sys_log')->insertGetId(array_merge([
            'server_id' => 1,
            'datalog_id' => 0,
            'loglevel' => 0,
            'tstamp' => 1700000000,
            'message' => 'Processed datalog_id 0.',
        ], $overrides), 'syslog_id');
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_endpoint_requires_api_key(): void
    {
        $this->seedLog();

        $this->getJson('/api/v1/monitor/system-logs')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Unauthorized', 'status' => 401]);
    }

    // ------------------------------------------------------------------
    // List: envelope + default newest-first ordering
    // ------------------------------------------------------------------

    public function test_list_returns_data_meta_envelope_newest_first(): void
    {
        $oldest = $this->seedLog(['tstamp' => 1700000001, 'loglevel' => 1, 'datalog_id' => 54321]);
        $middle = $this->seedLog(['tstamp' => 1700000002]);
        $newest = $this->seedLog(['tstamp' => 1700000003, 'message' => 'Latest entry.']);

        $this->getJson('/api/v1/monitor/system-logs', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.limit', 25)
            ->assertJsonPath('meta.offset', 0)
            // Default sort=tstamp, order=desc — newest entry first.
            ->assertJsonPath('data.0.id', $newest)
            ->assertJsonPath('data.1.id', $middle)
            ->assertJsonPath('data.2.id', $oldest)
            ->assertJsonPath('data.0.tstamp', 1700000003)
            ->assertJsonPath('data.0.message', 'Latest entry.')
            ->assertJsonPath('data.0.server_id', 1)
            // datalog_id is the bridge to /monitor/data-logs
            ->assertJsonPath('data.2.datalog_id', 54321)
            ->assertJsonPath('data.2.loglevel', 1)
            // The primary key is exposed as `id`, never as syslog_id.
            ->assertJsonMissingPath('data.0.syslog_id');
    }

    public function test_message_is_nullable(): void
    {
        $this->seedLog(['message' => null]);

        $this->getJson('/api/v1/monitor/system-logs', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.message', null);
    }

    public function test_list_pagination_bounds(): void
    {
        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[] = $this->seedLog(['tstamp' => 1700000000 + $i]);
        }

        // tstamp desc: [5,4,3,2,1] -> offset 2, limit 2 -> [3,2]
        $this->getJson('/api/v1/monitor/system-logs?limit=2&offset=2', $this->authHeaders())
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
        $first = $this->seedLog(['tstamp' => 1700000003, 'loglevel' => 2]);
        $second = $this->seedLog(['tstamp' => 1700000001, 'loglevel' => 0]);
        $third = $this->seedLog(['tstamp' => 1700000002, 'loglevel' => 1]);

        // `id` is the contract's name for syslog_id.
        $this->getJson('/api/v1/monitor/system-logs?sort=id&order=asc', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.id', $first)
            ->assertJsonPath('data.1.id', $second)
            ->assertJsonPath('data.2.id', $third);

        $this->getJson('/api/v1/monitor/system-logs?sort=loglevel&order=asc', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.id', $second)
            ->assertJsonPath('data.1.id', $third)
            ->assertJsonPath('data.2.id', $first);

        // order defaults to desc even with an explicit sort column.
        $this->getJson('/api/v1/monitor/system-logs?sort=id', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.0.id', $third);
    }

    // ------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------

    public function test_loglevel_filter_matches_enum_values(): void
    {
        $debug = $this->seedLog(['loglevel' => 0]);
        $warning = $this->seedLog(['loglevel' => 1]);
        $error = $this->seedLog(['loglevel' => 2]);

        $this->getJson('/api/v1/monitor/system-logs?loglevel=2', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $error)
            ->assertJsonPath('data.0.loglevel', 2);

        // 0 (Debug) is a valid enum value, not "empty".
        $this->getJson('/api/v1/monitor/system-logs?loglevel=0', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $debug);

        $this->getJson('/api/v1/monitor/system-logs?loglevel=1', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $warning);
    }

    public function test_server_id_filter(): void
    {
        $this->seedLog(['server_id' => 1]);
        $wanted = $this->seedLog(['server_id' => 2, 'tstamp' => 1700000002]);
        $this->seedLog(['server_id' => 2, 'tstamp' => 1700000001, 'loglevel' => 2]);

        $this->getJson('/api/v1/monitor/system-logs?server_id=2', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $wanted);

        // Filters combine.
        $this->getJson('/api/v1/monitor/system-logs?server_id=2&loglevel=2', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.loglevel', 2);
    }

    public function test_date_window_filter_is_inclusive(): void
    {
        $this->seedLog(['tstamp' => 100]);
        $inWindowLow = $this->seedLog(['tstamp' => 200]);
        $inWindowHigh = $this->seedLog(['tstamp' => 300]);
        $this->seedLog(['tstamp' => 400]);

        $this->getJson('/api/v1/monitor/system-logs?start_date=200&end_date=300&sort=tstamp&order=asc', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $inWindowLow)
            ->assertJsonPath('data.1.id', $inWindowHigh);
    }

    // ------------------------------------------------------------------
    // Validation (400 problem+json per the contract)
    // ------------------------------------------------------------------

    public function test_list_rejects_bad_parameters_with_400_problem(): void
    {
        $this->seedLog();

        foreach ([
            'loglevel=abc',
            'loglevel=3',      // outside the 0/1/2 enum
            'loglevel=-1',
            'sort=evil_column',
            'sort=syslog_id',  // DB column name is not part of the contract enum
            'order=upwards',
            'limit=0',
            'limit=101',
            'limit=abc',
            'offset=-1',
            'server_id=abc',
            'start_date=notatime',
            'end_date=-5',
        ] as $param) {
            $this->getJson('/api/v1/monitor/system-logs?'.$param, $this->authHeaders())
                ->assertStatus(400)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 400);
        }
    }

    // ------------------------------------------------------------------
    // Read-only guarantee (SC-005)
    // ------------------------------------------------------------------

    public function test_endpoint_produces_no_datalog_entries(): void
    {
        \Tests\Support\MonitorSchema::create(); // provides sys_datalog

        $this->seedLog();

        $this->getJson('/api/v1/monitor/system-logs', $this->authHeaders())->assertOk();
        $this->getJson('/api/v1/monitor/system-logs?loglevel=abc', $this->authHeaders())->assertStatus(400);

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }
}
