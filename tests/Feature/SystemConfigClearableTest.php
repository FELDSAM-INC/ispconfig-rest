<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SystemSchema;
use Tests\TestCase;

/**
 * Clearing text settings of the system configuration (spec 040).
 *
 * An empty string means "clear this value" for every exposed text setting —
 * ISPConfig's own form clears them the same way. Settings it marks as required
 * (`web_php_options`, the only NOTEMPTY field of the legacy form) and
 * non-string settings keep refusing an empty value.
 */
class SystemConfigClearableTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-dev-key';

    /** Section, setting and a value to set before clearing it. */
    private const CLEARABLE = [
        'webmail address' => ['mail', 'webmail_url', 'https://webmail.example.test'],
        'external DNS servers' => ['dns', 'dns_external_slave_fqdn', 'ns9.example.test'],
        'SSH authentication mode' => ['sites', 'ssh_authentication', 'key'],
        'company name' => ['misc', 'company_name', 'QA040 Ltd'],
    ];

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

        DB::table('sys_ini')->insert([
            'sysini_id' => 1,
            'config' => (string) file_get_contents(base_path('tests/fixtures/sys_ini_config.ini')),
            'default_logo' => '',
            'custom_logo' => '',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return ['X-API-Key' => self::KEY];
    }

    private function blob(): string
    {
        return (string) DB::table('sys_ini')->where('sysini_id', 1)->value('config');
    }

    public function test_every_text_setting_can_be_set_and_cleared(): void
    {
        foreach (self::CLEARABLE as $label => [$section, $key, $value]) {
            $this->putJson("/api/v1/system/config/{$section}", [$key => $value], $this->authHeaders())
                ->assertOk()
                ->assertJsonPath($key, $value, $label);

            $this->putJson("/api/v1/system/config/{$section}", [$key => ''], $this->authHeaders())
                ->assertOk()
                ->assertJsonPath($key, '', $label);

            $this->getJson("/api/v1/system/config/{$section}", $this->authHeaders())
                ->assertOk()
                ->assertJsonPath($key, '', $label);
        }
    }

    public function test_null_clears_like_an_empty_string(): void
    {
        $this->putJson('/api/v1/system/config/mail', ['webmail_url' => 'https://webmail.example.test'], $this->authHeaders())
            ->assertOk();

        $this->putJson('/api/v1/system/config/mail', ['webmail_url' => null], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('webmail_url', '');
    }

    public function test_clearing_an_already_empty_setting_is_accepted(): void
    {
        $this->putJson('/api/v1/system/config/misc', ['custom_login_text' => ''], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('custom_login_text', '');

        $this->putJson('/api/v1/system/config/misc', ['custom_login_text' => ''], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('custom_login_text', '');
    }

    public function test_required_and_non_string_settings_still_refuse_an_empty_value(): void
    {
        // web_php_options is the only NOTEMPTY field of the legacy form.
        $this->putJson('/api/v1/system/config/sites', ['web_php_options' => []], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['web_php_options']]);

        $this->putJson('/api/v1/system/config/sites', ['web_php_options' => ''], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['web_php_options']]);

        // Numbers and y/n switches are not text settings.
        $this->putJson('/api/v1/system/config/sites', ['default_webserver' => ''], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['default_webserver']]);

        $this->putJson('/api/v1/system/config/domains', ['use_domain_module' => ''], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['use_domain_module']]);
    }

    public function test_clearing_preserves_every_other_key_of_the_blob(): void
    {
        $before = $this->blob();

        $this->putJson('/api/v1/system/config/mail', ['webmail_url' => ''], $this->authHeaders())->assertOk();

        $after = $this->blob();

        // Unexposed legacy keys survive untouched.
        foreach (['phpmyadmin_url', 'client_protection', 'webdavuser_prefix', 'dkim_path'] as $legacyKey) {
            if (str_contains($before, $legacyKey)) {
                $this->assertStringContainsString($legacyKey, $after, "unexposed key {$legacyKey} must survive");
            }
        }

        // The cleared setting keeps its key with an empty value, as ISPConfig writes it.
        $this->assertMatchesRegularExpression('/^webmail_url=\s*$/m', $after);

        // Every other line is unchanged.
        $changed = array_diff(explode("\n", $after), explode("\n", $before));
        $changed = array_values(array_filter($changed, static fn (string $line): bool => trim($line) !== ''));
        $this->assertSame(['webmail_url='], $changed);
    }

    public function test_the_whole_document_route_clears_the_same_way(): void
    {
        $this->putJson('/api/v1/system/config', ['misc' => ['company_name' => 'QA040 Ltd']], $this->authHeaders())
            ->assertOk();

        $this->putJson('/api/v1/system/config', ['misc' => ['company_name' => '']], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('misc.company_name', '');
    }
}
