<?php

namespace Tests\Feature;

use App\Services\WebPhpSettingsService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SitesApiTestCase;
use Tests\Support\SystemSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;

final class WebPhpSettingsApiTest extends SitesApiTestCase
{
    use TenantFixtures;

    private function defaults(int $server = 1, int $version = 0, string $mode = 'cgi', array $values = [], array $scan = []): void
    {
        DB::table('api_web_php_defaults')->updateOrInsert(['server_id' => $server, 'server_php_id' => $version, 'mode' => $mode], [
            'measured_at' => time(), 'settings' => json_encode(['opcache' => true, 'scan' => $scan, 'values' => $values + [
                'memory_limit' => '1024M', 'max_execution_time' => '30', 'max_input_time' => '30', 'post_max_size' => '512M',
                'upload_max_filesize' => '512M', 'disable_functions' => 'exec,shell_exec', 'opcache.enable' => 'on',
                'error_reporting' => 'E_ALL', 'display_errors' => 'off', 'log_errors' => 'on', 'allow_url_fopen' => 'on',
                'file_uploads' => 'on', 'short_open_tag' => 'on',
            ]]),
        ]);
    }

    public function test_client_can_change_only_allowlisted_settings_with_datalog_and_preserved_admin_config(): void
    {
        TenantSchema::create();
        $this->seedTenants();
        $this->defaults();
        $ini = "; administrator policy\nmemory_limit = 1024M\ndisable_functions = exec,shell_exec,opcache_get_status\nsession.cookie_httponly = 1\n";
        $id = $this->seedVhost($this->ownedBy('clientA', ['php' => 'fast-cgi', 'custom_php_ini' => $ini]));
        $url = '/api/v1/sites/web-domains/'.$id;
        $headers = $this->tenantHeaders('clientA');
        $view = $this->getJson($url, $headers)->assertOk()->assertJsonPath('php_settings.available', true);
        $this->assertSame('1024M', $view->json('php_settings.values.memory_limit'));
        $this->assertSame('off', $view->json('php_settings.values.opcache_get_status'));
        $changes = ['opcache.enable' => false, 'opcache_get_status' => true, 'error_reporting' => WebPhpSettingsService::ERROR_REPORTING[3],
            'display_errors' => true, 'log_errors' => false, 'allow_url_fopen' => false, 'file_uploads' => false, 'short_open_tag' => false];
        $this->putJson($url, ['php_settings' => $changes], $headers)->assertOk();
        $raw = DB::table('web_domain')->where('domain_id', $id)->value('custom_php_ini');
        $this->assertStringContainsString('; administrator policy', $raw);
        $this->assertStringContainsString('memory_limit = 1024M', $raw);
        $this->assertStringContainsString('session.cookie_httponly = 1', $raw);
        $this->assertStringContainsString('disable_functions = "exec,shell_exec"', $raw);
        $values = $this->getJson($url, $headers)->assertOk()->json('php_settings.values');
        foreach ($changes as $key => $value) {
            $this->assertSame(is_bool($value) ? ($value ? 'on' : 'off') : $value, $values[$key]);
        }
        $this->assertCount(1, $this->datalogRows('web_domain'));
        $this->putJson($url, ['php_settings' => $changes], $headers)->assertOk();
        $this->assertCount(1, $this->datalogRows('web_domain'), 'unchanged settings do not reload PHP');
        $this->putJson($url, ['custom_php_ini' => 'memory_limit=2G'], $headers)->assertUnprocessable();
    }

