<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Tests\Support\WebBackupApiTestCase;

/**
 * GET /sites/web-domains/{id}/backup-jobs (spec 018 US1): attribution of
 * sys_remoteaction rows to a website (R5) and the state mapping (R4).
 */
class WebBackupJobApiTest extends WebBackupApiTestCase
{
    public function test_jobs_are_attributed_to_the_website(): void
    {
        $website = $this->website('clientA');
        $other = $this->website('clientA');
        $backup = $this->backup($website);
        $otherBackup = $this->backup($other);

        $files = $this->remoteAction(['action_type' => 'backup_web_files', 'action_param' => (string) $website]);
        $database = $this->remoteAction(['action_type' => 'backup_database', 'action_param' => (string) $website]);
        $restore = $this->remoteAction(['action_type' => 'backup_restore', 'action_param' => (string) $backup]);
        $this->remoteAction(['action_type' => 'backup_download', 'action_param' => (string) $otherBackup]);
        $this->remoteAction(['action_type' => 'backup_web_files', 'action_param' => (string) $other]);
        $this->remoteAction(['action_type' => 'os_update', 'action_param' => '']);

        $response = $this->getJson($this->url($website, '/backup-jobs?sort=id&order=asc'), $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $this->assertSame([$files, $database, $restore], array_column($response->json('data'), 'id'));
    }

    public function test_job_representation(): void
    {
        $website = $this->website('clientA');
        $backup = $this->backup($website, ['backup_type' => 'mysql', 'backup_format' => 'gzip', 'filename' => 'db_c1shop_2025-09-14_00-00.sql.gz']);
        $database = $this->remoteAction(['action_type' => 'backup_database', 'action_param' => (string) $website, 'action_state' => 'ok']);
        $restore = $this->remoteAction(['action_type' => 'backup_restore', 'action_param' => (string) $backup]);

        $headers = $this->tenantHeaders('clientA');

        $this->getJson($this->url($website, "/backup-jobs/{$database}"), $headers)
            ->assertOk()
            ->assertExactJson([
                'id' => $database,
                'action' => 'backup',
                'backup_type' => 'mysql',
                'backup_id' => null,
                'server_id' => self::BACKUP_SERVER,
                'state' => 'ok',
                'created_at' => CarbonImmutable::createFromTimestamp(self::BACKUP_TSTAMP, config('app.timezone'))->toIso8601String(),
                'download' => null,
            ]);

        $this->getJson($this->url($website, "/backup-jobs/{$restore}"), $headers)
            ->assertOk()
            ->assertJsonPath('action', 'restore')
            ->assertJsonPath('backup_type', 'mysql')
            ->assertJsonPath('backup_id', $backup)
            ->assertJsonPath('state', 'pending');
    }

    public function test_state_mapping_exposes_unknown_states_as_error(): void
    {
        $website = $this->website('clientA');

        foreach (['pending', 'ok', 'warning', 'error', ''] as $state) {
            $this->remoteAction(['action_type' => 'backup_web_files', 'action_param' => (string) $website, 'action_state' => $state]);
        }

        $response = $this->getJson($this->url($website, '/backup-jobs?sort=id&order=asc'), $this->tenantHeaders('clientA'))
            ->assertOk();

        $this->assertSame(['pending', 'ok', 'warning', 'error', 'error'], array_column($response->json('data'), 'state'));
    }

    public function test_filters_by_state_and_action(): void
    {
        $website = $this->website('clientA');
        $backup = $this->backup($website);
        $this->remoteAction(['action_type' => 'backup_web_files', 'action_param' => (string) $website, 'action_state' => 'error']);
        $this->remoteAction(['action_type' => 'backup_database', 'action_param' => (string) $website, 'action_state' => '']);
        $this->remoteAction(['action_type' => 'backup_restore', 'action_param' => (string) $backup, 'action_state' => 'ok']);

        $headers = $this->tenantHeaders('clientA');

        $this->getJson($this->url($website, '/backup-jobs?state=error'), $headers)->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson($this->url($website, '/backup-jobs?action=backup'), $headers)->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson($this->url($website, '/backup-jobs?action=restore&state=ok'), $headers)->assertOk()->assertJsonPath('meta.total', 1);

        foreach (['state=stalled', 'action=rsync', 'foo=bar'] as $query) {
            $this->getJson($this->url($website, "/backup-jobs?{$query}"), $headers)->assertStatus(400);
        }
    }

    public function test_jobs_are_newest_first_by_default(): void
    {
        $website = $this->website('clientA');
        $older = $this->remoteAction(['action_type' => 'backup_web_files', 'action_param' => (string) $website, 'tstamp' => self::BACKUP_TSTAMP]);
        $newer = $this->remoteAction(['action_type' => 'backup_database', 'action_param' => (string) $website, 'tstamp' => self::BACKUP_TSTAMP + 60]);

        $this->getJson($this->url($website, '/backup-jobs'), $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer)
            ->assertJsonPath('data.1.id', $older);
    }

    public function test_jobs_of_other_websites_return_404(): void
    {
        $website = $this->website('clientA');
        $other = $this->website('clientA');
        $job = $this->remoteAction(['action_type' => 'backup_web_files', 'action_param' => (string) $other]);

        $this->getJson($this->url($website, "/backup-jobs/{$job}"), $this->tenantHeaders('clientA'))
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_client_cannot_list_jobs_of_another_clients_website(): void
    {
        $website = $this->website('clientB');
        $this->remoteAction(['action_type' => 'backup_web_files', 'action_param' => (string) $website]);

        $this->getJson($this->url($website, '/backup-jobs'), $this->tenantHeaders('clientA'))->assertStatus(404);
    }
}
