<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Shared fixture for the mail-module completion feature tests (spec 005):
 * full ISPConfig mail schema, dev API key acting as sys_userid 1, two
 * servers (1 = mail server with a config INI blob, 2 = web server) and
 * seed helpers for the parent resources.
 */
abstract class MailCompletionApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'test-dev-key';

    protected function setUp(): void
    {
        parent::setUp();

        MailCompletionSchema::create();

        config(['api.dev_key' => self::KEY]);

        DB::table('sys_user')->insert([
            'userid' => 1,
            'username' => 'apiadmin',
            'typ' => 'admin',
            'default_group' => 1,
        ]);

        DB::table('server')->insert([
            [
                'server_id' => 1,
                'server_name' => 'mail1',
                'mail_server' => 1,
                'mirror_server_id' => 0,
                'active' => 1,
                'config' => $this->serverConfigIni(),
            ],
            [
                'server_id' => 2,
                'server_name' => 'web1',
                'mail_server' => 0,
                'mirror_server_id' => 0,
                'active' => 1,
                'config' => '',
            ],
        ]);
    }

    /**
     * A realistic server.config blob: the two exposed sections plus an
     * untouched [web] section (read-merge-write must preserve it).
     */
    protected function serverConfigIni(): string
    {
        return implode("\n", [
            '[server]',
            'ip_address=192.168.0.105',
            'netmask=255.255.255.0',
            'gateway=192.168.0.1',
            'hostname=mail1.example.com',
            'nameservers=192.168.0.1,192.168.0.2',
            'firewall=ufw',
            '',
            '[mail]',
            'module=postfix_mysql',
            'maildir_path=/var/vmail/[domain]/[localpart]',
            'homedir_path=/var/vmail',
            'maildir_format=maildir',
            'mailuser_uid=5000',
            'mailuser_gid=5000',
            'mailuser_name=vmail',
            'mailuser_group=vmail',
            'mailbox_virtual_uidgid_maps=n',
            'mail_filter_syntax=sieve',
            'pop3_imap_daemon=dovecot',
            '',
            '[web]',
            'website_basedir=/var/www',
            '',
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function authHeaders(): array
    {
        return ['X-API-Key' => self::KEY];
    }

    protected function seedDomain(array $overrides = []): int
    {
        return (int) DB::table('mail_domain')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 5,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'domain' => 'example.com',
            'active' => 'y',
        ], $overrides), 'domain_id');
    }

    protected function seedMailUser(array $overrides = []): int
    {
        return (int) DB::table('mail_user')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 5,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'email' => 'box@example.com',
            'login' => 'box@example.com',
            'password' => '$6$rounds=5000$abcdef$hash',
            'name' => 'Box',
            'maildir' => '/var/vmail/example.com/box',
            'homedir' => '/var/vmail',
            'postfix' => 'y',
        ], $overrides), 'mailuser_id');
    }

    /**
     * Datalog rows for one table, oldest first.
     *
     * @return Collection<int, object>
     */
    protected function datalogRows(string $table)
    {
        return DB::table('sys_datalog')->where('dbtable', $table)->orderBy('datalog_id')->get();
    }

    /**
     * Assert exactly one datalog row (table+action[+dbidx]) and return its
     * unserialized data payload.
     *
     * @return array{old?: array<string, mixed>, new?: array<string, mixed>}
     */
    protected function assertDatalog(string $table, string $action, ?string $dbidx = null): array
    {
        $query = DB::table('sys_datalog')->where('dbtable', $table)->where('action', $action);

        if ($dbidx !== null) {
            $query->where('dbidx', $dbidx);
        }

        $rows = $query->get();

        $this->assertCount(
            1,
            $rows,
            "expected exactly one sys_datalog row for {$table} action {$action}".($dbidx !== null ? " dbidx {$dbidx}" : '')
        );

        $data = unserialize($rows->first()->data);
        $this->assertIsArray($data);

        return $data;
    }

    /**
     * Generic CRUD lifecycle assertions shared by every plain resource:
     * create 201 (+ datalog i with legacy diff shape), show 200/404, update
     * 200 (+ datalog u) then a no-change PUT writing NO datalog row,
     * delete 204 (+ datalog d), auth 401 and bad list sort 400.
     *
     * @param  string  $base  e.g. '/api/v1/mail/transports'
     * @param  array<string, mixed>  $createPayload
     * @param  array<string, mixed>  $updatePayload  single changed field(s)
     * @param  string  $table  ISPConfig table name
     * @param  string  $pk  primary key column
     */
    protected function runCrudLifecycle(
        string $base,
        array $createPayload,
        array $updatePayload,
        string $table,
        string $pk
    ): void {
        // Auth: 401 problem+json without a key.
        $this->getJson($base)
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json');

        // List: envelope + bad sort 400.
        $this->getJson($base, $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']]);

        $this->getJson($base.'?sort=evil_column', $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json');

        // Show: missing id 404s as problem+json.
        $this->getJson($base.'/99999', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');

        // Create: 201 + datalog 'i' with legacy insert diff shape.
        $created = $this->postJson($base, $createPayload, $this->authHeaders());
        $created->assertStatus(201);
        $id = $created->json('id');
        $this->assertIsInt($id);

        $data = $this->assertDatalog($table, 'i', $pk.':'.$id);
        $this->assertSame(['new', 'old'], array_keys($data), "{$table} insert payload serializes 'new' first");
        $this->assertArrayHasKey($pk, $data['new']);
        $this->assertSame((string) $id, (string) $data['new'][$pk]);

        // Show the created record.
        $this->getJson($base.'/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id);

        // Update: 200 + datalog 'u' with old/new order.
        $this->putJson($base.'/'.$id, $updatePayload, $this->authHeaders())->assertOk();

        $data = $this->assertDatalog($table, 'u', $pk.':'.$id);
        $this->assertSame(['old', 'new'], array_keys($data), "{$table} update payload serializes 'old' first");

        // No-change update: legacy suppression writes NOTHING.
        $countBefore = DB::table('sys_datalog')->count();
        $this->putJson($base.'/'.$id, $updatePayload, $this->authHeaders())->assertOk();
        $this->assertSame($countBefore, DB::table('sys_datalog')->count(), "{$table} no-change update must not datalog");

        // Delete: 204 + datalog 'd', row gone.
        $this->deleteJson($base.'/'.$id, [], $this->authHeaders())->assertStatus(204);
        $this->assertDatalog($table, 'd', $pk.':'.$id);
        $this->assertDatabaseMissing($table, [$pk => $id]);
    }
}
