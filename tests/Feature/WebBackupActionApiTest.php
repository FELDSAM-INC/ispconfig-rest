<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\WebBackupApiTestCase;

/**
 * Backup actions queued as sys_remoteaction rows (spec 018 R1-R3, R8, R9):
 * exact legacy rows, no datalog, pending de-duplication, permissions and
 * backup availability per target server.
 */
class WebBackupActionApiTest extends WebBackupApiTestCase
{
    // ------------------------------------------------------------------
    // Restore (US1)
    // ------------------------------------------------------------------

    public function test_restore_queues_backup_restore_action_and_returns_pending_job(): void
    {
        $website = $this->website('clientA');
        $backup = $this->backup($website);

        $response = $this->postJson($this->url($website, "/backups/{$backup}/restore"), [], $this->tenantHeaders('clientA'))
            ->assertStatus(201)
            ->assertJsonPath('action', 'restore')
            ->assertJsonPath('backup_type', 'web')
            ->assertJsonPath('backup_id', $backup)
            ->assertJsonPath('server_id', self::BACKUP_SERVER)
            ->assertJsonPath('state', 'pending')
            ->assertJsonPath('download', null)
            ->assertHeaderMissing('X-Change-Set-Id');

        $this->assertIsInt($response->json('id'));
        $this->assertRemoteActionRows([
            ['server_id' => self::BACKUP_SERVER, 'action_type' => 'backup_restore', 'action_param' => (string) $backup],
        ]);
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_restore_targets_the_website_server_when_the_backup_has_no_server(): void
    {
        $website = $this->website('clientA');
        $backup = $this->backup($website, ['server_id' => 0]);

        $this->postJson($this->url($website, "/backups/{$backup}/restore"), [], $this->tenantHeaders('clientA'))
            ->assertStatus(201)
            ->assertJsonPath('server_id', self::BACKUP_SERVER);

        $this->assertRemoteActionRows([
            ['server_id' => self::BACKUP_SERVER, 'action_type' => 'backup_restore', 'action_param' => (string) $backup],
        ]);
    }

    public function test_restore_while_the_same_restore_is_pending_returns_409(): void
    {
        $website = $this->website('clientA');
        $backup = $this->backup($website);
        $this->remoteAction(['action_type' => 'backup_restore', 'action_param' => (string) $backup]);

        $this->postJson($this->url($website, "/backups/{$backup}/restore"), [], $this->tenantHeaders('clientA'))
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->assertSame(1, DB::table('sys_remoteaction')->count());
    }

    public function test_restore_is_allowed_again_once_the_previous_restore_finished(): void
    {
        $website = $this->website('clientA');
        $backup = $this->backup($website);
        $this->remoteAction(['action_type' => 'backup_restore', 'action_param' => (string) $backup, 'action_state' => 'ok']);

        $this->postJson($this->url($website, "/backups/{$backup}/restore"), [], $this->tenantHeaders('clientA'))
            ->assertStatus(201);

        $this->assertSame(2, DB::table('sys_remoteaction')->count());
    }

    public function test_restore_requires_update_permission_on_the_website(): void
    {
        $reseller = $this->tenant('reseller');
        $website = $this->website('clientA', ['sys_userid' => $reseller['userid'], 'sys_perm_group' => 'r']);
        $backup = $this->backup($website);

        $this->getJson($this->url($website, '/backups'), $this->tenantHeaders('clientA'))->assertOk();

        $this->postJson($this->url($website, "/backups/{$backup}/restore"), [], $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->assertSame(0, DB::table('sys_remoteaction')->count());
    }

    public function test_restore_of_another_websites_backup_returns_404(): void
    {
        $first = $this->website('clientA');
        $second = $this->website('clientA');
        $backup = $this->backup($second);

        $this->postJson($this->url($first, "/backups/{$backup}/restore"), [], $this->tenantHeaders('clientA'))
            ->assertStatus(404);

        $this->assertSame(0, DB::table('sys_remoteaction')->count());
    }

    public function test_restore_on_a_server_without_backup_dir_returns_409(): void
    {
        $website = $this->website('clientA', ['server_id' => self::PLAIN_SERVER]);
        $backup = $this->backup($website, ['server_id' => self::PLAIN_SERVER]);

        $this->postJson($this->url($website, "/backups/{$backup}/restore"), [], $this->tenantHeaders('clientA'))
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->assertSame(0, DB::table('sys_remoteaction')->count());
    }

    // ------------------------------------------------------------------
    // On-demand backup (US2)
    // ------------------------------------------------------------------

    public function test_web_backup_queues_one_backup_web_files_action_on_the_website_server(): void
    {
        $website = $this->website('clientA');

        $this->postJson($this->url($website, '/backups'), ['type' => 'web'], $this->tenantHeaders('clientA'))
            ->assertStatus(201)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'backup')
            ->assertJsonPath('data.0.backup_type', 'web')
            ->assertJsonPath('data.0.backup_id', null)
            ->assertJsonPath('data.0.server_id', self::BACKUP_SERVER)
            ->assertJsonPath('data.0.state', 'pending')
            ->assertHeaderMissing('X-Change-Set-Id');

        $this->assertRemoteActionRows([
            ['server_id' => self::BACKUP_SERVER, 'action_type' => 'backup_web_files', 'action_param' => (string) $website],
        ]);
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_database_backup_queues_one_action_per_database_server(): void
    {
        DB::table('server')->where('server_id', self::PLAIN_SERVER)->update(['config' => "[server]\nbackup_dir=/var/backup"]);

        $website = $this->website('clientA');
        $this->database('clientA', $website, self::PLAIN_SERVER, 'c1two');
        $this->database('clientA', $website, self::BACKUP_SERVER, 'c1one');
        $this->database('clientA', $website, self::BACKUP_SERVER, 'c1three');

        $this->postJson($this->url($website, '/backups'), ['type' => 'mysql'], $this->tenantHeaders('clientA'))
            ->assertStatus(201)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.backup_type', 'mysql');

        $this->assertRemoteActionRows([
            ['server_id' => self::BACKUP_SERVER, 'action_type' => 'backup_database', 'action_param' => (string) $website],
            ['server_id' => self::PLAIN_SERVER, 'action_type' => 'backup_database', 'action_param' => (string) $website],
        ]);
    }

    public function test_database_backup_without_databases_returns_422(): void
    {
        $website = $this->website('clientA');

        $this->postJson($this->url($website, '/backups'), ['type' => 'mysql'], $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);

        $this->assertSame(0, DB::table('sys_remoteaction')->count());
    }

    public function test_database_backup_with_a_database_on_a_server_without_backup_dir_returns_409(): void
    {
        $website = $this->website('clientA');
        $this->database('clientA', $website, self::PLAIN_SERVER, 'c1two');

        $this->postJson($this->url($website, '/backups'), ['type' => 'mysql'], $this->tenantHeaders('clientA'))
            ->assertStatus(409);

        $this->assertSame(0, DB::table('sys_remoteaction')->count());
    }

    public function test_backup_while_the_same_type_is_pending_returns_409(): void
    {
        $website = $this->website('clientA');
        $this->remoteAction(['action_type' => 'backup_web_files', 'action_param' => (string) $website]);

        $this->postJson($this->url($website, '/backups'), ['type' => 'web'], $this->tenantHeaders('clientA'))
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->assertSame(1, DB::table('sys_remoteaction')->count());
    }

    public function test_backup_requires_update_permission(): void
    {
        $reseller = $this->tenant('reseller');
        $website = $this->website('clientA', ['sys_userid' => $reseller['userid'], 'sys_perm_group' => 'r']);

        $this->postJson($this->url($website, '/backups'), ['type' => 'web'], $this->tenantHeaders('clientA'))
            ->assertStatus(403);

        $this->assertSame(0, DB::table('sys_remoteaction')->count());
    }

    public function test_backup_type_is_validated(): void
    {
        $website = $this->website('clientA');
        $headers = $this->tenantHeaders('clientA');

        $this->postJson($this->url($website, '/backups'), [], $headers)->assertStatus(422)->assertJsonValidationErrors(['type']);
        $this->postJson($this->url($website, '/backups'), ['type' => 'mongodb'], $headers)->assertStatus(422)->assertJsonValidationErrors(['type']);

        $this->assertSame(0, DB::table('sys_remoteaction')->count());
    }

    // ------------------------------------------------------------------
    // Delete (US2)
    // ------------------------------------------------------------------

    public function test_delete_queues_backup_delete_action_and_returns_204(): void
    {
        $website = $this->website('clientA');
        $backup = $this->backup($website);

        $this->deleteJson($this->url($website, "/backups/{$backup}"), [], $this->tenantHeaders('clientA'))
            ->assertNoContent()
            ->assertHeaderMissing('X-Change-Set-Id');

        $this->assertRemoteActionRows([
            ['server_id' => self::BACKUP_SERVER, 'action_type' => 'backup_delete', 'action_param' => (string) $backup],
        ]);
        $this->assertSame(1, DB::table('web_backup')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_delete_while_the_same_delete_is_pending_returns_409(): void
    {
        $website = $this->website('clientA');
        $backup = $this->backup($website);
        $this->remoteAction(['action_type' => 'backup_delete', 'action_param' => (string) $backup]);

        $this->deleteJson($this->url($website, "/backups/{$backup}"), [], $this->tenantHeaders('clientA'))
            ->assertStatus(409);

        $this->assertSame(1, DB::table('sys_remoteaction')->count());
    }

    public function test_delete_requires_update_permission(): void
    {
        $reseller = $this->tenant('reseller');
        $website = $this->website('clientA', ['sys_userid' => $reseller['userid'], 'sys_perm_group' => 'r']);
        $backup = $this->backup($website);

        $this->deleteJson($this->url($website, "/backups/{$backup}"), [], $this->tenantHeaders('clientA'))
            ->assertStatus(403);

        $this->assertSame(0, DB::table('sys_remoteaction')->count());
    }

    public function test_delete_of_another_websites_backup_returns_404(): void
    {
        $first = $this->website('clientA');
        $second = $this->website('clientA');
        $backup = $this->backup($second);

        $this->deleteJson($this->url($first, "/backups/{$backup}"), [], $this->tenantHeaders('clientA'))
            ->assertStatus(404);

        $this->assertSame(0, DB::table('sys_remoteaction')->count());
    }

    // ------------------------------------------------------------------
    // Download (US3)
    // ------------------------------------------------------------------

    public function test_download_queues_backup_download_action_and_returns_pending_job(): void
    {
        $website = $this->website('clientA');
        $backup = $this->backup($website);

        $this->postJson($this->url($website, "/backups/{$backup}/download"), [], $this->tenantHeaders('clientA'))
            ->assertStatus(201)
            ->assertJsonPath('action', 'download')
            ->assertJsonPath('backup_type', 'web')
            ->assertJsonPath('backup_id', $backup)
            ->assertJsonPath('server_id', self::BACKUP_SERVER)
            ->assertJsonPath('state', 'pending')
            ->assertJsonPath('download', null)
            ->assertHeaderMissing('X-Change-Set-Id');

        $this->assertRemoteActionRows([
            ['server_id' => self::BACKUP_SERVER, 'action_type' => 'backup_download', 'action_param' => (string) $backup],
        ]);
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_download_needs_only_read_permission(): void
    {
        $reseller = $this->tenant('reseller');
        $website = $this->website('clientA', ['sys_userid' => $reseller['userid'], 'sys_perm_group' => 'r']);
        $backup = $this->backup($website);

        $this->postJson($this->url($website, "/backups/{$backup}/download"), [], $this->tenantHeaders('clientA'))
            ->assertStatus(201);

        $this->assertRemoteActionRows([
            ['server_id' => self::BACKUP_SERVER, 'action_type' => 'backup_download', 'action_param' => (string) $backup],
        ]);
    }

    public function test_download_while_the_same_download_is_pending_returns_409(): void
    {
        $website = $this->website('clientA');
        $backup = $this->backup($website);
        $this->remoteAction(['action_type' => 'backup_download', 'action_param' => (string) $backup]);

        $this->postJson($this->url($website, "/backups/{$backup}/download"), [], $this->tenantHeaders('clientA'))
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->assertSame(1, DB::table('sys_remoteaction')->count());
    }

    public function test_download_of_a_backup_stored_on_another_server_returns_422(): void
    {
        DB::table('server')->where('server_id', self::PLAIN_SERVER)
            ->update(['config' => "[server]\nip_address=10.0.0.2\nbackup_dir=/var/backup\nbackup_mode=rootgz"]);
        $website = $this->website('clientA');
        $this->database('clientA', $website, self::PLAIN_SERVER, 'c1shop');
        $backup = $this->backup($website, [
            'server_id' => self::PLAIN_SERVER,
            'backup_type' => 'mysql',
            'backup_format' => 'gzip',
            'filename' => 'db_c1shop_2025-09-14_00-00.sql.gz',
        ]);

        $this->getJson($this->url($website, "/backups/{$backup}"), $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('download_available', false);

        $this->postJson($this->url($website, "/backups/{$backup}/download"), [], $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('backup_id');

        $this->assertSame(0, DB::table('sys_remoteaction')->count());
    }

    public function test_download_on_a_server_without_backup_dir_returns_409(): void
    {
        $website = $this->website('clientA', ['server_id' => self::PLAIN_SERVER]);
        $backup = $this->backup($website, ['server_id' => self::PLAIN_SERVER]);

        $this->postJson($this->url($website, "/backups/{$backup}/download"), [], $this->tenantHeaders('clientA'))
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->assertSame(0, DB::table('sys_remoteaction')->count());
    }

    public function test_download_of_another_websites_backup_returns_404(): void
    {
        $first = $this->website('clientA');
        $second = $this->website('clientA');
        $backup = $this->backup($second);

        $this->postJson($this->url($first, "/backups/{$backup}/download"), [], $this->tenantHeaders('clientA'))
            ->assertStatus(404);

        $this->assertSame(0, DB::table('sys_remoteaction')->count());
    }
}
