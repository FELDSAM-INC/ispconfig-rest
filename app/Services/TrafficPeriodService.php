<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Calendar traffic periods and history for the usage module (spec 017 FR-006,
 * FR-010; research R5, R11).
 *
 * ISPConfig writes `web_traffic.traffic_date` (DATE, per day) and
 * `mail_traffic.month` ('YYYY-MM') in the server's local time, so every period
 * boundary is computed in the API timezone (`config('app.timezone')`, aligned
 * with the server by FR-015). Period sums for a whole page of rows use one
 * grouped `SUM(CASE …)` query per table, which works on MySQL and on the sqlite
 * test database. Missing rows mean no traffic: 0, never null.
 */
class TrafficPeriodService
{
    public function timezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    /**
     * @return array{this_month_start: CarbonImmutable, next_month_start: CarbonImmutable, last_month_start: CarbonImmutable, this_year_start: CarbonImmutable, next_year_start: CarbonImmutable, last_year_start: CarbonImmutable}
     */
    public function boundaries(): array
    {
        $now = Carbon::now($this->timezone())->toImmutable();
        $thisMonth = $now->startOfMonth();
        $thisYear = $now->startOfYear();

        return [
            'this_month_start' => $thisMonth,
            'next_month_start' => $thisMonth->addMonthNoOverflow(),
            'last_month_start' => $thisMonth->subMonthNoOverflow(),
            'this_year_start' => $thisYear,
            'next_year_start' => $thisYear->addYear(),
            'last_year_start' => $thisYear->subYear(),
        ];
    }

    /**
     * @return array{this_month: int, last_month: int, this_year: int, last_year: int}
     */
    public static function emptyPeriods(): array
    {
        return ['this_month' => 0, 'last_month' => 0, 'this_year' => 0, 'last_year' => 0];
    }

    /**
     * This month / last month / this year / last year web traffic per hostname.
     *
     * @param  array<int, string>  $hostnames
     * @return array<string, array{this_month: int, last_month: int, this_year: int, last_year: int}>
     */
    public function webPeriods(array $hostnames): array
    {
        $hostnames = array_values(array_unique(array_filter(array_map('strval', $hostnames), fn (string $h): bool => $h !== '')));

        if ($hostnames === []) {
            return [];
        }

        $b = $this->formatted('Y-m-d');
        $sum = $this->sumCase('traffic_date', 'traffic_bytes');

        $rows = DB::table('web_traffic')
            ->select('hostname')
            ->selectRaw($sum.' AS this_month', [$b['this_month_start'], $b['next_month_start']])
            ->selectRaw($sum.' AS last_month', [$b['last_month_start'], $b['this_month_start']])
            ->selectRaw($sum.' AS this_year', [$b['this_year_start'], $b['next_year_start']])
            ->selectRaw($sum.' AS last_year', [$b['last_year_start'], $b['this_year_start']])
            ->whereIn('hostname', $hostnames)
            ->where('traffic_date', '>=', $b['last_year_start'])
            ->groupBy('hostname')
            ->get();

        $periods = array_fill_keys($hostnames, self::emptyPeriods());

        foreach ($rows as $row) {
            $periods[(string) $row->hostname] = $this->periodRow($row);
        }

        return $periods;
    }

    /**
     * This month / last month / this year / last year mail traffic per mailbox.
     *
     * @param  array<int, int|string>  $mailuserIds
     * @return array<int, array{this_month: int, last_month: int, this_year: int, last_year: int}>
     */
    public function mailPeriods(array $mailuserIds): array
    {
        $mailuserIds = array_values(array_unique(array_filter(array_map('intval', $mailuserIds), fn (int $id): bool => $id > 0)));

        if ($mailuserIds === []) {
            return [];
        }

        $b = $this->formatted('Y-m');
        $sum = $this->sumCase('month', 'traffic');

        $rows = DB::table('mail_traffic')
            ->select('mailuser_id')
            ->selectRaw($sum.' AS this_month', [$b['this_month_start'], $b['next_month_start']])
            ->selectRaw($sum.' AS last_month', [$b['last_month_start'], $b['this_month_start']])
            ->selectRaw($sum.' AS this_year', [$b['this_year_start'], $b['next_year_start']])
            ->selectRaw($sum.' AS last_year', [$b['last_year_start'], $b['this_year_start']])
            ->whereIn('mailuser_id', $mailuserIds)
            ->where('month', '>=', $b['last_year_start'])
            ->groupBy('mailuser_id')
            ->get();

        $periods = array_fill_keys($mailuserIds, self::emptyPeriods());

        foreach ($rows as $row) {
            $periods[(int) $row->mailuser_id] = $this->periodRow($row);
        }

        return $periods;
    }

