<?php

namespace Tests\Feature;

use Tests\Support\UsageApiTestCase;

/**
 * Website usage (contract: api/modules/usage/web-domains.yaml, spec 017 US2/US3).
 */
class WebDomainUsageApiTest extends UsageApiTestCase
{
    private const MB = 1048576;

    private int $a1;

    private int $a2;

    private int $a3;

    private int $sub;

    private int $legacy;

    private int $b1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a1 = $this->site('clientA', 'a1.test', ['system_user' => 'web1', 'hd_quota' => 1024, 'traffic_quota' => 10240]);
        $this->a2 = $this->site('clientA', 'a2.test', ['system_user' => 'web2', 'hd_quota' => 2]);
        $this->a3 = $this->site('clientA', 'a3.test', ['system_user' => 'web4']);
        $this->sub = $this->site('clientA', 'sub.a1.test', ['type' => 'vhostsubdomain', 'parent_domain_id' => $this->a1, 'system_user' => 'web1']);
        $this->site('clientA', 'alias.a1.test', ['type' => 'vhostalias', 'parent_domain_id' => $this->a1, 'system_user' => 'web1']);
        $this->legacy = $this->site('clientA', 'old.a1.test', ['type' => 'subdomain', 'parent_domain_id' => $this->a1]);
        $this->b1 = $this->site('clientB', 'b1.test', ['system_user' => 'web9']);

        $this->blob(1, 'harddisk_quota', [
            'user' => [
                'web1' => ['used' => '868', 'soft' => '1048576', 'hard' => '1049600', 'files' => '43'],
                'web2' => ['used' => '1024', 'soft' => '0', 'hard' => '0', 'files' => '5'],
                'web9' => ['used' => '5000', 'soft' => '0', 'hard' => '0', 'files' => '9'],
            ],
            'group' => [],
        ], 120);

        $this->webTraffic('a1.test', '2026-09-10', 1000);
        $this->webTraffic('a1.test', '2026-08-20', 300);
        $this->webTraffic('a1.test', '2026-01-15', 50);
        $this->webTraffic('a1.test', '2025-12-31', 20);
        $this->webTraffic('sub.a1.test', '2026-09-11', 500);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rowsByDomain(array $data): array
    {
        return collect($data)->keyBy('domain')->all();
    }

    public function test_lists_readable_vhost_type_websites_with_disk_and_traffic(): void
    {
        $response = $this->getAs('clientA', '/usage/web-domains')->assertOk();

        $response->assertJsonPath('meta.total', 5);
        $this->assertSame(
            ['a1.test', 'a2.test', 'a3.test', 'alias.a1.test', 'sub.a1.test'],
            array_column($response->json('data'), 'domain')
        );

        $rows = $this->rowsByDomain($response->json('data'));

        $a1 = $rows['a1.test'];
        $this->assertSame($this->a1, $a1['domain_id']);
        $this->assertSame('vhost', $a1['type']);
        $this->assertSame(0, $a1['parent_domain_id']);
        $this->assertSame(1, $a1['server_id']);
        $this->assertSame(868 * 1024, $a1['disk']['used_bytes']);
        $this->assertSame(1048576 * 1024, $a1['disk']['soft_limit_bytes']);
        $this->assertSame(1049600 * 1024, $a1['disk']['hard_limit_bytes']);
        $this->assertSame(43, $a1['disk']['files']);
        $this->assertEqualsWithDelta(0.1, $a1['disk']['used_percent'], 0.0001);
        $this->assertSame($this->iso(120), $a1['disk']['measured_at']);
        $this->assertSame(1024 * self::MB, $a1['hd_quota_bytes']);
        $this->assertSame(['this_month' => 1000, 'last_month' => 300, 'this_year' => 1350, 'last_year' => 20], $a1['traffic']);
        $this->assertSame(10240 * self::MB, $a1['traffic_quota_bytes']);

        // no soft limit from the collector: percent against the website's hd_quota
        $a2 = $rows['a2.test'];
        $this->assertSame(1024 * 1024, $a2['disk']['used_bytes']);
        $this->assertNull($a2['disk']['soft_limit_bytes']);
        $this->assertNull($a2['disk']['hard_limit_bytes']);
        $this->assertEqualsWithDelta(50.0, $a2['disk']['used_percent'], 0.0001);
        $this->assertSame(['this_month' => 0, 'last_month' => 0, 'this_year' => 0, 'last_year' => 0], $a2['traffic']);
        $this->assertNull($a2['traffic_quota_bytes']);

        // system user missing from the blob: unknown disk usage
        $a3 = $rows['a3.test'];
        $this->assertNull($a3['disk']['used_bytes']);
        $this->assertNull($a3['disk']['measured_at']);
        $this->assertNull($a3['hd_quota_bytes']);

        // child sites: disk accounted to the parent vhost
        $sub = $rows['sub.a1.test'];
        $this->assertSame('vhostsubdomain', $sub['type']);
        $this->assertSame($this->a1, $sub['parent_domain_id']);
        $this->assertSame(
            ['used_bytes' => null, 'soft_limit_bytes' => null, 'hard_limit_bytes' => null, 'files' => null, 'used_percent' => null, 'measured_at' => null],
            $sub['disk']
        );
        $this->assertSame(500, $sub['traffic']['this_month']);
    }

    public function test_filters_sorting_and_strict_parameters(): void
    {
        $this->assertSame(
            ['alias.a1.test', 'sub.a1.test'],
            array_column($this->getAs('clientA', '/usage/web-domains?domain=*.a1.test')->assertOk()->json('data'), 'domain')
        );

        $this->getAs('clientA', '/usage/web-domains?parent_domain_id='.$this->a1)->assertOk()->assertJsonPath('meta.total', 2);

        $this->assertSame('sub.a1.test', $this->getAs('clientA', '/usage/web-domains?order=desc')->json('data.0.domain'));

        $this->getAs('clientA', '/usage/web-domains?sort=server_id')->assertStatus(400);
        $this->getAs('clientA', '/usage/web-domains?foo=1')->assertStatus(400);
    }

    public function test_client_id_filter_is_for_admin_and_reseller_keys_only(): void
    {
        $clientA = $this->tenant('clientA')['client_id'];

        $this->getAs('admin', '/usage/web-domains?client_id='.$clientA)->assertOk()->assertJsonPath('meta.total', 5);
        $this->getAs('reseller', '/usage/web-domains?client_id='.$clientA)->assertOk()->assertJsonPath('meta.total', 5);

        $this->getAs('clientA', '/usage/web-domains?client_id='.$clientA)
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_other_client_sees_only_its_own_websites(): void
    {
        $response = $this->getAs('clientB', '/usage/web-domains')->assertOk();

        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.domain', 'b1.test');
        $response->assertJsonPath('data.0.disk.used_bytes', 5000 * 1024);
    }

    public function test_detail_is_scoped_and_limited_to_vhost_types(): void
    {
        $this->getAs('clientA', '/usage/web-domains/'.$this->a1)
            ->assertOk()
            ->assertJsonPath('domain', 'a1.test')
            ->assertJsonPath('disk.used_bytes', 868 * 1024)
            ->assertJsonPath('traffic.this_month', 1000);

        $this->getAs('clientA', '/usage/web-domains/'.$this->b1)->assertNotFound();
        $this->getAs('clientA', '/usage/web-domains/'.$this->legacy)->assertNotFound();
        $this->getAs('clientA', '/usage/web-domains/999999')->assertNotFound();
    }
}
