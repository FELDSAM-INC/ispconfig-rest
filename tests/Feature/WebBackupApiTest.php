<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\WebBackupApiTestCase;

/**
 * GET /sites/web-domains/{id}/backups and /backups/{backup_id} (spec 018 US1):
 * newest-first lists, filters, visibility by the website's and its databases'
 * servers (R6), derived fields ported from plugin_backuplist.inc.php (R7).
 */
class WebBackupApiTest extends WebBackupApiTestCase
{
    private function iso(int $tstamp): string
    {
        return CarbonImmutable::createFromTimestamp($tstamp, config('app.timezone'))->toIso8601String();
    }

    public function test_endpoints_require_api_key(): void
    {
        $website = $this->website('clientA');

        $this->getJson($this->url($website, '/backups'))->assertStatus(401);
    }

    public function test_list_returns_backups_newest_first_with_meta(): void
    {
        $website = $this->website('clientA');
        $old = $this->backup($website, ['tstamp' => self::BACKUP_TSTAMP]);
        $new = $this->backup($website, ['tstamp' => self::BACKUP_TSTAMP + 100, 'filename' => 'web_new.tar.gz']);
        $mid = $this->backup($website, ['tstamp' => self::BACKUP_TSTAMP + 50, 'filename' => 'web_mid.tar.gz']);

        $this->getJson($this->url($website, '/backups'), $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.limit', 25)
            ->assertJsonPath('meta.offset', 0)
            ->assertJsonPath('data.0.id', $new)
            ->assertJsonPath('data.1.id', $mid)
            ->assertJsonPath('data.2.id', $old);
    }

    public function test_show_returns_backup_representation(): void
    {
        $website = $this->website('clientA');
        $id = $this->backup($website);

        $this->getJson($this->url($website, "/backups/{$id}"), $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertExactJson([
                'id' => $id,
                'server_id' => self::BACKUP_SERVER,
                'parent_domain_id' => $website,
                'backup_type' => 'web',
                'database_name' => null,
                'backup_mode' => 'rootgz',
                'backup_format' => 'tar_gzip',
                'filename' => 'web_2025-09-14_00-00.tar.gz',
                'filesize' => 1048576,
                'filesize_approximate' => false,
                'created_at' => $this->iso(self::BACKUP_TSTAMP),
                'job' => 'auto',
                'encrypted' => false,
                'download_available' => true,
            ]);
    }

    public function test_filters_by_type_and_job(): void
    {
        $website = $this->website('clientA');
        $this->backup($website);
        $auto = $this->backup($website, ['backup_type' => 'mysql', 'backup_format' => 'gzip', 'filename' => 'db_c1shop_2025-09-14_00-00.sql.gz']);
        $manual = $this->backup($website, ['backup_type' => 'mysql', 'backup_format' => 'gzip', 'filename' => 'manual-db_c1shop_2025-09-14_01-00.sql.gz']);

        $headers = $this->tenantHeaders('clientA');

        $this->getJson($this->url($website, '/backups?type=mysql'), $headers)->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson($this->url($website, '/backups?job=manual'), $headers)->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $manual);
        $this->getJson($this->url($website, '/backups?type=mysql&job=auto'), $headers)->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $auto);
    }

    public function test_sort_by_id_ascending(): void
    {
        $website = $this->website('clientA');
        $first = $this->backup($website, ['tstamp' => self::BACKUP_TSTAMP + 10]);
        $second = $this->backup($website, ['tstamp' => self::BACKUP_TSTAMP]);

        $this->getJson($this->url($website, '/backups?sort=id&order=asc'), $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $first)
            ->assertJsonPath('data.1.id', $second);
    }

    public function test_invalid_list_parameters_return_400(): void
    {
        $website = $this->website('clientA');
        $headers = $this->tenantHeaders('clientA');

        foreach (['type=zip', 'job=nightly', 'foo=1', 'sort=tstamp'] as $query) {
            $this->getJson($this->url($website, "/backups?{$query}"), $headers)
                ->assertStatus(400)
                ->assertHeader('Content-Type', 'application/problem+json');
        }
    }

