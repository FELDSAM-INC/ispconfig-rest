<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MailCompletionSchema;
use Tests\Support\SitesSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * P3 quota-SUM caps (spec 012 US3 / FR-022…FR-024): limit_mailquota
 * (bytes→MB), limit_web_quota (MB), limit_database_quota (bespoke sys_groupid,
 * MB). The check runs on create AND update (excluding the edited row), denies
 * when SUM+new exceeds the cap or when a single unlimited quota is requested
 * under a finite cap, and enforces the reseller SUM too.
 */
class ClientQuotaSumTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        // SitesSchema first: its `server` table carries db_server (the mail
        // schema's does not), so the composed server row can set it.
        SitesSchema::create();
        MailCompletionSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        DB::table('server')->insert([
            'server_id' => 1,
            'server_name' => 'srv1',
            'mail_server' => 1,
            'web_server' => 1,
            'db_server' => 1,
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

    /** Bytes for a MB value (mail_user.quota is stored in bytes). */
    protected function mb(int $mb): int
    {
        return $mb * 1024 * 1024;
    }

    protected function seedMailDomain(string $owner): void
    {
        DB::table('mail_domain')->insert($this->ownedBy($owner, [
            'server_id' => 1, 'domain' => 'q-dom.test', 'active' => 'y',
        ]));
    }

    protected function seedVhost(string $owner, string $domain, int $hdQuota): int
    {
        $id = (int) DB::table('web_domain')->insertGetId($this->ownedBy($owner, [
            'server_id' => 1, 'domain' => $domain, 'type' => 'vhost', 'parent_domain_id' => 0,
            'vhost_type' => 'name', 'hd_quota' => $hdQuota, 'traffic_quota' => -1, 'active' => 'y',
            'allow_override' => 'All', 'backup_copies' => 1,
        ]), 'domain_id');

        DB::table('web_domain')->where('domain_id', $id)->update([
            'document_root' => "/var/www/clients/client/web{$id}", 'system_user' => "web{$id}", 'system_group' => 'client',
        ]);

        return $id;
    }

    // ------------------------------------------------------------------
    // limit_mailquota (bytes → MB)
    // ------------------------------------------------------------------

    public function test_mail_quota_sum_on_create(): void
    {
        $this->seedMailDomain('clientA');
        $this->setClientLimit('clientA', 'limit_mailquota', 1000);

        // 900 MB already allocated.
        DB::table('mail_user')->insert($this->ownedBy('clientA', [
            'server_id' => 1, 'email' => 'big@q-dom.test', 'login' => 'big@q-dom.test', 'quota' => $this->mb(900),
        ]));

        // 900 + 200 > 1000 -> denied.
        $this->postJson('/api/v1/mail/users', [
            'email' => 'x@q-dom.test', 'password' => 'Secret123!', 'name' => 'X', 'quota' => $this->mb(200),
        ], $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('detail', 'You have reached the maximum number of mailbox quota allowed for your account.');

        // 900 + 100 = 1000 -> allowed.
        $this->postJson('/api/v1/mail/users', [
            'email' => 'ok@q-dom.test', 'password' => 'Secret123!', 'name' => 'OK', 'quota' => $this->mb(100),
        ], $this->tenantHeaders('clientA'))->assertStatus(201);

        // Unlimited (0) mailbox under a finite cap -> denied.
        $this->postJson('/api/v1/mail/users', [
            'email' => 'unl@q-dom.test', 'password' => 'Secret123!', 'name' => 'Unl', 'quota' => 0,
        ], $this->tenantHeaders('clientA'))->assertStatus(403);

        // -1 cap: any quota (including unlimited 0) allowed.
        $this->setClientLimit('clientA', 'limit_mailquota', -1);
        $this->postJson('/api/v1/mail/users', [
            'email' => 'free@q-dom.test', 'password' => 'Secret123!', 'name' => 'Free', 'quota' => 0,
        ], $this->tenantHeaders('clientA'))->assertStatus(201);
    }

    public function test_mail_quota_sum_on_update_excludes_the_edited_row(): void
    {
        $this->seedMailDomain('clientA');
        $this->setClientLimit('clientA', 'limit_mailquota', 1000);

        // 900 MB in another mailbox; the edited one starts at 50 MB.
        DB::table('mail_user')->insert($this->ownedBy('clientA', [
            'server_id' => 1, 'email' => 'big@q-dom.test', 'login' => 'big@q-dom.test', 'quota' => $this->mb(900),
        ]));
        $editId = (int) DB::table('mail_user')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 1, 'email' => 'edit@q-dom.test', 'login' => 'edit@q-dom.test', 'quota' => $this->mb(50),
        ]), 'mailuser_id');

        // Raise the edited row to 200 -> others(900) + 200 > 1000 -> 403.
        $this->putJson("/api/v1/mail/users/{$editId}", ['quota' => $this->mb(200)], $this->tenantHeaders('clientA'))
            ->assertStatus(403);

        // Raise within the cap -> others(900) + 90 <= 1000 -> 200.
        $this->putJson("/api/v1/mail/users/{$editId}", ['quota' => $this->mb(90)], $this->tenantHeaders('clientA'))
            ->assertStatus(200);
    }

    public function test_reseller_mail_quota_sum(): void
    {
        $this->seedMailDomain('clientA');
        // Client itself unlimited; the reseller caps the aggregate.
        $this->setClientLimit('clientA', 'limit_mailquota', -1);
        $this->setClientLimit('reseller', 'limit_mailquota', 1000);

        DB::table('mail_user')->insert($this->ownedBy('clientA', [
            'server_id' => 1, 'email' => 'big@q-dom.test', 'login' => 'big@q-dom.test', 'quota' => $this->mb(900),
        ]));

        $this->postJson('/api/v1/mail/users', [
            'email' => 'x@q-dom.test', 'password' => 'Secret123!', 'name' => 'X', 'quota' => $this->mb(200),
        ], $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('detail', 'Reseller: You have reached the maximum number of mailbox quota allowed for your account.');
    }

    // ------------------------------------------------------------------
    // limit_web_quota (MB, type='vhost')
    // ------------------------------------------------------------------

    public function test_web_quota_sum_on_create(): void
    {
        $this->setClientLimit('clientA', 'limit_web_quota', 1000);
        $this->seedVhost('clientA', 'big.test', 900);

        // 900 + 200 > 1000 -> 403.
        $this->postJson('/api/v1/sites/web-domains', [
            'server_id' => 1, 'domain' => 'over.test', 'hd_quota' => 200,
        ], $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('detail', 'You have reached the maximum number of web disk quota allowed for your account.');

        // 900 + 100 -> allowed.
        $this->postJson('/api/v1/sites/web-domains', [
            'server_id' => 1, 'domain' => 'ok.test', 'hd_quota' => 100,
        ], $this->tenantHeaders('clientA'))->assertStatus(201);

        // Unlimited (-1) disk under a finite cap -> denied (web/db treat
        // hd_quota <= 0 as unlimited, parity web_vhost_domain_edit.php:1122).
        $this->postJson('/api/v1/sites/web-domains', [
            'server_id' => 1, 'domain' => 'unl.test', 'hd_quota' => -1,
        ], $this->tenantHeaders('clientA'))->assertStatus(403);

        // -1 cap -> any quota allowed.
        $this->setClientLimit('clientA', 'limit_web_quota', -1);
        $this->postJson('/api/v1/sites/web-domains', [
            'server_id' => 1, 'domain' => 'free.test', 'hd_quota' => 5000,
        ], $this->tenantHeaders('clientA'))->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // limit_database_quota (bespoke sys_groupid, MB)
    // ------------------------------------------------------------------

    public function test_database_quota_sum_uses_bespoke_group_predicate(): void
    {
        $parent = $this->seedVhost('clientA', 'db.test', -1);
        $groupA = $this->tenant('clientA')['groupid'];
        $this->setClientLimit('clientA', 'limit_database_quota', 1000);

        $userId = (int) DB::table('web_database_user')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 1, 'database_user' => 'c'.$groupA.'app', 'database_user_prefix' => 'c'.$groupA,
            'database_password' => '*HASH',
        ]), 'database_user_id');

        // 900 MB already used across the client's group.
        DB::table('web_database')->insert($this->ownedBy('clientA', [
            'server_id' => 1, 'parent_domain_id' => $parent, 'type' => 'mysql',
            'database_name' => 'c'.$groupA.'big', 'database_name_prefix' => 'c'.$groupA,
            'database_quota' => 900, 'active' => 'y',
        ]));

        // 900 + 200 > 1000 -> 403.
        $this->postJson('/api/v1/sites/databases', [
            'server_id' => 1, 'parent_domain_id' => $parent, 'database_name' => 'over',
            'database_user_id' => $userId, 'database_quota' => 200,
        ], $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('detail', 'You have reached the maximum number of database quota allowed for your account.');

        // 900 + 100 -> allowed.
        $this->postJson('/api/v1/sites/databases', [
            'server_id' => 1, 'parent_domain_id' => $parent, 'database_name' => 'okdb',
            'database_user_id' => $userId, 'database_quota' => 100,
        ], $this->tenantHeaders('clientA'))->assertStatus(201);
    }
}
