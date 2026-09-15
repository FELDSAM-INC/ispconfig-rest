<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\WebBackupApiTestCase;

/**
 * Backups of a locked client (spec 018 FR-017, owner-delegated decision
 * 2026-09-15; interaction with spec 019): client and reseller keys may not
 * start, restore or delete backups or change backup settings while the
 * website's client is locked — 403 problem+json, no sys_remoteaction row and
 * no datalog. Reading backups and jobs and preparing a download stay
 * allowed; admin keys are not restricted.
 */
class WebBackupLockedClientTest extends WebBackupApiTestCase
{
    protected const DETAIL = 'The account is locked; its backups cannot be started, restored, deleted or reconfigured.';

    protected function lock(string $tenant): void
    {
        DB::table('client')->where('client_id', $this->tenant($tenant)['client_id'])->update(['locked' => 'y']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function assertRefused(string $method, string $uri, array $payload, string $tenant): void
    {
        $actions = DB::table('sys_remoteaction')->count();
        $datalog = DB::table('sys_datalog')->count();

        $this->json($method, $uri, $payload, $this->tenantHeaders($tenant))
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('detail', self::DETAIL)
            ->assertJsonPath('type', 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#account-locked');

        $this->assertSame($actions, DB::table('sys_remoteaction')->count(), "{$method} {$uri} must not queue a remote action");
        $this->assertSame($datalog, DB::table('sys_datalog')->count(), "{$method} {$uri} must not write datalog");
    }

    public function test_client_key_cannot_change_backups_of_its_locked_account(): void
    {
        $website = $this->website('clientA');
        $backup = $this->backup($website);
        $this->lock('clientA');

        $this->assertRefused('POST', $this->url($website, '/backups'), ['type' => 'web'], 'clientA');
        $this->assertRefused('POST', $this->url($website, "/backups/{$backup}/restore"), [], 'clientA');
        $this->assertRefused('DELETE', $this->url($website, "/backups/{$backup}"), [], 'clientA');
        $this->assertRefused('PUT', $this->url($website, '/backup-settings'), ['backup_interval' => 'daily'], 'clientA');

        $this->assertSame('none', DB::table('web_domain')->where('domain_id', $website)->value('backup_interval'));
    }

    public function test_reseller_key_is_refused_for_its_locked_client(): void
    {
        $website = $this->website('clientA');
        $backup = $this->backup($website);
        $this->lock('clientA');

        $this->assertRefused('POST', $this->url($website, '/backups'), ['type' => 'web'], 'reseller');
        $this->assertRefused('POST', $this->url($website, "/backups/{$backup}/restore"), [], 'reseller');
        $this->assertRefused('DELETE', $this->url($website, "/backups/{$backup}"), [], 'reseller');
        $this->assertRefused('PUT', $this->url($website, '/backup-settings'), ['backup_interval' => 'daily'], 'reseller');
    }

    public function test_reading_backups_and_preparing_a_download_stay_allowed(): void
    {
        $website = $this->website('clientA');
        $backup = $this->backup($website);
        $this->lock('clientA');

        $this->getJson($this->url($website, '/backups'), $this->tenantHeaders('clientA'))->assertOk();
        $this->getJson($this->url($website, "/backups/{$backup}"), $this->tenantHeaders('clientA'))->assertOk();
        $this->getJson($this->url($website, '/backup-jobs'), $this->tenantHeaders('clientA'))->assertOk();
        $this->getJson($this->url($website, '/backup-settings'), $this->tenantHeaders('clientA'))->assertOk();

        $this->postJson($this->url($website, "/backups/{$backup}/download"), [], $this->tenantHeaders('clientA'))
            ->assertStatus(201);

        $this->assertRemoteActionRows([
            ['server_id' => self::BACKUP_SERVER, 'action_type' => 'backup_download', 'action_param' => (string) $backup],
        ]);
    }

    public function test_admin_key_is_not_restricted(): void
    {
        $website = $this->website('clientA');
        $this->lock('clientA');

        $this->postJson($this->url($website, '/backups'), ['type' => 'web'], $this->tenantHeaders('admin'))
            ->assertStatus(201);

        $this->putJson($this->url($website, '/backup-settings'), ['backup_interval' => 'daily'], $this->tenantHeaders('admin'))
            ->assertOk();
    }

    public function test_other_accounts_are_not_affected_by_a_lock(): void
    {
        $website = $this->website('clientA');
        $this->lock('clientB');

        $this->postJson($this->url($website, '/backups'), ['type' => 'web'], $this->tenantHeaders('clientA'))
            ->assertStatus(201);
    }
}