    public function test_backups_on_database_servers_are_listed_only_when_a_database_lives_there(): void
    {
        $website = $this->website('clientA');
        $elsewhere = $this->backup($website, ['server_id' => self::PLAIN_SERVER, 'backup_type' => 'mysql', 'backup_format' => 'gzip', 'filename' => 'db_c1shop_2025-09-14_00-00.sql.gz']);

        $headers = $this->tenantHeaders('clientA');
        $this->getJson($this->url($website, '/backups'), $headers)->assertOk()->assertJsonPath('meta.total', 0);

        $this->database('clientA', $website, self::PLAIN_SERVER, 'c1shop');

        $this->getJson($this->url($website, '/backups'), $headers)->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $elsewhere)
            ->assertJsonPath('data.0.download_available', false)
            ->assertJsonPath('data.0.database_name', 'c1shop');
    }

    public function test_backups_of_other_websites_are_not_visible(): void
    {
        $first = $this->website('clientA');
        $second = $this->website('clientA');
        $backup = $this->backup($first);

        $headers = $this->tenantHeaders('clientA');

        $this->getJson($this->url($second, '/backups'), $headers)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($this->url($second, "/backups/{$backup}"), $headers)
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
        $this->getJson($this->url($first, '/backups/999999'), $headers)->assertStatus(404);
    }

    public function test_client_cannot_read_backups_of_another_clients_website(): void
    {
        $website = $this->website('clientB');
        $this->backup($website);

        $this->getJson($this->url($website, '/backups'), $this->tenantHeaders('clientA'))->assertStatus(404);
    }

    public function test_derived_fields_for_borg_web_backup(): void
    {
        $website = $this->website('clientA', ['backup_format_web' => 'tar_xz', 'backup_encrypt' => 'y', 'backup_password' => ' secret ']);
        $id = $this->backup($website, ['backup_mode' => 'borg', 'backup_format' => '', 'filename' => 'web_2025-09-14_00-00', 'filesize' => '']);

        $this->getJson($this->url($website, "/backups/{$id}"), $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('backup_format', 'tar_xz')
            ->assertJsonPath('filename', 'web_2025-09-14_00-00.tar.xz')
            ->assertJsonPath('filesize', null)
            ->assertJsonPath('filesize_approximate', true)
            ->assertJsonPath('encrypted', true);
    }

    public function test_derived_fields_for_borg_database_backup_with_default_format(): void
    {
        $website = $this->website('clientA', ['backup_format_db' => 'default', 'backup_encrypt' => 'n', 'backup_password' => 'unused']);
        $id = $this->backup($website, [
            'backup_mode' => 'borg',
            'backup_type' => 'mysql',
            'backup_format' => '',
            'filename' => 'manual-db_c1shop_2025-09-14_00-00',
            'backup_password' => 'row-password',
        ]);

        $this->getJson($this->url($website, "/backups/{$id}"), $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('backup_format', 'gzip')
            ->assertJsonPath('filename', 'manual-db_c1shop_2025-09-14_00-00.sql.gz')
            ->assertJsonPath('database_name', 'c1shop')
            ->assertJsonPath('job', 'manual')
            ->assertJsonPath('encrypted', false);
    }

    public function test_formats_of_old_backups_without_a_stored_format(): void
    {
        $website = $this->website('clientA');
        $zip = $this->backup($website, ['backup_mode' => 'userzip', 'backup_format' => '']);
        $tar = $this->backup($website, ['backup_mode' => 'rootgz', 'backup_format' => '']);
        $sql = $this->backup($website, ['backup_type' => 'mysql', 'backup_format' => '', 'filename' => 'db_old_2020-01-01_00-00.sql.gz']);

        $headers = $this->tenantHeaders('clientA');

        $this->getJson($this->url($website, "/backups/{$zip}"), $headers)->assertJsonPath('backup_format', 'zip');
        $this->getJson($this->url($website, "/backups/{$tar}"), $headers)->assertJsonPath('backup_format', 'tar_gzip');
        $this->getJson($this->url($website, "/backups/{$sql}"), $headers)->assertJsonPath('backup_format', 'gzip');
    }

    public function test_row_password_marks_non_borg_backup_encrypted_and_is_never_returned(): void
    {
        $website = $this->website('clientA');
        $id = $this->backup($website, ['backup_password' => 'secret']);

        $response = $this->getJson($this->url($website, "/backups/{$id}"), $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('encrypted', true);

        $this->assertArrayNotHasKey('backup_password', $response->json());
        $this->assertStringNotContainsString('secret', $response->getContent());
    }

    public function test_created_at_uses_the_api_timezone(): void
    {
        config(['app.timezone' => 'Europe/Prague']);

        $website = $this->website('clientA');
        $id = $this->backup($website);

        $this->getJson($this->url($website, "/backups/{$id}"), $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('created_at', CarbonImmutable::createFromTimestamp(self::BACKUP_TSTAMP, 'Europe/Prague')->toIso8601String());

        $this->assertStringEndsWith('+02:00', CarbonImmutable::createFromTimestamp(self::BACKUP_TSTAMP, 'Europe/Prague')->toIso8601String());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }
}
