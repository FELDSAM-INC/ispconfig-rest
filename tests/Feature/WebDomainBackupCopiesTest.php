<?php

namespace Tests\Feature;

use Tests\Support\SitesApiTestCase;

/**
 * FR-016 (owner decision 2026-09-14): POST/PUT /sites/web-domains accept only
 * the legacy backup_copies options 1-10, 15, 20, 30 — for admin keys too.
 * The client-key cases live in BackupLimitGateTest.
 */
class WebDomainBackupCopiesTest extends SitesApiTestCase
{
    public function test_create_accepts_only_legacy_backup_copies(): void
    {
        foreach ([11, 25] as $copies) {
            $this->postJson('/api/v1/sites/web-domains', [
                'server_id' => 1,
                'domain' => "copies{$copies}.example.com",
                'sys_groupid' => 5,
                'backup_copies' => $copies,
            ], $this->authHeaders())
                ->assertStatus(422)
                ->assertJsonValidationErrors(['backup_copies']);
        }

        $this->postJson('/api/v1/sites/web-domains', [
            'server_id' => 1,
            'domain' => 'thirty.example.com',
            'sys_groupid' => 5,
            'backup_copies' => 30,
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('backup_copies', 30);
    }

    public function test_update_accepts_only_legacy_backup_copies(): void
    {
        $id = $this->seedVhost();

        foreach ([11, 25] as $copies) {
            $this->putJson("/api/v1/sites/web-domains/{$id}", ['backup_copies' => $copies], $this->authHeaders())
                ->assertStatus(422)
                ->assertJsonValidationErrors(['backup_copies']);
        }

        $this->putJson("/api/v1/sites/web-domains/{$id}", ['backup_copies' => 15], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('backup_copies', 15);
    }
}
