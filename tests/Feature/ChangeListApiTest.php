<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ChangeFixtures;
use Tests\Support\MonitorSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * GET /changes (contract: api/modules/changes/changes.yaml, spec 015 US2).
 */
class ChangeListApiTest extends TestCase
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
        $this->addServer(1);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function entryBy(string $tenant, array $overrides = []): int
    {
        $username = DB::table('sys_user')->where('userid', $this->tenant($tenant)['userid'])->value('username');

        return $this->journalEntry(array_merge(['user' => $username, 'session_id' => 'set-'.$tenant], $overrides));
    }

    private function list(string $tenant, string $query = '')
    {
        return $this->getJson('/api/v1/changes'.$query, $this->tenantHeaders($tenant));
    }

    /**
     * @return array<int, int>
     */
    private function ids($response): array
    {
        return array_map(fn (array $item): int => $item['id'], $response->json('data'));
    }

    public function test_non_admin_keys_see_only_their_own_writes_including_legacy_panel_sessions(): void
    {
        $a1 = $this->entryBy('clientA');
        $legacy = $this->entryBy('clientA', ['session_id' => str_repeat('k', 26)]);
        $b = $this->entryBy('clientB');
        $admin = $this->entryBy('admin');

        $this->list('clientA')->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame([$legacy, $a1], $this->ids($this->list('clientA')));
        $this->assertSame([$b], $this->ids($this->list('clientB')));
        $this->assertSame([$admin, $b, $legacy, $a1], $this->ids($this->list('admin')));
    }

    public function test_reseller_key_sees_only_its_own_username(): void
    {
        $this->entryBy('clientA');
        $own = $this->entryBy('reseller');

        $this->assertSame([$own], $this->ids($this->list('reseller')));
    }

    public function test_newest_first_by_default_and_ascending_on_request(): void
    {
        $first = $this->entryBy('clientA');
        $second = $this->entryBy('clientA');

        $this->assertSame([$second, $first], $this->ids($this->list('clientA')));
        $this->assertSame([$first, $second], $this->ids($this->list('clientA', '?order=asc')));
    }

    public function test_status_filter_with_correct_totals_and_paging(): void
    {
        $applied = $this->entryBy('clientA');
        $failed = $this->entryBy('clientA', ['error' => 'boom']);
        $pending1 = $this->entryBy('clientA');
        $pending2 = $this->entryBy('clientA');
        $stalled = $this->entryBy('clientA', ['server_id' => 7]);
        $this->setWatermark(1, $failed);

        $this->list('clientA', '?status=pending&limit=1')
            ->assertOk()
            ->assertJsonPath('meta', ['total' => 2, 'limit' => 1, 'offset' => 0])
            ->assertJsonPath('data.0.id', $pending2)
            ->assertJsonPath('data.0.status', 'pending');

        $this->assertSame([$pending1], $this->ids($this->list('clientA', '?status=pending&limit=1&offset=1')));
        $this->assertSame([$applied], $this->ids($this->list('clientA', '?status=applied')));
        $this->assertSame([$stalled], $this->ids($this->list('clientA', '?status=stalled')));

        $this->list('clientA', '?status=failed')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $failed)
            ->assertJsonPath('data.0.error', 'boom');
    }

    public function test_table_change_set_and_since_filters_alone_and_combined(): void
    {
        $old = $this->entryBy('clientA', ['dbtable' => 'web_domain', 'tstamp' => 1700000000, 'session_id' => 's1']);
        $new = $this->entryBy('clientA', ['dbtable' => 'web_domain', 'tstamp' => 1800000000, 'session_id' => 's2']);
        $mail = $this->entryBy('clientA', ['dbtable' => 'mail_domain', 'tstamp' => 1800000000, 'session_id' => 's2']);

        $this->assertSame([$new, $old], $this->ids($this->list('clientA', '?table=web_domain')));
        $this->assertSame([$mail, $new], $this->ids($this->list('clientA', '?change_set_id=s2')));
        $this->assertSame([$mail, $new], $this->ids($this->list('clientA', '?since=2027-01-15T08:00:00Z')));
        $this->assertSame([$new], $this->ids($this->list('clientA', '?table=web_domain&since=2027-01-15T08:00:00%2B00:00&change_set_id=s2')));
    }

    public function test_invalid_parameters_are_400(): void
    {
        foreach (['?sort=datalog_id', '?unknown=1', '?status=done', '?since=yesterday', '?order=up'] as $query) {
            $this->list('clientA', $query)
                ->assertStatus(400)
                ->assertHeader('Content-Type', 'application/problem+json');
        }
    }

    public function test_requires_api_key(): void
    {
        $this->getJson('/api/v1/changes')->assertStatus(401);
    }

    public function test_items_never_expose_payload_writer_or_server(): void
    {
        $this->entryBy('clientA');

        $response = $this->list('clientA')->assertOk();

        foreach (['data', 'user', 'server_id', 'dbidx', 'session_id'] as $field) {
            $response->assertJsonMissingPath('data.0.'.$field);
        }
    }

    public function test_query_count_does_not_depend_on_page_size(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->entryBy('clientA', ['error' => $i % 3 === 0 ? 'boom' : '']);
        }

        $this->freezeTime();
        $this->list('clientA')->assertOk();

        $small = $this->countQueries(fn () => $this->list('clientA', '?limit=2')->assertOk());
        $large = $this->countQueries(fn () => $this->list('clientA', '?limit=30')->assertOk()->assertJsonCount(30, 'data'));

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