    /**
     * Monthly points (oldest first, zero-filled) or daily points of the current
     * month up to yesterday for one website.
     *
     * @return array{granularity: string, period_start: string, period_end: string, timezone: string, points: array<int, array{period: string, bytes: int}>}
     */
    public function webHistory(string $hostname, int $months = 12, string $granularity = 'month'): array
    {
        $b = $this->boundaries();
        $grammar = DB::getQueryGrammar();

        if ($granularity === 'day') {
            $start = $b['this_month_start'];
            $end = Carbon::now($this->timezone())->toImmutable()->startOfDay();

            $bytes = DB::table('web_traffic')
                ->selectRaw($grammar->wrap('traffic_date').' AS period, SUM('.$grammar->wrap('traffic_bytes').') AS bytes')
                ->where('hostname', $hostname)
                ->where('traffic_date', '>=', $start->format('Y-m-d'))
                ->where('traffic_date', '<', $end->format('Y-m-d'))
                ->groupBy('traffic_date')
                ->pluck('bytes', 'period');

            $points = [];

            for ($day = $start; $day < $end; $day = $day->addDay()) {
                $key = $day->format('Y-m-d');
                $points[] = ['period' => $key, 'bytes' => (int) ($bytes[$key] ?? 0)];
            }

            return $this->history('day', $start, $end, $points);
        }

        $start = $b['this_month_start']->subMonthsNoOverflow($months - 1);
        $end = $b['next_month_start'];
        $expression = 'SUBSTR('.$grammar->wrap('traffic_date').', 1, 7)';

        $bytes = DB::table('web_traffic')
            ->selectRaw($expression.' AS period, SUM('.$grammar->wrap('traffic_bytes').') AS bytes')
            ->where('hostname', $hostname)
            ->where('traffic_date', '>=', $start->format('Y-m-d'))
            ->where('traffic_date', '<', $end->format('Y-m-d'))
            ->groupByRaw($expression)
            ->pluck('bytes', 'period');

        return $this->history('month', $start, $end, $this->monthlyPoints($start, $months, $bytes->all()));
    }

    /**
     * Monthly points (oldest first, zero-filled) for one mailbox.
     *
     * @return array{granularity: string, period_start: string, period_end: string, timezone: string, points: array<int, array{period: string, bytes: int}>}
     */
    public function mailHistory(int $mailuserId, int $months = 12): array
    {
        $b = $this->boundaries();
        $start = $b['this_month_start']->subMonthsNoOverflow($months - 1);
        $end = $b['next_month_start'];

        $bytes = DB::table('mail_traffic')
            ->selectRaw(DB::getQueryGrammar()->wrap('month').' AS period, SUM('.DB::getQueryGrammar()->wrap('traffic').') AS bytes')
            ->where('mailuser_id', $mailuserId)
            ->where('month', '>=', $start->format('Y-m'))
            ->where('month', '<', $end->format('Y-m'))
            ->groupBy('month')
            ->pluck('bytes', 'period');

        return $this->history('month', $start, $end, $this->monthlyPoints($start, $months, $bytes->all()));
    }

    /**
     * @return array<string, string>
     */
    private function formatted(string $format): array
    {
        return array_map(fn (CarbonImmutable $date): string => $date->format($format), $this->boundaries());
    }

    private function sumCase(string $column, string $sumColumn): string
    {
        $grammar = DB::getQueryGrammar();
        $column = $grammar->wrap($column);

        return 'SUM(CASE WHEN '.$column.' >= ? AND '.$column.' < ? THEN '.$grammar->wrap($sumColumn).' ELSE 0 END)';
    }

    /**
     * @return array{this_month: int, last_month: int, this_year: int, last_year: int}
     */
    private function periodRow(object $row): array
    {
        return [
            'this_month' => (int) $row->this_month,
            'last_month' => (int) $row->last_month,
            'this_year' => (int) $row->this_year,
            'last_year' => (int) $row->last_year,
        ];
    }

    /**
     * @param  array<string, int|string>  $bytes  period => bytes
     * @return array<int, array{period: string, bytes: int}>
     */
    private function monthlyPoints(CarbonImmutable $start, int $months, array $bytes): array
    {
        $points = [];

        for ($i = 0; $i < $months; $i++) {
            $key = $start->addMonthsNoOverflow($i)->format('Y-m');
            $points[] = ['period' => $key, 'bytes' => (int) ($bytes[$key] ?? 0)];
        }

        return $points;
    }

    /**
     * @param  array<int, array{period: string, bytes: int}>  $points
     * @return array{granularity: string, period_start: string, period_end: string, timezone: string, points: array<int, array{period: string, bytes: int}>}
     */
    private function history(string $granularity, CarbonImmutable $start, CarbonImmutable $end, array $points): array
    {
        return [
            'granularity' => $granularity,
            'period_start' => $start->format('Y-m-d'),
            'period_end' => $end->format('Y-m-d'),
            'timezone' => $this->timezone(),
            'points' => $points,
        ];
    }
}
