<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_key_returns_401_problem(): void
    {
        $this->getJson('/api/v1/ping')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Unauthorized', 'status' => 401]);
    }

    public function test_invalid_key_returns_401_problem(): void
    {
        $this->getJson('/api/v1/ping', ['X-API-Key' => 'nonsense'])
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_dev_key_authenticates_in_testing_env(): void
    {
        config(['api.dev_key' => 'test-dev-key']);

        $this->getJson('/api/v1/ping', ['X-API-Key' => 'test-dev-key'])
            ->assertOk()
            ->assertJson(['data' => ['pong' => true]]);
    }

    public function test_database_backed_key_authenticates(): void
    {
        [, $plaintext] = ApiKey::mint('test suite', 1, 1);

        $this->getJson('/api/v1/ping', ['X-API-Key' => $plaintext])
            ->assertOk()
            ->assertJson(['data' => ['pong' => true]]);
    }

    public function test_revoked_key_is_rejected(): void
    {
        [$key, $plaintext] = ApiKey::mint('revoked key');
        $key->update(['active' => false]);

        $this->getJson('/api/v1/ping', ['X-API-Key' => $plaintext])
            ->assertStatus(401);
    }

    public function test_key_use_updates_last_used_at(): void
    {
        [$key, $plaintext] = ApiKey::mint('tracked key');
        $this->assertNull($key->last_used_at);

        $this->getJson('/api/v1/ping', ['X-API-Key' => $plaintext])->assertOk();

        $this->assertNotNull($key->fresh()->last_used_at);
    }
}
