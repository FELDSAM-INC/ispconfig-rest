<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\MonitorCompletionSchema;
use Tests\Support\MonitorSchema;
use Tests\TestCase;

/**
 * Monitor Server-Status endpoints (contract: api/modules/monitor/server-status.yaml).
 * Read-only, computed resource — monitor_data rows are seeded with blobs
 * shaped exactly like the live ISPConfig collectors write them (fixtures
 * extracted from a real panel's monitor_data table, hostnames genericized).
 */
class ServerStatusApiTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'test-dev-key';

    protected function setUp(): void
    {
        parent::setUp();

        MonitorSchema::create();           // server (+ shared infra tables)
        MonitorCompletionSchema::create(); // monitor_data, sys_log

        config(['api.dev_key' => self::KEY]);
    }

    protected function authHeaders(): array
    {
        return ['X-API-Key' => self::KEY];
    }

    protected function seedServer(int $serverId, string $name): void
    {
        DB::table('server')->insert([
            'server_id' => $serverId,
            'server_name' => $name,
            'updated' => 0,
            'mirror_server_id' => 0,
            'active' => 1,
        ]);
    }

    /**
     * @param  array<mixed>|string|null  $data  arrays are serialize()d like the collectors do
     */
    protected function seedMonitorData(int $serverId, string $type, int $created, array|string|null $data, string $state): void
    {
        DB::table('monitor_data')->insert([
            'server_id' => $serverId,
            'type' => $type,
            'created' => $created,
            'data' => is_array($data) ? serialize($data) : $data,
            'state' => $state,
        ]);
    }

    // ------------------------------------------------------------------
    // Real collector blob shapes (extracted from a live panel; see
    // 100-monitor_server.inc.php, monitor_tools::monitorServices(),
    // 100-monitor_disk_usage.inc.php, 100-monitor_mem_usage.inc.php)
    // ------------------------------------------------------------------

    /**
     * server_load: up_* fields are floats, uptime is the raw `uptime`
     * output string, loads are floats.
     */
    protected function serverLoadBlob(): array
    {
        return [
            'up_days' => 155.0,
            'up_hours' => 4.0,
            'up_minutes' => 18.0,
            'uptime' => " 20:35:01 up 155 days,  4:18,  2 users,  load average: 0.00, 0.02, 0.19\n",
            'user_online' => 2,
            'load_1' => 0.0,
            'load_5' => 0.02,
            'load_15' => 0.19,
        ];
    }

    /**
     * mem_usage: /proc/meminfo map, values in bytes (real map carries
     * ~55 keys; the composition only reads MemTotal/MemAvailable).
     */
    protected function memUsageBlob(): array
    {
        return [
            'MemTotal' => 8326975488,
            'MemFree' => 2333450240,
            'MemAvailable' => 5959340032,
            'Buffers' => 45223936,
            'Cached' => 3387944960,
            'SwapCached' => 0,
            'SwapTotal' => 0,
            'SwapFree' => 0,
        ];
    }

    /**
     * disk_usage: df -PhT rows, 1-indexed, percent as raw df string.
     */
    protected function diskUsageBlob(): array
    {
        return [
            1 => ['fs' => 'tmpfs', 'type' => 'tmpfs', 'size' => '795M', 'used' => '79M', 'available' => '716M', 'percent' => '10%', 'mounted' => '/run'],
            2 => ['fs' => '/dev/sda1', 'type' => 'ext4', 'size' => '19G', 'used' => '5.6G', 'available' => '13G', 'percent' => '31%', 'mounted' => '/'],
            3 => ['fs' => '/dev/sda16', 'type' => 'ext4', 'size' => '881M', 'used' => '300M', 'available' => '520M', 'percent' => '37%', 'mounted' => '/boot'],
            4 => ['fs' => '/dev/sda15', 'type' => 'vfat', 'size' => '105M', 'used' => '6.2M', 'available' => '99M', 'percent' => '6%', 'mounted' => '/boot/efi'],
        ];
    }

    /**
     * services: the live blob carries only 7 keys — no mongodbserver —
     * so the missing key must surface as null (not monitored).
     */
    protected function servicesBlob(): array
    {
        return [
            'webserver' => 1,
            'ftpserver' => 1,
            'smtpserver' => 1,
            'pop3server' => 1,
            'imapserver' => 1,
            'bindserver' => 1,
            'mysqlserver' => 1,
        ];
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        $this->seedServer(1, 'server1.example.com');

        $this->getJson('/api/v1/monitor/servers/status')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Unauthorized', 'status' => 401]);

        $this->getJson('/api/v1/monitor/servers/1/status')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Composition from real-shaped blobs
    // ------------------------------------------------------------------

    public function test_status_is_composed_from_live_shaped_blobs(): void
    {
        $this->seedServer(1, 'server1.example.com');
        $this->seedMonitorData(1, 'server_load', 1783190101, $this->serverLoadBlob(), 'ok');
        $this->seedMonitorData(1, 'mem_usage', 1783190101, $this->memUsageBlob(), 'no_state');
        $this->seedMonitorData(1, 'disk_usage', 1783190101, $this->diskUsageBlob(), 'ok');
        $this->seedMonitorData(1, 'services', 1783190221, $this->servicesBlob(), 'ok');

        $this->getJson('/api/v1/monitor/servers/1/status', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('server_id', 1)
            ->assertJsonPath('server_name', 'server1.example.com')
            ->assertJsonPath('status', 'ok')
            // load_average = [load_1, load_5, load_15]; JSON drops the zero
            // fraction on 0.0, so it decodes as integer 0
            ->assertJsonPath('load_average', [0, 0.02, 0.19])
            // uptime = 155d 4h 18m in seconds (minute resolution)
            ->assertJsonPath('uptime', 155 * 86400 + 4 * 3600 + 18 * 60)
            // (MemTotal - MemAvailable) / MemTotal * 100 = 28.43 %
            ->assertJsonPath('memory_usage', 28.43)
            // df rows re-indexed, percent parsed to a number
            ->assertJsonCount(4, 'disk_usage')
            ->assertJsonPath('disk_usage.1.fs', '/dev/sda1')
            ->assertJsonPath('disk_usage.1.type', 'ext4')
            ->assertJsonPath('disk_usage.1.size', '19G')
            ->assertJsonPath('disk_usage.1.used', '5.6G')
            ->assertJsonPath('disk_usage.1.available', '13G')
            ->assertJsonPath('disk_usage.1.percent', 31)
            ->assertJsonPath('disk_usage.1.mounted', '/')
            // 1 => true; key absent from the live blob => null
            ->assertJsonPath('services.webserver', true)
            ->assertJsonPath('services.bindserver', true)
            ->assertJsonPath('services.mysqlserver', true)
            ->assertJsonPath('services.mongodbserver', null)
            // MAX(created) across all types, ISO 8601
            ->assertJsonPath('last_updated', Carbon::createFromTimestamp(1783190221)->toIso8601String());
    }

    public function test_service_flags_map_running_stopped_unmonitored(): void
    {
        // US2 scenario 3: webserver=1, smtpserver=0, bindserver=-1
        $blob = array_merge($this->servicesBlob(), [
            'smtpserver' => 0,
            'bindserver' => -1,
            'mongodbserver' => -1,
        ]);

        $this->seedServer(1, 'server1.example.com');
        $this->seedMonitorData(1, 'services', 1783190101, $blob, 'error');

        $this->getJson('/api/v1/monitor/servers/1/status', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('services.webserver', true)
            ->assertJsonPath('services.smtpserver', false)
            ->assertJsonPath('services.bindserver', null)
            ->assertJsonPath('services.mongodbserver', null)
            // a down monitored service carries state error => status error
            ->assertJsonPath('status', 'error');
    }

    // ------------------------------------------------------------------
    // State aggregation (legacy _setState ladder + 7->4 enum mapping)
    // ------------------------------------------------------------------

    public function test_status_maps_seven_state_enum_onto_contract_enum(): void
    {
        // Aggregate = highest-severity state (seeded at 'ok'), then mapped:
        // ok->ok, info->ok, warning->warning, critical->error, error->error,
        // unknown->unknown; no_state never outranks the 'ok' seed (legacy).
        $expectations = [
            'ok' => 'ok',
            'info' => 'ok',
            'warning' => 'warning',
            'critical' => 'error',
            'error' => 'error',
            'unknown' => 'unknown',
            'no_state' => 'ok',
        ];

        $serverId = 0;
        foreach ($expectations as $dbState => $apiStatus) {
            $serverId++;
            $this->seedServer($serverId, sprintf('server%d.example.com', $serverId));
            $this->seedMonitorData($serverId, 'disk_usage', 1783190101, $this->diskUsageBlob(), $dbState);

            $this->getJson('/api/v1/monitor/servers/'.$serverId.'/status', $this->authHeaders())
                ->assertOk()
                ->assertJsonPath('status', $apiStatus);
        }
    }

    public function test_status_takes_highest_severity_and_skips_openvz_beancounter(): void
    {
        $this->seedServer(1, 'server1.example.com');
        $this->seedMonitorData(1, 'services', 1783190101, $this->servicesBlob(), 'ok');
        $this->seedMonitorData(1, 'disk_usage', 1783190101, $this->diskUsageBlob(), 'warning');
        // legacy ignores this type's state entirely — its 'error' must not win
        $this->seedMonitorData(1, 'openvz_beancounter', 1783190101, ['failcnt' => 12], 'error');

        $this->getJson('/api/v1/monitor/servers/1/status', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('status', 'warning');
    }

    public function test_newest_row_per_type_wins(): void
    {
        // ~240 s retention means several rows per type can coexist; only
        // the newest row's blob and state may be used.
        $oldLoad = array_merge($this->serverLoadBlob(), ['load_1' => 9.99, 'load_5' => 9.99, 'load_15' => 9.99]);

        $this->seedServer(1, 'server1.example.com');
        $this->seedMonitorData(1, 'server_load', 1783190041, $oldLoad, 'error');
        $this->seedMonitorData(1, 'server_load', 1783190101, $this->serverLoadBlob(), 'ok');

        $this->getJson('/api/v1/monitor/servers/1/status', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('load_average', [0, 0.02, 0.19])
            ->assertJsonPath('last_updated', Carbon::createFromTimestamp(1783190101)->toIso8601String());
    }

    // ------------------------------------------------------------------
    // Missing / corrupt data (FR-003, FR-017)
    // ------------------------------------------------------------------

    public function test_server_without_monitor_data_is_unknown_with_null_metrics(): void
    {
        $this->seedServer(1, 'server1.example.com');

        $this->getJson('/api/v1/monitor/servers/1/status', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('server_id', 1)
            ->assertJsonPath('server_name', 'server1.example.com')
            ->assertJsonPath('status', 'unknown')
            ->assertJsonPath('load_average', null)
            ->assertJsonPath('uptime', null)
            ->assertJsonPath('memory_usage', null)
            ->assertJsonPath('disk_usage', null)
            ->assertJsonPath('services', null)
            ->assertJsonPath('last_updated', null);
    }

    public function test_missing_types_yield_null_fields_only(): void
    {
        $this->seedServer(1, 'server1.example.com');
        $this->seedMonitorData(1, 'services', 1783190101, $this->servicesBlob(), 'ok');

        $this->getJson('/api/v1/monitor/servers/1/status', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('services.webserver', true)
            ->assertJsonPath('load_average', null)
            ->assertJsonPath('uptime', null)
            ->assertJsonPath('memory_usage', null)
            ->assertJsonPath('disk_usage', null);
    }

    public function test_corrupt_blob_yields_null_fields_not_500(): void
    {
        $this->seedServer(1, 'server1.example.com');
        $this->seedMonitorData(1, 'server_load', 1783190101, 'a:8:{s:7:"up_days";d:155;TRUNCATED', 'ok');
        $this->seedMonitorData(1, 'services', 1783190101, $this->servicesBlob(), 'ok');

        $this->getJson('/api/v1/monitor/servers/1/status', $this->authHeaders())
            ->assertOk()
            // state column is intact, so the corrupt blob only nulls its fields
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('load_average', null)
            ->assertJsonPath('uptime', null)
            ->assertJsonPath('services.webserver', true);
    }

    // ------------------------------------------------------------------
    // List: envelope, fixed server_name ordering, pagination
    // ------------------------------------------------------------------

    public function test_list_returns_data_meta_envelope_ordered_by_server_name(): void
    {
        $this->seedServer(1, 'web3.example.com');
        $this->seedServer(2, 'web1.example.com');
        $this->seedServer(3, 'web2.example.com');
        $this->seedMonitorData(2, 'services', 1783190101, $this->servicesBlob(), 'ok');

        $this->getJson('/api/v1/monitor/servers/status', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.limit', 25)
            ->assertJsonPath('meta.offset', 0)
            ->assertJsonCount(3, 'data')
            // fixed server_name ascending — no sort/order parameters exist
            ->assertJsonPath('data.0.server_name', 'web1.example.com')
            ->assertJsonPath('data.1.server_name', 'web2.example.com')
            ->assertJsonPath('data.2.server_name', 'web3.example.com')
            ->assertJsonPath('data.0.status', 'ok')
            // servers without monitor data still appear (US1 scenario 3)
            ->assertJsonPath('data.1.status', 'unknown')
            ->assertJsonPath('data.1.load_average', null);
    }

    public function test_list_pagination_bounds(): void
    {
        foreach (range(1, 5) as $i) {
            $this->seedServer($i, sprintf('server%d.example.com', $i));
        }

        $this->getJson('/api/v1/monitor/servers/status?limit=2&offset=2', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.limit', 2)
            ->assertJsonPath('meta.offset', 2)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.server_name', 'server3.example.com')
            ->assertJsonPath('data.1.server_name', 'server4.example.com');
    }

    public function test_list_rejects_bad_parameters_with_400_problem(): void
    {
        $this->seedServer(1, 'server1.example.com');

        foreach (['limit=0', 'limit=101', 'limit=abc', 'offset=-1'] as $param) {
            $this->getJson('/api/v1/monitor/servers/status?'.$param, $this->authHeaders())
                ->assertStatus(400)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 400);
        }
    }

    // ------------------------------------------------------------------
    // Show: bare object + 404
    // ------------------------------------------------------------------

    public function test_show_returns_bare_object_not_wrapped_in_data(): void
    {
        $this->seedServer(7, 'server7.example.com');

        $this->getJson('/api/v1/monitor/servers/7/status', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('server_id', 7)
            ->assertJsonMissingPath('data')
            ->assertJsonMissingPath('meta');
    }

    public function test_show_missing_server_returns_404_problem(): void
    {
        $this->getJson('/api/v1/monitor/servers/999/status', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 404)
            ->assertJsonPath('detail', 'Server not found.');
    }

    // ------------------------------------------------------------------
    // Read-only guarantee (SC-005)
    // ------------------------------------------------------------------

    public function test_endpoints_produce_no_datalog_entries(): void
    {
        $this->seedServer(1, 'server1.example.com');
        $this->seedMonitorData(1, 'services', 1783190101, $this->servicesBlob(), 'ok');

        $this->getJson('/api/v1/monitor/servers/status', $this->authHeaders())->assertOk();
        $this->getJson('/api/v1/monitor/servers/1/status', $this->authHeaders())->assertOk();
        $this->getJson('/api/v1/monitor/servers/999/status', $this->authHeaders())->assertStatus(404);

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }
}
