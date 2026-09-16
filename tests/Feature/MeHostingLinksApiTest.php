<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ServerSchema;
use Tests\Support\SitesSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * GET /me/hosting-links (spec 036, api/modules/me/hosting-links.yaml): the
 * installation's database administration and web file manager addresses as
 * they apply to one account — `[SERVERNAME]` resolved per database server,
 * `[DATABASENAME]` left for the consumer, and the interface's own link switch
 * (`dblist_phpmyadmin_link`) deciding availability.
 */
class MeHostingLinksApiTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const URL = '/api/v1/me/hosting-links';

    protected function setUp(): void
    {
        parent::setUp();

        ServerSchema::create();
        SitesSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        $this->server(1, 'db1.example.com', ['db_server' => 1]);
        $this->server(2, 'db2.example.com', ['db_server' => 1]);
        $this->server(3, 'web1.example.com', ['web_server' => 1]);
        $this->server(4, 'db-mirror.example.com', ['db_server' => 1, 'mirror_server_id' => 1]);
        $this->server(5, 'db3.example.com', ['db_server' => 1]);

        // Mirror (4) and missing (99) entries are skipped, order is kept.
        $this->assignServers('clientA', ['db' => [4, 1, 2, 99]]);
        $this->assignServers('clientB', ['db' => [1]]);

        // A database on a server the account is not assigned to is appended.
        $this->database('clientA', 'c1_shop', 5);
        // Another client's database must not add its server.
        $this->database('clientB', 'c2_shop', 1);

        $this->setSites('https://[SERVERNAME]:8081/phpmyadmin', 'y', '');
    }

    private function server(int $id, string $name, array $flags): void
    {
        DB::table('server')->insert(array_merge([
            'server_id' => $id,
            'server_name' => $name,
            'web_server' => 0,
            'mail_server' => 0,
            'dns_server' => 0,
            'db_server' => 0,
            'mirror_server_id' => 0,
            'active' => 1,
        ], $flags));
    }

    private function database(string $tenant, string $name, int $serverId): void
    {
        DB::table('web_database')->insert($this->ownedBy($tenant, [
            'server_id' => $serverId, 'database_name' => $name, 'type' => 'mysql', 'active' => 'y',
        ]));
    }

    private function setSites(string $phpMyAdmin, string $listLink, string $webFtp): void
    {
        DB::table('sys_ini')->updateOrInsert(['sysini_id' => 1], [
            'config' => implode("\n", [
                '[sites]',
                'dbname_prefix=c[CLIENTID]_',
                'phpmyadmin_url='.$phpMyAdmin,
                'dblist_phpmyadmin_link='.$listLink,
                'webftp_url='.$webFtp,
                '[misc]',
                'ssh_authentication=',
            ]),
        ]);
    }

    public function test_requires_api_key(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
    }

    public function test_database_administration_links_per_server(): void
    {
        $response = $this->getJson(self::URL, $this->tenantHeaders('clientA'))->assertOk();

        $response->assertJsonPath('client_id', $this->tenant('clientA')['client_id']);
        $response->assertJsonPath('database_administration.available', true);

        // Assigned servers in assignment order, then the server hosting the
        // account's database; the mirror, the missing id, the web-only server
        // and the other client's server never appear.
        $this->assertSame([
            ['server_id' => 1, 'server_name' => 'db1.example.com', 'url' => 'https://db1.example.com:8081/phpmyadmin'],
            ['server_id' => 2, 'server_name' => 'db2.example.com', 'url' => 'https://db2.example.com:8081/phpmyadmin'],
            ['server_id' => 5, 'server_name' => 'db3.example.com', 'url' => 'https://db3.example.com:8081/phpmyadmin'],
        ], $response->json('database_administration.servers'));

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_database_name_placeholder_is_left_for_the_consumer(): void
    {
        $this->setSites('https://[SERVERNAME]/pma/?db=[DATABASENAME]', 'y', '');

        $this->getJson(self::URL, $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('database_administration.servers.0.url', 'https://db1.example.com/pma/?db=[DATABASENAME]');
    }

    public function test_unavailable_when_switched_off_or_unset(): void
    {
        // The interface's own switch decides.
        $this->setSites('https://[SERVERNAME]:8081/phpmyadmin', 'n', '');

        $response = $this->getJson(self::URL, $this->tenantHeaders('clientA'))->assertOk();
        $response->assertJsonPath('database_administration.available', false);
        $this->assertCount(3, $response->json('database_administration.servers'));

        // No address configured: unavailable and empty addresses.
        $this->setSites('', 'y', '');

        $response = $this->getJson(self::URL, $this->tenantHeaders('clientA'))->assertOk();
        $response->assertJsonPath('database_administration.available', false);
        $response->assertJsonPath('database_administration.servers.0.url', '');
    }

    public function test_file_transfer_mirrors_the_setting(): void
    {
        $this->getJson(self::URL, $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('file_transfer', ['available' => false, 'url' => '']);

        $this->setSites('https://[SERVERNAME]:8081/phpmyadmin', 'y', 'https://files.example.test/[SERVERNAME]');

        // Returned verbatim — legacy substitutes nothing in this setting.
        $this->getJson(self::URL, $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('file_transfer', ['available' => true, 'url' => 'https://files.example.test/[SERVERNAME]']);
    }

    public function test_nothing_configured_and_exposure_boundary(): void
    {
        $this->setSites('', 'n', '');

        $response = $this->getJson(self::URL, $this->tenantHeaders('clientA'))->assertOk();

        $response->assertJsonPath('database_administration.available', false);
        $response->assertJsonPath('file_transfer', ['available' => false, 'url' => '']);

        // Exactly the three documented keys; no other [sites] value leaks.
        $this->assertSame(['client_id', 'database_administration', 'file_transfer'], array_keys($response->json()));
        $this->assertStringNotContainsString('dbname_prefix', $response->getContent());
        $this->assertStringNotContainsString('c[CLIENTID]', $response->getContent());
    }

    public function test_account_without_database_servers(): void
    {
        $response = $this->getJson(self::URL, $this->tenantHeaders('reseller'))->assertOk();

        $response->assertJsonPath('database_administration.servers', []);
        $response->assertJsonPath('database_administration.available', true);
    }

    public function test_target_rules(): void
    {
        $clientA = $this->tenant('clientA')['client_id'];

        // The reseller may describe its own account and its client.
        $this->getJson(self::URL, $this->tenantHeaders('reseller'))->assertOk();
        $this->getJson(self::URL.'?client_id='.$clientA, $this->tenantHeaders('reseller'))
            ->assertOk()
            ->assertJsonPath('client_id', $clientA);

        // A foreign client is not found for the reseller or another client.
        $foreign = $this->tenant('clientB')['client_id'];
        $this->getJson(self::URL.'?client_id='.$foreign, $this->tenantHeaders('reseller'))->assertStatus(404);
        $this->getJson(self::URL.'?client_id='.$foreign, $this->tenantHeaders('clientA'))->assertStatus(404);

        // Admin keys must name the client.
        $this->getJson(self::URL, $this->tenantHeaders('admin'))->assertStatus(422);
        $this->getJson(self::URL.'?client_id=999999', $this->tenantHeaders('admin'))->assertStatus(404);
        $this->getJson(self::URL.'?client_id='.$clientA, $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertJsonPath('client_id', $clientA);

        // Parameter validation.
        $this->getJson(self::URL.'?foo=1', $this->tenantHeaders('clientA'))->assertStatus(400);
        $this->getJson(self::URL.'?client_id=0', $this->tenantHeaders('clientA'))->assertStatus(422);
    }
}
