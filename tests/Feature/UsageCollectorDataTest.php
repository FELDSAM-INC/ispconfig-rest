<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\UsageApiTestCase;

/**
 * Collector data edge cases and read budget of the usage lists (spec 017
 * FR-011, FR-012; research R1, R2).
 */
class UsageCollectorDataTest extends UsageApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->site('clientA', 'a1.test', ['system_user' => 'web1']);
        $this->mailbox('clientA', 'info@a1.test', 1048576);
        $this->database('clientA', 'c1a', 10);
    }

    private function seedFreshBlobs(int $diskAge = 60, int $mailAge = 60, int $dbAge = 60): void
    {
        $this->blob(1, 'harddisk_quota', ['user' => ['web1' => ['used' => '100', 'soft' => '0', 'hard' => '0', 'files' => '1']]], $diskAge);
        $this->blob(1, 'email_quota', ['info@a1.test' => ['used' => 2048]], $mailAge);
        $this->blob(1, 'database_size', [['database_name' => 'c1a', 'size' => 4096]], $dbAge);
    }

    public function test_data_within_the_staleness_window_is_used(): void
    {
        $this->seedFreshBlobs(1799, 3599, 1799);

        $this->getAs('clientA', '/usage/web-domains')->assertOk()->assertJsonPath('data.0.disk.used_bytes', 102400);
        $this->getAs('clientA', '/usage/mail-users')->assertOk()->assertJsonPath('data.0.used_bytes', 2048);
        $this->getAs('clientA', '/usage/databases')->assertOk()->assertJsonPath('data.0.size_bytes', 4096);
    }

    public function test_stale_data_is_unknown(): void
    {
        $this->seedFreshBlobs(1801, 3601, 1801);

        $this->getAs('clientA', '/usage/web-domains')
            ->assertOk()
            ->assertJsonPath('data.0.disk.used_bytes', null)
            ->assertJsonPath('data.0.disk.measured_at', null);
        $this->getAs('clientA', '/usage/mail-users')
            ->assertOk()
            ->assertJsonPath('data.0.used_bytes', null)
            ->assertJsonPath('data.0.measured_at', null);
        $this->getAs('clientA', '/usage/databases')
            ->assertOk()
            ->assertJsonPath('data.0.size_bytes', null)
            ->assertJsonPath('data.0.measured_at', null);
    }

    public function test_missing_and_corrupt_data_is_unknown_not_an_error(): void
    {
        $this->getAs('clientA', '/usage/web-domains')->assertOk()->assertJsonPath('data.0.disk.used_bytes', null);

        $this->blob(1, 'harddisk_quota', 'garbage');
        $this->blob(1, 'email_quota', 'a:1:{truncated');
        $this->blob(1, 'database_size', 'O:8:"stdClass":0:{}');

        $this->getAs('clientA', '/usage/web-domains')->assertOk()->assertJsonPath('data.0.disk.used_bytes', null);
        $this->getAs('clientA', '/usage/mail-users')->assertOk()->assertJsonPath('data.0.used_bytes', null);
        $this->getAs('clientA', '/usage/databases')->assertOk()->assertJsonPath('data.0.size_bytes', null);
    }

    public function test_sites_read_only_their_own_servers_blob(): void
    {
        $this->site('clientA', 'x2.test', ['system_user' => 'web1', 'server_id' => 2]);

        $this->blob(1, 'harddisk_quota', ['user' => ['web1' => ['used' => '100']]]);
        $this->blob(2, 'harddisk_quota', ['user' => ['web1' => ['used' => '200']]]);

        $rows = collect($this->getAs('clientA', '/usage/web-domains')->assertOk()->json('data'))->keyBy('domain');

        $this->assertSame(102400, $rows['a1.test']['disk']['used_bytes']);
        $this->assertSame(204800, $rows['x2.test']['disk']['used_bytes']);
    }

    public function test_each_list_reads_collector_data_and_traffic_once_per_request(): void
    {
        foreach (range(1, 5) as $i) {
            $this->site('clientA', "s{$i}.test", ['system_user' => "web{$i}"]);
            $this->mailbox('clientA', "box{$i}@a1.test", 1048576);
            $this->database('clientA', "c1db{$i}", 10);
            $this->webTraffic("s{$i}.test", '2026-09-01', $i);
        }

        $this->seedFreshBlobs();

        $this->assertSame(['monitor_data' => 1, 'web_traffic' => 1, 'mail_traffic' => 0], $this->collectorQueries('/usage/web-domains'));
        $this->assertSame(['monitor_data' => 1, 'web_traffic' => 0, 'mail_traffic' => 1], $this->collectorQueries('/usage/mail-users'));
        $this->assertSame(['monitor_data' => 1, 'web_traffic' => 0, 'mail_traffic' => 0], $this->collectorQueries('/usage/databases'));
    }

    /**
     * @return array{monitor_data: int, web_traffic: int, mail_traffic: int}
     */
    private function collectorQueries(string $uri): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getAs('clientA', $uri)->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $count = fn (string $table): int => count(array_filter($queries, fn (string $sql): bool => str_contains($sql, $table)));

        return [
            'monitor_data' => $count('monitor_data'),
            'web_traffic' => $count('web_traffic'),
            'mail_traffic' => $count('mail_traffic'),
        ];
    }

    public function test_large_client_list_pages_answer_within_two_seconds(): void
    {
        $sites = $mailboxes = $databases = $traffic = $diskUsers = $emails = $sizes = [];

        foreach (range(1, 200) as $i) {
            $sites[] = $this->ownedBy('clientA', [
                'server_id' => 1, 'domain' => "big{$i}.test", 'type' => 'vhost', 'parent_domain_id' => 0,
                'system_user' => "webbig{$i}", 'hd_quota' => 100, 'traffic_quota' => -1, 'active' => 'y',
            ]);
            $traffic[] = ['hostname' => "big{$i}.test", 'traffic_date' => '2026-09-01', 'traffic_bytes' => $i];
            $diskUsers["webbig{$i}"] = ['used' => (string) $i, 'soft' => '0', 'hard' => '0', 'files' => '1'];
        }

        foreach (range(1, 500) as $i) {
            $mailboxes[] = $this->ownedBy('clientA', ['server_id' => 1, 'email' => "box{$i}@big.test", 'login' => "box{$i}@big.test", 'quota' => 1048576]);
            $emails["box{$i}@big.test"] = ['used' => $i];
        }

        foreach (range(1, 50) as $i) {
            $databases[] = $this->ownedBy('clientA', ['server_id' => 1, 'parent_domain_id' => 0, 'type' => 'mysql', 'database_name' => "c1big{$i}", 'database_quota' => 10]);
            $sizes[] = ['database_name' => "c1big{$i}", 'size' => $i];
        }

        foreach (array_chunk($sites, 50) as $chunk) {
            DB::table('web_domain')->insert($chunk);
        }
        foreach (array_chunk($mailboxes, 50) as $chunk) {
            DB::table('mail_user')->insert($chunk);
        }
        DB::table('web_database')->insert($databases);
        foreach (array_chunk($traffic, 50) as $chunk) {
            DB::table('web_traffic')->insert($chunk);
        }

        $this->blob(1, 'harddisk_quota', ['user' => $diskUsers]);
        $this->blob(1, 'email_quota', $emails);
        $this->blob(1, 'database_size', $sizes);

        foreach (['/usage/web-domains?limit=100', '/usage/mail-users?limit=100', '/usage/databases?limit=100', '/usage/summary'] as $uri) {
            $started = microtime(true);
            $this->getAs('clientA', $uri)->assertOk();
            $this->assertLessThan(2.0, microtime(true) - $started, $uri);
        }

        // The read budget of T028 holds at this size.
        $this->assertSame(['monitor_data' => 1, 'web_traffic' => 1, 'mail_traffic' => 0], $this->collectorQueries('/usage/web-domains?limit=100'));
        $this->assertSame(['monitor_data' => 1, 'web_traffic' => 0, 'mail_traffic' => 1], $this->collectorQueries('/usage/mail-users?limit=100'));
        $this->assertSame(['monitor_data' => 1, 'web_traffic' => 0, 'mail_traffic' => 0], $this->collectorQueries('/usage/databases?limit=100'));
    }
}
