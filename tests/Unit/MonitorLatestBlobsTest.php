<?php

namespace Tests\Unit;

use App\Services\MonitorDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MonitorCompletionSchema;
use Tests\TestCase;

/**
 * MonitorDataService::latestBlobs() — the newest decoded collector blob per
 * (server, type), read with one query for all requested types and servers
 * (spec 017 research R1, FR-012).
 */
class MonitorLatestBlobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MonitorCompletionSchema::create();
    }

    private function seedBlob(int $serverId, string $type, int $created, array|string|null $data): void
    {
        DB::table('monitor_data')->insert([
            'server_id' => $serverId,
            'type' => $type,
            'created' => $created,
            'data' => is_array($data) ? serialize($data) : $data,
            'state' => 'ok',
        ]);
    }

    public function test_newest_row_per_server_and_type_wins(): void
    {
        $this->seedBlob(1, 'harddisk_quota', 100, ['user' => ['web1' => ['used' => '1']]]);
        $this->seedBlob(1, 'harddisk_quota', 200, ['user' => ['web1' => ['used' => '2']]]);
        $this->seedBlob(1, 'email_quota', 150, ['info@example.com' => ['used' => 1024]]);
        $this->seedBlob(2, 'harddisk_quota', 300, ['user' => ['web1' => ['used' => '3']]]);
        $this->seedBlob(1, 'server_load', 500, ['load_1' => 0.1]);

        $blobs = app(MonitorDataService::class)->latestBlobs(['harddisk_quota', 'email_quota'], [1, 2]);

        $this->assertSame(200, $blobs[1]['harddisk_quota']['created']);
        $this->assertSame('2', $blobs[1]['harddisk_quota']['data']['user']['web1']['used']);
        $this->assertSame(1024, $blobs[1]['email_quota']['data']['info@example.com']['used']);
        $this->assertSame('3', $blobs[2]['harddisk_quota']['data']['user']['web1']['used']);
        $this->assertArrayNotHasKey('server_load', $blobs[1]);
    }

    public function test_reads_all_types_and_servers_with_one_query(): void
    {
        $this->seedBlob(1, 'harddisk_quota', 100, ['user' => []]);
        $this->seedBlob(2, 'database_size', 100, []);

        DB::enableQueryLog();
        app(MonitorDataService::class)->latestBlobs(['harddisk_quota', 'email_quota', 'database_size'], [1, 2, 3]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
    }

    public function test_undecodable_and_object_blobs_yield_null_data(): void
    {
        $this->seedBlob(1, 'harddisk_quota', 100, 'not a serialized array');
        $this->seedBlob(1, 'database_size', 100, 'O:8:"stdClass":1:{s:1:"a";i:1;}');

        $blobs = app(MonitorDataService::class)->latestBlobs(['harddisk_quota', 'database_size'], [1]);

        $this->assertNull($blobs[1]['harddisk_quota']['data']);
        $this->assertSame(100, $blobs[1]['harddisk_quota']['created']);
        $this->assertNull($blobs[1]['database_size']['data']);
    }

    public function test_unknown_server_is_absent_and_empty_input_runs_no_query(): void
    {
        $this->seedBlob(1, 'harddisk_quota', 100, ['user' => []]);

        $service = app(MonitorDataService::class);

        $this->assertArrayNotHasKey(9, $service->latestBlobs(['harddisk_quota'], [1, 9]));

        DB::enableQueryLog();
        $this->assertSame([], $service->latestBlobs([], [1]));
        $this->assertSame([], $service->latestBlobs(['harddisk_quota'], []));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queries);
    }
}
