<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Shared fixture for the sites-module feature tests: the ISPConfig table
 * subset (SitesSchema), an acting admin sys_user, client groups, and two
 * servers whose serialized config blobs mirror a real ISPConfig panel
 * (website_path template, php_open_basedir template, SNI flag, server
 * IPs) plus the sys_ini sites config carrying the name-prefix patterns.
 */
abstract class SitesApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'test-dev-key';

    protected function setUp(): void
    {
        parent::setUp();

        SitesSchema::create();

        config(['api.dev_key' => self::KEY]);

        DB::table('sys_user')->insert([
            'userid' => 1,
            'username' => 'apiadmin',
            'typ' => 'admin',
            'default_group' => 1,
        ]);

        DB::table('sys_group')->insert([
            ['groupid' => 1, 'name' => 'admin', 'client_id' => 0],
            ['groupid' => 5, 'name' => 'Test Client', 'client_id' => 3],
            ['groupid' => 6, 'name' => 'Full Client', 'client_id' => 4],
        ]);

        DB::table('client')->insert([
            ['client_id' => 3, 'contact_name' => 'Test Client', 'limit_cron_type' => 'url'],
            ['client_id' => 4, 'contact_name' => 'Full Client', 'limit_cron_type' => 'full'],
        ]);

        // server 1: apache web+db server with SNI; server 2: nginx web+db
        // server without SNI (for the SNI/rewrite-rule tests).
        DB::table('server')->insert([
            [
                'server_id' => 1,
                'server_name' => 'web1',
                'web_server' => 1,
                'db_server' => 1,
                'mail_server' => 0,
                'mirror_server_id' => 0,
                'active' => 1,
                'config' => implode("\n", [
                    '[web]',
                    'server_type=apache',
                    'website_path=/var/www/clients/client[client_id]/web[website_id]',
                    'php_open_basedir=[website_path]/web:[website_path]/tmp:/usr/share/php',
                    'htaccess_allow_override=All',
                    'enable_sni=y',
                    'php_fpm_default_chroot=n',
                    '[server]',
                    'ip_address=10.0.0.1',
                    'log_retention=30',
                ]),
            ],
            [
                'server_id' => 2,
                'server_name' => 'web2',
                'web_server' => 1,
                'db_server' => 1,
                'mail_server' => 0,
                'mirror_server_id' => 0,
                'active' => 1,
                'config' => implode("\n", [
                    '[web]',
                    'server_type=nginx',
                    'website_path=/srv/www/clients/client[client_id]/web[website_id]',
                    'php_open_basedir=[website_path]/web:/tmp',
                    'htaccess_allow_override=None',
                    'enable_sni=n',
                    '[server]',
                    'ip_address=10.0.0.2',
                    'log_retention=0',
                ]),
            ],
        ]);

        DB::table('sys_ini')->insert([
            'sysini_id' => 1,
            'config' => implode("\n", [
                '[sites]',
                'dbname_prefix=c[CLIENTID]',
                'dbuser_prefix=c[CLIENTID]',
                'ftpuser_prefix=[CLIENTNAME]',
                'shelluser_prefix=[CLIENTNAME]',
                'webdavuser_prefix=[CLIENTNAME]',
                'default_remote_dbserver=',
                '[misc]',
                'ssh_authentication=',
            ]),
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
     * Seed a provisioned vhost row (as the API's own create would leave
     * it) and return its domain_id.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function seedVhost(array $overrides = []): int
    {
        static $counter = 0;
        $counter++;

        $id = (int) DB::table('web_domain')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 5,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'domain' => "site{$counter}.example.com",
            'type' => 'vhost',
            'parent_domain_id' => 0,
            'vhost_type' => 'name',
            'hd_quota' => -1,
            'traffic_quota' => -1,
            'active' => 'y',
            'allow_override' => 'All',
            'backup_copies' => 1,
        ], $overrides), 'domain_id');

        // Provisioning values normally derived on create.
        if (! array_key_exists('document_root', $overrides)) {
            DB::table('web_domain')->where('domain_id', $id)->update([
                'document_root' => "/var/www/clients/client3/web{$id}",
                'system_user' => "web{$id}",
                'system_group' => 'client3',
            ]);
        }

        return $id;
    }

    /**
     * Update the sys_ini [misc] ssh_authentication mode.
     */
    protected function setSshAuthenticationMode(string $mode): void
    {
        $config = (string) DB::table('sys_ini')->where('sysini_id', 1)->value('config');
        $config = preg_replace('/^ssh_authentication=.*$/m', 'ssh_authentication='.$mode, $config);
        DB::table('sys_ini')->where('sysini_id', 1)->update(['config' => $config]);
    }

    /**
     * All sys_datalog rows for a table.
     *
     * @return array<int, object>
     */
    protected function datalogRows(string $table): array
    {
        return DB::table('sys_datalog')->where('dbtable', $table)->orderBy('datalog_id')->get()->all();
    }
}
