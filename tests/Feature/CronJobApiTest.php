<?php

namespace Tests\Feature;

use App\Support\CronOutputLog;
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

    public function test_list_filters_by_parent_domain_id(): void
    {
        $parentA = $this->seedVhost(['domain' => 'a.com']);
        $parentB = $this->seedVhost(['domain' => 'b.com']);
        $this->seedCronJob($parentA, ['command' => 'https://a.example.com/']);
        $this->seedCronJob($parentB, ['command' => 'https://b.example.com/']);

        $this->getJson('/api/v1/sites/cron-jobs?parent_domain_id='.$parentA, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.parent_domain_id', $parentA);

        $this->getJson('/api/v1/sites/cron-jobs?parent_domain_id=abc', $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 400);
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

    public function test_output_log_wraps_only_the_native_command(): void
    {
        $chrooted = $this->seedVhost(['sys_groupid' => 5]);
        $root = DB::table('web_domain')->where('domain_id', $chrooted)->value('document_root');
        $id = $this->postJson('/api/v1/sites/cron-jobs', $this->validPayload($chrooted, [
            'command' => $root.'/web/cron.php # nightly', 'output_log' => true, 'log' => true,
        ]), $this->authHeaders())->assertCreated()
            ->assertJsonPath('type', 'chrooted')->assertJsonPath('output_log', true)->assertJsonPath('log', false)
            // ISPConfig drops a chrooted task's leading document root; behind the prefix the API does it
            ->assertJsonPath('command', '/web/cron.php # nightly')->json('id');
        $native = DB::table('cron')->where('id', $id)->value('command');
        $this->assertMatchesRegularExpression("~\\Acommand exec >>'/private/\\.ispcp-cron-[a-f0-9]{32}\\.log' 2>&1; .*; /web/cron\\.php # nightly\\z~", $native);
        $rows = $this->datalogRows('cron');
        $this->assertStringContainsString('command exec >>', end($rows)->data, 'ISPConfig receives the prefixed command');
        $token = CronOutputLog::parse($native)['token'];

        // Edits keep the task's file; the list shows and searches the task's own command
        $this->putJson('/api/v1/sites/cron-jobs/'.$id, ['command' => '/web/other.php'], $this->authHeaders())->assertOk()
            ->assertJsonPath('command', '/web/other.php')->assertJsonPath('output_log', true);
        $this->putJson('/api/v1/sites/cron-jobs/'.$id, ['run_min' => '0'], $this->authHeaders())->assertOk()->assertJsonPath('command', '/web/other.php');
        $this->assertSame(['token' => $token, 'command' => '/web/other.php'], array_intersect_key(CronOutputLog::parse(DB::table('cron')->where('id', $id)->value('command')), ['token' => 1, 'command' => 1]));
        $this->getJson('/api/v1/sites/cron-jobs?search=other.php', $this->authHeaders())->assertOk()->assertJsonPath('data.0.command', '/web/other.php');

        $this->putJson('/api/v1/sites/cron-jobs/'.$id, ['output_log' => false], $this->authHeaders())->assertOk()->assertJsonPath('output_log', false);
        $this->assertSame('/web/other.php', DB::table('cron')->where('id', $id)->value('command'));

        // Full tasks write into the document root's private directory; URL tasks keep ISPConfig's wget line
        $full = $this->seedVhost(['sys_groupid' => 6]);
        $fullId = $this->postJson('/api/v1/sites/cron-jobs', $this->validPayload($full, ['command' => '/usr/bin/php script.php', 'output_log' => true]), $this->authHeaders())
            ->assertCreated()->assertJsonPath('type', 'full')->json('id');
        $this->assertStringStartsWith("command exec >>'".DB::table('web_domain')->where('domain_id', $full)->value('document_root')."/private/.ispcp-cron-", DB::table('cron')->where('id', $fullId)->value('command'));
        $this->postJson('/api/v1/sites/cron-jobs', $this->validPayload($full, ['output_log' => true]), $this->authHeaders())
            ->assertCreated()->assertJsonPath('type', 'url')->assertJsonPath('output_log', false)->assertJsonPath('command', 'https://example.com/cron.php');
    }

    public function test_output_log_is_read_through_the_web_log_worker(): void
    {
        $site = $this->seedVhost(['sys_groupid' => 6]);
        $root = DB::table('web_domain')->where('domain_id', $site)->value('document_root');
        $plain = $this->seedCronJob($site, ['type' => 'full', 'command' => '/usr/bin/php x.php']);
        $this->getJson('/api/v1/sites/cron-jobs/'.$plain.'/log', $this->authHeaders())->assertOk()->assertJsonPath('state', 'disabled');

        $logged = $this->seedCronJob($site, ['type' => 'full', 'command' => CronOutputLog::wrap('/usr/bin/php x.php', str_repeat('a', 32), 'full', $root)]);
        $url = '/api/v1/sites/cron-jobs/'.$logged.'/log?lines=50';
        $this->getJson($url, $this->authHeaders())->assertOk()->assertJsonPath('state', 'unavailable');
        DB::table('api_web_log_workers')->insert(['server_id' => 1, 'heartbeat' => time()]);
        $this->getJson($url, $this->authHeaders())->assertOk()->assertJsonPath('state', 'pending')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(['kind' => 'cron', 'cron_id' => $logged, 'lines' => 50], json_decode(DB::table('api_web_log_reads')->value('request'), true));
        DB::table('api_web_log_reads')->update(['result' => json_encode(['lines' => ['=== Fri Oct  2 08:00:01 UTC 2026 ===', 'hello', '=== exit 0 ==='], 'size' => 64, 'modified_at' => 1790000000, 'truncated' => false])]);
        $this->getJson($url, $this->authHeaders())->assertOk()->assertJson(['state' => 'ready', 'lines' => ['=== Fri Oct  2 08:00:01 UTC 2026 ===', 'hello', '=== exit 0 ==='],
            'size' => 64, 'modified_at' => gmdate('c', 1790000000), 'truncated' => false]);
        $this->getJson('/api/v1/sites/cron-jobs/'.$logged.'/log?lines=0', $this->authHeaders())->assertStatus(422);
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
