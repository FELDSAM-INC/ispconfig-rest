<?php

namespace Tests\Feature;

use App\Http\Controllers\SwaggerController;
use Illuminate\Http\Request;
use Tests\TestCase;

class SwaggerSpecServerTest extends TestCase
{
    private function specFor(string $url): string
    {
        return (new SwaggerController)->getSpec(Request::create($url, 'GET'))->getContent();
    }

    public function test_server_url_is_the_request_origin(): void
    {
        $body = $this->specFor('https://panel.example.com:8090/api/spec');

        $this->assertStringContainsString('- url: https://panel.example.com:8090/api/v1', $body);
    }

    public function test_plain_host_without_port(): void
    {
        $body = $this->specFor('http://api.example.org/api/spec');

        $this->assertStringContainsString('- url: http://api.example.org/api/v1', $body);
    }

    public function test_old_hardcoded_values_are_gone(): void
    {
        $body = $this->specFor('https://real-host:8090/api/spec');

        $this->assertStringNotContainsString('{hostname}', $body);
        $this->assertStringNotContainsString('localhost:8000', $body);
    }

    public function test_rest_of_spec_is_preserved(): void
    {
        $body = $this->specFor('https://real-host:8090/api/spec');

        $this->assertStringContainsString('openapi: 3.0.0', $body);
        $this->assertStringContainsString('paths:', $body);
        $this->assertStringContainsString('apiKeyAuth', $body);
        // The comment block immediately after servers: must survive the rewrite.
        $this->assertStringContainsString('Default security applied to every operation', $body);
    }

    public function test_http_endpoint_serves_the_spec(): void
    {
        $this->get('/api/spec')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/yaml');
    }
}
