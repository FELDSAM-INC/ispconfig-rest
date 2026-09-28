<?php

namespace Tests\Feature;

use App\Models\WebDomain;
use App\Services\WordPressService;
use App\Support\WordPressPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SitesApiTestCase;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;

final class WordPressApiTest extends SitesApiTestCase
{
    use TenantFixtures;

    private function prepareSite(array $attributes = []): array
    {
        DB::table('api_wordpress_workers')->insertOrIgnore(['server_id' => 1, 'heartbeat' => time(), 'version' => '1', 'available' => true]);
        $id = $this->seedVhost($attributes);
        $site = WebDomain::findOrFail($id);
        $installation = ['id' => WordPressPolicy::id('blog'), 'path' => 'blog', 'url' => 'https://example.test/blog', 'version' => '6.8', 'title' => 'Example', 'database_id' => 42, 'undo' => ['private' => 'secret'], 'security' => []];
        DB::table('api_wordpress_sites')->insert(['website_id' => $id, 'server_id' => 1, 'identity' => app(WordPressService::class)->identity($site), 'scanned_at' => time(), 'installations' => json_encode([$installation])]);

        return [$id, '/api/v1/sites/web-domains/'.$id.'/wordpress', $installation['id']];
    }

    public function test_inventory_and_jobs_are_tenant_scoped_without_private_metadata(): void
    {
        TenantSchema::create();
        $this->seedTenants();
        [$id, $url, $installation] = $this->prepareSite($this->ownedBy('clientA'));
        $headers = $this->tenantHeaders('clientA');
        $this->getJson($url, $headers)->assertOk()->assertJsonPath('installations.0.path', 'blog')->assertJsonMissingPath('installations.0.database_id')->assertJsonMissingPath('installations.0.undo');
        $this->getJson($url, $this->tenantHeaders('clientB'))->assertNotFound();
        $this->postJson($url.'/jobs', ['action' => 'rescan'])->assertUnauthorized();
        $this->postJson($url.'/jobs', ['action' => 'rescan'], $this->tenantHeaders('clientB'))->assertNotFound();
        $job = $this->postJson($url.'/jobs', ['action' => 'check', 'installation' => $installation], $headers)->assertCreated()->json('id');
        $this->getJson($url.'/jobs/'.$job, $headers)->assertOk();
        $this->getJson($url.'/jobs/'.$job, $this->tenantHeaders('clientB'))->assertNotFound();
        $this->postJson($url.'/jobs', ['action' => 'rescan'], $headers)->assertConflict();
        DB::table('web_domain')->where('domain_id', $id)->update(['domain' => 'renamed.example.test']);
        $this->getJson($url, $headers)->assertJsonCount(0, 'installations')->assertJsonPath('job', null);
        $this->getJson($url.'/jobs/'.$job, $headers)->assertNotFound();
    }

    public function test_native_rules_are_datalogged_and_preserve_other_blocks(): void
    {
        [$id, $url, $installation] = $this->prepareSite(['apache_directives' => "# Existing WAF\r\nHeader set X-Test test\r\n"]);
        $body = ['action' => 'secure', 'installation' => $installation, 'measures' => ['xmlrpc', 'uploads_php']];
        $this->postJson($url.'/jobs', $body, $this->authHeaders())->assertCreated();
        $raw = DB::table('web_domain')->where('domain_id', $id)->value('apache_directives');
        $this->assertStringContainsString("# Existing WAF\r\nHeader set X-Test test\r\n", $raw);
        $this->assertStringContainsString('(?i)^/blog/xmlrpc', $raw);
        $this->assertCount(1, $this->datalogRows('web_domain'));
        DB::table('api_wordpress_jobs')->update(['status' => 'completed']);
        $body['action'] = 'revert';
        $body['measures'] = ['xmlrpc'];
        $this->postJson($url.'/jobs', $body, $this->authHeaders())->assertCreated();
        $raw = DB::table('web_domain')->where('domain_id', $id)->value('apache_directives');
        $this->assertStringNotContainsString('xmlrpc', $raw);
        $this->assertStringContainsString('uploads_php', $raw);
    }

