<?php

namespace Tests\Feature;

use App\Models\WebDomain;
use App\Services\WebRuntimeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SitesApiTestCase;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;

final class WebRuntimeApiTest extends SitesApiTestCase
{
    use TenantFixtures;

    private function body(string $root = '', array $env = []): array
    {
        return ['runtime_settings' => ['document_root_subdir' => $root, 'environment' => $env]];
    }

    private function worker(int $server = 1, int $version = 1, ?int $heartbeat = null): void
    {
        DB::table('api_web_log_workers')->updateOrInsert(['server_id' => $server], ['runtime_version' => $version, 'heartbeat' => $heartbeat ?? time()]);
    }

    public function test_apache_environment_is_datalogged_round_trips_literal_values_and_removal_preserves_admin_directives(): void
    {
        $id = $this->seedVhost(['apache_directives' => "Header always set X-Example yes\n", 'nginx_directives' => "add_header X-Example yes;\n"]);
        $url = '/api/v1/sites/web-domains/'.$id;
        $env = ['APP_ENV' => ' production ', 'DB_SECRET' => 'a$"\\{}<tmpl_var name="domain">=č', 'EMPTY' => ''];
        $this->putJson($url, $this->body('', $env), $this->authHeaders())->assertOk();
        $this->getJson($url, $this->authHeaders())->assertOk()->assertJsonPath('runtime_settings.environment', $env)
            ->assertJsonPath('runtime_settings.environment_available', true)->assertJsonPath('runtime_settings.document_root_available', false);
        $this->assertCount(1, $this->datalogRows('web_domain'));
        $raw = DB::table('web_domain')->where('domain_id', $id)->first();
        $this->assertStringContainsString('SetEnvIfExpr', $raw->apache_directives);
        $this->assertStringContainsString('Header always set X-Example yes', $raw->apache_directives);
        $this->assertStringContainsString('${ispcp_literal_dollar}', $raw->nginx_directives);
        $this->assertStringNotContainsString('<tmpl_var', $raw->nginx_directives);
        $this->putJson($url, $this->body('', $env), $this->authHeaders())->assertOk();
        $this->assertCount(1, $this->datalogRows('web_domain'), 'unchanged settings must not reload the server');
        $this->putJson($url, $this->body(), $this->authHeaders())->assertOk();
        $raw = DB::table('web_domain')->where('domain_id', $id)->first();
        $this->assertSame("Header always set X-Example yes\n", $raw->apache_directives);
        $this->assertSame("add_header X-Example yes;\n", $raw->nginx_directives);
    }

    public function test_directory_preflight_runs_before_write_transaction_and_base_path_never_changes(): void
    {
        $this->worker();
        $id = $this->seedVhost(['document_root' => '/var/www/clients/client3/web1']);
        $service = Mockery::mock(WebRuntimeService::class)->makePartial();
        $service->shouldReceive('checkDirectory')->once()->withArgs(function (WebDomain $site, string $path): bool {
            $this->assertSame(0, DB::transactionLevel() - 1, 'only the PHPUnit wrapping transaction is open');

            return $path === 'app/public';
        });
        $this->app->instance(WebRuntimeService::class, $service);
        $this->putJson('/api/v1/sites/web-domains/'.$id, $this->body('app/public'), $this->authHeaders())->assertOk();
        $row = DB::table('web_domain')->where('domain_id', $id)->first();
        $this->assertSame('/var/www/clients/client3/web1', $row->document_root);
        $this->getJson('/api/v1/sites/web-domains/'.$id, $this->authHeaders())->assertOk()->assertJsonPath('public_document_root', '/var/www/clients/client3/web1/web/app/public');
        $this->assertStringContainsString('DocumentRoot "{DOCROOT_CLIENT}/app/public"', $row->apache_directives);
        $this->assertStringContainsString('##subroot app/public##', $row->nginx_directives);
    }

    public function test_invalid_or_unavailable_directory_check_cannot_partially_save_other_settings(): void
    {
        $id = $this->seedVhost();
        $url = '/api/v1/sites/web-domains/'.$id;
        $this->putJson($url, $this->body('public') + ['active' => false], $this->authHeaders())->assertUnprocessable();
        $this->worker();
        $service = Mockery::mock(WebRuntimeService::class)->makePartial();
        $service->shouldReceive('checkDirectory')->once()->andThrow(ValidationException::withMessages(['runtime_settings.document_root_subdir' => 'Invalid child folder.']));
        $this->app->instance(WebRuntimeService::class, $service);
        $this->putJson($url, $this->body('public') + ['active' => false], $this->authHeaders())->assertUnprocessable();
        $this->assertSame('y', DB::table('web_domain')->where('domain_id', $id)->value('active'));
        $this->assertCount(0, $this->datalogRows('web_domain'));
    }

