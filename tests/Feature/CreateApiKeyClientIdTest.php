<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * api:key:create --client-id (spec 011 FR-019, SC-008): resolves the
 * client's control-panel identity (sys_group.groupid by client_id,
 * sys_user.userid by default_group), fails cleanly on unknown clients and
 * rejects mixing with an explicit identity.
 */
class CreateApiKeyClientIdTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        TenantSchema::create();
        $this->seedTenants();
    }

    public function test_client_id_resolves_the_clients_control_panel_identity(): void
    {
        $clientA = $this->tenant('clientA');

        $this->artisan('api:key:create', [
            'name' => 'acme automation',
            '--client-id' => $clientA['client_id'],
        ])->assertExitCode(0);

        $key = ApiKey::query()->where('name', 'acme automation')->first();
        $this->assertNotNull($key);
        $this->assertSame($clientA['userid'], (int) $key->sys_userid);
        $this->assertSame($clientA['groupid'], (int) $key->sys_groupid);
    }

    public function test_unknown_client_id_aborts_with_a_clear_error(): void
    {
        $this->artisan('api:key:create', [
            'name' => 'ghost',
            '--client-id' => 99999,
        ])
            ->expectsOutputToContain('Client 99999 not found')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('api_keys', ['name' => 'ghost']);
    }

    public function test_client_without_control_panel_user_aborts(): void
    {
        // sys_group exists but its control-panel sys_user is gone.
        $orphanClient = (int) DB::table('client')->insertGetId(['username' => 'orphan'], 'client_id');
        DB::table('sys_group')->insert(['name' => 'orphan', 'client_id' => $orphanClient]);

        $this->artisan('api:key:create', [
            'name' => 'orphan key',
            '--client-id' => $orphanClient,
        ])
            ->expectsOutputToContain('has no control-panel user')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('api_keys', ['name' => 'orphan key']);
    }

    public function test_client_id_is_mutually_exclusive_with_explicit_identity(): void
    {
        $this->artisan('api:key:create', [
            'name' => 'conflicting',
            '--client-id' => $this->tenant('clientA')['client_id'],
            '--sys-userid' => 5,
        ])
            ->expectsOutputToContain('cannot be combined')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('api_keys', ['name' => 'conflicting']);
    }

    public function test_plain_identity_minting_still_works(): void
    {
        $this->artisan('api:key:create', [
            'name' => 'admin key cli',
        ])->assertExitCode(0);

        $key = ApiKey::query()->where('name', 'admin key cli')->first();
        $this->assertNotNull($key);
        $this->assertSame(1, (int) $key->sys_userid);
        $this->assertSame(1, (int) $key->sys_groupid);
    }
}
