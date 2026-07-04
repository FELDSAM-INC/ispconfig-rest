<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MailSchema;
use Tests\Support\SitesSchema;
use Tests\TestCase;

class StrictListParamsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MailSchema::create();
        SitesSchema::create();
        config(['api.dev_key' => 'test-dev-key']);
    }

    private function get_(string $uri)
    {
        return $this->getJson($uri, ['X-API-Key' => 'test-dev-key']);
    }

    public function test_unknown_filter_param_returns_400_problem(): void
    {
        // A misspelled filter must never silently return the unfiltered
        // collection (a consumer deleting "the match" would hit the wrong row).
        $this->get_('/api/v1/sites/web-domains?domain=example.com')
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 400);
    }

    public function test_declared_extra_param_still_accepted(): void
    {
        $this->get_('/api/v1/sites/web-domains?search=example')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']]);
    }

    public function test_shared_params_still_accepted(): void
    {
        $this->get_('/api/v1/mail/domains?limit=5&offset=0&sort=domain&order=asc')
            ->assertOk();
    }

    public function test_unknown_param_on_mail_domains_returns_400(): void
    {
        $this->get_('/api/v1/mail/domains?domian=typo.com')
            ->assertStatus(400)
            ->assertJsonPath('status', 400);
    }
}
