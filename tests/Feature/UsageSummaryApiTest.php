<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\UsageApiTestCase;

/**
 * GET /usage/summary (contract: api/modules/usage/summary.yaml, spec 017 US1).
 */
class UsageSummaryApiTest extends UsageApiTestCase
{
    private const MB = 1048576;

    private int $a1;

    protected function setUp(): void
    {
        parent::setUp();

        // Client A: two quota-carrying vhosts, one inactive vhost, a vhost
        // subdomain, a legacy subdomain; two mailboxes; one database.
        $this->a1 = $this->site('clientA', 'a1.test', ['system_user' => 'web1', 'hd_quota' => 1024, 'traffic_quota' => 10240]);
        $this->site('clientA', 'a2.test', ['system_user' => 'web2', 'hd_quota' => 2]);
        $this->site('clientA', 'a3.test', ['system_user' => 'web3', 'active' => 'n']);
        $this->site('clientA', 'sub.a1.test', ['type' => 'vhostsubdomain', 'parent_domain_id' => $this->a1, 'system_user' => 'web1']);
        $this->site('clientA', 'old.a1.test', ['type' => 'subdomain', 'parent_domain_id' => $this->a1]);
        $this->mailbox('clientA', 'info@a1.test', 10 * self::MB);
        $this->mailbox('clientA', 'nolimit@a1.test', 0);
        $this->database('clientA', 'c1a', 512);

        // Client B.
        $this->site('clientB', 'b1.test', ['system_user' => 'web9', 'hd_quota' => 50]);
        $this->mailbox('clientB', 'info@b1.test', 1000);
        $this->database('clientB', 'c2b', 7);

        $this->setClientLimit('clientA', 'limit_web_quota', 4);
        $this->setClientLimit('clientA', 'limit_mailquota', 100);
        $this->setClientLimit('clientA', 'limit_web_domain', 5);
        $this->setClientLimit('clientA', 'limit_cron', 0);

        $this->blob(1, 'harddisk_quota', [
            'user' => [
                'web1' => ['used' => '868', 'soft' => '1048576', 'hard' => '1049600', 'files' => '43'],
                'web2' => ['used' => '1024', 'soft' => '0', 'hard' => '0', 'files' => '5'],
                'web3' => ['used' => '10', 'soft' => '0', 'hard' => '0', 'files' => '1'],
                'web9' => ['used' => '5000', 'soft' => '0', 'hard' => '0', 'files' => '9'],
            ],
            'group' => ['client1' => ['used' => '1652', 'soft' => '0', 'hard' => '0']],
        ], 120);
        $this->blob(1, 'email_quota', [
            'info@a1.test' => ['used' => self::MB],
            'info@b1.test' => ['used' => 7],
        ], 300);
        $this->blob(1, 'database_size', [
            ['database_name' => 'c1a', 'size' => 5 * self::MB, 'sys_groupid' => '2'],
            ['database_name' => 'c2b', 'size' => 99, 'sys_groupid' => '3'],
        ], 60);

        $this->webTraffic('a1.test', '2026-09-10', 1000);
        $this->webTraffic('sub.a1.test', '2026-09-11', 500);
        $this->webTraffic('a3.test', '2026-09-12', 777);   // inactive website: excluded
        $this->webTraffic('a1.test', '2026-08-20', 300);   // last month: excluded
        $this->webTraffic('b1.test', '2026-09-10', 9999);  // other client
    }

