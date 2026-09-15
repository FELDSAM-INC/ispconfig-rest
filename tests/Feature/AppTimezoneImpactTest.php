<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\ChangeFixtures;
use Tests\Support\MonitorCompletionSchema;
use Tests\Support\MonitorSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Existing endpoints while the API runs in a non-UTC timezone (spec 017
 * FR-015, research R13): `config/app.php` now honours APP_TIMEZONE, and
 * installations are aligned with the ISPConfig server (Europe/Prague on
 * isp-test). Instants must not shift.
 */
class AppTimezoneImpactTest extends TestCase
{
    use ChangeFixtures;
    use RefreshDatabase;
    use TenantFixtures;

    private string $phpTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        TenantSchema::create();
        MonitorSchema::create();
        MonitorCompletionSchema::create();
        $this->seedTenants();

        // What Laravel does at boot with APP_TIMEZONE=Europe/Prague.
        config(['app.timezone' => 'Europe/Prague']);
        $this->phpTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Prague');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->phpTimezone);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_change_timestamps_keep_their_utc_instants(): void
    {
        $this->addServer(1);
        $this->journalEntry(['tstamp' => 1700000000, 'session_id' => 'set-tz']);

        $this->getJson('/api/v1/changes/set-tz', $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertJsonPath('created_at', '2023-11-14T22:13:20+00:00');
    }

    public function test_since_with_an_explicit_offset_is_unchanged(): void
    {
        $this->addServer(1);
        $this->journalEntry(['tstamp' => 1700000000, 'session_id' => 'set-old']);
        $this->journalEntry(['tstamp' => 1800000000, 'session_id' => 'set-new']);

        $this->getJson('/api/v1/changes?since=2025-01-01T00:00:00Z', $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_monitor_last_updated_keeps_its_utc_instant(): void
    {
        DB::table('server')->updateOrInsert(
            ['server_id' => 1],
            ['server_name' => 'server1', 'active' => 1, 'mirror_server_id' => 0, 'updated' => 0]
        );
        DB::table('monitor_data')->insert([
            'server_id' => 1,
            'type' => 'server_load',
            'created' => 1783190221,
            'data' => serialize(['load_1' => 0.1, 'load_5' => 0.1, 'load_15' => 0.1]),
            'state' => 'ok',
        ]);

        $this->getJson('/api/v1/monitor/servers/1/status', $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertJsonPath('last_updated', CarbonImmutable::createFromTimestamp(1783190221, 'UTC')->toIso8601String());
    }

    public function test_new_api_key_timestamps_serialise_as_the_current_utc_instant(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 10:00:00', 'UTC'));

        $this->postJson('/api/v1/system/api-keys', ['name' => 'timezone check'], $this->tenantHeaders('admin'))
            ->assertCreated()
            ->assertJsonPath('created_at', '2026-09-15T10:00:00.000000Z');
    }
}
