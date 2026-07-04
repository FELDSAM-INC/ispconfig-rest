<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Shared fixture for the client-module feature tests: the sqlite schema
 * (ClientSchema), the dev API key acting as the ISPConfig admin
 * (sys_userid/sys_groupid 1), the admin's sys_user/sys_group rows, and one
 * all-purpose server (ClientService resolves default server lists from it).
 */
abstract class ClientApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'test-dev-key';

    protected function setUp(): void
    {
        parent::setUp();

        ClientSchema::create();

        config(['api.dev_key' => self::KEY]);

        // The admin login + group (userid/groupid 1), like a real install;
        // datalog 'user' resolution reads sys_user.username.
        DB::table('sys_user')->insert([
            'userid' => 1,
            'username' => 'apiadmin',
            'typ' => 'admin',
            'default_group' => 1,
            'groups' => '1',
            'client_id' => 0,
        ]);

        DB::table('sys_group')->insert([
            'groupid' => 1,
            'name' => 'admin',
            'client_id' => 0,
        ]);

        DB::table('server')->insert([
            'server_id' => 1,
            'server_name' => 'server1',
            'mail_server' => 1,
            'web_server' => 1,
            'dns_server' => 1,
            'db_server' => 1,
            'mirror_server_id' => 0,
            'active' => 1,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function authHeaders(): array
    {
        return ['X-API-Key' => self::KEY];
    }

    /**
     * Insert a client row directly (sqlite defaults supply the rest).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function seedClient(array $overrides = []): int
    {
        return (int) DB::table('client')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'company_name' => 'Acme Inc.',
            'contact_name' => 'John Doe',
            'email' => 'john@acme.tld',
            'username' => 'jdoe',
            'password' => '$6$rounds=5000$0123456789abcdef$seeded',
        ], $overrides), 'client_id');
    }

    /**
     * Provision the client's sys_group + control-panel sys_user (what
     * ClientService::createClient does for API-created clients).
     *
     * @return array{groupId: int, userId: int}
     */
    protected function seedClientLogin(int $clientId, string $username): array
    {
        $groupId = (int) DB::table('sys_group')->insertGetId([
            'name' => $username,
            'client_id' => $clientId,
        ], 'groupid');

        $userId = (int) DB::table('sys_user')->insertGetId([
            'username' => $username,
            'passwort' => '$6$rounds=5000$0123456789abcdef$seeded',
            'typ' => 'user',
            'default_group' => $groupId,
            'groups' => (string) $groupId,
            'client_id' => $clientId,
        ], 'userid');

        return ['groupId' => $groupId, 'userId' => $userId];
    }

    /**
     * Insert a client_template row directly.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function seedTemplate(array $overrides = []): int
    {
        return (int) DB::table('client_template')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'template_name' => 'Basic',
            'template_type' => 'm',
        ], $overrides), 'template_id');
    }

    /**
     * Latest sys_datalog row for a table, with its unserialized payload.
     *
     * @return array{row: object, data: array<string, mixed>}
     */
    protected function datalogFor(string $table, ?string $action = null): array
    {
        $query = DB::table('sys_datalog')->where('dbtable', $table)->orderByDesc('datalog_id');

        if ($action !== null) {
            $query->where('action', $action);
        }

        $row = $query->first();
        $this->assertNotNull($row, "expected a sys_datalog row for {$table}".($action ? " action {$action}" : ''));

        return ['row' => $row, 'data' => unserialize($row->data)];
    }
}
