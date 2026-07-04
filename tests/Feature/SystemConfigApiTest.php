<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SystemSchema;
use Tests\TestCase;

/**
 * GET/PUT /system/config[/{section}] — the sys_ini INI blob panels.
 *
 * The primary fixture (tests/fixtures/sys_ini_config.ini) is the real,
 * sanitized config blob of an ISPConfig 3.2 test panel, extracted verbatim —
 * it carries legacy-only keys the API never exposes (dkim_path, the smtp_*
 * block, client_protection, phpmyadmin_url, webdavuser_prefix, ...) whose
 * byte-survival across PUTs these tests prove.
 */
class SystemConfigApiTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'test-dev-key';

    protected function setUp(): void
    {
        parent::setUp();

        SystemSchema::create();

        config(['api.dev_key' => self::KEY]);

        DB::table('sys_user')->insert([
            'userid' => 1,
            'username' => 'apiadmin',
            'typ' => 'admin',
            'default_group' => 1,
        ]);
    }

    protected function authHeaders(): array
    {
        return ['X-API-Key' => self::KEY];
    }

    protected function fixtureBlob(): string
    {
        return file_get_contents(base_path('tests/fixtures/sys_ini_config.ini'));
    }

    protected function seedSysIni(?string $blob = null): void
    {
        DB::table('sys_ini')->insert([
            'sysini_id' => 1,
            'config' => $blob ?? $this->fixtureBlob(),
            'default_logo' => '',
            'custom_logo' => '',
        ]);
    }

    protected function storedBlob(): string
    {
        return (string) DB::table('sys_ini')->where('sysini_id', 1)->value('config');
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        $this->seedSysIni();

        foreach (['/api/v1/system/config', '/api/v1/system/config/dns'] as $path) {
            $this->getJson($path)
                ->assertStatus(401)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJson(['title' => 'Unauthorized', 'status' => 401]);
        }

        $this->putJson('/api/v1/system/config/mail', ['enable_custom_login' => 'y'])
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // GET
    // ------------------------------------------------------------------

    public function test_get_composite_config_returns_all_sections(): void
    {
        $this->seedSysIni();

        $this->getJson('/api/v1/system/config', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', 1)
            ->assertJsonStructure(['id', 'sites', 'mail', 'dns', 'domains', 'misc'])
            // values straight from the blob, typed per the contract
            ->assertJsonPath('sites.dbname_prefix', 'c[CLIENTID]')
            ->assertJsonPath('sites.default_webserver', 1)
            ->assertJsonPath('sites.web_php_options', ['no', 'fast-cgi', 'mod', 'php-fpm'])
            ->assertJsonPath('sites.le_caa_autocreate_options', 'y')
            ->assertJsonPath('mail.enable_custom_login', 'n')
            ->assertJsonPath('mail.mailboxlist_webmail_link', 'y')
            ->assertJsonPath('mail.webmail_url', 'https://[SERVERNAME]:8081/webmail')
            ->assertJsonPath('mail.default_mailserver', 1)
            ->assertJsonPath('dns.default_dnsserver', 1)
            ->assertJsonPath('dns.default_slave_dnsserver', 0)
            ->assertJsonPath('domains.use_domain_module', 'y')
            ->assertJsonPath('misc.customer_no_template', 'C[CUSTOMER_NO]')
            ->assertJsonPath('misc.min_password_length', 8)
            ->assertJsonPath('misc.min_password_strength', '3')
            // legacy-only blob keys are never exposed
            ->assertJsonMissingPath('mail.smtp_host')
            ->assertJsonMissingPath('mail.dkim_path')
            ->assertJsonMissingPath('sites.client_protection')
            ->assertJsonMissingPath('sites.phpmyadmin_url');
    }

    public function test_get_section_returns_contract_shape(): void
    {
        $this->seedSysIni();

        $this->getJson('/api/v1/system/config/dns', $this->authHeaders())
            ->assertOk()
            ->assertExactJson([
                'default_dnsserver' => 1,
                'default_slave_dnsserver' => 0,
                'dns_external_slave_fqdn' => '',
                'dns_show_zoneexport' => 'n',
            ]);
    }

    public function test_get_section_fills_absent_keys_with_legacy_defaults(): void
    {
        $this->seedSysIni("[sites]\ndbname_prefix=p_\n\n");

        $this->getJson('/api/v1/system/config/misc', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('min_password_length', 5)
            ->assertJsonPath('dashboard_atom_url_admin', 'https://www.ispconfig.org/atom')
            ->assertJsonPath('use_loadindicator', 'y')
            ->assertJsonPath('maintenance_mode', 'n')
            ->assertJsonPath('min_password_strength', '');

        $this->getJson('/api/v1/system/config/mail', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('enable_welcome_mail', 'y')
            ->assertJsonPath('mailbox_show_autoresponder_tab', 'y')
            ->assertJsonPath('mailbox_show_last_access', 'n')
            ->assertJsonPath('default_mailserver', 1);

        $this->getJson('/api/v1/system/config/sites', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('dbname_prefix', 'p_')
            ->assertJsonPath('le_caa_autocreate_options', 'y')
            ->assertJsonPath('default_webserver', 1)
            ->assertJsonPath('web_php_options', []);
    }

    public function test_get_500_when_singleton_row_is_missing(): void
    {
        $this->getJson('/api/v1/system/config', $this->authHeaders())
            ->assertStatus(500)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 500);
    }

    // ------------------------------------------------------------------
    // PUT — merge semantics and datalog
    // ------------------------------------------------------------------

    public function test_put_section_changes_exactly_one_line_and_datalogs_once(): void
    {
        $this->seedSysIni();
        $before = $this->storedBlob();

        $this->putJson('/api/v1/system/config/dns', ['default_dnsserver' => 3], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('default_dnsserver', 3)
            ->assertJsonPath('default_slave_dnsserver', 0)
            ->assertJsonPath('dns_show_zoneexport', 'n');

        // Byte-level proof: the stored blob differs from the original in
        // exactly the one changed line.
        $this->assertSame(
            str_replace("default_dnsserver=1\n", "default_dnsserver=3\n", $before),
            $this->storedBlob()
        );

        // Exactly one sys_datalog 'u' entry for sys_ini, legacy format.
        $rows = DB::table('sys_datalog')->get();
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('sys_ini', $row->dbtable);
        $this->assertSame('sysini_id:1', $row->dbidx);
        $this->assertSame('u', $row->action);
        $this->assertSame(0, (int) $row->server_id); // sys_ini has no server_id
        $this->assertSame('apiadmin', $row->user);

        $data = unserialize($row->data);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame($before, $data['old']['config']);
        $this->assertSame($this->storedBlob(), $data['new']['config']);
    }

    public function test_put_preserves_unexposed_legacy_keys_byte_for_byte(): void
    {
        $this->seedSysIni();

        $this->putJson('/api/v1/system/config/mail', ['enable_custom_login' => 'y'], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('enable_custom_login', 'y');

        $blob = $this->storedBlob();

        // Legacy-only keys of the SAME section survive untouched...
        $this->assertStringContainsString("dkim_path=/var/lib/amavis/dkim\n", $blob);
        $this->assertStringContainsString("smtp_enabled=n\n", $blob);
        $this->assertStringContainsString("smtp_host=localhost\n", $blob);
        // ...and of other sections too.
        $this->assertStringContainsString("client_protection=y\n", $blob);
        $this->assertStringContainsString("webdavuser_prefix=[CLIENTNAME]\n", $blob);
        $this->assertStringContainsString("phpmyadmin_url=https://[SERVERNAME]:8081/phpmyadmin\n", $blob);
        $this->assertStringContainsString("vhost_subdomains=y\n", $blob);

        // Full byte-diff: only the one submitted line changed.
        $this->assertSame(
            str_replace("enable_custom_login=n\n", "enable_custom_login=y\n", $this->fixtureBlob()),
            $blob
        );
    }

    public function test_put_merge_is_exact_on_a_hand_written_blob(): void
    {
        // Hand-written canonical blob — expectation computed without the
        // implementation's own serializer.
        $this->seedSysIni("[mail]\nenable_custom_login=n\ndkim_path=/usr/local/dkim\n\n[dns]\ndefault_dnsserver=1\n\n");

        $this->putJson('/api/v1/system/config/dns', ['default_dnsserver' => 2], $this->authHeaders())
            ->assertOk();

        $this->assertSame(
            "[mail]\nenable_custom_login=n\ndkim_path=/usr/local/dkim\n\n[dns]\ndefault_dnsserver=2\n\n",
            $this->storedBlob()
        );
    }

    public function test_put_appends_new_keys_and_sections(): void
    {
        $this->seedSysIni("[dns]\ndefault_dnsserver=1\n\n");

        // New key appended at the end of its section...
        $this->putJson('/api/v1/system/config/dns', ['dns_show_zoneexport' => 'y'], $this->authHeaders())
            ->assertOk();
        $this->assertSame(
            "[dns]\ndefault_dnsserver=1\ndns_show_zoneexport=y\n\n",
            $this->storedBlob()
        );

        // ...a missing section is created at the end of the blob.
        $this->putJson('/api/v1/system/config/domains', ['use_domain_module' => 'y'], $this->authHeaders())
            ->assertOk();
        $this->assertSame(
            "[dns]\ndefault_dnsserver=1\ndns_show_zoneexport=y\n\n[domains]\nuse_domain_module=y\n\n",
            $this->storedBlob()
        );
    }

    public function test_put_composite_merges_multiple_sections_with_one_datalog_entry(): void
    {
        $this->seedSysIni();

        $this->putJson('/api/v1/system/config', [
            'sites' => ['dbname_prefix' => 'x_'],
            'misc' => ['maintenance_mode' => 'y'],
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', 1)
            ->assertJsonPath('sites.dbname_prefix', 'x_')
            ->assertJsonPath('misc.maintenance_mode', 'y')
            ->assertJsonPath('mail.enable_custom_login', 'n');

        $expected = str_replace(
            ["dbname_prefix=c[CLIENTID]\n", "maintenance_mode=n\n"],
            ["dbname_prefix=x_\n", "maintenance_mode=y\n"],
            $this->fixtureBlob()
        );
        $this->assertSame($expected, $this->storedBlob());

        $this->assertSame(1, DB::table('sys_datalog')->count());
        $this->assertSame(1, DB::table('sys_datalog')->where('dbtable', 'sys_ini')->where('action', 'u')->count());
    }

    public function test_put_serializes_types_back_into_blob_strings(): void
    {
        $this->seedSysIni();

        // integer -> plain key=value string
        $this->putJson('/api/v1/system/config/misc', ['session_timeout' => 45], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('session_timeout', 45);
        $this->assertStringContainsString("session_timeout=45\n", $this->storedBlob());

        // JSON array -> comma-separated blob value
        $this->putJson('/api/v1/system/config/sites', ['web_php_options' => ['php-fpm', 'mod']], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('web_php_options', ['php-fpm', 'mod']);
        $this->assertStringContainsString("web_php_options=php-fpm,mod\n", $this->storedBlob());
    }

    public function test_put_applies_legacy_striptags_stripnl_filters(): void
    {
        $this->seedSysIni();

        $this->putJson('/api/v1/system/config/misc', [
            'company_name' => "<b>Acme</b>\nCorp",
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('company_name', 'AcmeCorp');

        $this->assertStringContainsString("company_name=AcmeCorp\n", $this->storedBlob());
    }

    public function test_put_ignores_keys_the_contract_does_not_expose(): void
    {
        $this->seedSysIni();
        $before = $this->storedBlob();

        // dkim_path/smtp_host exist in the legacy blob but not in the
        // contract — they must be ignored, never merged.
        $this->putJson('/api/v1/system/config/mail', [
            'dkim_path' => '/tmp/evil',
            'smtp_host' => 'evil.example.com',
        ], $this->authHeaders())->assertOk();

        $this->assertSame($before, $this->storedBlob());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_put_without_changes_writes_no_datalog_entry(): void
    {
        $this->seedSysIni();

        // Re-sending the current value round-trips to an identical blob.
        $this->putJson('/api/v1/system/config/dns', ['default_dnsserver' => 1], $this->authHeaders())
            ->assertOk();

        $this->assertSame($this->fixtureBlob(), $this->storedBlob());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    // ------------------------------------------------------------------
    // Validation (legacy validator parity)
    // ------------------------------------------------------------------

    public function test_put_validation_failures_return_422_and_write_nothing(): void
    {
        $this->seedSysIni();
        $before = $this->storedBlob();

        $cases = [
            'sites prefix regex' => ['sites', ['dbname_prefix' => 'bad prefix!'], 'dbname_prefix'],
            'sites remote db ip list' => ['sites', ['default_remote_dbserver' => '192.168.1.1,not-an-ip'], 'default_remote_dbserver'],
            'sites php options empty' => ['sites', ['web_php_options' => []], 'web_php_options'],
            'sites php options bogus' => ['sites', ['web_php_options' => ['bogus']], 'web_php_options.0'],
            'sites ssh auth enum' => ['sites', ['ssh_authentication' => 'pw'], 'ssh_authentication'],
            'sites yn enum' => ['sites', ['postgresql_database' => 'x'], 'postgresql_database'],
            'mail webmail url regex' => ['mail', ['webmail_url' => 'https://webmail.tld/?a=b'], 'webmail_url'],
            'mail mailinglist url regex' => ['mail', ['mailmailinglist_url' => 'https://lists.tld/[x]'], 'mailmailinglist_url'],
            'dns integer' => ['dns', ['default_dnsserver' => 'abc'], 'default_dnsserver'],
            'misc login link regex' => ['misc', ['custom_login_link' => 'ftp://example.com'], 'custom_login_link'],
            'misc exclude ips' => ['misc', ['maintenance_mode_exclude_ips' => '1.2.3.4,999.1.1.1'], 'maintenance_mode_exclude_ips'],
            'misc customer template regex' => ['misc', ['customer_no_template' => 'bad tpl'], 'customer_no_template'],
            'misc password strength enum' => ['misc', ['min_password_strength' => '9'], 'min_password_strength'],
        ];

        foreach ($cases as $label => [$section, $payload, $errorField]) {
            $response = $this->putJson('/api/v1/system/config/'.$section, $payload, $this->authHeaders());
            $response->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 422);
            $this->assertArrayHasKey($errorField, $response->json('errors'), "case: {$label}");
        }

        // Composite PUT validates nested section fields the same way.
        $this->putJson('/api/v1/system/config', ['misc' => ['min_password_length' => 'many']], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonPath('status', 422);

        $this->assertSame($before, $this->storedBlob());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }
}
