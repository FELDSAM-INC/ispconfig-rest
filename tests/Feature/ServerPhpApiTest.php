<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ServerSchema;
use Tests\TestCase;

class ServerPhpApiTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'test-dev-key';

    protected function setUp(): void
    {
        parent::setUp();

        ServerSchema::create();

        config(['api.dev_key' => self::KEY]);

        DB::table('sys_user')->insert([
            'userid' => 1, 'username' => 'apiadmin', 'typ' => 'admin', 'default_group' => 1,
        ]);

        // 1: non-mirrored web server; 2: mail-only; 3: mirrored web server.
        DB::table('server')->insert([
            ['server_id' => 1, 'server_name' => 'web01', 'mail_server' => 0, 'web_server' => 1, 'mirror_server_id' => 0, 'active' => 1],
            ['server_id' => 2, 'server_name' => 'mail01', 'mail_server' => 1, 'web_server' => 0, 'mirror_server_id' => 0, 'active' => 1],
            ['server_id' => 3, 'server_name' => 'web02', 'mail_server' => 0, 'web_server' => 1, 'mirror_server_id' => 1, 'active' => 1],
        ]);
    }

    protected function authHeaders(): array
    {
        return ['X-API-Key' => self::KEY];
    }

    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'PHP 8.2',
            'php_cli_binary' => '/usr/bin/php8.2',
            'php_jk_section' => 'php82',
        ], $overrides);
    }

    protected function seedPhp(array $overrides = []): int
    {
        return (int) DB::table('server_php')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'client_id' => 0,
            'name' => 'PHP 8.1',
            'php_cli_binary' => '/usr/bin/php8.1',
            'php_jk_section' => 'php81',
            'active' => 'y',
            'sortprio' => 100,
        ], $overrides), 'server_php_id');
    }

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/servers/1/php-versions')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_missing_parent_server_returns_404(): void
    {
        $this->getJson('/api/v1/servers/999/php-versions', $this->authHeaders())
            ->assertStatus(404);
    }

    public function test_child_of_another_server_returns_404(): void
    {
        $foreign = $this->seedPhp(['server_id' => 3]);

        $this->getJson('/api/v1/servers/1/php-versions/'.$foreign, $this->authHeaders())
            ->assertStatus(404);
        $this->deleteJson('/api/v1/servers/1/php-versions/'.$foreign, [], $this->authHeaders())
            ->assertStatus(404);
    }

    public function test_list_is_scoped_sorted_by_sortprio(): void
    {
        $this->seedPhp(['name' => 'PHP 8.3', 'sortprio' => 200]);
        $this->seedPhp(['name' => 'PHP 8.1', 'sortprio' => 100]);
        $this->seedPhp(['server_id' => 3, 'name' => 'PHP 7.4']);

        $this->getJson('/api/v1/servers/1/php-versions', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.name', 'PHP 8.1')
            ->assertJsonPath('data.1.name', 'PHP 8.3');

        $this->getJson('/api/v1/servers/1/php-versions?sort=evil', $this->authHeaders())
            ->assertStatus(400);
    }

    public function test_show_returns_contract_shape(): void
    {
        $id = $this->seedPhp();

        $this->getJson('/api/v1/servers/1/php-versions/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('server_id', 1)
            ->assertJsonPath('name', 'PHP 8.1')
            ->assertJsonPath('php_cli_binary', '/usr/bin/php8.1')
            ->assertJsonPath('php_jk_section', 'php81')
            ->assertJsonPath('active', true)
            ->assertJsonPath('sortprio', 100)
            ->assertJsonMissingPath('server_php_id');
    }

    public function test_create_applies_legacy_defaults_and_datalogs(): void
    {
        $response = $this->postJson('/api/v1/servers/1/php-versions', $this->validPayload([
            'php_fpm_init_script' => 'php8.2-fpm',
        ]), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('server_id', 1)
            ->assertJsonPath('name', 'PHP 8.2')
            ->assertJsonPath('php_fpm_init_script', 'php8.2-fpm')
            // legacy defaults
            ->assertJsonPath('active', true)
            ->assertJsonPath('sortprio', 100)
            ->assertJsonPath('client_id', 0)
            ->assertJsonPath('sys_perm_user', 'riud');

        $id = $response->json('id');

        $row = DB::table('sys_datalog')->where('dbtable', 'server_php')->first();
        $this->assertNotNull($row);
        $this->assertSame('i', $row->action);
        $this->assertSame('server_php_id:'.$id, $row->dbidx);
        $this->assertSame(1, (int) $row->server_id);

        $data = unserialize($row->data);
        $this->assertSame(['new', 'old'], array_keys($data));
        $this->assertSame('/usr/bin/php8.2', $data['new']['php_cli_binary']);
        $this->assertSame('y', $data['new']['active']);
    }

    public function test_create_strips_tags_and_newlines_from_name(): void
    {
        $this->postJson('/api/v1/servers/1/php-versions', $this->validPayload([
            'name' => "<b>PHP</b> 8.2\n",
        ]), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('name', 'PHP 8.2');
    }

    public function test_create_on_non_web_or_mirrored_server_returns_422(): void
    {
        // mail-only server
        $this->postJson('/api/v1/servers/2/php-versions', $this->validPayload(), $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['server_id']]);

        // mirrored web server
        $this->postJson('/api/v1/servers/3/php-versions', $this->validPayload(), $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['server_id']]);

        $this->assertSame(0, DB::table('server_php')->count());
    }

    public function test_create_validation_failures_return_422(): void
    {
        $cases = [
            'empty name' => [$this->validPayload(['name' => '']), 'name'],
            'relative cli binary' => [$this->validPayload(['php_cli_binary' => 'php8.2']), 'php_cli_binary'],
            'cli binary with semicolon' => [$this->validPayload(['php_cli_binary' => '/usr/bin/php8.2;rm']), 'php_cli_binary'],
            'missing cli binary' => [array_diff_key($this->validPayload(), ['php_cli_binary' => 1]), 'php_cli_binary'],
            'bad jk section' => [$this->validPayload(['php_jk_section' => 'php 8.2!']), 'php_jk_section'],
            'missing jk section' => [array_diff_key($this->validPayload(), ['php_jk_section' => 1]), 'php_jk_section'],
        ];

        foreach ($cases as $label => [$payload, $errorField]) {
            $response = $this->postJson('/api/v1/servers/1/php-versions', $payload, $this->authHeaders());
            $response->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json');
            $this->assertArrayHasKey($errorField, $response->json('errors'), "case: {$label}");
        }

        $this->assertSame(0, DB::table('server_php')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_update_returns_200_datalogs_and_keeps_server_immutable(): void
    {
        $id = $this->seedPhp();

        $this->putJson('/api/v1/servers/1/php-versions/'.$id, [
            'sortprio' => 50,
            'active' => 'n',
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('sortprio', 50)
            ->assertJsonPath('active', false);

        $row = DB::table('sys_datalog')->where('dbtable', 'server_php')->first();
        $this->assertSame('u', $row->action);
        $data = unserialize($row->data);
        $this->assertSame('100', $data['old']['sortprio']);
        $this->assertSame('50', $data['new']['sortprio']);

        $response = $this->putJson('/api/v1/servers/1/php-versions/'.$id, [
            'server_id' => 3,
        ], $this->authHeaders());
        $response->assertStatus(422);
        $this->assertArrayHasKey('server_id', $response->json('errors'));
    }

    public function test_delete_returns_204_and_datalogs(): void
    {
        $id = $this->seedPhp();

        $this->deleteJson('/api/v1/servers/1/php-versions/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('server_php', ['server_php_id' => $id]);
        $this->assertSame(1, DB::table('sys_datalog')->where('dbtable', 'server_php')->where('action', 'd')->count());
    }
}
