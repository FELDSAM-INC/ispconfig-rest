<?php

namespace Tests\Feature;

use Tests\TestCase;

class RootInfoTest extends TestCase
{
    public function test_root_returns_api_info(): void
    {
        $this->getJson('/')
            ->assertOk()
            ->assertJson([
                'name' => 'ISPConfig REST API',
                'documentation' => '/api/documentation',
            ]);
    }

    public function test_health_endpoint_is_up(): void
    {
        $this->get('/up')->assertOk();
    }
}