    public function test_nginx_requires_upgraded_worker_and_old_or_stale_workers_do_not_advertise_runtime(): void
    {
        $id = $this->seedVhost(['server_id' => 2, 'php' => 'php-fpm']);
        $url = '/api/v1/sites/web-domains/'.$id;
        foreach ([[0, time()], [1, time() - 200]] as [$version, $heartbeat]) {
            $this->worker(2, $version, $heartbeat);
            $this->getJson($url, $this->authHeaders())->assertOk()->assertJsonPath('runtime_settings.environment_available', false);
            $this->putJson($url, $this->body('', ['APP_ENV' => 'production']), $this->authHeaders())->assertUnprocessable();
        }
        $this->worker(2);
        $this->putJson($url, $this->body('', ['APP_ENV' => 'production']), $this->authHeaders())->assertOk();
        $this->getJson($url, $this->authHeaders())->assertOk()->assertJsonPath('runtime_settings.environment', ['APP_ENV' => 'production']);
    }

    #[DataProvider('unsafeSettings')]
    public function test_unsafe_settings_are_rejected_without_datalog(array $body): void
    {
        $id = $this->seedVhost();
        $this->putJson('/api/v1/sites/web-domains/'.$id, ['runtime_settings' => $body], $this->authHeaders())->assertUnprocessable();
        $this->assertCount(0, $this->datalogRows('web_domain'));
    }

    public static function unsafeSettings(): array
    {
        $cases = [];
        foreach (['../private', '/etc', 'public/../private', 'public//x', 'public/.', 'x\\y', 'x;root', '<tmpl_var>', 'a'."\n".'b', str_repeat('x', 201)] as $root) {
            $cases[] = [['document_root_subdir' => $root, 'environment' => []]];
        }
        foreach (['PHP_VALUE', 'PHP_ADMIN_VALUE', 'HTTP_HOST', 'PATH', 'LD_PRELOAD', 'SCRIPT_FILENAME', 'APP;evil', 'lowercase'] as $key) {
            $cases[] = [['document_root_subdir' => '', 'environment' => [$key => 'test']]];
        }
        foreach (["a\nb", "a\rb", "a\0b", ['not' => 'scalar'], str_repeat('x', 4097)] as $value) {
            $cases[] = [['document_root_subdir' => '', 'environment' => ['APP_VALUE' => $value]]];
        }
        $cases[] = [['document_root_subdir' => '', 'environment' => [], 'apache_directives' => 'forbidden']];

        return $cases;
    }

    public function test_customer_scope_and_locked_accounts_still_guard_runtime_writes(): void
    {
        TenantSchema::create();
        $this->seedTenants();
        $id = $this->seedVhost($this->ownedBy('clientA'));
        $url = '/api/v1/sites/web-domains/'.$id;
        $this->getJson($url)->assertUnauthorized();
        $this->putJson($url, $this->body('', ['APP_ENV' => 'prod']), $this->tenantHeaders('clientB'))->assertNotFound();
        $this->putJson($url, $this->body('', ['APP_ENV' => 'prod']), $this->tenantHeaders('clientA'))->assertOk();
        $this->putJson($url, ['apache_directives' => 'DocumentRoot /etc'], $this->tenantHeaders('clientA'))->assertUnprocessable();
        DB::table('client')->where('client_id', $this->tenants['clientA']['client_id'])->update(['locked' => 'y']);
        $this->putJson($url, $this->body(), $this->tenantHeaders('clientA'))->assertOk();
        DB::table('web_domain')->where('domain_id', $id)->update(['active' => 'n']);
        $this->putJson($url, $this->body('', ['APP_ENV' => 'prod']) + ['active' => true], $this->tenantHeaders('clientA'))->assertForbidden();
    }

    public function test_vhost_child_keeps_own_folder_and_an_admin_root_is_not_overwritten(): void
    {
        $this->worker();
        $id = $this->seedVhost(['type' => 'vhostsubdomain', 'web_folder' => 'blog', 'php_fpm_chroot' => 'y']);
        $service = Mockery::mock(WebRuntimeService::class)->makePartial();
        $service->shouldReceive('checkDirectory')->andReturnNull();
        $this->app->instance(WebRuntimeService::class, $service);
        $url = '/api/v1/sites/web-domains/'.$id;
        $this->putJson($url, $this->body('public'), $this->authHeaders())->assertOk();
        $raw = DB::table('web_domain')->where('domain_id', $id)->first();
        $this->assertSame('blog', $raw->web_folder);
        $this->assertStringContainsString('DOCUMENT_ROOT "/blog/public"', $raw->apache_directives);
        $this->assertStringContainsString('SCRIPT_FILENAME "/blog/public$fastcgi_script_name"', $raw->nginx_directives);
        DB::table('web_domain')->where('domain_id', $id)->update(['apache_directives' => 'DocumentRoot /custom/admin/path']);
        $this->putJson($url, $this->body('public'), $this->authHeaders())->assertConflict();
    }
}
