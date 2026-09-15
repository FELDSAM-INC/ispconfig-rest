<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\SitesSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Spec 020 US2 — PHP modes and versions within the plan (FR-003…FR-006;
 * legacy tform valuelimit, web_vhost_domain_edit.php:247-258, 1286-1304,
 * 1507-1546).
 */
class WebPhpScopedKeyTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const FPM = ['php_fpm_init_script' => 'php8-fpm', 'php_fpm_ini_dir' => '/etc/php/fpm', 'php_fpm_pool_dir' => '/etc/php/fpm/pool.d'];

    private const FCGI = ['php_fastcgi_binary' => '/usr/bin/php-cgi', 'php_fastcgi_ini_dir' => '/etc/php/cgi'];

    protected function setUp(): void
    {
        parent::setUp();

        SitesSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        foreach (['clientA', 'clientB', 'reseller'] as $tenant) {
            $this->assignServers($tenant, ['web' => [1]]);
        }

        foreach ([1, 2] as $serverId) {
            DB::table('server')->insert([
                'server_id' => $serverId,
                'server_name' => "web{$serverId}",
                'web_server' => 1,
                'db_server' => 0,
                'mail_server' => 0,
                'mirror_server_id' => 0,
                'active' => 1,
                'config' => $this->webConfig(false),
            ]);
        }

        $this->setSitesConfig('no,fast-cgi,cgi,mod,suphp,php-fpm,hhvm');

        // 1: public fpm+fcgi (prio 20); 2: public fpm only (prio 10); 3: inactive;
        // 4: other server; 5: private to client B; 6: private to client A.
        $rows = [
            [1, 1, 0, 'PHP 8.2', 'y', 20, self::FPM + self::FCGI],
            [2, 1, 0, 'PHP 8.3', 'y', 10, self::FPM],
            [3, 1, 0, 'PHP 7.4', 'n', 5, self::FPM + self::FCGI],
            [4, 2, 0, 'PHP 8.1', 'y', 10, self::FPM + self::FCGI],
            [5, 1, $this->tenant('clientB')['client_id'], 'PHP B', 'y', 30, self::FPM + self::FCGI],
            [6, 1, $this->tenant('clientA')['client_id'], 'PHP A', 'y', 30, self::FPM + self::FCGI],
        ];

        foreach ($rows as [$id, $server, $client, $name, $active, $prio, $binaries]) {
            DB::table('server_php')->insert(array_merge([
                'server_php_id' => $id, 'server_id' => $server, 'client_id' => $client, 'name' => $name,
                'active' => $active, 'sortprio' => $prio,
            ], $binaries));
        }
    }

    protected function webConfig(bool $hideDefault): string
    {
        return implode("\n", [
            '[web]',
            'server_type=apache',
            'website_path=/var/www/clients/client[client_id]/web[website_id]',
            'php_open_basedir=[website_path]/web:[website_path]/tmp',
            'htaccess_allow_override=All',
            'enable_sni=y',
            'php_fpm_default_chroot=n',
            'php_default_hide='.($hideDefault ? 'y' : 'n'),
            'php_default_name=Default',
            '[server]',
            'ip_address=10.0.0.1',
            'log_retention=30',
        ]);
    }

    protected function hideDefault(int $serverId = 1): void
    {
        DB::table('server')->where('server_id', $serverId)->update(['config' => $this->webConfig(true)]);
    }

    protected function setSitesConfig(?string $webPhpOptions): void
    {
        DB::table('sys_ini')->delete();
        DB::table('sys_ini')->insert([
            'sysini_id' => 1,
            'config' => implode("\n", array_filter([
                '[sites]',
                'dbname_prefix=c[CLIENTID]',
                $webPhpOptions !== null ? 'web_php_options='.$webPhpOptions : null,
                '[misc]',
                'ssh_authentication=',
            ])),
        ]);
    }

    protected function setClientModes(string $tenant, string $modes): void
    {
        DB::table('client')->where('client_id', $this->tenant($tenant)['client_id'])->update(['web_php_options' => $modes]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    protected function seedVhost(string $owner, array $attrs = []): int
    {
        $id = (int) DB::table('web_domain')->insertGetId($this->ownedBy($owner, array_merge([
            'server_id' => 1, 'ip_address' => '*', 'domain' => 'v'.uniqid().'.test', 'type' => 'vhost',
            'parent_domain_id' => 0, 'vhost_type' => 'name', 'hd_quota' => -1, 'traffic_quota' => -1,
            'active' => 'y', 'allow_override' => 'All', 'backup_copies' => 1, 'cgi' => 'n', 'ssi' => 'n',
            'perl' => 'n', 'ruby' => 'n', 'python' => 'n', 'suexec' => 'y', 'errordocs' => 0,
            'subdomain' => 'www', 'ssl' => 'n', 'ssl_letsencrypt' => 'n', 'directive_snippets_id' => 0,
            'php' => 'php-fpm', 'server_php_id' => 1,
        ], $attrs)), 'domain_id');

        DB::table('web_domain')->where('domain_id', $id)->update([
            'document_root' => "/var/www/clients/client/web{$id}",
            'system_user' => "web{$id}", 'system_group' => 'client',
        ]);

        return $id;
    }

    protected function assertFieldError(TestResponse $response, string $field, ?string $message = null): void
    {
        $response->assertStatus(422);
        $errors = $response->json('errors') ?? [];
        $this->assertArrayHasKey($field, $errors, 'errors: '.json_encode($errors));

        if ($message !== null) {
            $this->assertSame($message, $errors[$field][0]);
        }
    }

    public function test_php_mode_must_be_in_system_and_client_lists(): void
    {
        $this->setSitesConfig('no,fast-cgi,php-fpm');
        $this->setClientModes('clientA', 'no,php-fpm,mod');
        $headers = $this->tenantHeaders('clientA');

        $this->assertFieldError(
            $this->postJson('/api/v1/sites/web-domains', ['domain' => 'fcgi.test', 'php' => 'fast-cgi'], $headers),
            'php',
            'The selected PHP mode is not available for this account.'
        );
        $this->assertFieldError($this->postJson('/api/v1/sites/web-domains', ['domain' => 'mod.test', 'php' => 'mod'], $headers), 'php');
        $this->postJson('/api/v1/sites/web-domains', ['domain' => 'fpm.test', 'php' => 'php-fpm'], $headers)->assertStatus(201);

        $site = $this->seedVhost('clientA', ['php' => 'fast-cgi']);
        $this->putJson("/api/v1/sites/web-domains/{$site}", ['php' => 'fast-cgi', 'active' => false], $headers)->assertStatus(200);
        $this->assertFieldError($this->putJson("/api/v1/sites/web-domains/{$site}", ['php' => 'cgi'], $headers), 'php');
    }

    public function test_empty_system_list_does_not_restrict_modes(): void
    {
        $this->setSitesConfig(null);
        $this->setClientModes('clientA', 'no,mod');
        $headers = $this->tenantHeaders('clientA');

        $this->postJson('/api/v1/sites/web-domains', ['domain' => 'mod.test', 'php' => 'mod'], $headers)->assertStatus(201);
        $this->assertFieldError($this->postJson('/api/v1/sites/web-domains', ['domain' => 'fcgi.test', 'php' => 'fast-cgi'], $headers), 'php');
    }

    public function test_omitted_php_on_create_uses_an_allowed_mode(): void
    {
        $headers = $this->tenantHeaders('clientA');

        $this->setClientModes('clientA', 'no,php-fpm');
        $this->postJson('/api/v1/sites/web-domains', ['domain' => 'fpm-default.test'], $headers)->assertStatus(201);
        $this->assertSame('php-fpm', DB::table('web_domain')->where('domain', 'fpm-default.test')->value('php'));

        $this->setClientModes('clientA', 'no');
        $this->postJson('/api/v1/sites/web-domains', ['domain' => 'no-php.test'], $headers)->assertStatus(201);
        $row = DB::table('web_domain')->where('domain', 'no-php.test')->first();
        $this->assertSame('no', $row->php);
        $this->assertSame(0, (int) $row->server_php_id);

        $this->setClientModes('clientA', 'no,fast-cgi,php-fpm');
        $this->postJson('/api/v1/sites/web-domains', ['domain' => 'fcgi-default.test'], $headers)->assertStatus(201);
        $this->assertSame('fast-cgi', DB::table('web_domain')->where('domain', 'fcgi-default.test')->value('php'));
    }

    public function test_server_php_id_must_be_a_usable_version_of_the_website(): void
    {
        $headers = $this->tenantHeaders('clientA');
        $site = $this->seedVhost('clientA', ['php' => 'php-fpm', 'server_php_id' => 1]);
        $message = 'The selected PHP version is not available for this website.';

        foreach ([999, 3, 4, 5] as $version) {
            $this->assertFieldError(
                $this->putJson("/api/v1/sites/web-domains/{$site}", ['server_php_id' => $version], $headers),
                'server_php_id',
                $message
            );
        }

        $fcgiSite = $this->seedVhost('clientA', ['php' => 'fast-cgi', 'server_php_id' => 1]);
        $this->assertFieldError($this->putJson("/api/v1/sites/web-domains/{$fcgiSite}", ['server_php_id' => 2], $headers), 'server_php_id');

        $this->putJson("/api/v1/sites/web-domains/{$site}", ['server_php_id' => 6], $headers)->assertStatus(200);
        $this->assertSame(6, (int) DB::table('web_domain')->where('domain_id', $site)->value('server_php_id'));
        $this->putJson("/api/v1/sites/web-domains/{$site}", ['server_php_id' => 2], $headers)->assertStatus(200);
    }

    public function test_changing_the_mode_revalidates_an_explicit_version(): void
    {
        $headers = $this->tenantHeaders('clientA');
        $site = $this->seedVhost('clientA', ['php' => 'php-fpm', 'server_php_id' => 2]);

        $this->assertFieldError(
            $this->putJson("/api/v1/sites/web-domains/{$site}", ['php' => 'fast-cgi', 'server_php_id' => 2], $headers),
            'server_php_id'
        );
        $this->putJson("/api/v1/sites/web-domains/{$site}", ['php' => 'fast-cgi', 'server_php_id' => 1], $headers)->assertStatus(200);
    }

    public function test_child_website_versions_come_from_the_parent_server(): void
    {
        $headers = $this->tenantHeaders('clientA');
        $parent = $this->seedVhost('clientA', ['domain' => 'parent.test']);

        $this->assertFieldError($this->postJson('/api/v1/sites/web-domains', [
            'type' => 'vhostsubdomain', 'parent_domain_id' => $parent, 'domain' => 'sub.parent.test',
            'web_folder' => 'sub', 'php' => 'php-fpm', 'server_php_id' => 4,
        ], $headers), 'server_php_id');

        $this->postJson('/api/v1/sites/web-domains', [
            'type' => 'vhostsubdomain', 'parent_domain_id' => $parent, 'domain' => 'sub.parent.test',
            'web_folder' => 'sub', 'php' => 'php-fpm', 'server_php_id' => 1,
        ], $headers)->assertStatus(201);
    }

    public function test_modes_without_versions_store_zero(): void
    {
        $site = $this->seedVhost('clientA', ['php' => 'php-fpm', 'server_php_id' => 1]);

        $this->putJson("/api/v1/sites/web-domains/{$site}", ['php' => 'mod', 'server_php_id' => 1], $this->tenantHeaders('clientA'))
            ->assertStatus(200);
        $this->assertSame(0, (int) DB::table('web_domain')->where('domain_id', $site)->value('server_php_id'));
    }

    public function test_hidden_default_version_requires_a_real_version(): void
    {
        $this->hideDefault();
        $headers = $this->tenantHeaders('clientA');

        $this->postJson('/api/v1/sites/web-domains', ['domain' => 'fpm-first.test', 'php' => 'php-fpm'], $headers)->assertStatus(201);
        $this->assertSame(2, (int) DB::table('web_domain')->where('domain', 'fpm-first.test')->value('server_php_id'));

        $this->postJson('/api/v1/sites/web-domains', ['domain' => 'fcgi-first.test', 'php' => 'fast-cgi'], $headers)->assertStatus(201);
        $this->assertSame(1, (int) DB::table('web_domain')->where('domain', 'fcgi-first.test')->value('server_php_id'));

        $this->assertFieldError(
            $this->postJson('/api/v1/sites/web-domains', ['domain' => 'zero.test', 'php' => 'php-fpm', 'server_php_id' => 0], $headers),
            'server_php_id',
            'A PHP version must be selected for this website.'
        );

        $site = $this->seedVhost('clientA', ['php' => 'php-fpm', 'server_php_id' => 1]);
        $this->assertFieldError($this->putJson("/api/v1/sites/web-domains/{$site}", ['server_php_id' => 0], $headers), 'server_php_id');

        $legacy = $this->seedVhost('clientA', ['php' => 'php-fpm', 'server_php_id' => 0]);
        $this->putJson("/api/v1/sites/web-domains/{$legacy}", ['active' => false], $headers)->assertStatus(200);
        $this->assertSame(2, (int) DB::table('web_domain')->where('domain_id', $legacy)->value('server_php_id'));
    }

    public function test_hidden_default_without_usable_version_is_refused(): void
    {
        $this->hideDefault();
        DB::table('server_php')->update(['php_fastcgi_binary' => '']);

        $this->assertFieldError(
            $this->postJson('/api/v1/sites/web-domains', ['domain' => 'none.test', 'php' => 'fast-cgi'], $this->tenantHeaders('clientA')),
            'server_php_id',
            'No PHP version is available for the selected PHP mode on this website\'s server.'
        );
    }

    public function test_admin_key_is_not_restricted(): void
    {
        $this->hideDefault();
        $this->setSitesConfig('no,fast-cgi');
        $site = $this->seedVhost('clientA', ['php' => 'php-fpm', 'server_php_id' => 1]);
        $headers = $this->tenantHeaders('admin');

        $this->putJson("/api/v1/sites/web-domains/{$site}", ['server_php_id' => 5], $headers)->assertStatus(200);
        $this->putJson("/api/v1/sites/web-domains/{$site}", ['php' => 'php-fpm', 'server_php_id' => 0], $headers)->assertStatus(200);
        $this->assertSame(0, (int) DB::table('web_domain')->where('domain_id', $site)->value('server_php_id'));
    }
}
