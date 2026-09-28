<?php

namespace Tests\Feature;

use App\Services\ClientWebPhpPolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ClientApiTestCase;
use Tests\Support\SitesSchema;
use Tests\Support\SystemSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;

final class ClientWebPhpPolicyTest extends ClientApiTestCase
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
        DB::table('server')->where('server_id', 1)->update(['config' => "[web]\nserver_type=apache\nwebsite_path=/var/www/clients/client[client_id]/web[website_id]\nenable_sni=y\nphp_fpm_default_chroot=n\n"]);
        foreach (['fpm', 'cgi'] as $mode) {
            DB::table('api_web_php_defaults')->insert(['server_id' => 1, 'server_php_id' => 0, 'mode' => $mode, 'measured_at' => time(),
                'settings' => json_encode(['opcache' => true, 'scan' => [], 'values' => ['memory_limit' => '128M', 'max_execution_time' => '30', 'max_input_time' => '60', 'post_max_size' => '8M', 'upload_max_filesize' => '2M', 'disable_functions' => 'exec', 'opcache.enable' => 'on', 'error_reporting' => 'E_ALL', 'display_errors' => 'off', 'log_errors' => 'on', 'allow_url_fopen' => 'on', 'file_uploads' => 'on', 'short_open_tag' => 'on']])]);
        }
        DB::table('sys_ini')->insert(['sysini_id' => 1, 'config' => "[sites]\nweb_php_options=no,fast-cgi,php-fpm\n"]);
        foreach (['clientA', 'clientB'] as $name) {
            DB::table('client')->where('client_id', $this->tenants[$name]['client_id'])->update(['default_webserver' => 1, 'web_servers' => '1', 'web_php_options' => 'no,fast-cgi,php-fpm',
                'limit_web_domain' => -1, 'limit_web_subdomain' => -1, 'limit_web_aliasdomain' => -1, 'limit_web_quota' => -1]);
        }
    }

    private function policy(array $changes = []): array
    {
        return array_replace_recursive([
            'force_fpm' => true, 'php_fpm_use_socket' => true, 'php_fpm_chroot' => false, 'pm' => 'ondemand',
            'pm_max_children' => 10, 'pm_start_servers' => 2, 'pm_min_spare_servers' => 1, 'pm_max_spare_servers' => 5,
            'pm_process_idle_timeout' => 10, 'pm_max_requests' => 0,
            'ini' => ['memory_limit' => '256M', 'max_execution_time' => '30', 'max_input_time' => '60', 'post_max_size' => '8M', 'upload_max_filesize' => '2M'],
        ], $changes);
    }

    private function clientUrl(string $tenant = 'clientA'): string
    {
        return '/api/v1/clients/'.$this->tenants[$tenant]['client_id'];
    }

    private function applyPolicy(?array $policy, string $tenant = 'clientA')
    {
        return $this->putJson($this->clientUrl($tenant), ['web_php_policy' => $policy], $this->authHeaders());
    }

    private function site(array $changes = [], string $tenant = 'clientA'): array
    {
        static $number = 0;

        return $this->postJson('/api/v1/sites/web-domains', array_replace([
            'domain' => 'policy'.(++$number).'.example.test', 'hd_quota' => -1,
        ], $changes), $this->tenantHeaders($tenant))->assertCreated()->json();
    }

    public function test_client_creation_stores_policy_after_template_and_confirms_it(): void
    {
        $template = $this->seedTemplate(['web_php_options' => 'fast-cgi']);
        $result = $this->postJson('/api/v1/clients', [
            'username' => 'phpcustomer', 'contact_name' => 'PHP Customer', 'email' => 'php@example.test', 'password' => 'Password123!',
            'template_master' => $template, 'web_php_policy' => $this->policy(),
        ], $this->authHeaders())->assertCreated()->assertJsonPath('web_php_options', 'php-fpm')
            ->assertJsonPath('web_php_policy.ini.memory_limit', '256M');
        $clientId = $result->json('id');
        $this->assertSame('fast-cgi', DB::table('api_client_web_php_policies')->where('client_id', $clientId)->value('original_php_modes'));
        $this->putJson('/api/v1/clients/templates/'.$template, ['web_php_options' => 'fast-cgi,no'], $this->authHeaders())->assertOk();
        $this->assertSame('php-fpm', DB::table('client')->where('client_id', $clientId)->value('web_php_options'));
        $this->putJson('/api/v1/clients/'.$clientId, ['web_php_policy' => null], $this->authHeaders())
            ->assertOk()->assertJsonPath('web_php_options', 'fast-cgi,no')->assertJsonPath('web_php_policy', null);
    }

    public function test_new_website_and_vhost_children_get_limits_pool_settings_and_complete_datalog(): void
    {
        $this->applyPolicy($this->policy(['php_fpm_chroot' => true, 'pm_max_children' => 12]))->assertOk();
        $parent = $this->site();
        foreach ([$parent, $this->site(['type' => 'vhostsubdomain', 'parent_domain_id' => $parent['id'], 'domain' => 'blog.'.$parent['domain'], 'web_folder' => 'blog']),
            $this->site(['type' => 'vhostalias', 'parent_domain_id' => $parent['id'], 'web_folder' => 'alias'])] as $site) {
            $row = DB::table('web_domain')->where('domain_id', $site['id'])->first();
            $this->assertSame('php-fpm', $row->php);
            $this->assertSame('y', $row->php_fpm_use_socket);
            $this->assertSame('y', $row->php_fpm_chroot);
            $this->assertSame(12, $row->pm_max_children);
            $this->assertStringContainsString('memory_limit = 256M', $row->custom_php_ini);
            $this->assertSame('256M', $this->getJson('/api/v1/sites/web-domains/'.$site['id'], $this->tenantHeaders('clientA'))->assertOk()->json('php_settings.values.memory_limit'));
            $this->assertNotContains('memory_limit', $this->getJson('/api/v1/sites/web-domains/'.$site['id'], $this->tenantHeaders('clientA'))->assertOk()->json('php_settings.editable'));
            $log = DB::table('sys_datalog')->where('dbtable', 'web_domain')->where('dbidx', 'domain_id:'.$site['id'])->where('action', 'i')->first();
            $this->assertNotNull($log);
            $this->assertSame('php-fpm', unserialize($log->data)['new']['php']);
        }
    }

    public function test_product_changes_update_only_owned_vhosts_and_disabling_restores_previous_settings(): void
    {
        $first = $this->site(['php' => 'fast-cgi']);
        $second = $this->site(['php' => 'fast-cgi']);
        $other = $this->site(['php' => 'fast-cgi'], 'clientB');
        DB::table('web_domain')->whereIn('domain_id', [$first['id'], $second['id']])->update([
            'custom_php_ini' => "memory_limit = 128M\ndisplay_errors = off\nsession.cookie_httponly = 1\n", 'pm_max_children' => 7,
        ]);
        $policy = $this->policy(['pm' => 'dynamic', 'pm_max_children' => 20, 'pm_max_requests' => 500]);
        $this->applyPolicy($policy)->assertOk();
        $this->applyPolicy($policy)->assertOk();
        foreach ([$first, $second] as $site) {
            $row = DB::table('web_domain')->where('domain_id', $site['id'])->first();
            $this->assertSame('dynamic', $row->pm);
            $this->assertSame(20, $row->pm_max_children);
            $this->assertStringContainsString('session.cookie_httponly = 1', $row->custom_php_ini);
            $this->assertSame(1, substr_count($row->custom_php_ini, '; BEGIN ISPCONFIG REST PRODUCT PHP'));
        }
        $this->assertSame('fast-cgi', DB::table('web_domain')->where('domain_id', $other['id'])->value('php'));
        $this->applyPolicy(null)->assertOk();
        foreach ([$first, $second] as $site) {
            $row = DB::table('web_domain')->where('domain_id', $site['id'])->first();
            $this->assertSame('fast-cgi', $row->php);
            $this->assertSame(7, $row->pm_max_children);
            $this->assertSame("memory_limit = 128M\ndisplay_errors = off\nsession.cookie_httponly = 1\n", $row->custom_php_ini);
        }
        $this->assertSame(0, DB::table('api_web_php_policy_sites')->count());
    }

    public function test_reseller_and_customer_cannot_set_or_clear_a_policy(): void
    {
        $this->applyPolicy($this->policy())->assertOk();
        foreach (['clientA', 'clientB', 'reseller'] as $tenant) {
            foreach ([$this->policy(['pm_max_children' => 999]), null] as $policy) {
                $this->putJson($this->clientUrl(), ['web_php_policy' => $policy], $this->tenantHeaders($tenant))->assertForbidden();
            }
        }
        $this->assertSame(10, app(ClientWebPhpPolicyService::class)->policy($this->tenants['clientA']['client_id'])['pm_max_children']);
    }

    public function test_customer_cannot_bypass_php_mode_or_ini_limits(): void
    {
        $this->applyPolicy($this->policy())->assertOk();
        $site = $this->site();
        $url = '/api/v1/sites/web-domains/'.$site['id'];
        foreach ([['php' => 'fast-cgi'], ['php' => 'no'], ['pm_max_children' => 1000],
            ['custom_php_ini' => 'memory_limit=2G'], ['php_settings' => ['memory_limit' => '2G']]] as $body) {
            $this->putJson($url, $body, $this->tenantHeaders('clientA'))->assertUnprocessable();
        }
        $this->putJson($url, ['active' => false], $this->tenantHeaders('clientA'))->assertOk()
            ->assertJsonPath('php', 'php-fpm');
        $this->getJson($url, $this->tenantHeaders('clientA'))->assertOk()->assertJsonPath('php_settings.values.memory_limit', '256M');
    }

    public function test_invalid_configuration_does_not_write_policy_or_sites(): void
    {
        $site = $this->site(['php' => 'fast-cgi']);
        $before = DB::table('sys_datalog')->count();
        foreach ([['pm' => 'static'], ['pm_max_children' => 0], ['pm' => 'dynamic', 'pm_start_servers' => 8],
            ['pm_process_idle_timeout' => 0], ['extra' => 'bad'], ['ini' => ['memory_limit' => "256M\nauto_prepend_file=/tmp/evil"]],
            ['ini' => ['upload_max_filesize' => '1G']], ['ini' => ['disable_functions' => 'exec']],
            ['ini' => ['max_execution_time' => '30s']]] as $change) {
            $this->applyPolicy($this->policy($change))->assertUnprocessable();
        }
        $this->assertSame(0, DB::table('api_client_web_php_policies')->count());
        $this->assertSame($before, DB::table('sys_datalog')->count());
        $this->assertSame('fast-cgi', DB::table('web_domain')->where('domain_id', $site['id'])->value('php'));
    }

    public function test_conflicting_php_snippet_rolls_back_the_entire_product_change(): void
    {
        $first = $this->site(['php' => 'fast-cgi']);
        $second = $this->site(['php' => 'fast-cgi']);
        $php = DB::table('directive_snippets')->insertGetId(['type' => 'php', 'active' => 'y', 'snippet' => 'memory_limit=2G'], 'directive_snippets_id');
        $parent = DB::table('directive_snippets')->insertGetId(['type' => 'apache', 'active' => 'y', 'customer_viewable' => 'y', 'required_php_snippets' => (string) $php], 'directive_snippets_id');
        DB::table('web_domain')->where('domain_id', $second['id'])->update(['directive_snippets_id' => $parent]);
        $this->applyPolicy($this->policy())->assertUnprocessable();
        $this->assertSame(0, DB::table('api_client_web_php_policies')->count());
        $this->assertSame(0, DB::table('api_web_php_policy_sites')->count());
        $this->assertSame('fast-cgi', DB::table('web_domain')->where('domain_id', $first['id'])->value('php'));
    }

    public function test_switching_force_fpm_off_preserves_the_template_modes(): void
    {
        $this->applyPolicy($this->policy())->assertOk()->assertJsonPath('web_php_options', 'php-fpm');
        $this->applyPolicy($this->policy(['force_fpm' => false]))->assertOk()->assertJsonPath('web_php_options', 'no,fast-cgi,php-fpm');
        $site = $this->site(['php' => 'fast-cgi']);
        $this->assertSame('fast-cgi', $site['php']);
        $this->assertSame('256M', $this->getJson('/api/v1/sites/web-domains/'.$site['id'], $this->tenantHeaders('clientA'))->assertOk()->json('php_settings.values.memory_limit'));
    }

    public function test_php_version_with_fpm_support_is_kept_but_cgi_only_version_is_not(): void
    {
        $fpm = DB::table('server_php')->insertGetId(['server_id' => 1, 'client_id' => 0, 'active' => 'y', 'name' => 'Both',
            'php_fastcgi_binary' => '/usr/bin/php-cgi', 'php_fastcgi_ini_dir' => '/etc/php/cgi',
            'php_fpm_init_script' => 'php-fpm', 'php_fpm_ini_dir' => '/etc/php/fpm', 'php_fpm_pool_dir' => '/etc/php/fpm/pool.d'], 'server_php_id');
        $cgi = DB::table('server_php')->insertGetId(['server_id' => 1, 'client_id' => 0, 'active' => 'y', 'name' => 'CGI',
            'php_fastcgi_binary' => '/usr/bin/php-cgi', 'php_fastcgi_ini_dir' => '/etc/php/cgi'], 'server_php_id');
        $first = $this->site(['php' => 'fast-cgi', 'server_php_id' => $fpm]);
        $second = $this->site(['php' => 'fast-cgi', 'server_php_id' => $cgi]);
        $this->applyPolicy($this->policy())->assertOk();
        $this->assertSame($fpm, DB::table('web_domain')->where('domain_id', $first['id'])->value('server_php_id'));
        $this->assertSame(0, DB::table('web_domain')->where('domain_id', $second['id'])->value('server_php_id'));
    }

    public function test_missing_migration_is_a_clear_error_and_null_policy_remains_backward_compatible(): void
    {
        Schema::drop('api_client_web_php_policies');
        $this->applyPolicy($this->policy())->assertUnprocessable()->assertJsonValidationErrors('web_php_policy');
        $this->applyPolicy(null)->assertOk();
    }

    public function test_numeric_zero_and_one_are_not_compiled_as_fpm_boolean_flags(): void
    {
        $this->applyPolicy($this->policy(['ini' => ['max_execution_time' => '0', 'max_input_time' => '1']]))->assertOk();
        $site = $this->site();
        $ini = DB::table('web_domain')->where('domain_id', $site['id'])->value('custom_php_ini');
        $this->assertStringContainsString('max_execution_time = "0"', $ini);
        $this->assertStringContainsString('max_input_time = "1"', $ini);
    }

    public function test_client_delete_removes_the_policy_and_original_settings(): void
    {
        $this->applyPolicy($this->policy())->assertOk();
        $this->site();
        $this->deleteJson($this->clientUrl(), [], $this->authHeaders())->assertNoContent();
        $this->assertSame(0, DB::table('api_client_web_php_policies')->count());
        $this->assertSame(0, DB::table('api_web_php_policy_sites')->count());
    }

    public function test_nginx_dynamic_pool_and_customer_preferences_keep_the_read_only_limits(): void
    {
        DB::table('server')->where('server_id', 1)->update(['config' => "[web]\nserver_type=nginx\nwebsite_path=/var/www/clients/client[client_id]/web[website_id]\n"]);
        $this->applyPolicy($this->policy(['pm' => 'dynamic', 'php_fpm_use_socket' => false, 'pm_start_servers' => 3]))->assertOk();
        $site = $this->site();
        $url = '/api/v1/sites/web-domains/'.$site['id'];
        $this->putJson($url, ['php_settings' => ['display_errors' => true]], $this->tenantHeaders('clientA'))->assertOk();
        $this->getJson($url, $this->tenantHeaders('clientA'))->assertOk()->assertJsonPath('php_settings.values.memory_limit', '256M')
            ->assertJsonPath('php_settings.values.display_errors', 'on');
        $row = DB::table('web_domain')->where('domain_id', $site['id'])->first();
        $this->assertSame('dynamic', $row->pm);
        $this->assertSame(3, $row->pm_start_servers);
        $this->assertSame('n', $row->php_fpm_use_socket);
        $this->assertSame(1, substr_count($row->custom_php_ini, '; BEGIN ISPCONFIG REST PRODUCT PHP'));
    }

    public function test_unforced_policy_removal_preserves_customer_php_choice_and_native_modes(): void
    {
        $this->applyPolicy($this->policy(['force_fpm' => false]))->assertOk();
        $site = $this->site(['php' => 'fast-cgi']);
        $this->putJson('/api/v1/sites/web-domains/'.$site['id'], ['php' => 'php-fpm'], $this->tenantHeaders('clientA'))->assertOk();
        $this->putJson($this->clientUrl(), ['web_php_options' => 'php-fpm,no'], $this->authHeaders())->assertOk();
        $this->applyPolicy(null)->assertOk()->assertJsonPath('web_php_options', 'php-fpm,no');
        $this->assertSame('php-fpm', DB::table('web_domain')->where('domain_id', $site['id'])->value('php'));
    }

    public function test_website_delete_cleans_primary_and_child_snapshots(): void
    {
        $this->applyPolicy($this->policy())->assertOk();
        $parent = $this->site();
        $this->site(['type' => 'vhostsubdomain', 'parent_domain_id' => $parent['id'], 'web_folder' => 'child']);
        $this->assertSame(2, DB::table('api_web_php_policy_sites')->count());
        $this->deleteJson('/api/v1/sites/web-domains/'.$parent['id'], [], $this->tenantHeaders('clientA'))->assertNoContent();
        $this->assertSame(0, DB::table('api_web_php_policy_sites')->count());
    }

    public function test_disabled_global_fpm_and_malformed_ini_block_are_rejected_atomically(): void
    {
        DB::table('sys_ini')->where('sysini_id', 1)->update(['config' => "[sites]\nweb_php_options=fast-cgi,no\n"]);
        $this->applyPolicy($this->policy())->assertUnprocessable()->assertJsonValidationErrors('web_php_policy');
        $this->assertNull(app(ClientWebPhpPolicyService::class)->policy($this->tenants['clientA']['client_id']));
        DB::table('sys_ini')->where('sysini_id', 1)->update(['config' => "[sites]\nweb_php_options=php-fpm,fast-cgi,no\n"]);
        $site = $this->site();
        DB::table('web_domain')->where('domain_id', $site['id'])->update(['custom_php_ini' => "; BEGIN ISPCONFIG REST PRODUCT PHP\nmemory_limit=1G"]);
        $this->applyPolicy($this->policy())->assertUnprocessable()->assertJsonValidationErrors('web_php_policy');
        $this->assertSame(0, DB::table('api_web_php_policy_sites')->count());
    }
}
