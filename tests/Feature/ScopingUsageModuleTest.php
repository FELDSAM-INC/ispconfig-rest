<?php

namespace Tests\Feature;

use Tests\Support\UsageApiTestCase;

/**
 * Tenant isolation of the usage module (spec 017 SC-002, FR-001): the admin /
 * reseller / client A / client B matrix over all 9 operations. Client keys
 * never see another client's rows, totals, counts or history, and usage
 * routes are never admin-gated.
 */
class ScopingUsageModuleTest extends UsageApiTestCase
{
    /**
     * @var array<string, array{site: int, mailbox: int, database: int}>
     */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['clientA' => 'a', 'clientB' => 'b', 'reseller' => 'r'] as $tenant => $prefix) {
            $this->ids[$tenant] = [
                'site' => $this->site($tenant, "{$prefix}.test", ['system_user' => "web{$prefix}"]),
                'mailbox' => $this->mailbox($tenant, "info@{$prefix}.test", 1048576),
                'database' => $this->database($tenant, "c{$prefix}db", 10),
            ];
            $this->webTraffic("{$prefix}.test", '2026-09-01', 100);
        }

        $this->blob(1, 'harddisk_quota', ['user' => [
            'weba' => ['used' => '1'], 'webb' => ['used' => '2'], 'webr' => ['used' => '3'],
        ]]);
        $this->blob(1, 'email_quota', [
            'info@a.test' => ['used' => 1], 'info@b.test' => ['used' => 2], 'info@r.test' => ['used' => 3],
        ]);
        $this->blob(1, 'database_size', [
            ['database_name' => 'cadb', 'size' => 1], ['database_name' => 'cbdb', 'size' => 2], ['database_name' => 'crdb', 'size' => 3],
        ]);
    }

    /**
     * @return array<string, array<int, int>>
     */
    private function listedIds(string $tenant): array
    {
        return [
            'site' => array_column($this->getAs($tenant, '/usage/web-domains')->assertOk()->json('data'), 'domain_id'),
            'mailbox' => array_column($this->getAs($tenant, '/usage/mail-users')->assertOk()->json('data'), 'mailuser_id'),
            'database' => array_column($this->getAs($tenant, '/usage/databases')->assertOk()->json('data'), 'database_id'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function uris(string $owner): array
    {
        $ids = $this->ids[$owner];

        return [
            '/usage/web-domains/'.$ids['site'],
            '/usage/web-domains/'.$ids['site'].'/traffic',
            '/usage/mail-users/'.$ids['mailbox'],
            '/usage/mail-users/'.$ids['mailbox'].'/traffic',
            '/usage/databases/'.$ids['database'],
        ];
    }

    public function test_client_keys_list_only_their_own_rows(): void
    {
        foreach (['clientA', 'clientB'] as $tenant) {
            $listed = $this->listedIds($tenant);

            foreach (['site', 'mailbox', 'database'] as $kind) {
                $this->assertSame([$this->ids[$tenant][$kind]], $listed[$kind], "{$tenant} {$kind}");
            }
        }
    }

    public function test_client_keys_get_404_for_other_clients_rows_and_never_403(): void
    {
        foreach (['clientA' => 'clientB', 'clientB' => 'clientA'] as $tenant => $other) {
            foreach ($this->uris($other) as $uri) {
                $this->getAs($tenant, $uri)->assertNotFound();
            }

            foreach ($this->uris($tenant) as $uri) {
                $this->getAs($tenant, $uri)->assertOk();
            }

            $this->getAs($tenant, '/usage/summary')->assertOk()->assertJsonPath('client_id', $this->tenant($tenant)['client_id']);
            $this->getAs($tenant, '/usage/summary?client_id='.$this->tenant($other)['client_id'])->assertNotFound();
        }
    }

    public function test_summary_totals_and_counts_never_include_other_clients(): void
    {
        $this->getAs('clientA', '/usage/summary')
            ->assertOk()
            ->assertJsonPath('web_disk.used_bytes', 1024)
            ->assertJsonPath('mail_storage.used_bytes', 1)
            ->assertJsonPath('database_size.used_bytes', 1)
            ->assertJsonPath('web_traffic_this_month.used_bytes', 100)
            ->assertJsonPath('counts.web_domains.used', 1)
            ->assertJsonPath('counts.mailboxes.used', 1)
            ->assertJsonPath('counts.databases.used', 1);
    }

    public function test_reseller_sees_own_and_child_client_rows_but_not_foreign(): void
    {
        $listed = $this->listedIds('reseller');

        foreach (['site', 'mailbox', 'database'] as $kind) {
            $expected = [$this->ids['clientA'][$kind], $this->ids['reseller'][$kind]];
            sort($expected);
            $actual = $listed[$kind];
            sort($actual);
            $this->assertSame($expected, $actual, "reseller {$kind}");
        }

        foreach ($this->uris('clientB') as $uri) {
            $this->getAs('reseller', $uri)->assertNotFound();
        }

        foreach ($this->uris('clientA') as $uri) {
            $this->getAs('reseller', $uri)->assertOk();
        }
    }

    public function test_admin_sees_every_client(): void
    {
        $listed = $this->listedIds('admin');

        foreach (['site', 'mailbox', 'database'] as $kind) {
            $this->assertCount(3, $listed[$kind], "admin {$kind}");
        }

        foreach (['clientA', 'clientB', 'reseller'] as $owner) {
            foreach ($this->uris($owner) as $uri) {
                $this->getAs('admin', $uri)->assertOk();
            }
        }
    }
}
