<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SystemSchema;
use Tests\TestCase;

/**
 * POST /system/resync + GET /system/resync/servers.
 *
 * Legacy parity (tools/resync.php): per-flag table sets, per-table
 * active-only vs all-rows filters and emission order; forced datalog
 * entries for UNCHANGED records; DNS bumps dns_rr/dns_soa serials instead
 * of re-emitting; client is re-emitted unfiltered (the interface-plugin
 * event is a documented deviation).
 */
class ResyncApiTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'test-dev-key';

    /**
     * The exact legacy emission order for a resync_all run.
     */
    protected const ALL_TABLES_ORDER = [
        'web_domain', 'ftp_user', 'webdav_user', 'shell_user', 'cron',
        'web_database_user', 'web_database',
        'mail_domain', 'spamfilter_policy',
        'mail_get',
        'mail_user', 'mail_forwarding',
        'mail_access', 'mail_content_filter', 'mail_user_filter', 'spamfilter_users', 'spamfilter_wblist',
        'mail_mailinglist', 'mail_transport', 'mail_relay_recipient',
        'openvz_vm',
        'dns_rr', 'dns_soa',
        'client',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        SystemSchema::create();

        config(['api.dev_key' => self::KEY]);

        DB::table('sys_user')->insert([
            'userid' => 1,
            'username' => 'apiadmin',
            'typ' => 'admin',
            'default_group' => 1,
        ]);
    }

    protected function authHeaders(): array
    {
        return ['X-API-Key' => self::KEY];
    }

    protected function seedServer(int $id, array $overrides = []): void
    {
        DB::table('server')->insert(array_merge([
            'server_id' => $id,
            'server_name' => 'server'.$id,
            'mirror_server_id' => 0,
            'active' => 1,
        ], $overrides));
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        $this->postJson('/api/v1/system/resync', ['resync_sites' => 1])
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Unauthorized', 'status' => 401]);

        $this->getJson('/api/v1/system/resync/servers')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // POST /system/resync — forced re-emission
    // ------------------------------------------------------------------

    public function test_resync_sites_force_emits_unchanged_active_web_domains(): void
    {
        $this->seedServer(1, ['web_server' => 1]);
        $this->seedServer(2, ['web_server' => 1, 'active' => 0]); // inactive server
        $this->seedServer(3, ['web_server' => 1, 'mirror_server_id' => 1]); // mirror

        $a = DB::table('web_domain')->insertGetId(['server_id' => 1, 'domain' => 'a.tld', 'active' => 'y'], 'domain_id');
        $b = DB::table('web_domain')->insertGetId(['server_id' => 1, 'domain' => 'b.tld', 'active' => 'y'], 'domain_id');
        DB::table('web_domain')->insert(['server_id' => 1, 'domain' => 'off.tld', 'active' => 'n']); // inactive row
        DB::table('web_domain')->insert(['server_id' => 2, 'domain' => 'inactive-server.tld', 'active' => 'y']);
        DB::table('web_domain')->insert(['server_id' => 3, 'domain' => 'mirror-server.tld', 'active' => 'y']);

        $this->postJson('/api/v1/system/resync', ['resync_sites' => 1, 'web_server_id' => 0], $this->authHeaders())
            ->assertOk()
            ->assertExactJson([
                'total_datalog_entries' => 2,
                'resynced_tables' => [
                    ['table' => 'web_domain', 'datalog_entries' => 2],
                ],
            ]);

        $rows = DB::table('sys_datalog')->orderBy('datalog_id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['domain_id:'.$a, 'domain_id:'.$b], $rows->pluck('dbidx')->all());

        foreach ($rows as $row) {
            $this->assertSame('web_domain', $row->dbtable);
            $this->assertSame('u', $row->action);
            $this->assertSame(1, (int) $row->server_id);
            $this->assertSame('apiadmin', $row->user);

            // Forced emission of an UNCHANGED record: full row on both
            // sides, identical, 'new' serialized first (legacy force path).
            $data = unserialize($row->data);
            $this->assertSame(['new', 'old'], array_keys($data));
            $this->assertSame($data['old'], $data['new']);
            $this->assertArrayHasKey('domain', $data['new']);
        }

        // Re-emission writes no table rows.
        $this->assertSame('a.tld', DB::table('web_domain')->where('domain_id', $a)->value('domain'));
    }

    public function test_resync_targets_a_specific_server_id(): void
    {
        $this->seedServer(1, ['web_server' => 1]);
        $this->seedServer(2, ['web_server' => 1]);

        DB::table('web_domain')->insert(['server_id' => 1, 'domain' => 'one.tld', 'active' => 'y']);
        DB::table('web_domain')->insert(['server_id' => 2, 'domain' => 'two.tld', 'active' => 'y']);

        $this->postJson('/api/v1/system/resync', ['resync_sites' => 1, 'web_server_id' => 2], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('total_datalog_entries', 1);

        $data = unserialize(DB::table('sys_datalog')->first()->data);
        $this->assertSame('two.tld', $data['new']['domain']);
    }

    public function test_resync_db_emits_users_before_databases_with_legacy_filters(): void
    {
        $this->seedServer(1, ['db_server' => 1]);

        // web_database_user has no active column — ALL rows are re-emitted.
        DB::table('web_database_user')->insert(['server_id' => 1, 'database_user' => 'u1']);
        DB::table('web_database_user')->insert(['server_id' => 1, 'database_user' => 'u2']);
        // web_database is filtered to active rows.
        DB::table('web_database')->insert(['server_id' => 1, 'database_name' => 'db1', 'active' => 'y']);
        DB::table('web_database')->insert(['server_id' => 1, 'database_name' => 'db2', 'active' => 'n']);

        $this->postJson('/api/v1/system/resync', ['resync_db' => 1, 'db_server_id' => 0], $this->authHeaders())
            ->assertOk()
            ->assertExactJson([
                'total_datalog_entries' => 3,
                'resynced_tables' => [
                    ['table' => 'web_database_user', 'datalog_entries' => 2],
                    ['table' => 'web_database', 'datalog_entries' => 1],
                ],
            ]);

        // Emission order in sys_datalog: users first, then the database.
        $this->assertSame(
            ['web_database_user', 'web_database_user', 'web_database'],
            DB::table('sys_datalog')->orderBy('datalog_id')->pluck('dbtable')->all()
        );
    }

    public function test_resync_mail_and_mailbox_table_sets(): void
    {
        $this->seedServer(1, ['mail_server' => 1]);

        DB::table('mail_domain')->insert(['server_id' => 1, 'domain' => 'm.tld', 'active' => 'y']);
        DB::table('mail_domain')->insert(['server_id' => 1, 'domain' => 'off.tld', 'active' => 'n']);
        // spamfilter_policy: no server_id column, ALL rows.
        DB::table('spamfilter_policy')->insert(['policy_name' => 'default']);
        DB::table('spamfilter_policy')->insert(['policy_name' => 'strict']);
        // mail_user: ALL rows (server-filtered); mail_forwarding: active only.
        DB::table('mail_user')->insert(['server_id' => 1, 'email' => 'a@m.tld']);
        DB::table('mail_forwarding')->insert(['server_id' => 1, 'source' => 'x@m.tld', 'active' => 'n']);

        $this->postJson('/api/v1/system/resync', [
            'resync_mail' => 1,
            'mail_server_id' => 0,
            'resync_mailbox' => 1,
            'mailbox_server_id' => 0,
        ], $this->authHeaders())
            ->assertOk()
            ->assertExactJson([
                'total_datalog_entries' => 4,
                'resynced_tables' => [
                    ['table' => 'mail_domain', 'datalog_entries' => 1],
                    ['table' => 'spamfilter_policy', 'datalog_entries' => 2],
                    ['table' => 'mail_user', 'datalog_entries' => 1],
                    ['table' => 'mail_forwarding', 'datalog_entries' => 0],
                ],
            ]);
    }

    public function test_resync_mailget_uses_mail_server_id(): void
    {
        $this->seedServer(1, ['mail_server' => 1]);
        $this->seedServer(2, ['mail_server' => 1]);

        DB::table('mail_get')->insert(['server_id' => 2, 'source_username' => 'fetch', 'active' => 'y']);

        $this->postJson('/api/v1/system/resync', ['resync_mailget' => 1, 'mail_server_id' => 2], $this->authHeaders())
            ->assertOk()
            ->assertExactJson([
                'total_datalog_entries' => 1,
                'resynced_tables' => [
                    ['table' => 'mail_get', 'datalog_entries' => 1],
                ],
            ]);
    }

    public function test_resync_dns_bumps_serials_instead_of_reemitting(): void
    {
        $this->seedServer(2, ['dns_server' => 1]);

        $zone = DB::table('dns_soa')->insertGetId([
            'server_id' => 2, 'origin' => 'zone.tld.', 'serial' => 2024010101, 'active' => 'Y',
        ], 'id');
        $inactiveZone = DB::table('dns_soa')->insertGetId([
            'server_id' => 2, 'origin' => 'off.tld.', 'serial' => 2024010101, 'active' => 'N',
        ], 'id');

        $rrA = DB::table('dns_rr')->insertGetId([
            'server_id' => 2, 'zone' => $zone, 'name' => 'www', 'type' => 'A', 'data' => '1.2.3.4', 'serial' => 2024010101, 'active' => 'Y',
        ], 'id');
        $rrB = DB::table('dns_rr')->insertGetId([
            'server_id' => 2, 'zone' => $zone, 'name' => 'mail', 'type' => 'A', 'data' => '1.2.3.5', 'serial' => null, 'active' => 'Y',
        ], 'id');
        DB::table('dns_rr')->insert([
            'server_id' => 2, 'zone' => $zone, 'name' => 'old', 'type' => 'A', 'data' => '1.2.3.6', 'serial' => 2024010101, 'active' => 'N',
        ]);

        $this->postJson('/api/v1/system/resync', ['resync_dns' => 1, 'dns_server_id' => 0], $this->authHeaders())
            ->assertOk()
            ->assertExactJson([
                'total_datalog_entries' => 3,
                'resynced_tables' => [
                    ['table' => 'dns_rr', 'datalog_entries' => 2],
                    ['table' => 'dns_soa', 'datalog_entries' => 1],
                ],
            ]);

        // Serials strictly increased in the tables (YYYYMMDD01 today)...
        $expected = date('Ymd').'01';
        $this->assertSame($expected, (string) DB::table('dns_soa')->where('id', $zone)->value('serial'));
        $this->assertSame($expected, (string) DB::table('dns_rr')->where('id', $rrA)->value('serial'));
        $this->assertSame($expected, (string) DB::table('dns_rr')->where('id', $rrB)->value('serial'));
        // ...inactive zone/rr untouched.
        $this->assertSame('2024010101', (string) DB::table('dns_soa')->where('id', $inactiveZone)->value('serial'));

        // Emission order per zone: rr entries first, then the soa.
        $this->assertSame(
            ['dns_rr', 'dns_rr', 'dns_soa'],
            DB::table('sys_datalog')->orderBy('datalog_id')->pluck('dbtable')->all()
        );

        // dns entries are serial-change updates, not forced re-emissions.
        $rrRow = DB::table('sys_datalog')->where('dbtable', 'dns_rr')->where('dbidx', 'id:'.$rrA)->first();
        $data = unserialize($rrRow->data);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('2024010101', $data['old']['serial']);
        $this->assertSame($expected, $data['new']['serial']);
        $this->assertSame($data['old']['name'], $data['new']['name']);
    }

    public function test_resync_client_reemits_all_clients(): void
    {
        DB::table('client')->insert(['contact_name' => 'Alice']);
        DB::table('client')->insert(['contact_name' => 'Bob']);

        $this->postJson('/api/v1/system/resync', ['resync_client' => 1], $this->authHeaders())
            ->assertOk()
            ->assertExactJson([
                'total_datalog_entries' => 2,
                'resynced_tables' => [
                    ['table' => 'client', 'datalog_entries' => 2],
                ],
            ]);

        $this->assertSame(2, DB::table('sys_datalog')->where('dbtable', 'client')->where('action', 'u')->count());
    }

    public function test_resync_all_expands_every_flag_in_legacy_order(): void
    {
        $this->seedServer(1, ['web_server' => 1, 'mail_server' => 1, 'db_server' => 1, 'dns_server' => 1, 'file_server' => 1, 'vserver_server' => 1]);

        DB::table('web_domain')->insert(['server_id' => 1, 'domain' => 'a.tld', 'active' => 'y']);
        DB::table('client')->insert(['contact_name' => 'Alice']);

        $response = $this->postJson('/api/v1/system/resync', ['resync_all' => 1, 'all_server_id' => 0], $this->authHeaders())
            ->assertOk();

        // Every table of every service is reported, in the exact legacy
        // emission order — a selected service with no records is still a
        // success with 0 entries.
        $this->assertSame(self::ALL_TABLES_ORDER, array_column($response->json('resynced_tables'), 'table'));
        $this->assertSame(2, $response->json('total_datalog_entries'));

        $byTable = array_column($response->json('resynced_tables'), 'datalog_entries', 'table');
        $this->assertSame(1, $byTable['web_domain']);
        $this->assertSame(1, $byTable['client']);
        $this->assertSame(0, $byTable['mail_domain']);
        $this->assertSame(0, $byTable['dns_soa']);
    }

    public function test_resync_with_no_matching_records_is_a_success(): void
    {
        $this->seedServer(1, ['web_server' => 1]);

        $this->postJson('/api/v1/system/resync', ['resync_sites' => 1, 'web_server_id' => 0], $this->authHeaders())
            ->assertOk()
            ->assertExactJson([
                'total_datalog_entries' => 0,
                'resynced_tables' => [
                    ['table' => 'web_domain', 'datalog_entries' => 0],
                ],
            ]);
    }

    public function test_resync_unknown_server_id_returns_400_and_rolls_back(): void
    {
        $this->seedServer(1, ['web_server' => 1]);
        DB::table('web_domain')->insert(['server_id' => 1, 'domain' => 'a.tld', 'active' => 'y']);

        // The DNS flag references a nonexistent server: 400 — and the
        // web_domain entries already emitted are rolled back
        // (all-or-nothing, FR-013).
        $this->postJson('/api/v1/system/resync', [
            'resync_sites' => 1,
            'web_server_id' => 0,
            'resync_dns' => 1,
            'dns_server_id' => 99,
        ], $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 400);

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_resync_validation_failures_return_422(): void
    {
        foreach ([
            ['resync_sites' => 5],
            ['resync_sites' => 'yes'],
            ['web_server_id' => -1],
            ['resync_all' => 1, 'all_server_id' => 'abc'],
        ] as $payload) {
            $this->postJson('/api/v1/system/resync', $payload, $this->authHeaders())
                ->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 422);
        }

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    // ------------------------------------------------------------------
    // GET /system/resync/servers
    // ------------------------------------------------------------------

    public function test_servers_lists_legacy_candidates(): void
    {
        $this->seedServer(1, ['server_name' => 'alpha', 'web_server' => 1, 'mail_server' => 1]);
        $this->seedServer(2, ['server_name' => 'beta', 'dns_server' => 1]);
        $this->seedServer(3, ['server_name' => 'gamma', 'web_server' => 1, 'active' => 0]); // inactive
        $this->seedServer(4, ['server_name' => 'delta', 'web_server' => 1, 'mirror_server_id' => 1]); // mirror

        $this->getJson('/api/v1/system/resync/servers', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 2) // default: active=1 AND mirror_server_id=0
            ->assertJsonPath('data.0.id', 1) // sorted by server_name asc
            ->assertJsonPath('data.0.server_name', 'alpha')
            ->assertJsonPath('data.0.web_server', 1)
            ->assertJsonPath('data.0.mail_server', 1)
            ->assertJsonPath('data.0.dns_server', 0)
            ->assertJsonPath('data.0.active', 1)
            ->assertJsonPath('data.0.mirror_server_id', 0)
            ->assertJsonPath('data.1.server_name', 'beta')
            ->assertJsonMissingPath('data.0.config');

        $this->getJson('/api/v1/system/resync/servers?server_type=web', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.server_name', 'alpha');

        $this->getJson('/api/v1/system/resync/servers?server_type=dns', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.server_name', 'beta');

        // active=false narrows to inactive candidates (mirrors stay excluded).
        $this->getJson('/api/v1/system/resync/servers?active=false', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.server_name', 'gamma');
    }

    public function test_servers_pagination_and_sorting(): void
    {
        $this->seedServer(1, ['server_name' => 'aaa']);
        $this->seedServer(2, ['server_name' => 'bbb']);
        $this->seedServer(3, ['server_name' => 'ccc']);

        $this->getJson('/api/v1/system/resync/servers?limit=2&offset=1&sort=server_name&order=desc', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.limit', 2)
            ->assertJsonPath('meta.offset', 1)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.server_name', 'bbb')
            ->assertJsonPath('data.1.server_name', 'aaa');
    }

    public function test_servers_rejects_bad_parameters_with_400_problem(): void
    {
        foreach ([
            'server_type=proxy',
            'active=maybe',
            'sort=evil',
            'order=up',
            'limit=0',
            'offset=-1',
        ] as $param) {
            $this->getJson('/api/v1/system/resync/servers?'.$param, $this->authHeaders())
                ->assertStatus(400)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 400);
        }
    }
}
