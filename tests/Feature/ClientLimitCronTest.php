<?php

namespace Tests\Feature;

use App\Support\ProblemType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SitesSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Scheduled task plan rules for scoped keys (spec 035 US3; legacy
 * cron_edit.php:170-220 onInsertSave/onUpdateSave): the shortest interval
 * (`limit_cron_frequency`) and the URL-only case of `limit_cron_type`, both
 * checked for client and reseller keys on create and update.
 */
class ClientLimitCronTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        SitesSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        DB::table('server')->insert([
            'server_id' => 1, 'server_name' => 'web1', 'web_server' => 1, 'mirror_server_id' => 0, 'active' => 1,
        ]);

        foreach (['clientA', 'reseller'] as $tenant) {
            $this->assignServers($tenant, ['web' => [1]]);
            // The pre-existing count limit (limit_cron) must not mask the rules under test.
            $this->setClientLimit($tenant, 'limit_cron', 10);
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function setClient(string $tenant, array $attrs): void
    {
        DB::table('client')->where('client_id', $this->tenant($tenant)['client_id'])->update($attrs);
    }

    private function seedVhost(string $owner): int
    {
        $id = (int) DB::table('web_domain')->insertGetId($this->ownedBy($owner, [
            'server_id' => 1, 'ip_address' => '*', 'domain' => 'v'.uniqid().'.test', 'type' => 'vhost',
            'parent_domain_id' => 0, 'vhost_type' => 'name', 'hd_quota' => -1, 'traffic_quota' => -1,
            'active' => 'y', 'allow_override' => 'All', 'backup_copies' => 1, 'cgi' => 'n', 'ssi' => 'n',
            'perl' => 'n', 'ruby' => 'n', 'python' => 'n', 'suexec' => 'y', 'errordocs' => 0,
            'subdomain' => 'www', 'ssl' => 'n', 'ssl_letsencrypt' => 'n', 'directive_snippets_id' => 0,
            'php' => 'no', 'server_php_id' => 0,
        ]), 'domain_id');

        DB::table('web_domain')->where('domain_id', $id)->update([
            'document_root' => "/var/www/clients/client/web{$id}",
            'system_user' => "web{$id}", 'system_group' => 'client',
        ]);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $parentId, array $overrides = []): array
    {
        return array_merge([
            'parent_domain_id' => $parentId,
            'run_min' => '0',
            'run_hour' => '*',
            'run_mday' => '*',
            'run_month' => '*',
            'run_wday' => '*',
            'command' => 'https://example.com/cron.php',
        ], $overrides);
    }

    public function test_schedule_more_frequent_than_the_plan_is_refused(): void
    {
        $parentId = $this->seedVhost('clientA');
        $this->setClient('clientA', ['limit_cron_frequency' => 60, 'limit_cron_type' => 'url']);

        $datalog = DB::table('sys_datalog')->count();

        $this->postJson('/api/v1/sites/cron-jobs', $this->payload($parentId, ['run_min' => '*/5']), $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('type', ProblemType::uri(ProblemType::LIMIT_REACHED))
            ->assertJsonPath('limit.name', 'limit_cron_frequency')
            ->assertJsonPath('limit.scope', 'client')
            ->assertJsonPath('limit.max', 60)
            ->assertJsonPath('limit.used', 5);

        $this->assertSame($datalog, DB::table('sys_datalog')->count());
        $this->assertSame(0, DB::table('cron')->count());

        // Hourly is allowed.
        $this->postJson('/api/v1/sites/cron-jobs', $this->payload($parentId), $this->tenantHeaders('clientA'))
            ->assertStatus(201);
    }

    public function test_update_is_checked_against_the_merged_schedule(): void
    {
        $parentId = $this->seedVhost('clientA');
        $this->setClient('clientA', ['limit_cron_frequency' => 60, 'limit_cron_type' => 'url']);

        $id = $this->postJson('/api/v1/sites/cron-jobs', $this->payload($parentId), $this->tenantHeaders('clientA'))
            ->assertStatus(201)
            ->json('id');

        $datalog = DB::table('sys_datalog')->count();

        $this->putJson('/api/v1/sites/cron-jobs/'.$id, ['run_min' => '*/10'], $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('limit.name', 'limit_cron_frequency')
            ->assertJsonPath('limit.used', 10);

        $this->assertSame($datalog, DB::table('sys_datalog')->count());
        $this->assertSame('0', DB::table('cron')->where('id', $id)->value('run_min'));
    }

    public function test_command_kind_excluded_by_the_plan_is_refused(): void
    {
        $parentId = $this->seedVhost('clientA');
        $this->setClient('clientA', ['limit_cron_frequency' => 0, 'limit_cron_type' => 'url']);

        $datalog = DB::table('sys_datalog')->count();

        $this->postJson('/api/v1/sites/cron-jobs', $this->payload($parentId, ['command' => '/usr/bin/php -v']), $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('type', ProblemType::uri(ProblemType::FEATURE_NOT_ALLOWED))
            ->assertJsonPath('feature', 'limit_cron_type');

        $this->assertSame($datalog, DB::table('sys_datalog')->count());

        // The same command is accepted once the plan allows shell tasks.
        $this->setClient('clientA', ['limit_cron_type' => 'chrooted']);

        $this->postJson('/api/v1/sites/cron-jobs', $this->payload($parentId, ['command' => '/usr/bin/php -v']), $this->tenantHeaders('clientA'))
            ->assertStatus(201);
    }

    public function test_unconstrained_frequency_and_admin_keys_are_not_limited(): void
    {
        $parentId = $this->seedVhost('clientA');

        // 0 and 1 never refuse a schedule (legacy checks only > 1).
        foreach ([0, 1] as $frequency) {
            $this->setClient('clientA', ['limit_cron_frequency' => $frequency, 'limit_cron_type' => 'url']);

            $this->postJson('/api/v1/sites/cron-jobs', $this->payload($parentId, ['run_min' => '*']), $this->tenantHeaders('clientA'))
                ->assertStatus(201);
        }

        // Admin keys bypass both rules.
        $this->setClient('clientA', ['limit_cron_frequency' => 60, 'limit_cron_type' => 'url']);

        $this->postJson('/api/v1/sites/cron-jobs', $this->payload($parentId, ['run_min' => '*/5', 'command' => '/usr/bin/php -v']), $this->tenantHeaders('admin'))
            ->assertStatus(201);
    }

    public function test_locked_account_is_refused_by_the_lock_guard_not_the_plan_rules(): void
    {
        $parentId = $this->seedVhost('clientA');
        $this->setClient('clientA', ['limit_cron_frequency' => 60, 'limit_cron_type' => 'url', 'locked' => 'y']);

        // Spec 019 outranks the plan rules: the account-locked refusal wins
        // even though the schedule and the command also break the plan.
        $this->postJson('/api/v1/sites/cron-jobs', $this->payload($parentId, ['run_min' => '*/5']), $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('type', ProblemType::uri(ProblemType::ACCOUNT_LOCKED));

        $this->assertSame(0, DB::table('cron')->count());
    }

    public function test_invalid_expression_still_fails_validation(): void
    {
        $parentId = $this->seedVhost('clientA');
        $this->setClient('clientA', ['limit_cron_frequency' => 60, 'limit_cron_type' => 'url']);

        $this->postJson('/api/v1/sites/cron-jobs', $this->payload($parentId, ['run_min' => 'nonsense']), $this->tenantHeaders('clientA'))
            ->assertStatus(422);
    }
}
