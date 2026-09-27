<?php

namespace Tests\Feature;

use App\Models\WebDomain;
use App\Services\WordPressService;
use App\Support\WordPressPolicy;
use Illuminate\Support\Facades\DB;
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
        foreach ([['command' => 'id'], ['path' => '../../'], ['measures' => ['unknown']], ['measures' => ['prefix']], ['measures' => ['prefix'], 'confirmed' => true], ['measures' => ['admin_login'], 'confirmed' => true, 'backup' => true], ['measures' => ['permissions'], 'action' => 'revert']] as $override) {
            $this->postJson($url.'/jobs', array_replace(['action' => 'secure', 'installation' => $installation, 'measures' => ['xmlrpc']], $override), $this->authHeaders())->assertUnprocessable();
        }
        $this->assertCount(0, $this->datalogRows('web_domain'));
        $this->assertSame(0, DB::table('api_wordpress_jobs')->count());
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
}
