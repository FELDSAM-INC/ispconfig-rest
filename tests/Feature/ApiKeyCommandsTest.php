<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * api:key:list and api:key:revoke (spec 014 US4): operator recovery path on
 * the ISPConfig host. Output never contains the plaintext key or its hash.
 */
class ApiKeyCommandsTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        TenantSchema::create();
        $this->seedTenants();
    }

    protected function keyId(string $name): int
    {
        return (int) ApiKey::query()->where('name', $name)->value('id');
    }

    public function test_list_prints_every_key_without_secrets(): void
    {
        $clientKey = $this->tenant('clientA')['key'];

        $this->artisan('api:key:list')
            ->expectsOutputToContain('admin key')
            ->expectsOutputToContain('client A key')
            ->expectsOutputToContain('reseller key')
            ->doesntExpectOutputToContain($clientKey)
            ->doesntExpectOutputToContain(hash('sha256', $clientKey))
            ->assertExitCode(0);
    }

    public function test_list_filters_by_client_id(): void
    {
        $this->artisan('api:key:list', ['--client-id' => $this->tenant('clientA')['client_id']])
            ->expectsOutputToContain('client A key')
            ->doesntExpectOutputToContain('client B key')
            ->assertExitCode(0);
    }

    public function test_revoke_deactivates_the_key(): void
    {
        $id = $this->keyId('client A key');

        $this->artisan('api:key:revoke', ['id' => $id])
            ->expectsOutputToContain('revoked')
            ->assertExitCode(0);

        $this->assertFalse((bool) ApiKey::query()->findOrFail($id)->active);
        $this->getJson('/api/v1/ping', $this->tenantHeaders('clientA'))->assertStatus(401);
    }

    public function test_revoking_an_unknown_key_fails(): void
    {
        $this->artisan('api:key:revoke', ['id' => 9999])
            ->expectsOutputToContain('not found')
            ->assertExitCode(1);
    }

    public function test_revoking_an_inactive_key_is_a_notice(): void
    {
        $id = $this->keyId('client B key');
        ApiKey::query()->whereKey($id)->update(['active' => false]);

        $this->artisan('api:key:revoke', ['id' => $id])
            ->expectsOutputToContain('already inactive')
            ->assertExitCode(0);
    }
}
