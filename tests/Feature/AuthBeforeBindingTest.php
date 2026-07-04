<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MailSchema;
use Tests\TestCase;

class AuthBeforeBindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MailSchema::create();
    }

    public function test_missing_resource_without_key_returns_401_not_404(): void
    {
        $this->getJson('/api/v1/mail/domains/999999')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json');
    }
}
