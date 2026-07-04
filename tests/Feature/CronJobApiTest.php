<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\SitesApiTestCase;

class CronJobApiTest extends SitesApiTestCase
{
    protected function seedCronJob(int $parentId, array $overrides = []): int
    {
        return (int) DB::table('cron')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 5,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'parent_domain_id' => $parentId,
            'type' => 'url',
            'command' => 'https://example.com/cron.php',
            'run_min' => '*/5',
            'run_hour' => '*',
            'run_mday' => '*',
            'run_month' => '*',
            'run_wday' => '*',
            'log' => 'n',
            'active' => 'y',
        ], $overrides), 'id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validPayload(int $parentId, array $overrides = []): array
    {
        return array_merge([
            'parent_domain_id' => $parentId,
            'run_min' => '*/5',
            'run_hour' => '*',
            'run_mday' => '*',
            'run_month' => '*',
            'run_wday' => '*',
            'command' => 'https://example.com/cron.php',
        ], $overrides);
    }

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/sites/cron-jobs')->assertStatus(401);
    }

    public function test_list_envelope_search_and_bad_sort(): void
    {
        $parentId = $this->seedVhost();
        $this->seedCronJob($parentId, ['command' => 'https://one.example.com/']);
        $this->seedCronJob($parentId, ['command' => '/usr/bin/php /web/script.php']);

        $this->getJson('/api/v1/sites/cron-jobs', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.server_name', 'web1');

        $this->getJson('/api/v1/sites/cron-jobs?search=script.php', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/sites/cron-jobs?sort=hax', $this->authHeaders())
            ->assertStatus(400);
    }

    public function test_show_200_and_404(): void
    {
        $parentId = $this->seedVhost(['domain' => 'cronsite.com']);
        $id = $this->seedCronJob($parentId);

        $this->getJson('/api/v1/sites/cron-jobs/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('type', 'url')
            ->assertJsonPath('parent_domain', 'cronsite.com');

        $this->getJson('/api/v1/sites/cron-jobs/999', $this->authHeaders())
            ->assertStatus(404);
    }

    public function test_create_url_command_forces_type_url_and_datalogs_on_cron_table(): void
    {
        $parentId = $this->seedVhost();

        $response = $this->postJson('/api/v1/sites/cron-jobs', $this->validPayload($parentId), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('type', 'url')
            ->assertJsonPath('server_id', 1)
            ->assertJsonPath('sys_groupid', 5)
            ->assertJsonPath('active', true);

        $id = (int) $response->json('id');

        // Datalog on table `cron` (NOT web_cron) with dbidx id:<id>.
        $rows = $this->datalogRows('cron');
        $this->assertCount(1, $rows);
        $this->assertSame('i', $rows[0]->action);
        $this->assertSame('id:'.$id, $rows[0]->dbidx);

        $data = unserialize($rows[0]->data);
        $this->assertSame('url', $data['new']['type']);
        $this->assertSame('*/5', $data['new']['run_min']);
    }

    public function test_shell_command_type_derives_from_owning_client(): void
    {
        // Site owned by client 3 (limit_cron_type=url -> chrooted for shell).
        $urlClientSite = $this->seedVhost(['sys_groupid' => 5]);
        $this->postJson('/api/v1/sites/cron-jobs', $this->validPayload($urlClientSite, [
            'command' => '/usr/bin/php {DOMAIN}/script.php',
        ]), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('type', 'chrooted');

        // Site owned by client 4 (limit_cron_type=full).
        $fullClientSite = $this->seedVhost(['sys_groupid' => 6]);
        $this->postJson('/api/v1/sites/cron-jobs', $this->validPayload($fullClientSite, [
            'command' => '/usr/bin/php script.php',
        ]), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('type', 'full');

        // Admin-owned site (group 1, no client) -> full.
        $adminSite = $this->seedVhost(['sys_groupid' => 1]);
        $this->postJson('/api/v1/sites/cron-jobs', $this->validPayload($adminSite, [
            'command' => '/usr/bin/php script.php',
        ]), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('type', 'full');
    }

    public function test_time_field_validation_matches_legacy(): void
    {
        $parentId = $this->seedVhost();

        $badCases = [
            ['run_min' => '61'],          // out of range
            ['run_min' => 'a'],           // bad charset
            ['run_min' => '1,,2'],        // adjacent separators
            ['run_min' => '5-3'],         // range end <= start
            ['run_min' => '*/1'],         // step must be >= 2
            ['run_hour' => '24'],         // out of range
            ['run_mday' => '0'],          // min is 1
            ['run_month' => '13'],        // out of range
            ['run_wday' => '8'],          // out of range
            ['run_min' => '@reboot'],     // @reboot only in run_month
        ];

        foreach ($badCases as $overrides) {
            $field = array_key_first($overrides);
            $response = $this->postJson('/api/v1/sites/cron-jobs', $this->validPayload($parentId, $overrides), $this->authHeaders());
            $response->assertStatus(422);
            $this->assertArrayHasKey($field, $response->json('errors'), json_encode($overrides));
        }

        // Valid forms, including @reboot in run_month.
        foreach ([
            ['run_min' => '0-30/2'],
            ['run_month' => '@reboot'],
            ['run_wday' => '1,4,7'],
        ] as $overrides) {
            $this->postJson('/api/v1/sites/cron-jobs', $this->validPayload($parentId, $overrides), $this->authHeaders())
                ->assertStatus(201);
        }
    }

    public function test_command_validation_matches_legacy(): void
    {
        $parentId = $this->seedVhost();

        $badCommands = [
            "https://example.com/a\nb",      // newline
            'https://bad_host/x',            // invalid hostname
            'ftp://example.com/x',           // scheme must be http(s)
            'https://example.com/x\\y',      // backslash in URL
        ];

        foreach ($badCommands as $command) {
            $this->postJson('/api/v1/sites/cron-jobs', $this->validPayload($parentId, ['command' => $command]), $this->authHeaders())
                ->assertStatus(422)
                ->assertJsonStructure(['errors' => ['command']]);
        }

        // {DOMAIN} placeholder substituted with the parent domain before
        // URL validation.
        $this->postJson('/api/v1/sites/cron-jobs', $this->validPayload($parentId, [
            'command' => 'https://{DOMAIN}/cron.php',
        ]), $this->authHeaders())->assertStatus(201);
    }

    public function test_update_rederives_type_and_suppresses_no_change(): void
    {
        $parentId = $this->seedVhost(['sys_groupid' => 6]); // client 4: full
        $id = $this->seedCronJob($parentId);

        // Switching to a shell command re-derives the type.
        $this->putJson('/api/v1/sites/cron-jobs/'.$id, [
            'command' => '/usr/bin/php cleanup.php',
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('type', 'full');

        DB::table('sys_datalog')->delete();

        $this->putJson('/api/v1/sites/cron-jobs/'.$id, [
            'command' => '/usr/bin/php cleanup.php',
        ], $this->authHeaders())->assertOk();

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_delete_returns_204_with_datalog(): void
    {
        $parentId = $this->seedVhost();
        $id = $this->seedCronJob($parentId);

        $this->deleteJson('/api/v1/sites/cron-jobs/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('cron', ['id' => $id]);
        $rows = $this->datalogRows('cron');
        $this->assertCount(1, $rows);
        $this->assertSame('d', $rows[0]->action);
        $this->assertSame('id:'.$id, $rows[0]->dbidx);
    }
}
