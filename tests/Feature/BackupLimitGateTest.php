<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\WebBackupApiTestCase;

/**
 * The backup gate (spec 018 R8): website binding 404 first, then vhost-only
 * 404, then limit_backup = 'y' for client and reseller keys (403). Admin keys
 * are exempt.
 */
class BackupLimitGateTest extends WebBackupApiTestCase
{
    public function test_client_key_with_limit_backup_enabled_is_allowed(): void
    {
        $website = $this->website('clientA');

        $this->getJson($this->url($website, '/backups'), $this->tenantHeaders('clientA'))->assertOk();
        $this->getJson($this->url($website, '/backup-jobs'), $this->tenantHeaders('clientA'))->assertOk();
    }

    public function test_client_key_with_limit_backup_disabled_gets_403(): void
    {
        $website = $this->website('clientA');
        $backup = $this->backup($website);
        $this->setLimitBackup('clientA', 'n');

        $headers = $this->tenantHeaders('clientA');

        $this->getJson($this->url($website, '/backups'), $headers)
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#feature-not-allowed')
            ->assertJsonPath('feature', 'limit_backup');
        $this->getJson($this->url($website, '/backup-jobs'), $headers)->assertStatus(403);
        $this->postJson($this->url($website, "/backups/{$backup}/restore"), [], $headers)->assertStatus(403);

        $this->assertSame(0, DB::table('sys_remoteaction')->count());
    }

    public function test_reseller_key_follows_its_own_client_row(): void
    {
        $website = $this->website('clientA');
        $this->setLimitBackup('clientA', 'n');

        $this->getJson($this->url($website, '/backups'), $this->tenantHeaders('reseller'))->assertOk();

        $this->setLimitBackup('reseller', 'n');

        $this->getJson($this->url($website, '/backups'), $this->tenantHeaders('reseller'))->assertStatus(403);
    }

    public function test_key_without_a_client_row_gets_403(): void
    {
        $website = $this->website('clientA');
        DB::table('client')->where('client_id', $this->tenant('clientA')['client_id'])->delete();

        $this->getJson($this->url($website, '/backups'), $this->tenantHeaders('clientA'))->assertStatus(403);
    }

    public function test_admin_key_is_exempt(): void
    {
        $website = $this->website('clientA');
        $this->setLimitBackup('clientA', 'n');

        $this->getJson($this->url($website, '/backups'), $this->tenantHeaders('admin'))->assertOk();
    }

    public function test_unreadable_website_returns_404_before_the_gate(): void
    {
        $website = $this->website('clientB');
        $this->setLimitBackup('clientA', 'n');

        $this->getJson($this->url($website, '/backups'), $this->tenantHeaders('clientA'))
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
        $this->getJson($this->url(999999, '/backups'), $this->tenantHeaders('clientA'))->assertStatus(404);
    }

    public function test_non_vhost_websites_have_no_backups(): void
    {
        $parent = $this->website('clientA');
        $subdomain = $this->website('clientA', ['type' => 'vhostsubdomain', 'parent_domain_id' => $parent]);

        $this->getJson($this->url($subdomain, '/backups'), $this->tenantHeaders('admin'))
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_missing_api_key_returns_401(): void
    {
        $website = $this->website('clientA');

        $this->getJson($this->url($website, '/backup-jobs'))->assertStatus(401);
    }

    // ------------------------------------------------------------------
    // Existing endpoints carrying backup_* fields (US2, research R12)
    // ------------------------------------------------------------------

    public function test_web_domain_writes_with_backup_fields_are_refused_when_backups_are_disabled(): void
    {
        $website = $this->website('clientA');
        $this->setLimitBackup('clientA', 'n');
        $headers = $this->tenantHeaders('clientA');

        $this->putJson("/api/v1/sites/web-domains/{$website}", ['backup_copies' => 7], $headers)
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('detail', 'Backups are not enabled for this account.')
            ->assertJsonPath('type', 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#feature-not-allowed')
            ->assertJsonPath('feature', 'limit_backup');
        $this->postJson('/api/v1/sites/web-domains', ['domain' => 'new.example.test', 'backup_interval' => 'daily'], $headers)
            ->assertStatus(403);

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_database_writes_with_backup_fields_are_refused_when_backups_are_disabled(): void
    {
        $website = $this->website('clientA');
        $database = $this->database('clientA', $website, self::BACKUP_SERVER, 'c1db');
        $this->setLimitBackup('clientA', 'n');
        $headers = $this->tenantHeaders('clientA');

        $this->putJson("/api/v1/sites/databases/{$database}", ['backup_interval' => 'daily', 'database_user_id' => 1], $headers)
            ->assertStatus(403);
        $this->postJson('/api/v1/sites/databases', ['parent_domain_id' => $website, 'database_name' => 'c1new', 'database_user_id' => 1, 'backup_copies' => 3], $headers)
            ->assertStatus(403);

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_requests_without_backup_fields_are_not_gated(): void
    {
        $website = $this->website('clientA');
        $this->setLimitBackup('clientA', 'n');

        $this->putJson("/api/v1/sites/web-domains/{$website}", ['vhost_type' => 'bogus'], $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vhost_type']);
    }

    public function test_admin_key_may_send_backup_fields_when_the_client_has_backups_disabled(): void
    {
        $website = $this->website('clientA');
        $this->setLimitBackup('clientA', 'n');

        $this->putJson("/api/v1/sites/web-domains/{$website}", ['backup_copies' => 11], $this->tenantHeaders('admin'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['backup_copies']);
    }

    public function test_client_key_backup_copies_outside_legacy_values_returns_422(): void
    {
        $website = $this->website('clientA');

        foreach ([11, 25] as $copies) {
            $this->putJson("/api/v1/sites/web-domains/{$website}", ['backup_copies' => $copies], $this->tenantHeaders('clientA'))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['backup_copies']);
        }
    }
}
