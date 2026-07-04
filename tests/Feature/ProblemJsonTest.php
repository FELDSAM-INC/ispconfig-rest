<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProblemJsonTest extends TestCase
{
    public function test_unknown_api_route_returns_404_problem(): void
    {
        $this->getJson('/api/v1/definitely-not-a-route')
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonStructure(['type', 'title', 'status']);
    }

    public function test_wrong_method_returns_405_problem(): void
    {
        config(['api.dev_key' => 'test-dev-key']);

        $this->postJson('/api/v1/ping', [], ['X-API-Key' => 'test-dev-key'])
            ->assertStatus(405)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['status' => 405]);
    }
}