    public function test_client_key_gets_its_own_totals_against_limits(): void
    {
        $response = $this->getAs('clientA', '/usage/summary')->assertOk();

        $response->assertJsonPath('client_id', $this->tenant('clientA')['client_id']);

        // web disk: vhosts only, KiB -> bytes, 868 + 1024 + 10 KiB
        $response->assertJsonPath('web_disk.used_bytes', (868 + 1024 + 10) * 1024);
        $response->assertJsonPath('web_disk.allocated_bytes', (1024 + 2) * self::MB);
        $response->assertJsonPath('web_disk.limit_bytes', 4 * self::MB);
        $this->assertEqualsWithDelta(46.4, $response->json('web_disk.used_percent'), 0.0001);
        $response->assertJsonPath('web_disk.measured_at', $this->iso(120));

        // mail storage: the mailbox missing from the blob does not contribute
        $response->assertJsonPath('mail_storage.used_bytes', self::MB);
        $response->assertJsonPath('mail_storage.allocated_bytes', 10 * self::MB);
        $response->assertJsonPath('mail_storage.limit_bytes', 100 * self::MB);
        $this->assertEqualsWithDelta(1.0, $response->json('mail_storage.used_percent'), 0.0001);
        $response->assertJsonPath('mail_storage.measured_at', $this->iso(300));

        // database size: unlimited plan limit
        $response->assertJsonPath('database_size.used_bytes', 5 * self::MB);
        $response->assertJsonPath('database_size.allocated_bytes', 512 * self::MB);
        $response->assertJsonPath('database_size.limit_bytes', null);
        $response->assertJsonPath('database_size.used_percent', null);
        $response->assertJsonPath('database_size.measured_at', $this->iso(60));

        // this month's traffic of ACTIVE vhost-type websites only
        $response->assertJsonPath('web_traffic_this_month.used_bytes', 1500);
        $response->assertJsonPath('web_traffic_this_month.allocated_bytes', 10240 * self::MB);
        $response->assertJsonPath('web_traffic_this_month.limit_bytes', null);
        $response->assertJsonPath('web_traffic_this_month.used_percent', null);
        $response->assertJsonPath('web_traffic_this_month.measured_at', null);

        // counts (spec 012 rules): vhost-only web domain count, both subdomain kinds
        $response->assertJsonPath('counts.web_domains', ['used' => 3, 'limit' => 5]);
        $response->assertJsonPath('counts.web_subdomains', ['used' => 2, 'limit' => null]);
        $response->assertJsonPath('counts.web_alias_domains', ['used' => 0, 'limit' => null]);
        $response->assertJsonPath('counts.mailboxes', ['used' => 2, 'limit' => null]);
        $response->assertJsonPath('counts.databases', ['used' => 1, 'limit' => null]);
        $response->assertJsonPath('counts.cron_jobs', ['used' => 0, 'limit' => 0]);
        $response->assertJsonPath('counts.dns_zones', ['used' => 0, 'limit' => null]);
        $this->assertCount(16, $response->json('counts'));

        $response->assertJsonPath('period', ['this_month_start' => '2026-09-01', 'timezone' => 'Europe/Prague']);
    }

    public function test_other_client_sees_only_its_own_figures(): void
    {
        $this->getAs('clientB', '/usage/summary')
            ->assertOk()
            ->assertJsonPath('client_id', $this->tenant('clientB')['client_id'])
            ->assertJsonPath('web_disk.used_bytes', 5000 * 1024)
            ->assertJsonPath('mail_storage.used_bytes', 7)
            ->assertJsonPath('database_size.used_bytes', 99)
            ->assertJsonPath('web_traffic_this_month.used_bytes', 9999)
            ->assertJsonPath('counts.web_domains.used', 1);
    }

    public function test_admin_must_name_the_client(): void
    {
        $this->getAs('admin', '/usage/summary')
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonStructure(['errors' => ['client_id']]);

        $this->getAs('admin', '/usage/summary?client_id='.$this->tenant('clientA')['client_id'])
            ->assertOk()
            ->assertJsonPath('client_id', $this->tenant('clientA')['client_id'])
            ->assertJsonPath('web_disk.used_bytes', (868 + 1024 + 10) * 1024);

        $this->getAs('admin', '/usage/summary?client_id=999999')->assertNotFound();
    }

    public function test_reseller_gets_own_or_child_client_but_not_foreign(): void
    {
        $this->getAs('reseller', '/usage/summary')
            ->assertOk()
            ->assertJsonPath('client_id', $this->tenant('reseller')['client_id']);

        $this->getAs('reseller', '/usage/summary?client_id='.$this->tenant('clientA')['client_id'])
            ->assertOk()
            ->assertJsonPath('client_id', $this->tenant('clientA')['client_id'])
            ->assertJsonPath('web_disk.used_bytes', (868 + 1024 + 10) * 1024);

        $this->getAs('reseller', '/usage/summary?client_id='.$this->tenant('clientB')['client_id'])->assertNotFound();
    }

    public function test_client_key_may_only_name_itself(): void
    {
        $this->getAs('clientA', '/usage/summary?client_id='.$this->tenant('clientA')['client_id'])->assertOk();
        $this->getAs('clientA', '/usage/summary?client_id='.$this->tenant('clientB')['client_id'])->assertNotFound();
    }

