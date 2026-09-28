<?php

namespace Tests\Feature;

use App\Services\ClientResourceLimitsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ClientApiTestCase;
use Tests\Support\SitesSchema;
use Tests\Support\SystemSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;

/** Administrator `web_resource_limits` on clients (spec 053). */
final class ClientResourceLimitsTest extends ClientApiTestCase
{
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        SitesSchema::create();
        TenantSchema::create();
        SystemSchema::create();
        $this->seedTenants();
        Schema::table('server', fn ($table) => $table->text('config')->nullable());
        DB::table('server')->where('server_id', 1)->update(['config' => "[web]\nserver_type=apache\n"]);
        DB::table('sys_ini')->insert(['sysini_id' => 1, 'config' => "[sites]\nweb_php_options=no,fast-cgi,php-fpm\n"]);
    }

    private function limits(array $changes = []): array
    {
        return array_replace_recursive([
            'account' => ['cpu_percent' => 200, 'memory_mb' => 2048, 'tasks' => 256],
            'website' => ['cpu_percent' => 100, 'memory_mb' => 1024, 'tasks' => 64],
        ], $changes);
    }

    private function clientUrl(string $tenant = 'clientA'): string
    {
        return '/api/v1/clients/'.$this->tenants[$tenant]['client_id'];
    }

    private function apply(?array $limits, string $tenant = 'clientA')
    {
        return $this->putJson($this->clientUrl($tenant), ['web_resource_limits' => $limits], $this->authHeaders());
    }

    private function phpPolicy(int $children): array
    {
        return [
            'force_fpm' => false, 'php_fpm_use_socket' => true, 'php_fpm_chroot' => false, 'pm' => 'ondemand',
            'pm_max_children' => $children, 'pm_start_servers' => 2, 'pm_min_spare_servers' => 1, 'pm_max_spare_servers' => 5,
            'pm_process_idle_timeout' => 10, 'pm_max_requests' => 0,
            'ini' => ['memory_limit' => '256M', 'max_execution_time' => 30, 'max_input_time' => 60, 'post_max_size' => '8M', 'upload_max_filesize' => '2M'],
        ];
    }

    public function test_administrator_sets_reads_changes_and_removes_limits(): void
    {
        $clientId = $this->tenants['clientA']['client_id'];
        $this->getJson($this->clientUrl(), $this->authHeaders())->assertOk()->assertJsonPath('web_resource_limits', null);
        $this->apply($this->limits())->assertOk()
            ->assertJsonPath('web_resource_limits.account.memory_mb', 2048)
            ->assertJsonPath('web_resource_limits.website.cpu_percent', 100);
        $this->getJson($this->clientUrl(), $this->authHeaders())->assertOk()->assertJsonPath('web_resource_limits', $this->limits());
        $service = app(ClientResourceLimitsService::class);
        $revision = $service->revision($clientId);
        $this->assertNotNull($revision);

        // An identical save keeps the revision, so workers do not rewrite units.
        $this->apply($this->limits())->assertOk();
        $this->assertSame($revision, $service->revision($clientId));
        // Omission preserves; a change moves the revision forward.
        $this->putJson($this->clientUrl(), ['contact_name' => 'Renamed'], $this->authHeaders())->assertOk()
            ->assertJsonPath('web_resource_limits.account.tasks', 256);
        $this->apply($this->limits(['account' => ['cpu_percent' => null]]))->assertOk()->assertJsonPath('web_resource_limits.account.cpu_percent', null);
        $this->assertGreaterThan($revision, $service->revision($clientId));

        $this->apply(null)->assertOk()->assertJsonPath('web_resource_limits', null);
        $this->assertSame(0, DB::table('api_client_resource_limits')->count());
        // The client list does not carry administrator policies.
        $this->assertArrayNotHasKey('web_resource_limits', $this->getJson('/api/v1/clients', $this->authHeaders())->assertOk()->json('data.0'));
    }

    public function test_client_creation_stores_limits_and_deletion_forgets_them(): void
    {
        $result = $this->postJson('/api/v1/clients', [
            'username' => 'limited', 'contact_name' => 'Limited Customer', 'email' => 'limited@example.test', 'password' => 'Password123!',
            'web_resource_limits' => $this->limits(),
        ], $this->authHeaders())->assertCreated()->assertJsonPath('web_resource_limits.account.cpu_percent', 200);
        $clientId = $result->json('id');
        DB::table('api_php_limits_usage')->insert(['server_id' => 1, 'scope' => 'account', 'scope_id' => $clientId, 'client_id' => $clientId,
            'state' => 'isolated', 'measured_at' => time()]);
        $this->deleteJson('/api/v1/clients/'.$clientId, [], $this->authHeaders())->assertNoContent();
        $this->assertSame(0, DB::table('api_client_resource_limits')->count());
        $this->assertSame(0, DB::table('api_php_limits_usage')->count());
    }

    public function test_reseller_and_customer_cannot_set_or_clear_limits(): void
    {
        $this->apply($this->limits())->assertOk();
        foreach (['clientA', 'clientB', 'reseller'] as $tenant) {
            foreach ([$this->limits(['account' => ['memory_mb' => 999999]]), null] as $limits) {
                $this->putJson($this->clientUrl(), ['web_resource_limits' => $limits], $this->tenantHeaders($tenant))->assertForbidden();
            }
        }
        $this->assertSame(2048, app(ClientResourceLimitsService::class)->limits($this->tenants['clientA']['client_id'])['account']['memory_mb']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidLimits(): array
    {
        return [
            'website above account' => [['account' => ['cpu_percent' => 100], 'website' => ['cpu_percent' => 150]]],
            'memory below minimum' => [['account' => ['memory_mb' => 32]]],
            'tasks below minimum' => [['website' => ['tasks' => 4]]],
            'string value' => [['account' => ['cpu_percent' => '200%']]],
            'unknown field' => [['account' => ['io_weight' => 100]]],
            'unknown level' => [['server' => ['cpu_percent' => 100]]],
        ];
    }

    /** @dataProvider invalidLimits */
    public function test_invalid_limits_are_rejected(array $changes): void
    {
        $this->apply(array_replace_recursive($this->limits(), $changes))->assertUnprocessable();
        $this->assertSame(0, DB::table('api_client_resource_limits')->count());
    }

    public function test_missing_level_or_field_is_rejected(): void
    {
        $this->apply(['account' => ['cpu_percent' => 100, 'memory_mb' => null, 'tasks' => null]])->assertUnprocessable();
        $this->apply(['account' => ['cpu_percent' => 100], 'website' => ['cpu_percent' => null, 'memory_mb' => null, 'tasks' => null]])->assertUnprocessable();
    }

    public function test_all_null_limits_isolate_without_limiting(): void
    {
        $empty = ['cpu_percent' => null, 'memory_mb' => null, 'tasks' => null];
        $this->apply(['account' => $empty, 'website' => $empty])->assertOk()->assertJsonPath('web_resource_limits.account', $empty);
    }

    public function test_tasks_limit_must_fit_the_php_policy_workers(): void
    {
        $this->putJson($this->clientUrl(), ['web_php_policy' => $this->phpPolicy(80)], $this->authHeaders())->assertOk();
        $this->apply($this->limits())->assertUnprocessable()->assertJsonValidationErrors('web_resource_limits');
        $this->apply($this->limits(['website' => ['tasks' => 81]]))->assertOk();

        // Raising the PHP workers past the stored limit is refused and rolled back.
        $this->putJson($this->clientUrl(), ['web_php_policy' => $this->phpPolicy(120)], $this->authHeaders())->assertUnprocessable();
        $settings = DB::table('api_client_web_php_policies')->where('client_id', $this->tenants['clientA']['client_id'])->value('settings');
        $this->assertSame(80, json_decode($settings, true)['pm_max_children']);
        // Both together are checked against each other.
        $this->putJson($this->clientUrl(), ['web_php_policy' => $this->phpPolicy(120), 'web_resource_limits' => $this->limits(['website' => ['tasks' => 121]])], $this->authHeaders())->assertOk();
    }
}
