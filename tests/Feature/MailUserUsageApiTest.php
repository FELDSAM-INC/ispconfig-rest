<?php

namespace Tests\Feature;

use Tests\Support\UsageApiTestCase;

/**
 * Mailbox usage (contract: api/modules/usage/mail-users.yaml, spec 017 US2/US3).
 */
class MailUserUsageApiTest extends UsageApiTestCase
{
    private const MB = 1048576;

    private int $m1;

    private int $m4;

    protected function setUp(): void
    {
        parent::setUp();

        $this->m1 = $this->mailbox('clientA', 'info@a1.test', 10 * self::MB);
        $this->mailbox('clientA', 'sales@a1.test', 0);
        $this->mailbox('clientA', 'info@a2.test', -1);
        $this->m4 = $this->mailbox('clientB', 'info@b1.test', 1000);

        $this->blob(1, 'email_quota', [
            'info@a1.test' => ['used' => self::MB],
            'sales@a1.test' => ['used' => 2048],
            'info@b1.test' => ['used' => 7],
        ], 300);

        $this->mailTraffic($this->m1, '2026-09', 4096);
        $this->mailTraffic($this->m1, '2026-08', 1024);
        $this->mailTraffic($this->m1, '2025-12', 10);
    }

    public function test_lists_readable_mailboxes_with_storage_and_traffic(): void
    {
        $response = $this->getAs('clientA', '/usage/mail-users')->assertOk();

        $response->assertJsonPath('meta.total', 3);
        $this->assertSame(['info@a1.test', 'info@a2.test', 'sales@a1.test'], array_column($response->json('data'), 'email'));

        $rows = collect($response->json('data'))->keyBy('email');

        $m1 = $rows['info@a1.test'];
        $this->assertSame($this->m1, $m1['mailuser_id']);
        $this->assertSame(1, $m1['server_id']);
        $this->assertSame(self::MB, $m1['used_bytes']);
        $this->assertSame(10 * self::MB, $m1['quota_bytes']);
        $this->assertEqualsWithDelta(10.0, $m1['used_percent'], 0.0001);
        $this->assertSame($this->iso(300), $m1['measured_at']);
        $this->assertSame(['this_month' => 4096, 'last_month' => 1024, 'this_year' => 5120, 'last_year' => 10], $m1['traffic']);

        // quota 0 = unlimited
        $this->assertSame(2048, $rows['sales@a1.test']['used_bytes']);
        $this->assertNull($rows['sales@a1.test']['quota_bytes']);
        $this->assertNull($rows['sales@a1.test']['used_percent']);

        // missing from the blob, quota -1 = unlimited
        $this->assertNull($rows['info@a2.test']['used_bytes']);
        $this->assertNull($rows['info@a2.test']['measured_at']);
        $this->assertNull($rows['info@a2.test']['quota_bytes']);
        $this->assertSame(['this_month' => 0, 'last_month' => 0, 'this_year' => 0, 'last_year' => 0], $rows['info@a2.test']['traffic']);
    }

    public function test_filters_and_strict_parameters(): void
    {
        $this->assertSame(
            ['info@a1.test', 'sales@a1.test'],
            array_column($this->getAs('clientA', '/usage/mail-users?mail_domain=a1.test')->assertOk()->json('data'), 'email')
        );
        $this->assertSame(
            ['info@a1.test', 'info@a2.test'],
            array_column($this->getAs('clientA', '/usage/mail-users?email=info@*')->assertOk()->json('data'), 'email')
        );

        $this->getAs('clientA', '/usage/mail-users?sort=quota')->assertStatus(400);
        $this->getAs('clientA', '/usage/mail-users?client_id='.$this->tenant('clientA')['client_id'])->assertStatus(400);
        $this->getAs('admin', '/usage/mail-users?client_id='.$this->tenant('clientA')['client_id'])->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_detail_is_scoped(): void
    {
        $this->getAs('clientA', '/usage/mail-users/'.$this->m1)
            ->assertOk()
            ->assertJsonPath('email', 'info@a1.test')
            ->assertJsonPath('used_bytes', self::MB);

        $this->getAs('clientA', '/usage/mail-users/'.$this->m4)->assertNotFound();
    }

    public function test_monthly_mail_traffic_history(): void
    {
        $this->getAs('clientA', '/usage/mail-users/'.$this->m1.'/traffic?months=2')
            ->assertOk()
            ->assertJsonPath('granularity', 'month')
            ->assertJsonPath('period_start', '2026-08-01')
            ->assertJsonPath('period_end', '2026-10-01')
            ->assertJsonPath('timezone', 'Europe/Prague')
            ->assertJsonPath('points', [
                ['period' => '2026-08', 'bytes' => 1024],
                ['period' => '2026-09', 'bytes' => 4096],
            ]);

        $points = $this->getAs('clientA', '/usage/mail-users/'.$this->m1.'/traffic')->assertOk()->json('points');
        $this->assertCount(12, $points);
        $this->assertSame(['period' => '2025-12', 'bytes' => 10], $points[2]);
    }

    public function test_daily_granularity_and_invalid_months_are_rejected_for_mailboxes(): void
    {
        $this->getAs('clientA', '/usage/mail-users/'.$this->m1.'/traffic?granularity=day')->assertStatus(422);
        $this->getAs('clientA', '/usage/mail-users/'.$this->m1.'/traffic?months=37')->assertStatus(422);
    }

    public function test_mail_traffic_history_is_scoped(): void
    {
        $this->getAs('clientA', '/usage/mail-users/'.$this->m4.'/traffic')->assertNotFound();
    }
}
