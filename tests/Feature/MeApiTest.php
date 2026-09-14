<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * GET /me (spec 014 US3): every valid key — admin, reseller, client and the
 * local development key — can read its own identity and scope; the endpoint
 * is not admin-gated and never returns secrets.
 */
class MeApiTest extends TestCase
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

    public function test_admin_key_reports_admin_identity(): void
    {
        $this->getJson('/api/v1/me', $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertExactJson([
                'key_id' => $this->keyId('admin key'),
                'name' => 'admin key',
                'scope' => 'admin',
                'client_id' => null,
                'sys_userid' => 1,
                'sys_groupid' => 1,
            ]);
    }

    public function test_client_key_reports_its_client_identity(): void
    {
        $clientA = $this->tenant('clientA');

        $this->getJson('/api/v1/me', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertExactJson([
                'key_id' => $this->keyId('client A key'),
                'name' => 'client A key',
                'scope' => 'client',
                'client_id' => $clientA['client_id'],
                'sys_userid' => $clientA['userid'],
                'sys_groupid' => $clientA['groupid'],
            ]);
    }

    public function test_reseller_key_reports_reseller_scope(): void
    {
        $reseller = $this->tenant('reseller');

        $this->getJson('/api/v1/me', $this->tenantHeaders('reseller'))
            ->assertOk()
            ->assertJson([
                'key_id' => $this->keyId('reseller key'),
                'scope' => 'reseller',
                'client_id' => $reseller['client_id'],
                'sys_userid' => $reseller['userid'],
                'sys_groupid' => $reseller['groupid'],
            ]);
    }

    public function test_development_key_reports_a_null_key_id(): void
    {
        config(['api.dev_key' => 'test-dev-key']);

        $this->getJson('/api/v1/me', ['X-API-Key' => 'test-dev-key'])
            ->assertOk()
            ->assertExactJson([
                'key_id' => null,
                'name' => 'development key',
                'scope' => 'admin',
                'client_id' => null,
                'sys_userid' => 1,
                'sys_groupid' => 1,
            ]);
    }

    public function test_revoked_and_missing_keys_are_rejected(): void
    {
        ApiKey::query()->whereKey($this->keyId('client A key'))->update(['active' => false]);

        $this->getJson('/api/v1/me', $this->tenantHeaders('clientA'))->assertStatus(401);
        $this->getJson('/api/v1/me')->assertStatus(401);
    }
}
