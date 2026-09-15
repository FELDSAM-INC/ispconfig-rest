<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Shared fixture for the website backup feature tests (spec 018): the sites
 * tables, the four-identity tenant matrix with real keys (the backup gate
 * needs client and reseller scopes), the client `limit_backup` column, and
 * two servers — server 1 with a `backup_dir`, server 2 without one.
 */
abstract class WebBackupApiTestCase extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected const BACKUP_SERVER = 1;

    protected const PLAIN_SERVER = 2;

    protected const BACKUP_TSTAMP = 1757808000;

    private int $domainCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();

        SitesSchema::create();
        TenantSchema::create();

        if (! Schema::hasColumn('client', 'limit_backup')) {
            Schema::table('client', function (Blueprint $table): void {
                // enum('n','y') default 'y' (ispconfig3.sql)
                $table->string('limit_backup', 1)->default('y');
            });
        }

        $this->seedTenants();

        DB::table('server')->insert([
            [
                'server_id' => self::BACKUP_SERVER,
                'server_name' => 'web1',
                'web_server' => 1,
                'db_server' => 1,
                'mail_server' => 0,
                'mirror_server_id' => 0,
                'active' => 1,
                'config' => implode("\n", ['[server]', 'ip_address=10.0.0.1', 'backup_dir=/var/backup', 'backup_mode=rootgz']),
            ],
            [
                'server_id' => self::PLAIN_SERVER,
                'server_name' => 'web2',
                'web_server' => 1,
                'db_server' => 1,
                'mail_server' => 0,
                'mirror_server_id' => 0,
                'active' => 1,
                'config' => implode("\n", ['[server]', 'ip_address=10.0.0.2']),
            ],
        ]);
    }

    /**
     * Seed a website owned by the tenant and return its domain_id.
     *
     * @param  array<string, mixed>  $attrs
     */
    protected function website(string $owner, array $attrs = []): int
    {
        $this->domainCounter++;
        $n = $this->domainCounter;

        return (int) DB::table('web_domain')->insertGetId($this->ownedBy($owner, array_merge([
            'server_id' => self::BACKUP_SERVER,
            'domain' => "backup{$n}.example.test",
            'type' => 'vhost',
            'parent_domain_id' => 0,
            'active' => 'y',
            'document_root' => "/var/www/clients/client1/web{$n}",
            'system_user' => "web{$n}",
            'backup_interval' => 'none',
            'backup_copies' => 1,
        ], $attrs)), 'domain_id');
    }

    /**
     * Seed a MySQL database of the website on the given server.
     */
    protected function database(string $owner, int $websiteId, int $serverId, string $name): int
    {
        return (int) DB::table('web_database')->insertGetId($this->ownedBy($owner, [
            'server_id' => $serverId,
            'parent_domain_id' => $websiteId,
            'type' => 'mysql',
            'database_name' => $name,
        ]), 'database_id');
    }

    /**
     * Seed a stored backup row of the website.
     *
     * @param  array<string, mixed>  $attrs
     */
    protected function backup(int $websiteId, array $attrs = []): int
    {
        return (int) DB::table('web_backup')->insertGetId(array_merge([
            'server_id' => self::BACKUP_SERVER,
            'parent_domain_id' => $websiteId,
            'backup_type' => 'web',
            'backup_mode' => 'rootgz',
            'backup_format' => 'tar_gzip',
            'tstamp' => self::BACKUP_TSTAMP,
            'filename' => 'web_2025-09-14_00-00.tar.gz',
            'filesize' => '1048576',
            'backup_password' => '',
        ], $attrs), 'backup_id');
    }

    /**
     * Seed a queued or finished remote action.
     *
     * @param  array<string, mixed>  $attrs
     */
    protected function remoteAction(array $attrs): int
    {
        return (int) DB::table('sys_remoteaction')->insertGetId(array_merge([
            'server_id' => self::BACKUP_SERVER,
            'tstamp' => self::BACKUP_TSTAMP,
            'action_state' => 'pending',
            'response' => '',
        ], $attrs), 'action_id');
    }

    /**
     * Assert the complete sys_remoteaction table (tstamp checked for being recent).
     *
     * @param  array<int, array{server_id: int, action_type: string, action_param: string}>  $expected
     */
    protected function assertRemoteActionRows(array $expected): void
    {
        $rows = DB::table('sys_remoteaction')->orderBy('action_id')->get();

        $this->assertCount(count($expected), $rows, 'sys_remoteaction row count');

        foreach ($rows as $index => $row) {
            $this->assertSame((int) $expected[$index]['server_id'], (int) $row->server_id);
            $this->assertSame($expected[$index]['action_type'], $row->action_type);
            $this->assertSame($expected[$index]['action_param'], $row->action_param);
            $this->assertSame('pending', $row->action_state);
            $this->assertSame('', $row->response);
            $this->assertEqualsWithDelta(time(), (int) $row->tstamp, 5);
        }
    }

    protected function setLimitBackup(string $tenant, string $value): void
    {
        DB::table('client')->where('client_id', $this->tenant($tenant)['client_id'])->update(['limit_backup' => $value]);
    }

    protected function url(int $websiteId, string $suffix = ''): string
    {
        return "/api/v1/sites/web-domains/{$websiteId}{$suffix}";
    }
}