    public function test_arbitrary_commands_paths_and_unconfirmed_irreversible_actions_are_rejected(): void
    {
        [$id, $url, $installation] = $this->prepareSite();
        foreach ([['command' => 'id'], ['path' => '../../'], ['measures' => ['unknown']], ['measures' => ['salts']], ['database_change' => true], ['measures' => ['admin_login'], 'confirmed' => true, 'backup' => true], ['measures' => ['permissions'], 'action' => 'revert']] as $override) {
            $this->postJson($url.'/jobs', array_replace(['action' => 'secure', 'installation' => $installation, 'measures' => ['xmlrpc']], $override), $this->authHeaders())->assertUnprocessable();
        }
        $this->assertCount(0, $this->datalogRows('web_domain'));
        $this->assertSame(0, DB::table('api_wordpress_jobs')->count());
    }

    public function test_database_changes_need_owned_database_and_worker_v3_but_no_backup_worker(): void
    {
        [$id, $url, $install] = $this->prepareSite();
        $body = ['action' => 'secure', 'installation' => $install, 'measures' => ['prefix']];
        $this->postJson($url.'/jobs', $body, $this->authHeaders())->assertConflict();
        DB::table('api_wordpress_workers')->update(['version' => '3']);
        $this->postJson($url.'/jobs', $body, $this->authHeaders())->assertConflict();
        $site = WebDomain::findOrFail($id);
        DB::table('web_database')->insert(['database_id' => 42, 'database_name' => 'owned', 'server_id' => 1, 'sys_groupid' => $site->sys_groupid, 'sys_userid' => 1, 'active' => 'y', 'sys_perm_user' => 'riud', 'sys_perm_group' => 'riud']);
        $job = $this->postJson($url.'/jobs', $body, $this->authHeaders())->assertCreated()->assertJsonPath('backup_id', null)->json('id');
        $stored = DB::table('api_wordpress_jobs')->where('id', $job)->first();
        $this->assertTrue(json_decode($stored->request, true)['database_change']);
        $this->assertSame(0, DB::table('api_database_operations')->count());
        $this->deleteJson('/api/v1/sites/databases/42', [], $this->authHeaders())->assertConflict();
        DB::table('api_wordpress_jobs')->update(['status' => 'completed']);
        $body['action'] = 'revert';
        $this->postJson($url.'/jobs', $body, $this->authHeaders())->assertConflict();
        $row = DB::table('api_wordpress_sites')->where('website_id', $id)->first();
        $installs = json_decode($row->installations, true);
        $installs[0]['security']['prefix'] = ['status' => 'ok', 'can_revert' => true];
        $installs[0]['undo']['prefix'] = ['previous' => 'wp_', 'applied' => 'wp_example_', 'database' => 'owned'];
        DB::table('api_wordpress_sites')->where('website_id', $id)->update(['installations' => json_encode($installs)]);
        $this->postJson($url.'/jobs', $body, $this->authHeaders())->assertCreated()->assertJsonPath('backup_id', null);
        $this->getJson($url, $this->authHeaders())->assertJsonMissingPath('installations.0.undo');
    }

    public function test_offline_locked_and_read_only_sites_cannot_queue_changes(): void
    {
        TenantSchema::create();
        $this->seedTenants();
        [$id, $url] = $this->prepareSite($this->ownedBy('clientA', ['sys_perm_user' => 'r', 'sys_perm_group' => 'r']));
        $this->postJson($url.'/jobs', ['action' => 'rescan'], $this->tenantHeaders('clientA'))->assertForbidden();
        DB::table('api_wordpress_workers')->update(['heartbeat' => time() - 200]);
        $this->postJson($url.'/jobs', ['action' => 'rescan'], $this->authHeaders())->assertConflict();
        $this->getJson($url, $this->authHeaders())->assertJsonPath('available', false);
    }

