<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ChangeFixtures;
use Tests\Support\MonitorSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * GET /changes/{change_set_id} (contract: api/modules/changes/changes.yaml, spec 015 US1).
 */
class ChangeStatusApiTest extends TestCase
{
    use ChangeFixtures;
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        TenantSchema::create();
        MonitorSchema::create();
        $this->seedTenants();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function entryBy(string $tenant, string $set, array $overrides = []): int
    {
        $username = DB::table('sys_user')->where('userid', $this->tenant($tenant)['userid'])->value('username');

        return $this->journalEntry(array_merge(['user' => $username, 'session_id' => $set], $overrides));
    }

    private function show(string $tenant, string $set, string $query = '')
    {
        return $this->getJson('/api/v1/changes/'.$set.$query, $this->tenantHeaders($tenant));
    }

    public function test_timestamps_use_the_offset_of_the_configured_timezone(): void
    {
        // Owner-delegated decision 2026-09-15: consistent with backups and usage (spec 017 FR-015).
        config(['app.timezone' => 'Europe/Prague']);
        $this->addServer(1);
        $this->entryBy('clientA', 'set-tz', ['dbidx' => 'domain_id:12', 'action' => 'i', 'tstamp' => 1700000000]);

        $this->show('clientA', 'set-tz')
            ->assertOk()
            ->assertJsonPath('created_at', '2023-11-14T23:13:20+01:00')
            ->assertJsonPath('entries.0.created_at', '2023-11-14T23:13:20+01:00');

        $this->getJson('/api/v1/changes?change_set_id=set-tz', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('data.0.created_at', '2023-11-14T23:13:20+01:00');
    }

    public function test_pending_until_the_watermark_passes_then_applied(): void
    {
        $this->addServer(1);
        $id = $this->entryBy('clientA', 'set-a', ['dbidx' => 'domain_id:12', 'action' => 'i', 'tstamp' => 1700000000]);

        $this->show('clientA', 'set-a')
            ->assertOk()
            ->assertJsonPath('id', 'set-a')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('entry_counts', ['pending' => 1, 'applied' => 0, 'failed' => 0, 'stalled' => 0])
            ->assertJsonPath('created_at', CarbonImmutable::createFromTimestamp(1700000000, config('app.timezone'))->toIso8601String())
            ->assertJsonPath('entries.0.id', $id)
            ->assertJsonPath('entries.0.change_set_id', 'set-a')
            ->assertJsonPath('entries.0.table', 'mail_domain')
            ->assertJsonPath('entries.0.record_id', 12)
            ->assertJsonPath('entries.0.action', 'create')
            ->assertJsonPath('entries.0.status', 'pending')
            ->assertJsonMissingPath('entries.0.error');

        $this->setWatermark(1, $id);

        $this->show('clientA', 'set-a')
            ->assertOk()
            ->assertJsonPath('status', 'applied')
            ->assertJsonPath('entries.0.status', 'applied');
    }

    public function test_failed_with_verbatim_error_only_once_processed(): void
    {
        $this->addServer(1);
        $error = "Apache config test failed\nSyntax error on line 12";
        $id = $this->entryBy('clientA', 'set-a', ['error' => $error]);

        $this->show('clientA', 'set-a')
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonMissingPath('entries.0.error');

        $this->setWatermark(1, $id);

        $this->show('clientA', 'set-a')
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('entries.0.status', 'failed')
            ->assertJsonPath('entries.0.error', $error);
    }

    public function test_stalled_when_target_is_inactive_without_mirror(): void
    {
        $this->addServer(1, active: false);
        $this->entryBy('clientA', 'set-a');

        $this->show('clientA', 'set-a')->assertOk()->assertJsonPath('status', 'stalled');
    }

    public function test_mirror_server_processes_its_masters_entries(): void
    {
        $this->addServer(1, active: false);
        $this->addServer(2, mirrorOf: 1);
        $id = $this->entryBy('clientA', 'set-a');

        $this->show('clientA', 'set-a')->assertJsonPath('status', 'pending');

        $this->setWatermark(2, $id);

        $this->show('clientA', 'set-a')->assertJsonPath('status', 'applied');
    }

    public function test_server_independent_entry_waits_for_every_active_server(): void
    {
        $this->addServer(1);
        $this->addServer(2);
        $id = $this->entryBy('clientA', 'set-a', ['server_id' => 0]);
        $this->setWatermark(1, $id);

        $this->show('clientA', 'set-a')->assertJsonPath('status', 'pending');

        $this->setWatermark(2, $id);

        $this->show('clientA', 'set-a')->assertJsonPath('status', 'applied');
    }

    public function test_status_and_counts_cover_all_entries_while_entries_are_paged(): void
    {
        $this->addServer(1);
        $applied = $this->entryBy('clientA', 'set-a');
        $failed = $this->entryBy('clientA', 'set-a', ['error' => 'boom']);
        $pending = $this->entryBy('clientA', 'set-a');
        $this->setWatermark(1, $failed);

        $this->show('clientA', 'set-a', '?limit=1&offset=1')
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('entry_counts', ['pending' => 1, 'applied' => 1, 'failed' => 1, 'stalled' => 0])
            ->assertJsonCount(1, 'entries')
            ->assertJsonPath('entries.0.id', $failed)
            ->assertJsonPath('meta', ['total' => 3, 'limit' => 1, 'offset' => 1]);

        $this->show('clientA', 'set-a')
            ->assertJsonPath('entries.0.id', $applied)
            ->assertJsonPath('entries.2.id', $pending)
            ->assertJsonPath('meta', ['total' => 3, 'limit' => 25, 'offset' => 0]);
    }

    public function test_precedence_stalled_over_failed(): void
    {
        $this->addServer(1);
        $this->addServer(3, active: false);
        $failed = $this->entryBy('clientA', 'set-a', ['error' => 'boom']);
        $this->entryBy('clientA', 'set-a', ['server_id' => 3]);
        $this->setWatermark(1, $failed + 1);

        $this->show('clientA', 'set-a')->assertJsonPath('status', 'stalled');
    }

    public function test_unknown_query_parameter_is_400(): void
    {
        $this->addServer(1);
        $this->entryBy('clientA', 'set-a');

        $this->show('clientA', 'set-a', '?status=pending')
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_requires_api_key(): void
    {
        $this->entryBy('clientA', 'set-a');

        $this->getJson('/api/v1/changes/set-a')->assertStatus(401);
    }

    public function test_unknown_too_long_or_invalid_ids_are_404(): void
    {
        $this->addServer(1);

        $this->show('admin', 'no-such-set')->assertStatus(404)->assertHeader('Content-Type', 'application/problem+json');
        $this->show('admin', str_repeat('a', 65))->assertStatus(404);
        $this->show('admin', 'bad%24id')->assertStatus(404);
    }

    public function test_other_client_gets_404_for_a_foreign_set(): void
    {
        $this->addServer(1);
        $this->entryBy('clientA', 'set-a');

        $this->show('clientB', 'set-a')->assertStatus(404);
    }

    public function test_reseller_sees_only_sets_written_under_its_own_username(): void
    {
        $this->addServer(1);
        $this->entryBy('clientA', 'set-a');
        $this->entryBy('reseller', 'set-r');

        $this->show('reseller', 'set-a')->assertStatus(404);
        $this->show('reseller', 'set-r')->assertOk()->assertJsonPath('entry_counts.pending', 1);
    }

    public function test_admin_sees_any_set_and_non_admin_sees_only_own_entries_of_a_mixed_set(): void
    {
        $this->addServer(1);
        $this->entryBy('clientA', 'set-mixed');
        $this->entryBy('admin', 'set-mixed');

        $this->show('admin', 'set-mixed')->assertOk()->assertJsonPath('meta.total', 2);
        $this->show('clientA', 'set-mixed')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_response_never_exposes_payload_writer_or_server(): void
    {
        $this->addServer(1);
        $this->entryBy('clientA', 'set-a');

        $response = $this->show('clientA', 'set-a')->assertOk();

        foreach (['data', 'user', 'server_id', 'dbidx', 'session_id'] as $field) {
            $response->assertJsonMissingPath('entries.0.'.$field);
            $response->assertJsonMissingPath($field);
        }
    }

    public function test_status_requests_write_no_journal_rows(): void
    {
        $this->addServer(1);
        $this->entryBy('clientA', 'set-a');
        $before = DB::table('sys_datalog')->count();

        $this->show('clientA', 'set-a')->assertOk();
        $this->show('clientA', 'missing')->assertStatus(404);

        $this->assertSame($before, DB::table('sys_datalog')->count());
    }

    public function test_query_count_does_not_grow_with_set_size(): void
    {
        $this->addServer(1);
        $user = DB::table('sys_user')->where('userid', $this->tenant('clientA')['userid'])->value('username');

        $this->entryBy('clientA', 'small');
        $this->entryBy('clientA', 'small');

        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $rows[] = [
                'server_id' => 1, 'dbtable' => 'mail_domain', 'dbidx' => 'domain_id:'.($i + 1), 'action' => 'u',
                'tstamp' => 1700000000, 'user' => $user, 'data' => '', 'status' => 'ok', 'error' => '', 'session_id' => 'large',
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('sys_datalog')->insert($chunk);
        }

        // Freeze time and warm up once so the key's last_used_at write happens only on the first request.
        $this->freezeTime();
        $this->show('clientA', 'small')->assertOk();

        $small = $this->countQueries(fn () => $this->show('clientA', 'small')->assertOk());
        $large = $this->countQueries(fn () => $this->show('clientA', 'large')->assertOk()->assertJsonPath('meta.total', 500));

        $this->assertSame($small, $large);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
