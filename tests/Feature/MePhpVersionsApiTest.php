<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SitesSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * GET /me/php-versions (spec 021 US2, api/modules/me/php-versions.yaml): PHP
 * versions the account's websites may use per web server and mode — exactly
 * the versions spec 020 accepts (legacy web_vhost_domain_edit.php:240-272,
 * ajax_get_json.php:66-125).
 */
class MePhpVersionsApiTest extends TestCase
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

        // 1 apache (default shown, "Default PHP"), 2 apache (default hidden),
        // 3 mirror of 1, 4 mail only, 5 nginx (default shown).
        $servers = [
            [1, 1, 0, $this->webConfig('apache', false, 'Default PHP')],
            [2, 1, 0, $this->webConfig('apache', true, '')],
            [3, 1, 1, $this->webConfig('apache', false, '')],
            [4, 0, 0, ''],
            [5, 1, 0, $this->webConfig('nginx', false, '')],
        ];

        foreach ($servers as [$id, $web, $mirror, $config]) {
            DB::table('server')->insert([
                'server_id' => $id, 'server_name' => "server{$id}", 'web_server' => $web, 'db_server' => 0,
                'mail_server' => $web ? 0 : 1, 'mirror_server_id' => $mirror, 'active' => 1, 'config' => $config,
            ]);
        }

        DB::table('sys_ini')->insert([
            'sysini_id' => 1,
            'config' => implode("\n", ['[sites]', 'dbname_prefix=c[CLIENTID]', 'web_php_options=no,fast-cgi,cgi,mod,suphp,php-fpm,hhvm', '[misc]', 'ssh_authentication=']),
        ]);

        $clientA = $this->tenant('clientA')['client_id'];
        $clientB = $this->tenant('clientB')['client_id'];
        $rows = [
            [10, 1, 0, 'PHP 8.2', 'y', 20, self::FPM + self::FCGI],
            [11, 1, 0, 'PHP 8.3', 'y', 10, self::FPM],
            [12, 1, 0, 'PHP 7.4', 'n', 5, self::FPM + self::FCGI],
            [13, 1, $clientA, 'PHP A', 'y', 30, self::FPM + self::FCGI],
            [14, 1, $clientB, 'PHP B', 'y', 30, self::FPM + self::FCGI],
            [15, 1, 0, 'No binaries', 'y', 1, []],
            [16, 2, 0, 'PHP 8.1', 'y', 10, self::FPM + self::FCGI],
            [17, 3, 0, 'Mirror PHP', 'y', 10, self::FPM],
            [18, 5, 0, 'Nginx FPM', 'y', 10, self::FPM],
        ];

        foreach ($rows as [$id, $server, $client, $name, $active, $prio, $binaries]) {
            DB::table('server_php')->insert(array_merge([
                'server_php_id' => $id, 'server_id' => $server, 'client_id' => $client, 'name' => $name,
                'active' => $active, 'sortprio' => $prio,
            ], $binaries));
        }
    }

    protected function webConfig(string $type, bool $hideDefault, string $defaultName): string
    {
        return implode("\n", [
            '[web]',
            "server_type={$type}",
            'website_path=/var/www/clients/client[client_id]/web[website_id]',
            'php_open_basedir=[website_path]/web:[website_path]/tmp',
            'htaccess_allow_override=All',
            'enable_sni=y',
            'php_fpm_default_chroot=n',
            'php_default_hide='.($hideDefault ? 'y' : 'n'),
            "php_default_name={$defaultName}",
            '[server]',
            'ip_address=10.0.0.1',
            'log_retention=30',
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
            'php' => 'php-fpm', 'server_php_id' => 10,
        ], $attrs)), 'domain_id');

        DB::table('web_domain')->where('domain_id', $id)->update([
            'document_root' => "/var/www/clients/client/web{$id}",
            'system_user' => "web{$id}", 'system_group' => 'client',
        ]);

        return $id;
    }

    /**
     * @return array<int, int>
     */
    protected function ids(string $query, string $tenant = 'clientA'): array
    {
        return array_column($this->getJson('/api/v1/me/php-versions'.$query, $this->tenantHeaders($tenant))->assertOk()->json('data'), 'id');
    }

    public function test_requires_api_key(): void
    {
        $this->getJson('/api/v1/me/php-versions')->assertStatus(401);
    }

    public function test_lists_usable_versions_of_a_server_with_their_modes(): void
    {
        $this->assignServers('clientA', ['web' => [1]]);

        $this->getJson('/api/v1/me/php-versions?server_id=1', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    ['id' => 0, 'name' => 'Default PHP', 'server_id' => 1, 'modes' => ['php-fpm', 'fast-cgi'], 'is_default' => true],
                    ['id' => 11, 'name' => 'PHP 8.3', 'server_id' => 1, 'modes' => ['php-fpm'], 'is_default' => false],
                    ['id' => 10, 'name' => 'PHP 8.2', 'server_id' => 1, 'modes' => ['php-fpm', 'fast-cgi'], 'is_default' => false],
                    ['id' => 13, 'name' => 'PHP A', 'server_id' => 1, 'modes' => ['php-fpm', 'fast-cgi'], 'is_default' => false],
                ],
                'meta' => ['total' => 4, 'limit' => 25, 'offset' => 0],
            ]);

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_mode_filter_and_plan_modes_narrow_the_list(): void
    {
        $this->assignServers('clientA', ['web' => [1]]);

        $response = $this->getJson('/api/v1/me/php-versions?server_id=1&mode=fast-cgi', $this->tenantHeaders('clientA'))->assertOk();
        $this->assertSame([0, 10, 13], array_column($response->json('data'), 'id'));
        $this->assertSame(['fast-cgi'], $response->json('data.0.modes'));

        $this->setClientModes('clientA', 'no,php-fpm');
        $response = $this->getJson('/api/v1/me/php-versions?server_id=1', $this->tenantHeaders('clientA'))->assertOk();
        $this->assertSame([0, 11, 10, 13], array_column($response->json('data'), 'id'));
        $this->assertSame(['php-fpm'], $response->json('data.2.modes'));
        $this->assertSame([], $this->ids('?server_id=1&mode=fast-cgi'));

        $this->setClientModes('clientA', 'no,mod');
        $this->getJson('/api/v1/me/php-versions?server_id=1', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertExactJson(['data' => [], 'meta' => ['total' => 0, 'limit' => 25, 'offset' => 0]]);
    }

    public function test_hidden_default_is_not_listed(): void
    {
        $this->assignServers('clientA', ['web' => [2]]);

        $this->assertSame([16], $this->ids('?server_id=2'));
    }

    public function test_without_server_id_lists_every_web_server_of_the_account(): void
    {
        $this->assignServers('clientA', ['web' => [2, 3, 4, 1]]);
        $this->seedVhost('clientA', ['server_id' => 5, 'php' => 'fast-cgi', 'server_php_id' => 0]);
        $this->seedVhost('clientA', ['server_id' => 3]);

        $data = $this->getJson('/api/v1/me/php-versions', $this->tenantHeaders('clientA'))->assertOk()->json('data');

        $this->assertSame(
            [[2, 16], [1, 0], [1, 11], [1, 10], [1, 13], [5, 0], [5, 18]],
            array_map(fn (array $entry): array => [$entry['server_id'], $entry['id']], $data)
        );
        // nginx: the FPM-only version also serves fast-cgi websites.
        $this->assertSame(['php-fpm', 'fast-cgi'], $data[6]['modes']);
        $this->assertSame('Default', $data[5]['name']);
    }

    public function test_parameters_are_validated(): void
    {
        $this->assignServers('clientA', ['web' => [1]]);
        $headers = $this->tenantHeaders('clientA');
        $notOwn = 'The selected server is not a web server of this account.';

        $this->getJson('/api/v1/me/php-versions?server_id=5', $headers)->assertStatus(422)->assertJsonPath('errors.server_id.0', $notOwn);
        $this->getJson('/api/v1/me/php-versions?server_id=3', $headers)->assertStatus(422)->assertJsonPath('errors.server_id.0', $notOwn);
        $this->getJson('/api/v1/me/php-versions?server_id=abc', $headers)
            ->assertStatus(422)->assertJsonPath('errors.server_id.0', 'The server id must be a positive integer.');
        $this->getJson('/api/v1/me/php-versions?mode=hhvm', $headers)
            ->assertStatus(422)->assertJsonPath('errors.mode.0', 'The mode must be php-fpm or fast-cgi.');
        $this->getJson('/api/v1/me/php-versions?sort=name', $headers)->assertStatus(400);
        $this->getJson('/api/v1/me/php-versions?limit=0', $headers)->assertStatus(400);
        $this->getJson('/api/v1/me/php-versions?offset=-1', $headers)->assertStatus(400);
    }

    public function test_limit_and_offset(): void
    {
        $this->assignServers('clientA', ['web' => [1]]);

        $this->getJson('/api/v1/me/php-versions?server_id=1&limit=2&offset=1', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('meta', ['total' => 4, 'limit' => 2, 'offset' => 1])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', 11)
            ->assertJsonPath('data.1.id', 10);
    }

    public function test_target_account_rules(): void
    {
        foreach (['clientA', 'clientB', 'reseller'] as $tenant) {
            $this->assignServers($tenant, ['web' => [1]]);
        }

        $clientA = $this->tenant('clientA')['client_id'];
        $clientB = $this->tenant('clientB')['client_id'];

        $this->assertSame([0, 11, 10], $this->ids('?server_id=1', 'reseller'));
        $this->assertSame([0, 11, 10, 13], $this->ids("?server_id=1&client_id={$clientA}", 'reseller'));
        $this->getJson("/api/v1/me/php-versions?client_id={$clientB}", $this->tenantHeaders('reseller'))->assertStatus(404);
        $this->getJson("/api/v1/me/php-versions?client_id={$clientB}", $this->tenantHeaders('clientA'))->assertStatus(404);

        $this->getJson('/api/v1/me/php-versions', $this->tenantHeaders('admin'))
            ->assertStatus(422)->assertJsonPath('errors.client_id.0', 'The client id is required for admin keys.');
        $this->assertSame([0, 11, 10, 14], $this->ids("?server_id=1&client_id={$clientB}", 'admin'));
        $this->getJson('/api/v1/me/php-versions?client_id=9999', $this->tenantHeaders('admin'))->assertStatus(404);
    }

    public function test_listed_versions_are_accepted_by_website_writes(): void
    {
        $this->assignServers('clientA', ['web' => [1]]);
        $headers = $this->tenantHeaders('clientA');
        $site = $this->seedVhost('clientA');

        foreach (['php-fpm', 'fast-cgi'] as $mode) {
            $this->putJson("/api/v1/sites/web-domains/{$site}", ['php' => $mode, 'server_php_id' => 0], $headers)->assertStatus(200);
            $listed = $this->ids("?server_id=1&mode={$mode}");

            foreach ([0, 10, 11, 12, 13, 14, 15, 16, 17] as $version) {
                $status = $this->putJson("/api/v1/sites/web-domains/{$site}", ['server_php_id' => $version], $headers)->status();
                $this->assertSame(in_array($version, $listed, true) ? 200 : 422, $status, "{$mode} version {$version}");
            }
        }
    }
}
