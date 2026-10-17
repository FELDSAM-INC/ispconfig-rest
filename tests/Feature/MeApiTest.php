<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        config(['panel.url' => null, 'panel.vhosts' => []]);

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
                'access' => 'full',
                'zones' => [],
                'panel_url' => null,
                'services' => ['web' => false, 'mail' => false, 'db' => false, 'dns' => false],
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
                'access' => 'full',
                'zones' => [],
                'panel_url' => null,
                'services' => ['web' => false, 'mail' => false, 'db' => false, 'dns' => false],
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
                'access' => 'full',
                'zones' => [],
                'panel_url' => null,
                'services' => ['web' => false, 'mail' => false, 'db' => false, 'dns' => false],
            ]);
    }

    public function test_revoked_and_missing_keys_are_rejected(): void
    {
        ApiKey::query()->whereKey($this->keyId('client A key'))->update(['active' => false]);

        $this->getJson('/api/v1/me', $this->tenantHeaders('clientA'))->assertStatus(401);
        $this->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_services_follow_the_assigned_servers_of_the_account(): void
    {
        DB::table('server')->insert([
            ['server_id' => 11, 'server_name' => 'web1', 'web_server' => 1, 'mail_server' => 0, 'dns_server' => 0, 'mirror_server_id' => 0],
            ['server_id' => 12, 'server_name' => 'mail1', 'web_server' => 0, 'mail_server' => 1, 'dns_server' => 0, 'mirror_server_id' => 0],
            ['server_id' => 13, 'server_name' => 'ns1-mirror', 'web_server' => 0, 'mail_server' => 0, 'dns_server' => 1, 'mirror_server_id' => 14],
        ]);
        // No mail server for the account; its DNS server is a mirror, which is not usable on its own.
        DB::table('client')->where('client_id', $this->tenant('clientA')['client_id'])
            ->update(['web_servers' => '11', 'mail_servers' => '', 'dns_servers' => '13']);

        $this->getJson('/api/v1/me', $this->tenantHeaders('clientA'))->assertOk()
            ->assertJsonPath('services', ['web' => true, 'mail' => false, 'db' => false, 'dns' => false]);
        $this->getJson('/api/v1/me', $this->tenantHeaders('admin'))->assertOk()
            ->assertJsonPath('services', ['web' => true, 'mail' => true, 'db' => false, 'dns' => false]);
    }

    public function test_public_panel_url_is_visible_to_scoped_keys_without_credentials(): void
    {
        config(['panel.url' => 'https://panel.example.test:9443/']);
        $this->getJson('/api/v1/me', $this->tenantHeaders('clientA'))
            ->assertOk()->assertJsonPath('panel_url', 'https://panel.example.test:9443/');
    }
}
