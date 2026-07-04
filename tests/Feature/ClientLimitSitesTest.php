<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SitesSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Client resource-limit COUNTING on the sites module (spec 012 US1/US2):
 * limit_web_domain (P1, type='vhost' filter), the web child-domain per-type
 * counts (P2), limit_database (P1) and the bespoke limit_database_postgresql
 * (P2, sys_groupid predicate, applied only for type='postgresql').
 */
class ClientLimitSitesTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        SitesSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        DB::table('server')->insert([
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
                'php_open_basedir=[website_path]/web:[website_path]/tmp',
                'htaccess_allow_override=All',
                'enable_sni=y',
                'php_fpm_default_chroot=n',
                '[server]',
                'ip_address=10.0.0.1',
                'log_retention=30',
            ]),
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
     * A provisioned vhost row owned by the tenant.
     *
     * @param  array<string, mixed>  $attrs
     */
    protected function seedVhost(string $owner, array $attrs = []): int
    {
        $id = (int) DB::table('web_domain')->insertGetId($this->ownedBy($owner, array_merge([
            'server_id' => 1, 'domain' => 'v'.uniqid().'.test', 'type' => 'vhost',
            'parent_domain_id' => 0, 'vhost_type' => 'name', 'hd_quota' => -1,
            'traffic_quota' => -1, 'active' => 'y', 'allow_override' => 'All', 'backup_copies' => 1,
        ], $attrs)), 'domain_id');

        DB::table('web_domain')->where('domain_id', $id)->update([
            'document_root' => "/var/www/clients/client/web{$id}",
            'system_user' => "web{$id}", 'system_group' => 'client',
        ]);

        return $id;
    }

    // ------------------------------------------------------------------
    // limit_web_domain (P1) with the type='vhost' filter
    // ------------------------------------------------------------------

    public function test_web_domain_count_uses_vhost_type_filter(): void
    {
        // One owned vhost + one owned subdomain child; cap limit_web_domain=1.
        $this->seedVhost('clientA', ['domain' => 'site-a.test']);
        DB::table('web_domain')->insert($this->ownedBy('clientA', [
            'server_id' => 1, 'domain' => 'sub.site-a.test', 'type' => 'subdomain', 'active' => 'y',
        ]));
        $this->setClientLimit('clientA', 'limit_web_domain', 1);

        $datalog = DB::table('sys_datalog')->count();

        // The subdomain does NOT consume the vhost cap, but the one vhost does.
        $this->postJson('/api/v1/sites/web-domains', [
            'server_id' => 1, 'domain' => 'blocked.test',
        ], $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('detail', 'You have reached the maximum number of websites allowed for your account.');

        $this->assertSame($datalog, DB::table('sys_datalog')->count());

        // admin bypass.
        $this->postJson('/api/v1/sites/web-domains', [
            'server_id' => 1, 'domain' => 'admin-site.test',
        ], $this->tenantHeaders('admin'))->assertStatus(201);

        // under cap.
        $this->setClientLimit('clientA', 'limit_web_domain', 5);
        $this->postJson('/api/v1/sites/web-domains', [
            'server_id' => 1, 'domain' => 'ok-site.test',
        ], $this->tenantHeaders('clientA'))->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // Web child domains — per-type counts (P2)
    // ------------------------------------------------------------------

    public function test_web_child_domain_types_are_counted_independently(): void
    {
        $parent = $this->seedVhost('clientA', ['domain' => 'parent.test']);

        // One owned subdomain child; cap limit_web_subdomain=1, alias unlimited.
        DB::table('web_domain')->insert($this->ownedBy('clientA', [
            'server_id' => 1, 'domain' => 'blog.parent.test', 'type' => 'subdomain',
            'parent_domain_id' => $parent, 'active' => 'y',
        ]));
        $this->setClientLimit('clientA', 'limit_web_subdomain', 1);

        // subdomain at cap -> 403.
        $this->postJson('/api/v1/sites/web-child-domains', [
            'parent_domain_id' => $parent, 'domain' => 'shop', 'type' => 'subdomain',
        ], $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('detail', 'You have reached the maximum number of subdomains allowed for your account.');

        // alias under its own (unlimited) cap still succeeds — type isolation.
        $this->postJson('/api/v1/sites/web-child-domains', [
            'parent_domain_id' => $parent, 'domain' => 'alias.test', 'type' => 'alias',
        ], $this->tenantHeaders('clientA'))->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // limit_database (P1) + bespoke limit_database_postgresql (P2)
    // ------------------------------------------------------------------

    public function test_database_count_and_postgresql_bespoke_cap(): void
    {
        $parent = $this->seedVhost('clientA', ['domain' => 'db-site.test']);
        $groupA = $this->tenant('clientA')['groupid'];

        $userId = (int) DB::table('web_database_user')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 1, 'database_user' => 'c'.$groupA.'app', 'database_user_prefix' => 'c'.$groupA,
            'database_password' => '*HASH',
        ]), 'database_user_id');

        // One owned mysql database; cap limit_database=1.
        DB::table('web_database')->insert($this->ownedBy('clientA', [
            'server_id' => 1, 'parent_domain_id' => $parent, 'type' => 'mysql',
            'database_name' => 'c'.$groupA.'existing', 'database_name_prefix' => 'c'.$groupA,
            'active' => 'y',
        ]));
        $this->setClientLimit('clientA', 'limit_database', 1);

        $this->postJson('/api/v1/sites/databases', [
            'server_id' => 1, 'parent_domain_id' => $parent, 'database_name' => 'mydb', 'database_user_id' => $userId,
        ], $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('detail', 'You have reached the maximum number of databases allowed for your account.');

        // Raise limit_database so it passes; now the PostgreSQL bespoke cap
        // (limit_database_postgresql, sys_groupid predicate) engages.
        $this->setClientLimit('clientA', 'limit_database', 10);
        DB::table('web_database')->insert($this->ownedBy('clientA', [
            'server_id' => 1, 'parent_domain_id' => $parent, 'type' => 'postgresql',
            'database_name' => 'c'.$groupA.'pgexisting', 'database_name_prefix' => 'c'.$groupA,
            'database_user_id' => 0, 'active' => 'y',
        ]));
        $this->setClientLimit('clientA', 'limit_database_postgresql', 1);

        $this->postJson('/api/v1/sites/databases', [
            'server_id' => 1, 'parent_domain_id' => $parent, 'type' => 'postgresql',
            'database_name' => 'mypg', 'database_user_id' => $userId,
        ], $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('detail', 'You have reached the maximum number of PostgreSQL databases allowed for your account.');

        // A mysql create still succeeds (limit_database has room; the postgres
        // bespoke cap does not apply to mysql).
        $this->postJson('/api/v1/sites/databases', [
            'server_id' => 1, 'parent_domain_id' => $parent, 'database_name' => 'mysql2', 'database_user_id' => $userId,
        ], $this->tenantHeaders('clientA'))->assertStatus(201);
    }
}
