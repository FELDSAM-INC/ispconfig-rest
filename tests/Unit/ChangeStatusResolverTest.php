<?php

namespace Tests\Unit;

use App\Services\ChangeStatusResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ChangeFixtures;
use Tests\Support\MonitorSchema;
use Tests\TestCase;

/**
 * Status derivation of journal entries (spec 015 FR-003..FR-005, research R2/R3/R6).
 *
 * Legacy model (ISPConfig 3.3.1p1 modules.inc.php::processDatalog, server.php:76,
 * db_mysql.inc.php::datalogStatus): an entry is processed when every responsible
 * active server's `updated` watermark has passed its datalog_id.
 */
class ChangeStatusResolverTest extends TestCase
{
    use ChangeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MonitorSchema::create();
    }

    private function statusOf(int $datalogId): string
    {
        $row = DB::table('sys_datalog')->where('datalog_id', $datalogId)->first();

        return (new ChangeStatusResolver)->statusOf($row);
    }

    public function test_active_target_server_watermark_decides_pending_or_applied(): void
    {
        $this->addServer(1, updated: 0);
        $processed = $this->journalEntry(['server_id' => 1]);
        $pending = $this->journalEntry(['server_id' => 1]);
        $this->setWatermark(1, $processed);

        $this->assertSame('applied', $this->statusOf($processed));
        $this->assertSame('pending', $this->statusOf($pending));
    }

    public function test_inactive_target_with_active_mirror_uses_the_mirror_watermark(): void
    {
        $this->addServer(1, active: false, updated: 0);
        $this->addServer(2, active: true, mirrorOf: 1, updated: 0);
        $entry = $this->journalEntry(['server_id' => 1]);
        $this->setWatermark(2, $entry);

        $this->assertSame('applied', $this->statusOf($entry));
    }

    public function test_target_and_active_mirror_must_both_pass(): void
    {
        $this->addServer(1, updated: 0);
        $this->addServer(2, mirrorOf: 1, updated: 0);
        $entry = $this->journalEntry(['server_id' => 1]);
        $this->setWatermark(2, $entry);

        $this->assertSame('pending', $this->statusOf($entry));

        $this->setWatermark(1, $entry);

        $this->assertSame('applied', $this->statusOf($entry));
    }

    public function test_inactive_target_without_active_mirror_is_stalled(): void
    {
        $this->addServer(1, active: false, updated: 0);
        $this->addServer(2, active: false, mirrorOf: 1, updated: 0);
        $entry = $this->journalEntry(['server_id' => 1]);

        $this->assertSame('stalled', $this->statusOf($entry));
    }

    public function test_deleted_target_server_is_stalled(): void
    {
        $this->addServer(1, updated: 1000);
        $entry = $this->journalEntry(['server_id' => 9]);

        $this->assertSame('stalled', $this->statusOf($entry));
    }

    public function test_server_independent_entry_needs_every_active_server(): void
    {
        $this->addServer(1, updated: 0);
        $this->addServer(2, updated: 0);
        $this->addServer(3, active: false, updated: 0);
        $entry = $this->journalEntry(['server_id' => 0]);
        $this->setWatermark(1, $entry);

        $this->assertSame('pending', $this->statusOf($entry));

        $this->setWatermark(2, $entry);

        // Inactive server 3 is not responsible.
        $this->assertSame('applied', $this->statusOf($entry));
    }

    public function test_server_independent_entry_without_active_servers_is_stalled(): void
    {
        $this->addServer(1, active: false, updated: 1000);
        $entry = $this->journalEntry(['server_id' => 0]);

        $this->assertSame('stalled', $this->statusOf($entry));
    }

    public function test_error_counts_only_once_processed(): void
    {
        $this->addServer(1, updated: 0);
        $failed = $this->journalEntry(['server_id' => 1, 'error' => "vhost error\nline two"]);
        $pendingWithError = $this->journalEntry(['server_id' => 1, 'error' => 'early error']);
        $this->setWatermark(1, $failed);

        $this->assertSame('failed', $this->statusOf($failed));
        $this->assertSame('pending', $this->statusOf($pendingWithError));
    }

    public function test_null_error_is_applied(): void
    {
        $this->addServer(1, updated: 0);
        $entry = $this->journalEntry(['server_id' => 1, 'error' => null]);
        $this->setWatermark(1, $entry);

        $this->assertSame('applied', $this->statusOf($entry));
    }

    public function test_aggregate_precedence(): void
    {
        $counts = ['pending' => 0, 'applied' => 0, 'failed' => 0, 'stalled' => 0];

        $this->assertSame('applied', ChangeStatusResolver::aggregate(['applied' => 3] + $counts));
        $this->assertSame('failed', ChangeStatusResolver::aggregate(['applied' => 3, 'failed' => 1] + $counts));
        $this->assertSame('stalled', ChangeStatusResolver::aggregate(['failed' => 1, 'stalled' => 1] + $counts));
        $this->assertSame('pending', ChangeStatusResolver::aggregate(['pending' => 1, 'stalled' => 1, 'failed' => 1] + $counts));
    }

    public function test_status_filter_selects_exactly_the_rows_status_of_classifies(): void
    {
        $this->addServer(1, updated: 0);
        $this->addServer(2, mirrorOf: 1, updated: 0);
        $this->addServer(3, active: false, updated: 0);
        $this->addServer(4, updated: 0);

        $ids = [
            $this->journalEntry(['server_id' => 1]),
            $this->journalEntry(['server_id' => 1, 'error' => 'boom']),
            $this->journalEntry(['server_id' => 3]),
            $this->journalEntry(['server_id' => 0]),
            $this->journalEntry(['server_id' => 4, 'error' => null]),
            $this->journalEntry(['server_id' => 9]),
            $this->journalEntry(['server_id' => 1]),
            $this->journalEntry(['server_id' => 4, 'error' => 'late error']),
        ];

        // Servers 1 and 2 processed the first two entries; server 4 processed up to the fifth.
        $this->setWatermark(1, $ids[3]);
        $this->setWatermark(2, $ids[1]);
        $this->setWatermark(4, $ids[4]);

        $resolver = new ChangeStatusResolver;
        $expected = [];

        foreach (DB::table('sys_datalog')->orderBy('datalog_id')->get() as $row) {
            $expected[$resolver->statusOf($row)][] = (int) $row->datalog_id;
        }

        $this->assertNotEmpty($expected['pending'] ?? []);
        $this->assertNotEmpty($expected['applied'] ?? []);
        $this->assertNotEmpty($expected['failed'] ?? []);
        $this->assertNotEmpty($expected['stalled'] ?? []);

        foreach (['pending', 'applied', 'failed', 'stalled'] as $status) {
            $selected = $resolver->applyStatusFilter(DB::table('sys_datalog'), $status)
                ->orderBy('datalog_id')
                ->pluck('datalog_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $this->assertSame($expected[$status] ?? [], $selected, "status filter '{$status}'");
        }

        $counts = (array) DB::table('sys_datalog')->selectRaw(implode(', ', $resolver->statusCountSelects()))->first();

        foreach (['pending', 'applied', 'failed', 'stalled'] as $status) {
            $this->assertSame(count($expected[$status] ?? []), (int) $counts[$status.'_count'], "count '{$status}'");
        }
    }
}
