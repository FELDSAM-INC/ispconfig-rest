<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\ServerSchema;
use Tests\TestCase;

class ServerToolsInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_is_read_only_and_contains_no_configuration_or_credentials(): void
    {
        ServerSchema::create();
        DB::table('server')->insert([
            ['server_id' => 1, 'server_name' => 'web.example.test', 'active' => 1, 'web_server' => 1, 'db_server' => 0, 'mirror_server_id' => 0, 'config' => 'private-password'],
            ['server_id' => 2, 'server_name' => 'db.example.test', 'active' => 1, 'web_server' => 0, 'db_server' => 1, 'mirror_server_id' => 0, 'config' => 'another-secret'],
            ['server_id' => 3, 'server_name' => 'mirror.example.test', 'active' => 0, 'web_server' => 1, 'db_server' => 0, 'mirror_server_id' => 1, 'config' => 'secret'],
        ]);
        $this->assertSame(0, Artisan::call('server-tools:inventory'));
        $output = Artisan::output();
        $this->assertSame(['servers' => [
            ['id' => 1, 'host' => 'web.example.test', 'active' => true, 'web' => true, 'database' => false, 'mirror_of' => 0],
            ['id' => 2, 'host' => 'db.example.test', 'active' => true, 'web' => false, 'database' => true, 'mirror_of' => 0],
            ['id' => 3, 'host' => 'mirror.example.test', 'active' => false, 'web' => true, 'database' => false, 'mirror_of' => 1],
        ]], json_decode($output, true, 8, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('secret', $output);
        $this->assertStringNotContainsString('password', $output);
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }
}
