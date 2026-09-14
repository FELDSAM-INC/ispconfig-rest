<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LockRecordFixtures;
use Tests\Support\MailCompletionSchema;
use Tests\Support\SitesSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Spec 019 FR-013 (owner decision 2026-09-14): while a client is locked,
 * client and reseller keys cannot re-enable or add that client's
 * lock-managed records; admin keys are unaffected.
 */
class LockedClientWriteGuardTest extends TestCase
{
    use LockRecordFixtures;
    use RefreshDatabase;
    use TenantFixtures;

    protected const DETAIL = 'The account is locked; its services cannot be enabled or added.';

    /** @var array<string, int> */
    protected array $records = [];

    protected function setUp(): void
    {
        parent::setUp();

        SitesSchema::create();
        MailCompletionSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        foreach (['clientA', 'clientB', 'reseller'] as $tenant) {
            $this->assignServers($tenant, ['web' => [1], 'db' => [1], 'mail' => [1]]);
        }

        DB::table('server')->insert([
            'server_id' => 1,
            'server_name' => 'host1',
            'web_server' => 1,
            'db_server' => 1,
            'mail_server' => 1,
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

        $groupA = $this->tenant('clientA')['groupid'];
        $groupB = $this->tenant('clientB')['groupid'];
        $userA = $this->tenant('clientA')['userid'];
        $userB = $this->tenant('clientB')['userid'];

        // Mailboxes need an existing mail domain (MailUserService).
        $this->seedLockRecord('mail_domain', 'domain_id', $groupA, ['domain' => 'a-dom.test', 'active' => 'y'], $userA);
        $this->seedLockRecord('mail_domain', 'domain_id', $groupB, ['domain' => 'b-dom.test', 'active' => 'y'], $userB);

        $this->records = [
            'siteA' => $this->seedLockRecord('web_domain', 'domain_id', $groupA, $this->siteAttrs('a-site.test', 'n'), $userA),
            'mailA_off' => $this->seedLockRecord('mail_user', 'mailuser_id', $groupA, $this->mailUserAttrs('off@a-dom.test', 'n'), $userA),
            'mailA_on' => $this->seedLockRecord('mail_user', 'mailuser_id', $groupA, $this->mailUserAttrs('on@a-dom.test', 'y'), $userA),
            'mailB_off' => $this->seedLockRecord('mail_user', 'mailuser_id', $groupB, $this->mailUserAttrs('off@b-dom.test', 'n'), $userB),
        ];

        $this->records['cronA'] = $this->seedLockRecord('cron', 'id', $groupA, [
            'parent_domain_id' => $this->records['siteA'], 'type' => 'url', 'command' => 'https://a-site.test/cron',
            'run_min' => '*', 'run_hour' => '*', 'run_mday' => '*', 'run_month' => '*', 'run_wday' => '*',
            'active' => 'n',
        ], $userA);

        DB::table('client')->where('client_id', $this->tenant('clientA')['client_id'])->update(['locked' => 'y']);
    }

    protected function assertRefused(string $method, string $uri, array $payload, string $tenant): void
    {
        $datalog = DB::table('sys_datalog')->count();

        $this->json($method, $uri, $payload, $this->tenantHeaders($tenant))
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('detail', self::DETAIL);

        $this->assertSame($datalog, DB::table('sys_datalog')->count(), "{$method} {$uri} must not write datalog");
    }

    public function test_client_key_cannot_reenable_services_of_its_locked_account(): void
    {
        $this->assertRefused('PUT', '/api/v1/sites/web-domains/'.$this->records['siteA'], ['active' => true], 'clientA');
        $this->assertRefused('PUT', '/api/v1/mail/users/'.$this->records['mailA_off'], ['postfix' => true], 'clientA');
        $this->assertRefused('PUT', '/api/v1/sites/cron-jobs/'.$this->records['cronA'], ['active' => true], 'clientA');

        $this->assertSame('n', DB::table('web_domain')->where('domain_id', $this->records['siteA'])->value('active'));
        $this->assertSame('n', DB::table('mail_user')->where('mailuser_id', $this->records['mailA_off'])->value('postfix'));
        $this->assertSame('n', DB::table('cron')->where('id', $this->records['cronA'])->value('active'));
    }

    public function test_client_key_cannot_add_services_to_its_locked_account(): void
    {
        $this->assertRefused('POST', '/api/v1/mail/domains', ['domain' => 'new-a.test', 'active' => true, 'dkim' => false], 'clientA');
        $this->assertRefused('POST', '/api/v1/sites/web-domains', ['server_id' => 1, 'domain' => 'new-site-a.test'], 'clientA');

        $this->assertSame(0, DB::table('mail_domain')->where('domain', 'new-a.test')->count());
        $this->assertSame(0, DB::table('web_domain')->where('domain', 'new-site-a.test')->count());
    }

    public function test_reseller_key_is_refused_for_its_locked_client(): void
    {
        $this->assertRefused('PUT', '/api/v1/sites/cron-jobs/'.$this->records['cronA'], ['active' => true], 'reseller');
        $this->assertRefused('POST', '/api/v1/mail/domains', [
            'domain' => 'reseller-for-a.test', 'active' => true, 'dkim' => false,
            'client_id' => $this->tenant('clientA')['client_id'],
        ], 'reseller');
    }

    public function test_other_writes_stay_allowed(): void
    {
        // Disabling a service of the locked account.
        $this->putJson('/api/v1/mail/users/'.$this->records['mailA_on'], ['postfix' => false], $this->tenantHeaders('clientA'))
            ->assertOk();

        // An unrelated change of a locked account's record.
        $this->putJson('/api/v1/mail/users/'.$this->records['mailA_off'], ['name' => 'Renamed'], $this->tenantHeaders('clientA'))
            ->assertOk();

        // Unlocked accounts are not restricted.
        $this->putJson('/api/v1/mail/users/'.$this->records['mailB_off'], ['postfix' => true], $this->tenantHeaders('clientB'))
            ->assertOk();

        // Admin keys are not restricted.
        $this->putJson('/api/v1/mail/users/'.$this->records['mailA_off'], ['postfix' => true], $this->tenantHeaders('admin'))
            ->assertOk();
        $this->postJson('/api/v1/mail/domains', [
            'domain' => 'admin-for-a.test', 'active' => true, 'dkim' => false, 'server_id' => 1,
            'client_id' => $this->tenant('clientA')['client_id'],
        ], $this->tenantHeaders('admin'))->assertStatus(201);
    }
}
