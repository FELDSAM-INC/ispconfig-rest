<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MailCompletionSchema;
use Tests\Support\SitesSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * GET /me/mail-settings (spec 025 US2, api/modules/me/mail-settings.yaml):
 * host names, connections and webmail addresses of the account's mail
 * servers, plus the installation's mailbox rules.
 */
class MeMailSettingsApiTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const URL = '/api/v1/me/mail-settings';

    protected function setUp(): void
    {
        parent::setUp();

        SitesSchema::create();
        MailCompletionSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        $this->server(1, 'mail1.example.com', ['mail_server' => 1], 'apache');
        $this->server(2, 'mail2.example.com', ['mail_server' => 1], 'nginx');
        $this->server(3, 'web3.example.com', ['web_server' => 1], 'apache');
        $this->server(4, 'mirror4.example.com', ['mail_server' => 1, 'mirror_server_id' => 1], 'apache');
        $this->server(5, 'mail5.example.com', ['mail_server' => 1], 'apache');

        $this->setMail(
            ['webmail_url' => 'https://[SERVERNAME]:8081/webmail', 'mailboxlist_webmail_link' => 'y'],
            ['min_password_length' => '8', 'min_password_strength' => '3']
        );
    }

    /**
     * @param  array<string, int>  $flags
     */
    protected function server(int $id, string $name, array $flags, string $serverType): void
    {
        DB::table('server')->insert(array_merge([
            'server_id' => $id,
            'server_name' => $name,
            'web_server' => 0,
            'mail_server' => 0,
            'db_server' => 0,
            'mirror_server_id' => 0,
            'active' => 1,
            'config' => "[web]\nserver_type={$serverType}\n[mail]\ndkim_path=/var/lib/amavis/dkim\n",
        ], $flags));
    }

    /**
     * @param  array<string, string>  $mail
     * @param  array<string, string>  $misc
     */
    protected function setMail(array $mail, array $misc = []): void
    {
        $lines = ['[mail]'];

        foreach ($mail as $key => $value) {
            $lines[] = "{$key}={$value}";
        }

        $lines[] = '[misc]';

        foreach ($misc as $key => $value) {
            $lines[] = "{$key}={$value}";
        }

        DB::table('sys_ini')->updateOrInsert(['sysini_id' => 1], ['config' => implode("\n", $lines)]);
    }

    protected function mailDomain(string $owner, int $serverId, string $domain): void
    {
        DB::table('mail_domain')->insert($this->ownedBy($owner, [
            'server_id' => $serverId, 'domain' => $domain, 'active' => 'y',
        ]));
    }

    /**
     * @return array<int, int>
     */
    protected function serverIds(string $tenant, string $query = ''): array
    {
        return array_column($this->getJson(self::URL.$query, $this->tenantHeaders($tenant))->assertOk()->json('servers'), 'server_id');
    }

    public function test_requires_api_key(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
    }

    public function test_client_key_reads_its_mail_settings(): void
    {
        $this->assignServers('clientA', ['mail' => [1]]);

        $response = $this->getJson(self::URL, $this->tenantHeaders('clientA'));

        $response->assertOk()->assertExactJson([
            'client_id' => $this->tenant('clientA')['client_id'],
            'custom_login' => false,
            'webmail_link' => true,
            'password_policy' => ['min_length' => 8, 'min_strength' => 3, 'ascii_only' => false],
            'servers' => [[
                'server_id' => 1,
                'host' => 'mail1.example.com',
                'webmail_url' => 'https://mail1.example.com:8081/webmail',
                'imap' => ['port' => 993, 'security' => 'ssl'],
                'pop3' => ['port' => 995, 'security' => 'ssl'],
                'smtp' => [['port' => 587, 'security' => 'starttls'], ['port' => 465, 'security' => 'ssl']],
            ]],
        ]);
        $this->assertSame(
            ['client_id', 'custom_login', 'webmail_link', 'password_policy', 'servers'],
            array_keys($response->json())
        );
        $this->assertSame(
            ['server_id', 'host', 'webmail_url', 'imap', 'pop3', 'smtp'],
            array_keys($response->json('servers.0'))
        );
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_webmail_url_falls_back_to_the_server_webmail(): void
    {
        $this->assignServers('clientA', ['mail' => [1, 2]]);
        $headers = $this->tenantHeaders('clientA');

        $this->setMail(['webmail_url' => '', 'mailboxlist_webmail_link' => 'n']);
        $this->getJson(self::URL, $headers)
            ->assertOk()
            ->assertJsonPath('webmail_link', false)
            ->assertJsonPath('servers.0.webmail_url', 'https://mail1.example.com/webmail')
            ->assertJsonPath('servers.1.webmail_url', 'https://mail2.example.com:8081/webmail');

        // Missing keys: no URL setting, no list link (form default n), no custom login.
        $this->setMail([]);
        $this->getJson(self::URL, $headers)
            ->assertOk()
            ->assertJsonPath('webmail_link', false)
            ->assertJsonPath('custom_login', false)
            ->assertJsonPath('servers.0.webmail_url', 'https://mail1.example.com/webmail')
            ->assertJsonPath('password_policy', ['min_length' => 8, 'min_strength' => 0, 'ascii_only' => false]);

        // A fixed URL without placeholder is used as is.
        $this->setMail(['webmail_url' => 'https://webmail.example.net/', 'enable_custom_login' => 'y', 'mail_password_onlyascii' => 'y']);
        $this->getJson(self::URL, $headers)
            ->assertOk()
            ->assertJsonPath('custom_login', true)
            ->assertJsonPath('password_policy.ascii_only', true)
            ->assertJsonPath('servers.1.webmail_url', 'https://webmail.example.net/');
    }

    public function test_servers_are_assigned_first_then_hosting_servers(): void
    {
        // 3 is not a mail server, 4 is a mirror: both skipped.
        $this->assignServers('clientA', ['mail' => [2, 1, 3, 4]]);
        $this->mailDomain('clientA', 1, 'a1.test');
        $this->mailDomain('clientA', 5, 'a5.test');
        $this->mailDomain('clientA', 4, 'a4.test');
        $this->mailDomain('clientB', 3, 'b3.test');

        $this->assertSame([2, 1, 5], $this->serverIds('clientA'));
    }

    public function test_account_without_mail_servers_gets_an_empty_list(): void
    {
        $this->getJson(self::URL, $this->tenantHeaders('clientB'))
            ->assertOk()
            ->assertJsonPath('servers', []);
    }

    public function test_reseller_key_reads_own_and_client_settings(): void
    {
        $this->assignServers('reseller', ['mail' => [2]]);
        $this->assignServers('clientA', ['mail' => [1]]);
        $headers = $this->tenantHeaders('reseller');

        $this->getJson(self::URL, $headers)
            ->assertOk()
            ->assertJsonPath('client_id', $this->tenant('reseller')['client_id']);
        $this->assertSame([2], $this->serverIds('reseller'));
        $this->assertSame([1], $this->serverIds('reseller', '?client_id='.$this->tenant('clientA')['client_id']));

        $this->getJson(self::URL.'?client_id='.$this->tenant('clientB')['client_id'], $headers)->assertStatus(404);
    }

    public function test_admin_key_must_name_a_client(): void
    {
        $this->assignServers('clientB', ['mail' => [5]]);
        $headers = $this->tenantHeaders('admin');

        $this->getJson(self::URL, $headers)
            ->assertStatus(422)
            ->assertJsonPath('errors.client_id.0', 'The client id is required for admin keys.');

        $this->getJson(self::URL.'?client_id='.$this->tenant('clientB')['client_id'], $headers)
            ->assertOk()
            ->assertJsonPath('client_id', $this->tenant('clientB')['client_id'])
            ->assertJsonPath('servers.0.host', 'mail5.example.com');

        $this->getJson(self::URL.'?client_id=9999', $headers)->assertStatus(404);
    }

    public function test_client_key_cannot_name_another_client(): void
    {
        $headers = $this->tenantHeaders('clientA');

        $this->getJson(self::URL.'?client_id='.$this->tenant('clientB')['client_id'], $headers)->assertStatus(404);
        $this->getJson(self::URL.'?client_id='.$this->tenant('clientA')['client_id'], $headers)->assertOk();
    }

    public function test_query_parameters_are_validated(): void
    {
        $headers = $this->tenantHeaders('clientA');

        $this->getJson(self::URL.'?server_id=1', $headers)->assertStatus(400);
        $this->getJson(self::URL.'?client_id=abc', $headers)
            ->assertStatus(422)
            ->assertJsonPath('errors.client_id.0', 'The client id must be a positive integer.');
        $this->getJson(self::URL.'?client_id=0', $headers)->assertStatus(422);
    }
}