    public function test_policy_roundtrip_rejects_injection_and_survives_crlf(): void
    {
        $original = "# WAF\nInclude /etc/ispconfig-waf/base.conf\n";
        $raw = WordPressPolicy::replace($original, '', WordPressPolicy::SERVER);
        $raw = WordPressPolicy::replace($raw, 'nested/blog', ['xmlrpc']);
        $blocks = WordPressPolicy::blocks(str_replace("\n", "\r\n", $raw));
        $this->assertCount(2, $blocks);
        $this->assertSame(WordPressPolicy::SERVER, $blocks[WordPressPolicy::id('')]['measures']);
        $this->assertSame($original, WordPressPolicy::replace(WordPressPolicy::replace($raw, '', []), 'nested/blog', []));
        foreach (['../outside', '/absolute', 'a//b', "x\" >\nRequire all granted", 'a/./b', 'a/'] as $path) {
            try {
                WordPressPolicy::compile($path, ['xmlrpc']);
                $this->fail('Unsafe path accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        WordPressPolicy::blocks(str_replace('Require all denied', 'Require all granted', $raw));
    }

    public function test_vhost_children_have_independent_inventory_and_offline_cache(): void
    {
        $parent = $this->seedVhost();
        foreach (['vhostsubdomain', 'vhostalias'] as $type) {
            [$id, $url] = $this->prepareSite(['type' => $type, 'parent_domain_id' => $parent, 'web_folder' => $type, 'domain' => $type.'.example.test']);
            $this->getJson($url, $this->authHeaders())->assertOk()->assertJsonCount(1, 'installations');
            DB::table('api_wordpress_workers')->update(['heartbeat' => time() - 200]);
            $this->getJson('/api/v1/sites/web-domains/'.$id, $this->authHeaders())->assertJsonPath('wordpress.count', 1)->assertJsonPath('wordpress.available', false);
            $this->getJson('/api/v1/sites/web-domains/'.$parent.'/wordpress', $this->authHeaders())->assertJsonCount(0, 'installations');
            DB::table('api_wordpress_workers')->update(['heartbeat' => time()]);
        }
    }

    public function test_pending_jobs_block_site_changes_and_recovery_blocks_database_credentials(): void
    {
        [$id, $url] = $this->prepareSite();
        $job = $this->postJson($url.'/jobs', ['action' => 'rescan'], $this->authHeaders())->assertCreated()->json('id');
        $this->putJson('/api/v1/sites/web-domains/'.$id, ['active' => false], $this->authHeaders())->assertConflict();
        $this->deleteJson('/api/v1/sites/web-domains/'.$id, [], $this->authHeaders())->assertConflict();
        $user = DB::table('web_database_user')->insertGetId(['database_user' => 'c3test', 'server_id' => 0, 'sys_userid' => 1, 'sys_groupid' => 5, 'sys_perm_user' => 'riud', 'sys_perm_group' => 'riud'], 'database_user_id');
        $database = DB::table('web_database')->insertGetId(['database_name' => 'c3test', 'server_id' => 1, 'parent_domain_id' => $id, 'database_user_id' => $user, 'sys_userid' => 1, 'sys_groupid' => 5, 'sys_perm_user' => 'riud', 'sys_perm_group' => 'riud'], 'database_id');
        $backup = (string) Str::uuid();
        DB::table('api_database_operations')->insert(['id' => $backup, 'database_id' => $database, 'sys_groupid' => 5, 'server_id' => 1, 'database_name' => 'c3test', 'action' => 'export', 'status' => 'complete', 'created_at' => time(), 'updated_at' => time(), 'expires_at' => time() + 3600]);
        DB::table('api_wordpress_jobs')->where('id', $job)->update(['backup_id' => $backup, 'status' => 'recovery_required']);
        $this->putJson('/api/v1/sites/database-users/'.$user, ['database_password' => 'new-secret-password'], $this->authHeaders())->assertConflict();
        $this->deleteJson('/api/v1/sites/databases/'.$database, [], $this->authHeaders())->assertConflict();
        $this->getJson($url.'/jobs/'.$job, $this->authHeaders())->assertOk()->assertJsonPath('backup_database_id', $database);
    }

    public function test_integrity_requires_current_worker_and_rejects_command_options(): void
    {
        [$id, $url, $install] = $this->prepareSite();
        $body = ['action' => 'verify_integrity', 'installation' => $install];
        $this->postJson($url.'/jobs', $body, $this->authHeaders())->assertConflict();
        DB::table('api_wordpress_workers')->update(['version' => '2']);
        foreach (['version', 'locale', 'path', 'command', 'cron_token'] as $key) {
            $this->postJson($url.'/jobs', $body + [$key => 'unsafe'], $this->authHeaders())->assertUnprocessable();
        }
        $this->postJson($url.'/jobs', $body, $this->authHeaders())->assertCreated();
        $this->assertCount(0, $this->datalogRows('web_domain'));
    }

    public function test_cron_takeover_obeys_limits_and_owns_a_native_jailed_schedule(): void
    {
        TenantSchema::create();
        $this->seedTenants();
        $this->assignServers('clientA', ['web' => [1]]);
        $this->setClientLimit('reseller', 'limit_cron', 10);
        $client = DB::table('client')->where('client_id', $this->tenant('clientA')['client_id']);
        $client->update(['limit_cron' => 1, 'limit_cron_type' => 'chrooted', 'limit_cron_frequency' => 15]);
        [$id, $url, $install] = $this->prepareSite($this->ownedBy('clientA'));
        DB::table('api_wordpress_workers')->update(['version' => '2']);
        $headers = $this->tenantHeaders('clientA');
        $this->getJson($url, $headers)->assertJsonPath('installations.0.cron.available', true)->assertJsonPath('installations.0.cron.intervals.0', 15);
        $this->postJson($url.'/jobs', ['action' => 'cron_enable', 'installation' => $install, 'interval' => 5], $headers)->assertUnprocessable();
        $body = ['action' => 'cron_enable', 'installation' => $install, 'interval' => 15];
        $this->postJson($url.'/jobs', $body, $headers)->assertCreated();
        $managed = DB::table('api_wordpress_cron')->first();
        $native = DB::table('cron')->first();
        $this->assertSame('chrooted', $native->type);
        $this->assertSame(": > '/private/.ispcp-wp-cron-".$managed->id."'", $native->command);
        $this->assertSame('*/15', $native->run_min);
        $this->assertSame('enabling', $managed->state);
        $this->assertCount(1, $this->datalogRows('cron'));
        $this->getJson('/api/v1/sites/cron-jobs/'.$native->id, $headers)->assertJsonPath('wordpress.installation', $install);
        $this->putJson('/api/v1/sites/cron-jobs/'.$native->id, ['active' => false], $headers)->assertConflict();
        $this->deleteJson('/api/v1/sites/cron-jobs/'.$native->id, [], $headers)->assertConflict();
        DB::table('api_wordpress_jobs')->update(['status' => 'completed']);
        // Updating this reservation works at the limit; another install cannot reserve a second task.
        $this->postJson($url.'/jobs', array_replace($body, ['interval' => 30]), $headers)->assertCreated();
        $this->assertSame(1, DB::table('cron')->count());
        DB::table('api_wordpress_jobs')->update(['status' => 'completed']);
        [$other, $otherUrl, $otherInstall] = $this->prepareSite($this->ownedBy('clientA', ['domain' => 'second.test']));
        $this->postJson($otherUrl.'/jobs', array_replace($body, ['installation' => $otherInstall]), $headers)->assertConflict();
        // Stop remains possible after a downgrade to URL-only / zero allowance.
        $client->update(['limit_cron' => 0, 'limit_cron_type' => 'url']);
        $this->postJson($url.'/jobs', ['action' => 'cron_disable', 'installation' => $install], $headers)->assertCreated();
        $this->assertSame(0, DB::table('cron')->count());
        $this->assertSame('disabling', DB::table('api_wordpress_cron')->value('state'));
    }

    public function test_url_only_and_zero_cron_plans_cannot_take_over_wordpress(): void
    {
        TenantSchema::create();
        $this->seedTenants();
        [$id, $url, $install] = $this->prepareSite($this->ownedBy('clientA'));
        DB::table('api_wordpress_workers')->update(['version' => '2']);
        $client = DB::table('client')->where('client_id', $this->tenant('clientA')['client_id']);
        $client->update(['limit_cron' => 5, 'limit_cron_type' => 'url']);
        $this->getJson($url, $this->tenantHeaders('clientA'))->assertJsonPath('installations.0.cron.reason', 'cron_command_required');
        $this->postJson($url.'/jobs', ['action' => 'cron_enable', 'installation' => $install], $this->tenantHeaders('clientA'))->assertConflict();
        $client->update(['limit_cron' => 0, 'limit_cron_type' => 'full']);
        $this->getJson($url, $this->tenantHeaders('clientA'))->assertJsonPath('installations.0.cron.reason', 'cron_limit');
        $this->assertSame(0, DB::table('cron')->count());
    }
}
