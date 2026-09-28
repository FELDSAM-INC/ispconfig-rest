<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\UsageApiTestCase;

/** GET /usage/resources (contract: api/modules/usage/resources.yaml, spec 053). */
class ResourceUsageApiTest extends UsageApiTestCase
{
    private const LIMITS = [
        'account' => ['cpu_percent' => 200, 'memory_mb' => 2048, 'tasks' => 256],
        'website' => ['cpu_percent' => 100, 'memory_mb' => null, 'tasks' => 64],
    ];

    private int $fpm;

    private int $cgi;

    private int $sub;

    private int $off;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fpm = $this->site('clientA', 'fpm.test', ['php' => 'php-fpm']);
        $this->cgi = $this->site('clientA', 'cgi.test', ['php' => 'fast-cgi']);
        $this->sub = $this->site('clientA', 'sub.fpm.test', ['php' => 'php-fpm', 'type' => 'vhostsubdomain', 'parent_domain_id' => $this->fpm]);
        $this->off = $this->site('clientA', 'off.test', ['php' => 'php-fpm', 'active' => 'n']);
        $this->site('clientA', 'alias.fpm.test', ['type' => 'alias', 'parent_domain_id' => $this->fpm]);
        $this->site('clientB', 'other.test', ['php' => 'php-fpm']);
        DB::table('client')->where('client_id', $this->tenants['clientA']['client_id'])->update(['web_servers' => '1']);
    }

    private function limit(int $revision = 5): void
    {
        DB::table('api_client_resource_limits')->insert(['client_id' => $this->tenants['clientA']['client_id'],
            'settings' => json_encode(self::LIMITS), 'revision' => $revision]);
    }

    private function worker(int $age = 30, string $status = 'ready', int $server = 1): void
    {
        DB::table('api_php_limits_workers')->insert(['server_id' => $server, 'heartbeat' => $this->now - $age, 'version' => 1, 'status' => $status, 'services' => '[]']);
    }

    private function usage(string $scope, int $id, array $changes = []): void
    {
        DB::table('api_php_limits_usage')->insert(array_replace([
            'server_id' => 1, 'scope' => $scope, 'scope_id' => $id, 'client_id' => $this->tenants['clientA']['client_id'],
            'state' => 'isolated', 'reason' => null, 'php_service' => $scope === 'website' ? 'php8.3-fpm' : null, 'applied_revision' => 5,
            'memory_bytes' => 104857600, 'memory_peak_bytes' => 209715200, 'memory_limit_bytes' => 2147483648, 'memory_limit_hits_24h' => 2,
            'cpu_percent' => 12.345, 'cpu_percent_24h' => 3.21, 'cpu_limit_percent' => 200, 'cpu_limited_minutes_24h' => 4,
            'tasks' => 9, 'tasks_limit' => 256, 'tasks_limit_hits_24h' => 0, 'measured_at' => $this->now - 20,
        ], $changes));
    }

    /** @return array<int, array<string, mixed>> domain id => row */
    private function websites(string $tenant = 'clientA', string $query = ''): array
    {
        $rows = $this->getAs($tenant, '/usage/resources'.$query)->assertOk()->json('websites');

        return array_column($rows, null, 'domain_id');
    }

    public function test_without_limits_every_website_is_unlimited(): void
    {
        $this->worker();
        $response = $this->getAs('clientA', '/usage/resources')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('client_id', $this->tenants['clientA']['client_id'])
            ->assertJsonPath('limits', null)->assertJsonPath('revision', null)->assertJsonPath('available', true)
            ->assertJsonPath('servers.0.server_id', 1)->assertJsonPath('servers.0.applied', true)->assertJsonPath('servers.0.usage', null)
            ->assertJsonPath('freshness.interval_seconds', 60)->assertJsonPath('freshness.stale_after_seconds', 180)
            ->assertJsonPath('freshness.measured_at', null);
        $websites = array_column($response->json('websites'), null, 'domain');
        // Simple aliases share their parent's PHP runtime and are not listed.
        $this->assertSame(['cgi.test', 'fpm.test', 'off.test', 'sub.fpm.test'], array_keys($websites));
        $this->assertSame(['unlimited'], array_values(array_unique(array_column($websites, 'state'))));
    }

    public function test_limited_account_reports_containment_and_usage(): void
    {
        $this->limit();
        $this->worker();
        $this->usage('account', $this->tenants['clientA']['client_id']);
        $this->usage('website', $this->fpm, ['memory_limit_bytes' => 1073741824]);
        $this->usage('website', $this->sub, ['state' => 'fallback', 'reason' => 'unit_failed']);
        $response = $this->getAs('clientA', '/usage/resources')->assertOk()
            ->assertJsonPath('limits', self::LIMITS)->assertJsonPath('revision', 5)
            ->assertJsonPath('servers.0.applied', true)
            ->assertJsonPath('servers.0.usage.memory_bytes', 104857600)
            ->assertJsonPath('servers.0.usage.cpu_percent', 12.3)
            ->assertJsonPath('servers.0.usage.memory_limit_hits_24h', 2)
            ->assertJsonPath('servers.0.usage.measured_at', $this->iso(20))
            ->assertJsonPath('freshness.measured_at', $this->iso(20))
            ->assertJsonPath('freshness.next_expected_at', $this->iso(-40));
        $websites = array_column($response->json('websites'), null, 'domain_id');
        $this->assertSame('isolated', $websites[$this->fpm]['state']);
        $this->assertSame(1073741824, $websites[$this->fpm]['usage']['memory_limit_bytes']);
        $this->assertSame(['fallback', 'unit_failed'], [$websites[$this->sub]['state'], $websites[$this->sub]['reason']]);
        $this->assertSame(['not_fpm', 'php_mode_fast_cgi', null], [$websites[$this->cgi]['state'], $websites[$this->cgi]['reason'], $websites[$this->cgi]['usage']]);
        $this->assertSame('inactive', $websites[$this->off]['state']);
    }

    public function test_changed_limits_are_pending_until_the_worker_applies_them(): void
    {
        $this->limit(6);
        $this->worker();
        $this->usage('account', $this->tenants['clientA']['client_id']);
        $this->usage('website', $this->fpm);
        $this->getAs('clientA', '/usage/resources')->assertOk()->assertJsonPath('servers.0.applied', false);
        $this->assertSame('pending', $this->websites()[$this->fpm]['state']);
        // A new pool the worker has not seen yet is pending as well.
        $this->assertSame('pending', $this->websites()[$this->sub]['state']);
    }

    public function test_stale_or_missing_worker_is_unavailable(): void
    {
        $this->limit();
        $this->worker(200);
        $this->usage('website', $this->fpm);
        $this->getAs('clientA', '/usage/resources')->assertOk()->assertJsonPath('available', false)->assertJsonPath('servers.0.available', false);
        $this->assertSame('unavailable', $this->websites()[$this->fpm]['state']);
        DB::table('api_php_limits_workers')->update(['heartbeat' => $this->now, 'status' => 'unsupported']);
        $this->assertSame('unavailable', $this->websites()[$this->fpm]['state']);
    }

    public function test_stale_usage_keeps_its_timestamp_without_values(): void
    {
        $this->limit();
        $this->worker();
        $this->usage('account', $this->tenants['clientA']['client_id'], ['measured_at' => $this->now - 600]);
        $this->usage('website', $this->fpm, ['measured_at' => $this->now - 600]);
        $response = $this->getAs('clientA', '/usage/resources')->assertOk()
            ->assertJsonPath('servers.0.usage.memory_bytes', null)
            ->assertJsonPath('servers.0.usage.measured_at', $this->iso(600))
            ->assertJsonPath('freshness.measured_at', $this->iso(600));
        $this->assertSame('pending', array_column($response->json('websites'), null, 'domain_id')[$this->fpm]['state']);
    }

    public function test_server_without_websites_counts_as_applied(): void
    {
        $this->limit();
        $this->worker();
        $this->worker(10, 'ready', 2);
        DB::table('client')->where('client_id', $this->tenants['clientA']['client_id'])->update(['web_servers' => '1,2']);
        $this->usage('account', $this->tenants['clientA']['client_id']);
        $this->getAs('clientA', '/usage/resources')->assertOk()
            ->assertJsonPath('servers.1.server_id', 2)->assertJsonPath('servers.1.applied', true)->assertJsonPath('servers.1.usage', null);
    }

    public function test_target_client_rules_follow_the_usage_summary(): void
    {
        $clientA = $this->tenants['clientA']['client_id'];
        $clientB = $this->tenants['clientB']['client_id'];
        $this->getAs('clientB', '/usage/resources?client_id='.$clientA)->assertNotFound();
        $this->getAs('reseller', '/usage/resources?client_id='.$clientA)->assertOk()->assertJsonPath('client_id', $clientA);
        $this->getAs('reseller', '/usage/resources?client_id='.$clientB)->assertNotFound();
        $this->getAs('admin', '/usage/resources')->assertUnprocessable();
        $this->getAs('admin', '/usage/resources?client_id='.$clientB)->assertOk()
            ->assertJsonPath('websites.0.domain', 'other.test');
        $this->getAs('clientA', '/usage/resources?client_id=abc')->assertUnprocessable();
        $this->getAs('clientA', '/usage/resources?limit=5')->assertStatus(400);
    }
}
