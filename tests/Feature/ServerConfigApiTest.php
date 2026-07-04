<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ServerSchema;
use Tests\TestCase;

/**
 * HTTP layer over ServerConfigService (the byte-level merge/parser proofs
 * live in ServerConfigServiceTest). Fixture: sanitized REAL server.config
 * blob from a live panel (tests/fixtures/server-config.ini).
 */
class ServerConfigApiTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'test-dev-key';

    protected function setUp(): void
    {
        parent::setUp();

        ServerSchema::create();

        config(['api.dev_key' => self::KEY]);

        DB::table('sys_user')->insert([
            'userid' => 1, 'username' => 'apiadmin', 'typ' => 'admin', 'default_group' => 1,
        ]);

        DB::table('server')->insert([
            'server_id' => 1,
            'server_name' => 'server1',
            'mail_server' => 1,
            'web_server' => 1,
            'mirror_server_id' => 0,
            'active' => 1,
            'config' => self::fixture(),
        ]);

        // Fresh server row without a config blob.
        DB::table('server')->insert([
            'server_id' => 2,
            'server_name' => 'fresh',
            'web_server' => 1,
            'mirror_server_id' => 0,
            'active' => 1,
            'config' => null,
        ]);
    }

    protected static function fixture(): string
    {
        return file_get_contents(base_path('tests/fixtures/server-config.ini'));
    }

    protected function authHeaders(): array
    {
        return ['X-API-Key' => self::KEY];
    }

    // ------------------------------------------------------------------
    // Auth + routing
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/servers/1/configs')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->putJson('/api/v1/servers/1/configs/mail', [])
            ->assertStatus(401);
    }

    public function test_missing_server_returns_404(): void
    {
        $this->getJson('/api/v1/servers/999/configs', $this->authHeaders())
            ->assertStatus(404);
        $this->getJson('/api/v1/servers/999/configs/mail', $this->authHeaders())
            ->assertStatus(404);
    }

    public function test_unknown_section_returns_404(): void
    {
        // Only the contract's eleven sections are routable; [global] is
        // read-only and has NO section endpoint.
        $this->getJson('/api/v1/servers/1/configs/global', $this->authHeaders())
            ->assertStatus(404);
        $this->getJson('/api/v1/servers/1/configs/nonsense', $this->authHeaders())
            ->assertStatus(404);
        $this->putJson('/api/v1/servers/1/configs/global', [], $this->authHeaders())
            ->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    public function test_get_full_config_returns_all_parsed_sections(): void
    {
        $this->getJson('/api/v1/servers/1/configs', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('server_id', 1)
            // read-only [global] section is included
            ->assertJsonPath('global.webserver', 'apache')
            ->assertJsonPath('global.mailserver', 'postfix')
            ->assertJsonPath('server.hostname', 'server1.example.com')
            ->assertJsonPath('server.firewall', 'ufw')
            ->assertJsonPath('mail.content_filter', 'rspamd')
            // integers typed per the section schemas
            ->assertJsonPath('mail.mailbox_size_limit', 0)
            ->assertJsonPath('mail.dkim_strength', 2048)
            ->assertJsonPath('web.php_fpm_start_port', 9010)
            // installer-written keys unknown to the schemas are returned
            ->assertJsonPath('mail.sendmail_path', '/usr/sbin/sendmail')
            ->assertJsonPath('xmpp.xmpp_use_ispv6', 'n')
            ->assertJsonPath('rescue.try_rescue', 'n');
    }

    public function test_get_section_returns_parsed_section(): void
    {
        $this->getJson('/api/v1/servers/1/configs/mail', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('content_filter', 'rspamd')
            ->assertJsonPath('rspamd_available', 'y')
            ->assertJsonPath('mailuser_uid', 5000)
            ->assertJsonPath('mailbox_soft_delete', '0'); // string enum

        $this->getJson('/api/v1/servers/1/configs/cron', $this->authHeaders())
            ->assertOk()
            ->assertExactJson([
                'init_script' => 'cron',
                'crontab_dir' => '/etc/cron.d',
                'wget' => '/usr/bin/wget',
            ]);
    }

    public function test_get_section_of_fresh_server_returns_empty_object(): void
    {
        $response = $this->getJson('/api/v1/servers/2/configs/mail', $this->authHeaders())
            ->assertOk();

        $this->assertSame([], $response->json());
        $this->assertSame('{}', $response->getContent());
    }

    // ------------------------------------------------------------------
    // Write (byte-safe read-merge-write)
    // ------------------------------------------------------------------

    public function test_put_section_merges_byte_safely_and_datalogs_full_blob(): void
    {
        $before = self::fixture();

        $this->putJson('/api/v1/servers/1/configs/dns', [
            'bind_user' => 'bind',
            'disable_bind_log' => 'y',
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('bind_user', 'bind')
            ->assertJsonPath('disable_bind_log', 'y')
            // omitted text keys keep their stored value
            ->assertJsonPath('named_conf_path', '/etc/bind/named.conf');

        $after = (string) DB::table('server')->where('server_id', 1)->value('config');

        // Everything before [dns] and after the dns block is byte-identical.
        $prefixLength = strpos($before, '[dns]');
        $this->assertSame(substr($before, 0, $prefixLength), substr($after, 0, $prefixLength));

        $suffixStart = strpos($before, '[fastcgi]');
        $this->assertSame(substr($before, $suffixStart), substr($after, strpos($after, '[fastcgi]')));

        // Exactly ONE datalog row: 'u' on table server, full new blob in
        // the config field.
        $rows = DB::table('sys_datalog')->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('server', $row->dbtable);
        $this->assertSame('u', $row->action);
        $this->assertSame('server_id:1', $row->dbidx);
        $this->assertSame(1, (int) $row->server_id);

        $data = unserialize($row->data);
        $this->assertSame($before, $data['old']['config']);
        $this->assertSame($after, $data['new']['config']);
        $this->assertStringContainsString("bind_user=bind\n", $data['new']['config']);
    }

    public function test_put_preserves_unknown_keys_and_backfills_checkboxes(): void
    {
        $this->putJson('/api/v1/servers/1/configs/xmpp', [
            'xmpp_port_http' => 15290,
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('xmpp_port_http', 15290)
            // omitted checkbox -> unchecked value
            ->assertJsonPath('xmpp_use_ipv6', 'n');

        $after = (string) DB::table('server')->where('server_id', 1)->value('config');

        // The installer's historic typo key survives verbatim…
        $this->assertStringContainsString("xmpp_use_ispv6=n\n", $after);
        // …next to the schema's real checkbox key, backfilled with 'n'.
        $this->assertStringContainsString("xmpp_use_ipv6=n\n", $after);
        $this->assertStringContainsString("xmpp_port_http=15290\n", $after);
    }

    public function test_put_round_trips_with_get(): void
    {
        $this->putJson('/api/v1/servers/1/configs/getmail', [
            'getmail_config_dir' => '/etc/getmail2',
        ], $this->authHeaders())->assertOk();

        $this->getJson('/api/v1/servers/1/configs/getmail', $this->authHeaders())
            ->assertOk()
            ->assertExactJson(['getmail_config_dir' => '/etc/getmail2']);
    }

    public function test_put_on_fresh_server_creates_the_section(): void
    {
        $this->putJson('/api/v1/servers/2/configs/vlogger', [
            'config_dir' => '/etc',
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('config_dir', '/etc');

        $this->assertSame(
            "[vlogger]\nconfig_dir=/etc\n\n",
            (string) DB::table('server')->where('server_id', 2)->value('config')
        );
    }

    public function test_put_rejects_invalid_values_with_422(): void
    {
        $cases = [
            ['mail', ['content_filter' => 'spamassassin'], 'content_filter'],
            ['mail', ['mailuser_uid' => 100], 'mailuser_uid'], // RANGE >= 1999
            ['mail', ['stress_adaptive' => 'x'], 'stress_adaptive'],
            ['server', ['loglevel' => 5], 'loglevel'],
            ['server', ['ip_address' => 'not-an-ip'], 'ip_address'],
            ['server', ['hostname' => 'no_fqdn'], 'hostname'],
            ['web', ['server_type' => 'caddy'], 'server_type'],
            ['web', ['web_folder_permission' => '0777'], 'web_folder_permission'],
            ['cron', ['init_script' => 'bad name!'], 'init_script'],
            ['jailkit', ['jailkit_hardlinks' => 'maybe'], 'jailkit_hardlinks'],
        ];

        foreach ($cases as [$section, $payload, $errorField]) {
            $response = $this->putJson('/api/v1/servers/1/configs/'.$section, $payload, $this->authHeaders());
            $response->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json');
            $this->assertArrayHasKey($errorField, $response->json('errors'), "section {$section}");
        }

        // Nothing was written.
        $this->assertSame(self::fixture(), (string) DB::table('server')->where('server_id', 1)->value('config'));
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    // ------------------------------------------------------------------
    // Mail-section special rules
    // ------------------------------------------------------------------

    public function test_mailbox_size_limit_guard_returns_422(): void
    {
        $response = $this->putJson('/api/v1/servers/1/configs/mail', [
            'mailbox_size_limit' => 5,
            'message_size_limit' => 10,
        ], $this->authHeaders());

        $response->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json');
        $this->assertArrayHasKey('mailbox_size_limit', $response->json('errors'));
    }

    public function test_rspamd_available_is_never_accepted_from_the_client(): void
    {
        // The fixture stores rspamd_available=y; the client tries to unset it.
        $this->putJson('/api/v1/servers/1/configs/mail', [
            'rspamd_available' => 'n',
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('rspamd_available', 'y');

        $this->assertStringContainsString(
            "rspamd_available=y\n",
            (string) DB::table('server')->where('server_id', 1)->value('config')
        );
    }

    public function test_switching_content_filter_to_rspamd_touches_spamfilter_rows(): void
    {
        // Start from amavisd so the PUT is an actual switch.
        DB::table('server')->where('server_id', 1)->update([
            'config' => str_replace('content_filter=rspamd', 'content_filter=amavisd', self::fixture()),
        ]);

        DB::table('spamfilter_users')->insert([
            ['server_id' => 1, 'email' => '@example.com'],
            ['server_id' => 2, 'email' => '@other.tld'], // different server: untouched
        ]);
        DB::table('spamfilter_wblist')->insert([
            ['server_id' => 1, 'rid' => 1, 'email' => 'friend@x.tld'],
        ]);

        $this->putJson('/api/v1/servers/1/configs/mail', [
            'content_filter' => 'rspamd',
        ], $this->authHeaders())->assertOk();

        $this->assertSame(1, DB::table('sys_datalog')->where('dbtable', 'server')->where('action', 'u')->count());
        $this->assertSame(1, DB::table('sys_datalog')->where('dbtable', 'spamfilter_users')->where('action', 'u')->count());
        $this->assertSame(1, DB::table('sys_datalog')->where('dbtable', 'spamfilter_wblist')->where('action', 'u')->count());
        $this->assertSame(3, DB::table('sys_datalog')->count());

        // All rows of one request share the session id (legacy grouping).
        $this->assertSame(1, DB::table('sys_datalog')->distinct()->count('session_id'));
    }
}