    public function test_parameter_validation(): void
    {
        $this->getAs('clientA', '/usage/summary?foo=1')->assertStatus(400);
        $this->getAs('admin', '/usage/summary?client_id=0')->assertStatus(422);
        $this->getAs('admin', '/usage/summary?client_id=abc')->assertStatus(422);
        $this->getJson('/api/v1/usage/summary')->assertUnauthorized();
    }

    public function test_stale_or_missing_collector_data_makes_values_unknown(): void
    {
        DB::table('monitor_data')->where('type', 'harddisk_quota')->update(['created' => $this->now - 1801]);
        DB::table('monitor_data')->where('type', 'email_quota')->delete();

        $response = $this->getAs('clientA', '/usage/summary')->assertOk();

        $response->assertJsonPath('web_disk.used_bytes', null);
        $response->assertJsonPath('web_disk.measured_at', null);
        $response->assertJsonPath('web_disk.used_percent', null);
        $response->assertJsonPath('web_disk.allocated_bytes', (1024 + 2) * self::MB);
        $response->assertJsonPath('mail_storage.used_bytes', null);
        $response->assertJsonPath('database_size.used_bytes', 5 * self::MB);
    }

    public function test_client_without_control_panel_identity_is_not_found(): void
    {
        $clientId = (int) DB::table('client')->insertGetId(['username' => 'nologin', 'contact_name' => 'No Login', 'limit_client' => 0, 'parent_client_id' => 0], 'client_id');
        DB::table('sys_group')->insert(['name' => 'nologin', 'client_id' => $clientId]);

        $this->getAs('admin', '/usage/summary?client_id='.$clientId)->assertNotFound();
    }

    public function test_reading_the_summary_writes_nothing(): void
    {
        $before = DB::table('sys_datalog')->count();

        $this->getAs('clientA', '/usage/summary')->assertOk()->assertHeaderMissing('X-Change-Set-Id');
        $this->getAs('admin', '/usage/summary?client_id='.$this->tenant('clientB')['client_id'])->assertOk();

        $this->assertSame($before, DB::table('sys_datalog')->count());
    }

    public function test_mail_counts_cover_catchalls_alias_domains_filters_and_fetchmail(): void
    {
        $this->setClientLimit('clientA', 'limit_mailcatchall', 1);
        $this->setClientLimit('clientA', 'limit_mailfilter', 5);
        $this->setClientLimit('clientA', 'limit_fetchmail', 0);

        foreach ([
            ['catchall', '@a1.test'], ['aliasdomain', '@alias1.test'], ['aliasdomain', '@alias2.test'], ['alias', 'x@a1.test'],
        ] as [$type, $source]) {
            DB::table('mail_forwarding')->insert($this->ownedBy('clientA', [
                'server_id' => 1, 'type' => $type, 'source' => $source, 'destination' => 'info@a1.test', 'active' => 'y',
            ]));
        }

        DB::table('mail_forwarding')->insert($this->ownedBy('clientB', [
            'server_id' => 1, 'type' => 'catchall', 'source' => '@b1.test', 'destination' => 'info@b1.test', 'active' => 'y',
        ]));

        $box = (int) DB::table('mail_user')->where('email', 'info@a1.test')->value('mailuser_id');

        foreach (['one', 'two'] as $rule) {
            DB::table('mail_user_filter')->insert($this->ownedBy('clientA', ['mailuser_id' => $box, 'rulename' => $rule, 'active' => 'y']));
        }

        DB::table('mail_get')->insert($this->ownedBy('clientA', [
            'server_id' => 1, 'type' => 'imap', 'source_server' => 'imap.example.com', 'source_username' => 'u',
            'destination' => 'info@a1.test',
        ]));

        $this->getAs('clientA', '/usage/summary')
            ->assertOk()
            ->assertJsonPath('counts.mail_catchalls', ['used' => 1, 'limit' => 1])
            ->assertJsonPath('counts.mail_alias_domains', ['used' => 2, 'limit' => null])
            ->assertJsonPath('counts.mail_filters', ['used' => 2, 'limit' => 5])
            ->assertJsonPath('counts.fetchmail_accounts', ['used' => 1, 'limit' => 0]);

        $this->getAs('clientB', '/usage/summary')
            ->assertOk()
            ->assertJsonPath('counts.mail_catchalls', ['used' => 1, 'limit' => null])
            ->assertJsonPath('counts.mail_filters', ['used' => 0, 'limit' => null]);
    }
}
