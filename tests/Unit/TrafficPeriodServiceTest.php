<?php

namespace Tests\Unit;

use App\Services\TrafficPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\UsageSchema;
use Tests\TestCase;

/**
 * Calendar traffic periods in the API timezone (spec 017 FR-006, FR-010,
 * FR-015; research R5, R11).
 */
class TrafficPeriodServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        UsageSchema::create();
        config(['app.timezone' => 'Europe/Prague']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function now(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
    }

    private function web(string $hostname, string $date, int $bytes): void
    {
        DB::table('web_traffic')->insert(['hostname' => $hostname, 'traffic_date' => $date, 'traffic_bytes' => $bytes]);
    }

    private function mail(int $mailuserId, string $month, int $bytes): void
    {
        DB::table('mail_traffic')->insert(['mailuser_id' => $mailuserId, 'month' => $month, 'traffic' => $bytes]);
    }

    public function test_boundaries_use_the_api_timezone(): void
    {
        // 23:30 UTC on 31 August is already 1 September in Prague.
        $this->now('2026-08-31 23:30:00');

        $b = app(TrafficPeriodService::class)->boundaries();

        $this->assertSame('2026-09-01', $b['this_month_start']->format('Y-m-d'));
        $this->assertSame('2026-10-01', $b['next_month_start']->format('Y-m-d'));
        $this->assertSame('2026-08-01', $b['last_month_start']->format('Y-m-d'));
        $this->assertSame('2026-01-01', $b['this_year_start']->format('Y-m-d'));
        $this->assertSame('2027-01-01', $b['next_year_start']->format('Y-m-d'));
        $this->assertSame('2025-01-01', $b['last_year_start']->format('Y-m-d'));
        $this->assertSame('Europe/Prague', app(TrafficPeriodService::class)->timezone());
    }

    public function test_january_rollover_counts_december_as_last_month_and_last_year(): void
    {
        $this->now('2026-01-10 10:00:00');
        $this->web('example.com', '2025-12-20', 40);
        $this->web('example.com', '2026-01-05', 7);

        $periods = app(TrafficPeriodService::class)->webPeriods(['example.com']);

        $this->assertSame(['this_month' => 7, 'last_month' => 40, 'this_year' => 7, 'last_year' => 40], $periods['example.com']);
    }

    public function test_web_periods_are_grouped_by_hostname_in_one_query(): void
    {
        $this->now('2026-09-15 10:00:00');
        $this->web('a.example.com', '2026-09-10', 100);
        $this->web('a.example.com', '2026-08-31', 50);
        $this->web('a.example.com', '2026-02-01', 25);
        $this->web('a.example.com', '2025-12-31', 10);
        $this->web('a.example.com', '2024-06-01', 999);
        $this->web('other.example.com', '2026-09-10', 12345);

        DB::enableQueryLog();
        $periods = app(TrafficPeriodService::class)->webPeriods(['a.example.com', 'b.example.com']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertSame(['this_month' => 100, 'last_month' => 50, 'this_year' => 175, 'last_year' => 10], $periods['a.example.com']);
        $this->assertSame(['this_month' => 0, 'last_month' => 0, 'this_year' => 0, 'last_year' => 0], $periods['b.example.com']);
        $this->assertArrayNotHasKey('other.example.com', $periods);
    }

    public function test_mail_periods_are_grouped_by_mailbox_in_one_query(): void
    {
        $this->now('2026-09-15 10:00:00');
        $this->mail(7, '2026-09', 5);
        $this->mail(7, '2026-08', 3);
        $this->mail(7, '2026-01', 2);
        $this->mail(7, '2025-11', 1);
        $this->mail(8, '2026-09', 99);

        DB::enableQueryLog();
        $periods = app(TrafficPeriodService::class)->mailPeriods([7, 9]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertSame(['this_month' => 5, 'last_month' => 3, 'this_year' => 10, 'last_year' => 1], $periods[7]);
        $this->assertSame(['this_month' => 0, 'last_month' => 0, 'this_year' => 0, 'last_year' => 0], $periods[9]);
        $this->assertArrayNotHasKey(8, $periods);
    }

    public function test_empty_input_runs_no_query(): void
    {
        DB::enableQueryLog();
        $this->assertSame([], app(TrafficPeriodService::class)->webPeriods([]));
        $this->assertSame([], app(TrafficPeriodService::class)->mailPeriods([]));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queries);
    }

    public function test_monthly_web_history_is_zero_filled_oldest_first(): void
    {
        $this->now('2026-09-15 10:00:00');
        $this->web('example.com', '2026-07-05', 7);
        $this->web('example.com', '2026-09-01', 9);
        $this->web('example.com', '2026-06-30', 1000);

        $history = app(TrafficPeriodService::class)->webHistory('example.com', 3, 'month');

        $this->assertSame('month', $history['granularity']);
        $this->assertSame('2026-07-01', $history['period_start']);
        $this->assertSame('2026-10-01', $history['period_end']);
        $this->assertSame('Europe/Prague', $history['timezone']);
        $this->assertSame([
            ['period' => '2026-07', 'bytes' => 7],
            ['period' => '2026-08', 'bytes' => 0],
            ['period' => '2026-09', 'bytes' => 9],
        ], $history['points']);
    }

    public function test_monthly_history_bounds_one_and_thirty_six_months(): void
    {
        $this->now('2026-09-15 10:00:00');

        $service = app(TrafficPeriodService::class);

        $one = $service->webHistory('example.com', 1, 'month');
        $this->assertSame([['period' => '2026-09', 'bytes' => 0]], $one['points']);

        $many = $service->webHistory('example.com', 36, 'month');
        $this->assertCount(36, $many['points']);
        $this->assertSame('2023-10', $many['points'][0]['period']);
        $this->assertSame('2026-09', $many['points'][35]['period']);
    }

    public function test_daily_web_history_covers_the_current_month_up_to_yesterday(): void
    {
        $this->now('2026-09-04 10:00:00');
        $this->web('example.com', '2026-09-02', 11);
        $this->web('example.com', '2026-09-04', 99);
        $this->web('example.com', '2026-08-31', 5);

        $history = app(TrafficPeriodService::class)->webHistory('example.com', 12, 'day');

        $this->assertSame('day', $history['granularity']);
        $this->assertSame('2026-09-01', $history['period_start']);
        $this->assertSame('2026-09-04', $history['period_end']);
        $this->assertSame([
            ['period' => '2026-09-01', 'bytes' => 0],
            ['period' => '2026-09-02', 'bytes' => 11],
            ['period' => '2026-09-03', 'bytes' => 0],
        ], $history['points']);
    }

    public function test_daily_history_on_the_first_day_of_the_month_is_empty(): void
    {
        $this->now('2026-09-01 08:00:00');

        $history = app(TrafficPeriodService::class)->webHistory('example.com', 12, 'day');

        $this->assertSame('2026-09-01', $history['period_start']);
        $this->assertSame('2026-09-01', $history['period_end']);
        $this->assertSame([], $history['points']);
    }

    public function test_monthly_mail_history(): void
    {
        $this->now('2026-09-15 10:00:00');
        $this->mail(7, '2026-08', 3);
        $this->mail(7, '2026-09', 5);
        $this->mail(7, '2026-07', 100);

        $history = app(TrafficPeriodService::class)->mailHistory(7, 2);

        $this->assertSame('2026-08-01', $history['period_start']);
        $this->assertSame('2026-10-01', $history['period_end']);
        $this->assertSame([
            ['period' => '2026-08', 'bytes' => 3],
            ['period' => '2026-09', 'bytes' => 5],
        ], $history['points']);
    }
}
