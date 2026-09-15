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
}
