<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MailCompletionSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * /system/api-keys (spec 014): admin-only key management over HTTP.
 * Minting admin, client and reseller keys (plaintext shown once, identity
 * resolved like api:key:create --client-id), validation, the admin module
 * gate, and no sys_datalog writes (api_keys is API-owned).
 */
class ApiKeyManagementApiTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        MailCompletionSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        DB::table('server')->insert([
            'server_id' => 1, 'server_name' => 'mail1', 'mail_server' => 1, 'mirror_server_id' => 0, 'active' => 1,
        ]);
    }

    public function test_admin_key_creates_a_client_bound_key_and_returns_the_plaintext_once(): void
    {
        $clientA = $this->tenant('clientA');

        $response = $this->postJson('/api/v1/system/api-keys', [
            'name' => 'whmcs service 17',
            'client_id' => $clientA['client_id'],
        ], $this->tenantHeaders('admin'))
            ->assertStatus(201)
            ->assertJsonStructure(['id', 'name', 'scope', 'client_id', 'active', 'created_at', 'last_used_at', 'key'])
            ->assertJson([
                'name' => 'whmcs service 17',
                'scope' => 'client',
                'client_id' => $clientA['client_id'],
                'active' => true,
                'last_used_at' => null,
            ])
            ->assertJsonMissingPath('key_hash');

        $plaintext = (string) $response->json('key');
        $this->assertStringStartsWith('isp_', $plaintext);

        $stored = ApiKey::query()->findOrFail($response->json('id'));
        $this->assertSame(hash('sha256', $plaintext), $stored->key_hash);
        $this->assertSame($clientA['userid'], (int) $stored->sys_userid);
        $this->assertSame($clientA['groupid'], (int) $stored->sys_groupid);
    }

    public function test_minted_client_key_acts_with_the_clients_scope(): void
    {
        DB::table('mail_domain')->insert($this->ownedBy('clientA', ['server_id' => 1, 'domain' => 'a-dom.test', 'active' => 'y']));
        DB::table('mail_domain')->insert($this->ownedBy('clientB', ['server_id' => 1, 'domain' => 'b-dom.test', 'active' => 'y']));

        $plaintext = (string) $this->postJson('/api/v1/system/api-keys', [
            'name' => 'whmcs service 17',
            'client_id' => $this->tenant('clientA')['client_id'],
        ], $this->tenantHeaders('admin'))->assertStatus(201)->json('key');

        $this->getJson('/api/v1/mail/domains', ['X-API-Key' => $plaintext])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.domain', 'a-dom.test');

        $this->getJson('/api/v1/servers', ['X-API-Key' => $plaintext])
            ->assertStatus(403);
    }

    public function test_key_without_client_id_is_an_admin_key(): void
    {
        $response = $this->postJson('/api/v1/system/api-keys', ['name' => 'ops automation'], $this->tenantHeaders('admin'))
            ->assertStatus(201)
            ->assertJson(['scope' => 'admin', 'client_id' => null]);

        $stored = ApiKey::query()->findOrFail($response->json('id'));
        $this->assertSame(1, (int) $stored->sys_userid);
        $this->assertSame(1, (int) $stored->sys_groupid);

        $this->getJson('/api/v1/servers', ['X-API-Key' => (string) $response->json('key')])->assertOk();
    }

    public function test_reseller_client_id_yields_a_reseller_key(): void
    {
        $reseller = $this->tenant('reseller');

        $this->postJson('/api/v1/system/api-keys', [
            'name' => 'reseller automation',
            'client_id' => $reseller['client_id'],
        ], $this->tenantHeaders('admin'))
            ->assertStatus(201)
            ->assertJson(['scope' => 'reseller', 'client_id' => $reseller['client_id']]);
    }

    public function test_create_validation_failures_return_422_and_store_nothing(): void
    {
        $orphanClient = (int) DB::table('client')->insertGetId(['username' => 'orphan'], 'client_id');
        DB::table('sys_group')->insert(['name' => 'orphan', 'client_id' => $orphanClient]);

        $cases = [
            'missing name' => [[], 'name'],
            'empty name' => [['name' => ''], 'name'],
            'name too long' => [['name' => str_repeat('x', 256)], 'name'],
            'client_id zero' => [['name' => 'x', 'client_id' => 0], 'client_id'],
            'client_id negative' => [['name' => 'x', 'client_id' => -5], 'client_id'],
            'client_id string' => [['name' => 'x', 'client_id' => 'abc'], 'client_id'],
            'unknown client' => [['name' => 'x', 'client_id' => 99999], 'client_id'],
            'client without control-panel user' => [['name' => 'x', 'client_id' => $orphanClient], 'client_id'],
            'key supplied' => [['name' => 'x', 'key' => 'isp_mine'], 'key'],
            'key_hash supplied' => [['name' => 'x', 'key_hash' => str_repeat('a', 64)], 'key_hash'],
            'sys_userid supplied' => [['name' => 'x', 'sys_userid' => 1], 'sys_userid'],
            'sys_groupid supplied' => [['name' => 'x', 'sys_groupid' => 1], 'sys_groupid'],
            'id supplied' => [['name' => 'x', 'id' => 500], 'id'],
            'scope supplied' => [['name' => 'x', 'scope' => 'admin'], 'scope'],
        ];

        $before = ApiKey::query()->count();

        foreach ($cases as $label => [$body, $field]) {
            $this->postJson('/api/v1/system/api-keys', $body, $this->tenantHeaders('admin'))
                ->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonValidationErrors([$field], 'errors', $label);
        }

        $this->assertSame($before, ApiKey::query()->count());
    }

    public function test_client_and_reseller_keys_cannot_create_keys(): void
    {
        $before = ApiKey::query()->count();

        foreach (['clientA', 'reseller'] as $identity) {
            $this->postJson('/api/v1/system/api-keys', ['name' => 'self service'], $this->tenantHeaders($identity))
                ->assertStatus(403)
                ->assertHeader('Content-Type', 'application/problem+json');
        }

        $this->assertSame($before, ApiKey::query()->count());
    }

    public function test_creating_a_key_writes_no_datalog_entry(): void
    {
        $this->postJson('/api/v1/system/api-keys', [
            'name' => 'whmcs service 17',
            'client_id' => $this->tenant('clientA')['client_id'],
        ], $this->tenantHeaders('admin'))->assertStatus(201);

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }
}