    public function test_inherited_disabled_functions_survive_function_toggle_and_fpm_global_block_is_locked(): void
    {
        $this->defaults();
        $id = $this->seedVhost(['php' => 'fast-cgi']);
        $url = '/api/v1/sites/web-domains/'.$id;
        $this->putJson($url, ['php_settings' => ['opcache_get_status' => false]], $this->authHeaders())->assertOk();
        $this->getJson($url, $this->authHeaders())->assertJsonPath('php_settings.values.disable_functions', 'exec,shell_exec,opcache_get_status');
        $this->putJson($url, ['php_settings' => ['opcache_get_status' => true]], $this->authHeaders())->assertOk();
        $this->getJson($url, $this->authHeaders())->assertJsonPath('php_settings.values.disable_functions', 'exec,shell_exec');
        $this->defaults(2, 0, 'fpm', ['disable_functions' => 'system,opcache_get_status']);
        $child = $this->seedVhost(['server_id' => 2, 'php' => 'fast-cgi', 'type' => 'vhostsubdomain', 'custom_php_ini' => 'disable_functions = exec']);
        $childUrl = '/api/v1/sites/web-domains/'.$child;
        $view = $this->getJson($childUrl, $this->authHeaders())->assertOk()->assertJsonPath('php_settings.opcache_get_status_locked', true);
        $this->assertSame('system,opcache_get_status,exec', $view->json('php_settings.values.disable_functions'));
        $this->assertNotContains('opcache_get_status', $view->json('php_settings.editable'));
        $this->putJson($childUrl, ['php_settings' => ['opcache_get_status' => true]], $this->authHeaders())->assertUnprocessable();
        $this->putJson($childUrl, ['php_settings' => ['log_errors' => false]], $this->authHeaders())->assertOk();
    }

    #[DataProvider('invalid')]
    public function test_invalid_settings_cannot_partially_change_the_website(array $body): void
    {
        $this->defaults();
        $id = $this->seedVhost(['php' => 'fast-cgi']);
        $this->putJson('/api/v1/sites/web-domains/'.$id, $body + ['active' => false], $this->authHeaders())->assertUnprocessable();
        $this->assertCount(0, $this->datalogRows('web_domain'));
        $this->assertSame('y', DB::table('web_domain')->where('domain_id', $id)->value('active'));
    }

    public static function invalid(): array
    {
        $cases = [];
        foreach (['memory_limit', 'max_execution_time', 'max_input_time', 'post_max_size', 'upload_max_filesize', 'disable_functions', 'auto_prepend_file', 'zend_extension'] as $field) {
            $cases[] = [['php_settings' => [$field => '1']]];
        }
        foreach ([null, 'on', 1, ['on']] as $value) {
            $cases[] = [['php_settings' => ['display_errors' => $value]]];
        }
        foreach (["E_ALL\nauto_prepend_file=/tmp/a", 'E_ALL;extension=x', '${HOME}', 'phpinfo()', 'E_ALL | (1 << 99)'] as $value) {
            $cases[] = [['php_settings' => ['error_reporting' => $value]]];
        }
        $cases[] = [['php_settings' => ['display_errors' => true], 'custom_php_ini' => 'memory_limit=1G']];
        $cases[] = [['php_settings' => ['opcache' => ['enable' => true]]]];

        return $cases;
    }

    public function test_missing_stale_or_wrong_version_defaults_never_invent_limits_or_allow_writes(): void
    {
        $id = $this->seedVhost(['php' => 'fast-cgi']);
        $url = '/api/v1/sites/web-domains/'.$id;
        foreach (['missing', 'wrong_version', 'stale'] as $case) {
            if ($case === 'wrong_version') {
                $this->defaults(1, 12);
            }
            if ($case === 'stale') {
                $this->defaults();
                DB::table('api_web_php_defaults')->update(['measured_at' => time() - 151]);
            }
            $this->getJson($url, $this->authHeaders())->assertJsonPath('php_settings.available', false)
                ->assertJsonPath('php_settings.values.memory_limit', null)->assertJsonPath('php_settings.editable', []);
            $this->putJson($url, ['php_settings' => ['display_errors' => true]], $this->authHeaders())->assertUnprocessable();
        }
        $this->assertCount(0, $this->datalogRows('web_domain'));
    }

    public function test_auth_and_tenant_boundaries_and_list_responses(): void
    {
        TenantSchema::create();
        $this->seedTenants();
        $this->defaults();
        $id = $this->seedVhost($this->ownedBy('clientA', ['php' => 'fast-cgi']));
        $url = '/api/v1/sites/web-domains/'.$id;
        $this->getJson($url)->assertUnauthorized();
        $this->putJson($url, ['php_settings' => ['display_errors' => true]])->assertUnauthorized();
        $this->getJson($url, $this->tenantHeaders('clientB'))->assertNotFound();
        $this->putJson($url, ['php_settings' => ['display_errors' => true]], $this->tenantHeaders('clientB'))->assertNotFound();
        $this->getJson('/api/v1/sites/web-domains', $this->tenantHeaders('clientA'))->assertJsonMissingPath('data.0.php_settings');
        $this->assertCount(0, $this->datalogRows('web_domain'));
    }

