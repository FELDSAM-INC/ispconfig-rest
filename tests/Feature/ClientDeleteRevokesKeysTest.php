<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ClientSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Spec 014 FR-010 / SC-005: deleting a client through the API deactivates
 * every API key bound to that client (CLI- or HTTP-minted, bound by user or
 * by group) in the same operation; other clients' and admin keys stay
 * active.
 */
class ClientDeleteRevokesKeysTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        ClientSchema::create();
        TenantSchema::create();
        $this->seedTenants();
    }

    public function test_deleting_a_client_deactivates_all_of_its_keys(): void
    {
        $clientA = $this->tenant('clientA');

        $httpKeyId = (int) $this->postJson('/api/v1/system/api-keys', [
            'name' => 'whmcs client A',
            'client_id' => $clientA['client_id'],
        ], $this->tenantHeaders('admin'))->assertStatus(201)->json('id');

        [$groupBoundKey] = ApiKey::mint('group bound', 9999, $clientA['groupid']);

        $this->deleteJson('/api/v1/clients/'.$clientA['client_id'], [], $this->tenantHeaders('admin'))
            ->assertNoContent();

        $this->assertFalse($this->active('client A key'));
        $this->assertFalse((bool) ApiKey::query()->findOrFail($httpKeyId)->active);
        $this->assertFalse((bool) $groupBoundKey->fresh()->active);

        $this->assertTrue($this->active('client B key'));
        $this->assertTrue($this->active('reseller key'));
        $this->assertTrue($this->active('admin key'));

        $this->assertSame(0, ApiKey::query()
            ->where('active', true)
            ->where(fn ($q) => $q->where('sys_userid', $clientA['userid'])->orWhere('sys_groupid', $clientA['groupid']))
            ->count());
    }

    protected function active(string $name): bool
    {
        return (bool) ApiKey::query()->where('name', $name)->value('active');
    }
}
