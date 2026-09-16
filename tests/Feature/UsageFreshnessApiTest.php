<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\UsageApiTestCase;

/**
 * The `freshness` block of GET /usage/summary (spec 043): how often each
 * collector runs, when it last ran, and when the next run is due — so a
 * consumer can explain a figure instead of printing a bare timestamp, and can
 * tell "never measured" from "measured hours ago and now considered stale".
 */
class UsageFreshnessApiTest extends UsageApiTestCase
{
    private const URL = '/api/v1/usage/summary';

    protected function setUp(): void
    {
        parent::setUp();

        // Client A: one website, one mailbox, one database — all on server 1.
        $this->site('clientA', 'a1.test', ['system_user' => 'web1', 'hd_quota' => 1024]);
        $this->mailbox('clientA', 'info@a1.test', 10);
        $this->database('clientA', 'c1a', 512);
    }

    /**
     * @return array<string, mixed>
     */
    private function freshness(string $tenant = 'clientA'): array
    {
        return $this->getJson(self::URL, $this->tenantHeaders($tenant))->assertOk()->json('freshness');
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(string $tenant = 'clientA'): array
    {
        return $this->getJson(self::URL, $this->tenantHeaders($tenant))->assertOk()->json();
    }

    public function test_summary_carries_freshness_for_the_collector_backed_metrics(): void
    {
        $this->blob(1, 'harddisk_quota', ['user' => ['web1' => ['used' => '100', 'soft' => '0', 'hard' => '0', 'files' => '1']]], 120);

        $freshness = $this->freshness();

        $this->assertSame(['web_disk', 'mail_storage', 'database_size'], array_keys($freshness));
        $this->assertSame(
            ['interval_seconds', 'stale_after_seconds', 'measured_at', 'next_expected_at'],
            array_keys($freshness['web_disk'])
        );
        // Traffic is summed from daily counters, not collected.
        $this->assertArrayNotHasKey('web_traffic_this_month', $freshness);
    }

    public function test_each_metric_reports_its_own_cadence_and_stale_age(): void
    {
        $freshness = $this->freshness();

        $this->assertSame(300, $freshness['web_disk']['interval_seconds']);
        $this->assertSame(1800, $freshness['web_disk']['stale_after_seconds']);
        $this->assertSame(900, $freshness['mail_storage']['interval_seconds']);
        $this->assertSame(3600, $freshness['mail_storage']['stale_after_seconds']);
        $this->assertSame(300, $freshness['database_size']['interval_seconds']);
        $this->assertSame(1800, $freshness['database_size']['stale_after_seconds']);
    }

    public function test_measured_at_is_the_collection_time_and_next_is_one_interval_later(): void
    {
        $this->blob(1, 'harddisk_quota', ['user' => ['web1' => ['used' => '100', 'soft' => '0', 'hard' => '0', 'files' => '1']]], 120);
        $this->blob(1, 'email_quota', ['info@a1.test' => ['used' => 1024]], 300);

        $freshness = $this->freshness();

        // Disk: collected 120 s ago, next run due 300 s after that (180 s from now).
        $this->assertSame($this->iso(120), $freshness['web_disk']['measured_at']);
        $this->assertSame($this->iso(-180), $freshness['web_disk']['next_expected_at']);

        // Mail: collected 300 s ago, 900 s cadence → due 600 s from now.
        $this->assertSame($this->iso(300), $freshness['mail_storage']['measured_at']);
        $this->assertSame($this->iso(-600), $freshness['mail_storage']['next_expected_at']);
    }

    public function test_oldest_contributing_server_decides_the_measured_time(): void
    {
        // A second website on another server, whose collector ran much earlier.
        $this->site('clientA', 'a2.test', ['system_user' => 'web2', 'server_id' => 2, 'hd_quota' => 512]);

        $this->blob(1, 'harddisk_quota', ['user' => ['web1' => ['used' => '100', 'soft' => '0', 'hard' => '0', 'files' => '1']]], 120);
        $this->blob(2, 'harddisk_quota', ['user' => ['web2' => ['used' => '200', 'soft' => '0', 'hard' => '0', 'files' => '2']]], 600);

        $freshness = $this->freshness();

        // The total is only as current as its oldest part, and the next run is
        // due one interval after THAT measurement (11:50 + 5 min), not after now.
        $this->assertSame($this->iso(600), $freshness['web_disk']['measured_at']);
        $this->assertSame($this->iso(300), $freshness['web_disk']['next_expected_at']);
    }

    public function test_a_metric_that_was_never_collected_reports_no_measurement(): void
    {
        // Only disk was collected; mail and databases never were.
        $this->blob(1, 'harddisk_quota', ['user' => ['web1' => ['used' => '100', 'soft' => '0', 'hard' => '0', 'files' => '1']]], 120);

        $freshness = $this->freshness();

        $this->assertNull($freshness['mail_storage']['measured_at']);
        $this->assertNull($freshness['mail_storage']['next_expected_at']);
        $this->assertNull($freshness['database_size']['measured_at']);
        $this->assertNull($freshness['database_size']['next_expected_at']);

        // The rules still describe the installation.
        $this->assertSame(900, $freshness['mail_storage']['interval_seconds']);
        $this->assertSame(3600, $freshness['mail_storage']['stale_after_seconds']);
    }

    public function test_stale_data_keeps_reporting_when_it_was_collected(): void
    {
        // Older than stale_after (1800 s) — the metric aged out.
        $this->blob(1, 'harddisk_quota', ['user' => ['web1' => ['used' => '100', 'soft' => '0', 'hard' => '0', 'files' => '1']]], 2000);

        $summary = $this->summary();

        // Unchanged behaviour: the figure itself is unknown.
        $this->assertNull($summary['web_disk']['used_bytes']);
        $this->assertNull($summary['web_disk']['measured_at']);

        // The point of the feature: the age is still visible.
        $this->assertSame($this->iso(2000), $summary['freshness']['web_disk']['measured_at']);
        $this->assertSame($this->iso(1700), $summary['freshness']['web_disk']['next_expected_at']);
    }

    public function test_never_measured_and_stale_are_distinguishable(): void
    {
        $this->blob(1, 'harddisk_quota', ['user' => ['web1' => ['used' => '100', 'soft' => '0', 'hard' => '0', 'files' => '1']]], 2000);

        $summary = $this->summary();

        // Both metrics report null …
        $this->assertNull($summary['web_disk']['used_bytes']);
        $this->assertNull($summary['database_size']['used_bytes']);

        // … but only one of them was ever measured.
        $this->assertNotNull($summary['freshness']['web_disk']['measured_at'], 'stale data must keep its timestamp');
        $this->assertNull($summary['freshness']['database_size']['measured_at'], 'never collected must stay null');
    }

    public function test_corrupt_blob_behaves_like_stale_data(): void
    {
        $this->blob(1, 'harddisk_quota', 'this is not serialized php', 120);

        $summary = $this->summary();

        $this->assertNull($summary['web_disk']['used_bytes'], 'a corrupt blob yields no figure');
        $this->assertSame($this->iso(120), $summary['freshness']['web_disk']['measured_at'], 'the row still says when it was written');
    }

    public function test_client_without_resources_reports_no_measurements(): void
    {
        // clientB owns nothing in this fixture.
        $freshness = $this->freshness('clientB');

        foreach (['web_disk', 'mail_storage', 'database_size'] as $metric) {
            $this->assertNull($freshness[$metric]['measured_at'], $metric);
            $this->assertNull($freshness[$metric]['next_expected_at'], $metric);
            $this->assertIsInt($freshness[$metric]['interval_seconds'], $metric);
            $this->assertIsInt($freshness[$metric]['stale_after_seconds'], $metric);
        }
    }

    public function test_freshness_adds_no_collector_query(): void
    {
        $this->blob(1, 'harddisk_quota', ['user' => ['web1' => ['used' => '100', 'soft' => '0', 'hard' => '0', 'files' => '1']]], 120);
        $this->blob(1, 'email_quota', ['info@a1.test' => ['used' => 1024]], 300);
        $this->blob(1, 'database_size', ['c1a' => ['used' => 4096]], 120);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(self::URL, $this->tenantHeaders('clientA'))->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $monitorQueries = array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'monitor_data'));

        $this->assertCount(1, $monitorQueries, 'the summary must read monitor_data exactly once, as before');
    }
}