    public function test_cgi_scan_overrides_remain_authoritative(): void
    {
        $this->defaults(1, 0, 'cgi', [], ['display_errors' => 'off', 'disable_functions' => 'exec,opcache_get_status']);
        $id = $this->seedVhost(['php' => 'fast-cgi', 'custom_php_ini' => "display_errors=on\ndisable_functions=\"\""]);
        $url = '/api/v1/sites/web-domains/'.$id;
        $this->getJson($url, $this->authHeaders())->assertJsonPath('php_settings.values.display_errors', 'off')
            ->assertJsonPath('php_settings.values.opcache_get_status', 'off');
        $this->putJson($url, ['php_settings' => ['display_errors' => true]], $this->authHeaders())->assertUnprocessable();
        $this->putJson($url, ['php_settings' => ['opcache_get_status' => true]], $this->authHeaders())->assertUnprocessable();
    }

    public function test_required_snippets_are_displayed_and_cannot_be_overridden(): void
    {
        SystemSchema::create();
        $this->defaults();
        $php = DB::table('directive_snippets')->insertGetId(['type' => 'php', 'active' => 'y', 'snippet' => "memory_limit=256M\ndisplay_errors=off\ndisable_functions=exec,opcache_get_status"], 'directive_snippets_id');
        $parent = DB::table('directive_snippets')->insertGetId(['type' => 'apache', 'active' => 'y', 'customer_viewable' => 'y', 'required_php_snippets' => (string) $php], 'directive_snippets_id');
        $id = $this->seedVhost(['php' => 'fast-cgi', 'directive_snippets_id' => $parent, 'custom_php_ini' => "memory_limit=1G\ndisplay_errors=on"]);
        $url = '/api/v1/sites/web-domains/'.$id;
        $this->getJson($url, $this->authHeaders())->assertJsonPath('php_settings.values.memory_limit', '256M')->assertJsonPath('php_settings.values.display_errors', 'off');
        $this->putJson($url, ['php_settings' => ['display_errors' => true]], $this->authHeaders())->assertUnprocessable();
        $this->putJson($url, ['php_settings' => ['opcache_get_status' => true]], $this->authHeaders())->assertUnprocessable();
        $this->assertCount(0, $this->datalogRows('web_domain'));
    }

    public function test_sectioned_admin_configuration_is_not_rewritten_by_flat_controls(): void
    {
        $this->defaults();
        $id = $this->seedVhost(['php' => 'fast-cgi', 'custom_php_ini' => "[PHP]\ndisplay_errors=on"]);
        $url = '/api/v1/sites/web-domains/'.$id;
        $this->getJson($url, $this->authHeaders())->assertJsonPath('php_settings.available', false);
        $this->putJson($url, ['php_settings' => ['display_errors' => false]], $this->authHeaders())->assertUnprocessable();
        $this->assertCount(0, $this->datalogRows('web_domain'));
    }

    public function test_fpm_cannot_enable_opcache_when_disabled_at_startup(): void
    {
        $this->defaults(1, 0, 'fpm', ['opcache.enable' => 'off']);
        $id = $this->seedVhost(['php' => 'php-fpm', 'custom_php_ini' => 'opcache.enable=on']);
        $url = '/api/v1/sites/web-domains/'.$id;
        $view = $this->getJson($url, $this->authHeaders())->assertOk();
        $this->assertSame('off', $view->json('php_settings.values')['opcache.enable']);
        $this->assertNotContains('opcache.enable', $view->json('php_settings.editable'));
        $this->putJson($url, ['php_settings' => ['opcache.enable' => true]], $this->authHeaders())->assertUnprocessable();
        $this->assertCount(0, $this->datalogRows('web_domain'));
    }
}
