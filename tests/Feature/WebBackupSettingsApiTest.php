<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\WebBackupApiTestCase;

/**
 * GET/PUT /sites/web-domains/{id}/backup-settings (spec 018 US2, R10, R11):
 * legacy validation, one web_domain datalog update, write-only password.
 */
class WebBackupSettingsApiTest extends WebBackupApiTestCase
{
    public function test_read_settings_shape(): void
    {
        $website = $this->website('clientA', [
            'backup_interval' => 'weekly',
            'backup_copies' => 7,
            'backup_excludes' => 'web/cache',
            'backup_format_web' => 'tar_xz',
            'backup_format_db' => 'xz',
            'backup_encrypt' => 'y',
            'backup_password' => 'secret',
        ]);
        DB::table('monitor_data')->insert([
            'server_id' => self::BACKUP_SERVER,
            'type' => 'backup_utils',
            'created' => time(),
            'data' => serialize(['missing_utils' => ['pigz', 'rar']]),
            'state' => 'ok',
        ]);

        $response = $this->getJson($this->url($website, '/backup-settings'), $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertExactJson([
                'backup_interval' => 'weekly',
                'backup_copies' => 7,
                'backup_excludes' => 'web/cache',
                'backup_format_web' => 'tar_xz',
                'backup_format_db' => 'xz',
                'backup_encrypt' => true,
                'backup_password_set' => true,
                'backups_available' => true,
                'missing_utils' => ['pigz', 'rar'],
            ]);

        $this->assertStringNotContainsString('secret', $response->getContent());
    }

    public function test_settings_of_a_website_on_a_server_without_backups(): void
    {
        $website = $this->website('clientA', ['server_id' => self::PLAIN_SERVER]);

        $this->getJson($this->url($website, '/backup-settings'), $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('backups_available', false)
            ->assertJsonPath('missing_utils', null)
            ->assertJsonPath('backup_password_set', false);
    }

    public function test_update_writes_one_web_domain_datalog_entry_and_returns_settings(): void
    {
        $website = $this->website('clientA');

        $this->putJson($this->url($website, '/backup-settings'), [
            'backup_interval' => 'daily',
            'backup_copies' => 10,
            'backup_excludes' => 'web/tmp,private',
            'backup_format_web' => 'zip',
            'backup_format_db' => 'bzip2',
        ], $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertHeader('X-Change-Set-Id')
            ->assertJsonPath('backup_interval', 'daily')
            ->assertJsonPath('backup_copies', 10)
            ->assertJsonPath('backup_excludes', 'web/tmp,private')
            ->assertJsonPath('backup_format_web', 'zip')
            ->assertJsonPath('backup_format_db', 'bzip2');

        $this->assertSame(1, DB::table('sys_datalog')->where('dbtable', 'web_domain')->where('action', 'u')->count());
        $this->assertDatabaseHas('web_domain', [
            'domain_id' => $website,
            'backup_interval' => 'daily',
            'backup_copies' => 10,
            'backup_format_web' => 'zip',
            'backup_format_db' => 'bzip2',
        ]);
    }

    public function test_update_without_changes_writes_no_datalog(): void
    {
        $website = $this->website('clientA');

        $this->putJson($this->url($website, '/backup-settings'), ['backup_interval' => 'none'], $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertHeaderMissing('X-Change-Set-Id');

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_validation_matrix(): void
    {
        $website = $this->website('clientA');
        $headers = $this->tenantHeaders('clientA');

        $cases = [
            ['backup_interval' => 'hourly'],
            ['backup_copies' => 11],
            ['backup_copies' => 25],
            ['backup_excludes' => '../etc'],
            ['backup_excludes' => 'web/'.str_repeat('a', 252)],
            ['backup_format_web' => 'rar'],
            ['backup_format_db' => 'tar_gzip'],
            ['backup_encrypt' => 'maybe'],
        ];

        foreach ($cases as $payload) {
            $this->putJson($this->url($website, '/backup-settings'), $payload, $headers)
                ->assertStatus(422)
                ->assertJsonValidationErrors([array_key_first($payload)]);
        }

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_enabling_encryption_requires_a_password(): void
    {
        $website = $this->website('clientA');
        $headers = $this->tenantHeaders('clientA');

        $this->putJson($this->url($website, '/backup-settings'), ['backup_encrypt' => true], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['backup_password']);

        $response = $this->putJson($this->url($website, '/backup-settings'), ['backup_encrypt' => true, 'backup_password' => 'Str0ng-pass'], $headers)
            ->assertOk()
            ->assertJsonPath('backup_encrypt', true)
            ->assertJsonPath('backup_password_set', true);

        $this->assertArrayNotHasKey('backup_password', $response->json());
        $this->assertStringNotContainsString('Str0ng-pass', $response->getContent());
        $this->assertDatabaseHas('web_domain', ['domain_id' => $website, 'backup_encrypt' => 'y', 'backup_password' => 'Str0ng-pass']);
    }

    public function test_encryption_can_use_the_stored_password(): void
    {
        $website = $this->website('clientA', ['backup_password' => 'stored']);

        $this->putJson($this->url($website, '/backup-settings'), ['backup_encrypt' => 'y'], $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('backup_encrypt', true);

        $this->putJson($this->url($website, '/backup-settings'), ['backup_encrypt' => false], $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('backup_encrypt', false);

        $this->assertDatabaseHas('web_domain', ['domain_id' => $website, 'backup_encrypt' => 'n']);
    }

    public function test_update_requires_update_permission(): void
    {
        $reseller = $this->tenant('reseller');
        $website = $this->website('clientA', ['sys_userid' => $reseller['userid'], 'sys_perm_group' => 'r']);

        $this->getJson($this->url($website, '/backup-settings'), $this->tenantHeaders('clientA'))->assertOk();
        $this->putJson($this->url($website, '/backup-settings'), ['backup_interval' => 'daily'], $this->tenantHeaders('clientA'))
            ->assertStatus(403);

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_limit_backup_disabled_returns_403(): void
    {
        $website = $this->website('clientA');
        $this->setLimitBackup('clientA', 'n');

        $this->getJson($this->url($website, '/backup-settings'), $this->tenantHeaders('clientA'))->assertStatus(403);
        $this->putJson($this->url($website, '/backup-settings'), ['backup_interval' => 'daily'], $this->tenantHeaders('clientA'))->assertStatus(403);

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }
}
