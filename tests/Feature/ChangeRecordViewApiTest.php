<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ChangeFixtures;
use Tests\Support\MailSchema;
use Tests\Support\MonitorSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * GET /changes?table=…&record_id=… record view (spec 015 US3, FR-008).
 */
class ChangeRecordViewApiTest extends TestCase
{
    use ChangeFixtures;
    use RefreshDatabase;
    use TenantFixtures;

    private int $domainA;

    private int $domainB;

    protected function setUp(): void
    {
        parent::setUp();

        MailSchema::create();
        TenantSchema::create();
        MonitorSchema::create();
        $this->seedTenants();
        $this->addServer(1);

        $this->domainA = (int) DB::table('mail_domain')->insertGetId($this->ownedBy('clientA', ['server_id' => 1, 'domain' => 'a.example', 'active' => 'y']), 'domain_id');
        $this->domainB = (int) DB::table('mail_domain')->insertGetId($this->ownedBy('clientB', ['server_id' => 1, 'domain' => 'b.example', 'active' => 'y']), 'domain_id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function entryBy(string $tenant, array $overrides = []): int
    {
        $username = DB::table('sys_user')->where('userid', $this->tenant($tenant)['userid'])->value('username');

        return $this->journalEntry(array_merge(['user' => $username, 'session_id' => 'set-'.$tenant], $overrides));
    }

    private function recordView(string $tenant, string $query)
    {
        return $this->getJson('/api/v1/changes?'.$query, $this->tenantHeaders($tenant));
    }

    public function test_readable_record_shows_entries_from_other_writers(): void
    {
        $adminEntry = $this->entryBy('admin', ['dbtable' => 'mail_domain', 'dbidx' => 'domain_id:'.$this->domainA, 'action' => 'u']);
        $ownEntry = $this->entryBy('clientA', ['dbtable' => 'mail_domain', 'dbidx' => 'domain_id:'.$this->domainA]);
        $this->entryBy('admin', ['dbtable' => 'mail_domain', 'dbidx' => 'domain_id:'.$this->domainB]);

        $response = $this->recordView('clientA', 'table=mail_domain&record_id='.$this->domainA)
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $ownEntry)
            ->assertJsonPath('data.1.id', $adminEntry)
            ->assertJsonPath('data.1.record_id', $this->domainA);

        $response->assertJsonMissingPath('data.1.user');
    }

    public function test_record_of_another_tenant_is_404(): void
    {
        $this->entryBy('admin', ['dbtable' => 'mail_domain', 'dbidx' => 'domain_id:'.$this->domainB]);

        $this->recordView('clientA', 'table=mail_domain&record_id='.$this->domainB)
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_nonexistent_and_deleted_records_are_404_for_non_admin_keys(): void
    {
        $this->recordView('clientA', 'table=mail_domain&record_id=999')->assertStatus(404);

        $this->entryBy('clientA', ['dbtable' => 'mail_domain', 'dbidx' => 'domain_id:'.$this->domainA, 'action' => 'd']);
        DB::table('mail_domain')->where('domain_id', $this->domainA)->delete();

        $this->recordView('clientA', 'table=mail_domain&record_id='.$this->domainA)->assertStatus(404);
    }

    public function test_admin_keys_see_entries_even_when_the_record_no_longer_exists(): void
    {
        $entry = $this->entryBy('clientA', ['dbtable' => 'mail_domain', 'dbidx' => 'domain_id:'.$this->domainA, 'action' => 'd']);
        DB::table('mail_domain')->where('domain_id', $this->domainA)->delete();

        $this->recordView('admin', 'table=mail_domain&record_id='.$this->domainA)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $entry);
    }

    public function test_record_id_requires_a_supported_table(): void
    {
        $this->recordView('clientA', 'record_id='.$this->domainA)->assertStatus(400);
        $this->recordView('clientA', 'table=sys_log&record_id=1')->assertStatus(400);
        $this->recordView('clientA', 'table=mail_domain&record_id=0')->assertStatus(400);
        $this->recordView('clientA', 'table=mail_domain&record_id=abc')->assertStatus(400);
    }

    public function test_tables_without_permission_fields_are_admin_only(): void
    {
        $entry = $this->entryBy('admin', ['dbtable' => 'sys_ini', 'dbidx' => 'sysini_id:1', 'server_id' => 0]);

        $this->recordView('clientA', 'table=sys_ini&record_id=1')->assertStatus(404);
        $this->recordView('clientA', 'table=client_template_assigned&record_id=1')->assertStatus(404);

        $this->recordView('admin', 'table=sys_ini&record_id=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $entry);
    }

    public function test_record_view_combined_with_status_keeps_correct_totals(): void
    {
        $applied = $this->entryBy('admin', ['dbtable' => 'mail_domain', 'dbidx' => 'domain_id:'.$this->domainA]);
        $pending = $this->entryBy('clientA', ['dbtable' => 'mail_domain', 'dbidx' => 'domain_id:'.$this->domainA]);
        $this->setWatermark(1, $applied);

        $this->recordView('clientA', 'table=mail_domain&record_id='.$this->domainA.'&status=pending')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $pending);

        $this->recordView('clientA', 'table=mail_domain&record_id='.$this->domainA.'&status=applied')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $applied);
    }
}
