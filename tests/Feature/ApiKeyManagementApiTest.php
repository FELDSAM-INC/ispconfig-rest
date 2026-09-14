<?php

namespace Tests\Feature;

use App\Http\Requests\StoreApiKeyRequest;
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

    // ------------------------------------------------------------------
    // US2: list, show, update, delete
    // ------------------------------------------------------------------

    protected function keyId(string $name): int
    {
        return (int) ApiKey::query()->where('name', $name)->value('id');
    }

    public function test_list_returns_the_envelope_with_scopes_and_no_secrets(): void
    {
        $response = $this->getJson('/api/v1/system/api-keys', $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'scope', 'client_id', 'active', 'created_at', 'last_used_at']], 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 4);

        $byName = collect($response->json('data'))->keyBy('name');
        $this->assertSame('admin', $byName['admin key']['scope']);
        $this->assertNull($byName['admin key']['client_id']);
        $this->assertSame('reseller', $byName['reseller key']['scope']);
        $this->assertSame($this->tenant('reseller')['client_id'], $byName['reseller key']['client_id']);
        $this->assertSame('client', $byName['client A key']['scope']);
        $this->assertSame($this->tenant('clientA')['client_id'], $byName['client A key']['client_id']);

        foreach ($response->json('data') as $item) {
            $this->assertArrayNotHasKey('key', $item);
            $this->assertArrayNotHasKey('key_hash', $item);
        }
    }

    public function test_list_paginates_and_sorts(): void
    {
        $this->getJson('/api/v1/system/api-keys?limit=2&offset=1&sort=name&order=desc', $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.limit', 2)
            ->assertJsonPath('meta.offset', 1)
            ->assertJsonPath('data.0.name', 'client B key')
            ->assertJsonPath('data.1.name', 'client A key');
    }

    public function test_list_rejects_unknown_parameters_and_invalid_values(): void
    {
        foreach (['?foo=1', '?sort=key_hash', '?client_id=abc', '?client_id=0', '?active=maybe'] as $query) {
            $this->getJson('/api/v1/system/api-keys'.$query, $this->tenantHeaders('admin'))
                ->assertStatus(400)
                ->assertHeader('Content-Type', 'application/problem+json');
        }
    }

    public function test_list_filters_by_client_active_and_name(): void
    {
        $headers = $this->tenantHeaders('admin');

        $this->getJson('/api/v1/system/api-keys?client_id='.$this->tenant('clientA')['client_id'], $headers)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'client A key');
        $this->getJson('/api/v1/system/api-keys?client_id='.$this->tenant('reseller')['client_id'], $headers)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'reseller key');
        $this->getJson('/api/v1/system/api-keys?client_id=99999', $headers)
            ->assertOk()->assertJsonPath('meta.total', 0);

        $this->getJson('/api/v1/system/api-keys?active=false', $headers)->assertOk()->assertJsonPath('meta.total', 0);
        ApiKey::query()->whereKey($this->keyId('client B key'))->update(['active' => false]);
        $this->getJson('/api/v1/system/api-keys?active=false', $headers)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'client B key');
        $this->getJson('/api/v1/system/api-keys?active=true', $headers)->assertOk()->assertJsonPath('meta.total', 3);

        $this->getJson('/api/v1/system/api-keys?name=client*', $headers)->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/system/api-keys?name=client%20A%20key', $headers)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/system/api-keys?name=client', $headers)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_show_returns_metadata_and_reports_unbound_keys(): void
    {
        $this->getJson('/api/v1/system/api-keys/'.$this->keyId('client A key'), $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertJson(['name' => 'client A key', 'scope' => 'client', 'client_id' => $this->tenant('clientA')['client_id'], 'active' => true])
            ->assertJsonMissingPath('key')
            ->assertJsonMissingPath('key_hash');

        DB::table('sys_user')->where('userid', $this->tenant('clientB')['userid'])->delete();

        $this->getJson('/api/v1/system/api-keys/'.$this->keyId('client B key'), $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertJson(['scope' => 'unbound', 'client_id' => null]);
    }

    public function test_update_renames_revokes_and_reactivates_a_key(): void
    {
        $id = $this->keyId('client A key');
        $headers = $this->tenantHeaders('admin');
        $clientKey = $this->tenantHeaders('clientA');

        $this->putJson('/api/v1/system/api-keys/'.$id, ['name' => 'renamed'], $headers)
            ->assertOk()
            ->assertJson(['id' => $id, 'name' => 'renamed', 'active' => true])
            ->assertJsonMissingPath('key')
            ->assertJsonMissingPath('key_hash');

        $this->putJson('/api/v1/system/api-keys/'.$id, ['active' => false], $headers)
            ->assertOk()
            ->assertJson(['active' => false]);

        $this->getJson('/api/v1/ping', $clientKey)
            ->assertStatus(401)
            ->assertJson(['title' => 'Unauthorized', 'detail' => 'The provided API key is invalid or has been revoked.']);

        $this->putJson('/api/v1/system/api-keys/'.$id, ['active' => true], $headers)
            ->assertOk()
            ->assertJson(['active' => true]);

        $this->getJson('/api/v1/ping', $clientKey)->assertOk();
    }

    public function test_update_keeps_the_binding_immutable_and_rejects_prohibited_fields(): void
    {
        $id = $this->keyId('client A key');
        $headers = $this->tenantHeaders('admin');

        $this->putJson('/api/v1/system/api-keys/'.$id, ['client_id' => $this->tenant('clientB')['client_id']], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_id']);

        $this->putJson('/api/v1/system/api-keys/'.$id, ['client_id' => $this->tenant('clientA')['client_id']], $headers)
            ->assertOk();

        $this->putJson('/api/v1/system/api-keys/'.$this->keyId('client B key'), ['client_id' => null], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_id']);

        $this->putJson('/api/v1/system/api-keys/'.$this->keyId('reseller key'), ['client_id' => null, 'name' => 'x'], $headers)
            ->assertStatus(422);

        foreach (StoreApiKeyRequest::PROHIBITED as $field) {
            $this->putJson('/api/v1/system/api-keys/'.$id, [$field => 1], $headers)
                ->assertStatus(422)
                ->assertJsonValidationErrors([$field]);
        }

        $this->putJson('/api/v1/system/api-keys/'.$id, ['name' => ''], $headers)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_the_calling_key_cannot_revoke_or_delete_itself(): void
    {
        $adminId = $this->keyId('admin key');
        $headers = $this->tenantHeaders('admin');

        $this->putJson('/api/v1/system/api-keys/'.$adminId, ['active' => false], $headers)
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->deleteJson('/api/v1/system/api-keys/'.$adminId, [], $headers)
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->putJson('/api/v1/system/api-keys/'.$adminId, ['name' => 'renamed admin', 'active' => true], $headers)
            ->assertOk()
            ->assertJson(['name' => 'renamed admin', 'active' => true]);

        $this->assertTrue((bool) ApiKey::query()->findOrFail($adminId)->active);
    }

    public function test_delete_removes_the_key(): void
    {
        $id = $this->keyId('client A key');

        $this->deleteJson('/api/v1/system/api-keys/'.$id, [], $this->tenantHeaders('admin'))
            ->assertNoContent();

        $this->getJson('/api/v1/ping', $this->tenantHeaders('clientA'))->assertStatus(401);
        $this->getJson('/api/v1/system/api-keys/'.$id, $this->tenantHeaders('admin'))->assertStatus(404);
    }

    public function test_unknown_key_returns_404(): void
    {
        $headers = $this->tenantHeaders('admin');

        $this->getJson('/api/v1/system/api-keys/9999', $headers)->assertStatus(404)->assertHeader('Content-Type', 'application/problem+json');
        $this->putJson('/api/v1/system/api-keys/9999', ['name' => 'x'], $headers)->assertStatus(404);
        $this->deleteJson('/api/v1/system/api-keys/9999', [], $headers)->assertStatus(404);
    }

    public function test_client_and_reseller_keys_cannot_manage_keys(): void
    {
        $id = $this->keyId('client A key');

        foreach (['clientA', 'reseller'] as $identity) {
            $headers = $this->tenantHeaders($identity);

            $this->getJson('/api/v1/system/api-keys', $headers)->assertStatus(403);
            $this->getJson('/api/v1/system/api-keys/'.$id, $headers)->assertStatus(403);
            $this->putJson('/api/v1/system/api-keys/'.$id, ['name' => 'x'], $headers)->assertStatus(403);
            $this->deleteJson('/api/v1/system/api-keys/'.$id, [], $headers)->assertStatus(403);
        }
    }
}
