<?php

namespace Tests\Feature;

use App\Models\CronJob;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CronJob::minIntervalMinutes() — port of the `cron_min_freq` accumulation of
 * legacy validate_cron.inc.php:99-222 (spec 035 US3, research R7). Per field
 * the shortest gap between two runs (including the wrap-around) is converted
 * to minutes and kept only while it stays inside the field's own range; the
 * job's interval is the smallest kept value.
 */
class CronScheduleIntervalTest extends TestCase
{
    /**
     * @return array<string, array{0: array<int, string>, 1: int|null}>
     */
    public static function schedules(): array
    {
        return [
            // every five minutes -> run_min gap 5
            'every five minutes' => [['*/5', '*', '*', '*', '*'], 5],
            // every minute -> gap 1
            'every minute' => [['*', '*', '*', '*', '*'], 1],
            // hourly: run_min '0' yields 60, which exceeds its own max (59) and
            // is discarded; run_hour '*' contributes 1 * 60
            'hourly' => [['0', '*', '*', '*', '*'], 60],
            // daily at 03:00 -> run_hour '3' wraps to 24 hours
            'daily' => [['0', '3', '*', '*', '*'], 1440],
            // twice per hour through a list
            'half hourly list' => [['0,30', '*', '*', '*', '*'], 30],
            // range with a step
            'range with step' => [['0-30/10', '*', '*', '*', '*'], 10],
            // uneven list: the wrap-around gap is the smallest one
            'uneven list' => [['0,50', '*', '*', '*', '*'], 10],
            // weekdays only: run_wday 1-5 leaves a 1-day gap
            'weekdays' => [['0', '3', '*', '*', '1-5'], 1440],
            // @reboot is accepted in run_month and contributes nothing
            'reboot month' => [['0', '3', '*', '@reboot', '*'], 1440],
        ];
    }

    /**
     * @param  array<int, string>  $fields
     */
    #[DataProvider('schedules')]
    public function test_shortest_interval(array $fields, ?int $expected): void
    {
        [$min, $hour, $mday, $month, $wday] = $fields;

        $this->assertSame($expected, CronJob::minIntervalMinutes($min, $hour, $mday, $month, $wday));
    }

    public function test_invalid_expressions_do_not_constrain(): void
    {
        $this->assertNull(CronJob::minIntervalMinutes('nonsense', '*', '*', '*', '*'));
    }
}
