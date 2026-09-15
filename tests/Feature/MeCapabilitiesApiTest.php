<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SitesSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * GET /me/capabilities (spec 021 US1, api/modules/me/capabilities.yaml): the
 * website plan options, PHP modes and lock state of the key's account or a
 * named client — the rules spec 020 enforces.
 */
class MeCapabilitiesApiTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const WEB_KEYS = [
        'ssl', 'ssl_letsencrypt', 'wildcard', 'cgi', 'ssi', 'perl', 'ruby', 'python', 'error_documents',
        'directive_snippets', 'suexec_forced', 'backup', 'advanced_options', 'php_modes', 'php_default_mode',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        SitesSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        foreach (['clientA', 'clientB', 'reseller'] as $tenant) {
            $this->assignServers($tenant, ['web' => [1]]);
        }

        DB::table('client')->where('client_id', $this->tenant('reseller')['client_id'])->update(['limit_client' => 10]);

        DB::table('server')->insert([
            'server_id' => 1,
            'server_name' => 'web1',
            'web_server' => 1,
            'db_server' => 0,
            'mail_server' => 0,
            'mirror_server_id' => 0,
            'active' => 1,
            'config' => implode("\n", [
                '[web]',
                'server_type=apache',
                'website_path=/var/www/clients/client[client_id]/web[website_id]',
                'php_open_basedir=[website_path]/web:[website_path]/tmp',
                'htaccess_allow_override=All',
                'enable_sni=y',
                'php_fpm_default_chroot=n',
                '[server]',
                'ip_address=10.0.0.1',
                'log_retention=30',
            ]),
        ]);
    }

    protected function setSites(?string $webPhpOptions, string $resellerCanUseOptions = 'n'): void
    {
        DB::table('sys_ini')->updateOrInsert(['sysini_id' => 1], [
            'config' => implode("\n", array_filter([
                '[sites]',
                'dbname_prefix=c[CLIENTID]',
                $webPhpOptions !== null ? 'web_php_options='.$webPhpOptions : null,
                "reseller_can_use_options={$resellerCanUseOptions}",
                '[misc]',
                'ssh_authentication=',
            ])),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    protected function setClient(string $tenant, array $attrs): void
    {
        DB::table('client')->where('client_id', $this->tenant($tenant)['client_id'])->update($attrs);
    }

    protected function seedVhost(string $owner): int
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

    public function test_requires_api_key(): void
    {
        $this->getJson('/api/v1/me/capabilities')->assertStatus(401);
    }

    public function test_client_key_reads_its_plan_options(): void
    {
        $this->setSites('no,fast-cgi,cgi,mod,suphp,php-fpm,hhvm');
        $this->setClient('clientA', [
            'limit_ssl' => 'y', 'limit_ssl_letsencrypt' => 'n', 'limit_wildcard' => 'n', 'force_suexec' => 'y',
            'limit_cgi' => 'y', 'limit_ssi' => 'n', 'limit_perl' => 'y', 'limit_ruby' => 'n', 'limit_python' => 'y',
            'limit_hterror' => 'y', 'limit_directive_snippets' => 'n', 'limit_backup' => 'n',
            'web_php_options' => 'no,php-fpm',
        ]);

        $response = $this->getJson('/api/v1/me/capabilities', $this->tenantHeaders('clientA'));

        $response->assertOk()->assertExactJson([
            'client_id' => $this->tenant('clientA')['client_id'],
            'account_type' => 'client',
            'locked' => false,
            'canceled' => false,
            'web' => [
                'ssl' => true,
                'ssl_letsencrypt' => false,
                'wildcard' => false,
                'cgi' => true,
                'ssi' => false,
                'perl' => true,
                'ruby' => false,
                'python' => true,
                'error_documents' => true,
                'directive_snippets' => false,
                'suexec_forced' => true,
                'backup' => false,
                'advanced_options' => false,
                'php_modes' => ['no', 'php-fpm'],
                'php_default_mode' => 'php-fpm',
            ],
        ]);
        $this->assertSame(self::WEB_KEYS, array_keys($response->json('web')));
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_php_modes_intersect_system_and_client_lists(): void
    {
        $this->setSites('no,fast-cgi,mod,php-fpm');
        $headers = $this->tenantHeaders('clientA');

        $cases = [
            'no,php-fpm,cgi' => [['no', 'php-fpm'], 'php-fpm'],
            'php-fpm,fast-cgi,no' => [['php-fpm', 'fast-cgi', 'no'], 'fast-cgi'],
            'no,mod' => [['no', 'mod'], 'mod'],
            'no' => [['no'], 'no'],
            'cgi,hhvm' => [[], 'no'],
        ];

        foreach ($cases as $clientModes => [$modes, $default]) {
            $this->setClient('clientA', ['web_php_options' => $clientModes]);
            $response = $this->getJson('/api/v1/me/capabilities', $headers)->assertOk();
            $this->assertSame($modes, $response->json('web.php_modes'), $clientModes);
            $this->assertSame($default, $response->json('web.php_default_mode'), $clientModes);
        }
    }

    public function test_empty_system_list_does_not_restrict_modes(): void
    {
        $this->setSites(null);
        $this->setClient('clientA', ['web_php_options' => 'no,mod,cgi']);

        $this->getJson('/api/v1/me/capabilities', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('web.php_modes', ['no', 'mod', 'cgi'])
            ->assertJsonPath('web.php_default_mode', 'mod');
    }

    public function test_locked_and_canceled_state(): void
    {
        $this->setSites(null);
        $this->setClient('clientA', ['locked' => 'y', 'canceled' => 'y']);

        $this->getJson('/api/v1/me/capabilities', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('locked', true)
            ->assertJsonPath('canceled', true);
    }

    public function test_reseller_key_reads_own_and_client_views(): void
    {
        $this->setSites(null, 'y');
        $this->setClient('reseller', ['limit_ssl' => 'y']);
        $this->setClient('clientA', ['limit_ssl' => 'n']);
        $headers = $this->tenantHeaders('reseller');

        $this->getJson('/api/v1/me/capabilities', $headers)
            ->assertOk()
            ->assertJsonPath('client_id', $this->tenant('reseller')['client_id'])
            ->assertJsonPath('account_type', 'reseller')
            ->assertJsonPath('web.ssl', true)
            ->assertJsonPath('web.advanced_options', true);

        $this->getJson('/api/v1/me/capabilities?client_id='.$this->tenant('clientA')['client_id'], $headers)
            ->assertOk()
            ->assertJsonPath('client_id', $this->tenant('clientA')['client_id'])
            ->assertJsonPath('account_type', 'client')
            ->assertJsonPath('web.ssl', false)
            ->assertJsonPath('web.advanced_options', false);

        $this->getJson('/api/v1/me/capabilities?client_id='.$this->tenant('clientB')['client_id'], $headers)
            ->assertStatus(404);
    }

    public function test_admin_key_must_name_a_client(): void
    {
        $this->setSites(null);
        $this->setClient('clientB', ['limit_ssl_letsencrypt' => 'y']);
        $headers = $this->tenantHeaders('admin');

        $this->getJson('/api/v1/me/capabilities', $headers)
            ->assertStatus(422)
            ->assertJsonPath('errors.client_id.0', 'The client id is required for admin keys.');

        $this->getJson('/api/v1/me/capabilities?client_id='.$this->tenant('clientB')['client_id'], $headers)
            ->assertOk()
            ->assertJsonPath('client_id', $this->tenant('clientB')['client_id'])
            ->assertJsonPath('web.ssl_letsencrypt', true);

        $this->getJson('/api/v1/me/capabilities?client_id=9999', $headers)->assertStatus(404);
    }

    public function test_client_key_cannot_name_another_client(): void
    {
        $this->setSites(null);
        $headers = $this->tenantHeaders('clientA');

        $this->getJson('/api/v1/me/capabilities?client_id='.$this->tenant('clientB')['client_id'], $headers)->assertStatus(404);
        $this->getJson('/api/v1/me/capabilities?client_id='.$this->tenant('clientA')['client_id'], $headers)->assertOk();
    }

    public function test_query_parameters_are_validated(): void
    {
        $this->setSites(null);
        $headers = $this->tenantHeaders('clientA');

        $this->getJson('/api/v1/me/capabilities?server_id=1', $headers)->assertStatus(400);
        $this->getJson('/api/v1/me/capabilities?client_id=abc', $headers)
            ->assertStatus(422)
            ->assertJsonPath('errors.client_id.0', 'The client id must be a positive integer.');
        $this->getJson('/api/v1/me/capabilities?client_id=0', $headers)->assertStatus(422);
    }

    public function test_reported_capabilities_match_website_writes(): void
    {
        $this->setSites('no,fast-cgi,cgi,mod,suphp,php-fpm,hhvm');
        $this->setClient('clientA', ['limit_ssl' => 'n', 'web_php_options' => 'no,php-fpm']);
        $headers = $this->tenantHeaders('clientA');
        $site = $this->seedVhost('clientA');

        $capabilities = $this->getJson('/api/v1/me/capabilities', $headers)->assertOk()->json();
        $this->assertFalse($capabilities['web']['ssl']);
        $this->putJson("/api/v1/sites/web-domains/{$site}", ['ssl' => true], $headers)->assertStatus(422);

        foreach (['no', 'fast-cgi', 'cgi', 'mod', 'suphp', 'php-fpm', 'hhvm'] as $mode) {
            $status = $this->putJson("/api/v1/sites/web-domains/{$site}", ['php' => $mode], $headers)->status();
            $expected = in_array($mode, $capabilities['web']['php_modes'], true) ? 200 : 422;
            $this->assertSame($expected, $status, "php {$mode}");
        }

        $this->setClient('clientA', ['limit_ssl' => 'y']);
        $this->assertTrue($this->getJson('/api/v1/me/capabilities', $headers)->json('web.ssl'));
        $this->putJson("/api/v1/sites/web-domains/{$site}", ['ssl' => true], $headers)->assertStatus(200);
    }
}
