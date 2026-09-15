<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DnsSchema;
use Tests\Support\MailCompletionSchema;
use Tests\Support\SitesSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Spec 016 US1: client and reseller keys create websites, mail domains,
 * databases and DNS zones on servers assigned to their account (legacy
 * web_vhost_domain_edit.php, mail_domain_edit.php, database_edit.php,
 * dns_soa_edit.php), with the first valid list entry as default and one
 * indistinguishable 422 for unassigned or nonexistent servers. Admin keys are
 * unchanged.
 */
class ClientServerAssignmentTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        SitesSchema::create();
        MailCompletionSchema::create();
        DnsSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        $webConfig = implode("\n", [
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
        ]);

        $servers = [
            [1, 'web1', 'web', 0],
            [2, 'web2', 'web', 0],
            [3, 'mail1', 'mail', 0],
            [4, 'ns1', 'dns', 0],
            [5, 'db1', 'db', 0],
            [6, 'web1-mirror', 'web', 1],
        ];

        foreach ($servers as [$id, $name, $role, $mirror]) {
            DB::table('server')->insert([
                'server_id' => $id,
                'server_name' => $name,
                'web_server' => $role === 'web' ? 1 : 0,
                'mail_server' => $role === 'mail' ? 1 : 0,
                'dns_server' => $role === 'dns' ? 1 : 0,
                'db_server' => $role === 'db' ? 1 : 0,
                'mirror_server_id' => $mirror,
                'active' => 1,
                'config' => $webConfig,
            ]);
        }

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

    protected function seedVhost(string $owner, int $serverId, string $domain): int
    {
        $id = (int) DB::table('web_domain')->insertGetId($this->ownedBy($owner, [
            'server_id' => $serverId, 'domain' => $domain, 'type' => 'vhost',
            'parent_domain_id' => 0, 'vhost_type' => 'name', 'hd_quota' => -1,
            'traffic_quota' => -1, 'active' => 'y', 'allow_override' => 'All', 'backup_copies' => 1,
            'ip_address' => '*',
        ]), 'domain_id');

        DB::table('web_domain')->where('domain_id', $id)->update([
            'document_root' => "/var/www/clients/client/web{$id}",
            'system_user' => "web{$id}", 'system_group' => 'client',
        ]);

        return $id;
    }

    protected function seedDatabaseUser(string $owner, int $serverId): int
    {
        $group = $this->tenant($owner)['groupid'];

        return (int) DB::table('web_database_user')->insertGetId($this->ownedBy($owner, [
            'server_id' => $serverId, 'database_user' => 'c'.$group.'app', 'database_user_prefix' => 'c'.$group,
            'database_password' => '*HASH',
        ]), 'database_user_id');
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    protected function createRequests(string $owner): array
    {
        $vhost = $this->seedVhost($owner, 2, $owner.'-parent.test');
        $user = $this->seedDatabaseUser($owner, 5);

        return [
            'web' => ['/api/v1/sites/web-domains', ['domain' => strtolower($owner).'-site.test']],
            'mail' => ['/api/v1/mail/domains', ['domain' => strtolower($owner).'-mail.test', 'active' => true, 'dkim' => false]],
            'db' => ['/api/v1/sites/databases', ['parent_domain_id' => $vhost, 'database_name' => 'appdb', 'database_user_id' => $user]],
            'dns' => ['/api/v1/dns/soa', ['origin' => strtolower($owner).'-zone.test', 'ns' => 'ns1.example.test', 'mbox' => 'hostmaster@example.test']],
        ];
    }

    public function test_client_key_without_server_id_uses_first_assigned_server(): void
    {
        $this->assignServers('clientA', ['web' => [2, 1], 'mail' => [3], 'db' => [5], 'dns' => [4]]);
        $requests = $this->createRequests('clientA');

        foreach ($requests as $service => [$uri, $body]) {
            $this->postJson($uri, $body, $this->tenantHeaders('clientA'))->assertStatus(201);
        }

        $this->assertSame(2, (int) DB::table('web_domain')->where('domain', 'clienta-site.test')->value('server_id'));
        $this->assertSame(3, (int) DB::table('mail_domain')->where('domain', 'clienta-mail.test')->value('server_id'));
        $this->assertSame(5, (int) DB::table('web_database')->where('database_name', 'like', '%appdb')->value('server_id'));
        $this->assertSame(4, (int) DB::table('dns_soa')->where('origin', 'clienta-zone.test.')->value('server_id'));
    }

    public function test_assigned_server_id_is_accepted(): void
    {
        $this->assignServers('clientA', ['web' => [2, 1]]);

        $this->postJson('/api/v1/sites/web-domains', ['domain' => 'second.test', 'server_id' => 1], $this->tenantHeaders('clientA'))
            ->assertStatus(201);

        $this->assertSame(1, (int) DB::table('web_domain')->where('domain', 'second.test')->value('server_id'));
    }

    public function test_unassigned_nonexistent_mirror_and_wrong_role_servers_get_identical_errors(): void
    {
        $this->assignServers('clientA', ['web' => [2], 'mail' => [3]]);
        $datalog = DB::table('sys_datalog')->count();

        $bodies = [];

        foreach ([1, 99, 6, 3] as $serverId) {
            $bodies[$serverId] = $this->postJson('/api/v1/sites/web-domains', [
                'domain' => 'probe.test', 'server_id' => $serverId,
            ], $this->tenantHeaders('clientA'))
                ->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('errors.server_id.0', 'The selected server is not available for this account.')
                ->assertJsonPath('error_types.server_id', 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#server-not-assigned')
                ->json();
        }

        $this->assertSame($bodies[1], $bodies[99]);
        $this->assertSame($bodies[1], $bodies[6]);
        $this->assertSame($bodies[1], $bodies[3]);

        $this->postJson('/api/v1/mail/domains', [
            'domain' => 'probe-mail.test', 'server_id' => 4,
        ], $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonPath('errors.server_id.0', 'The selected server is not available for this account.')
            ->assertJsonPath('error_types.server_id', 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#server-not-assigned');

        $this->assertSame($datalog, DB::table('sys_datalog')->count());
        $this->assertDatabaseMissing('web_domain', ['domain' => 'probe.test']);
    }

    public function test_no_valid_server_for_service(): void
    {
        $this->assignServers('clientA', ['web' => [99, 6], 'mail' => [], 'db' => [], 'dns' => []]);
        $requests = $this->createRequests('clientA');
        $labels = ['web' => 'web', 'mail' => 'mail', 'db' => 'database', 'dns' => 'DNS'];
        $datalog = DB::table('sys_datalog')->count();

        foreach ($requests as $service => [$uri, $body]) {
            $message = "No {$labels[$service]} server is assigned to this account.";

            $this->postJson($uri, $body, $this->tenantHeaders('clientA'))
                ->assertStatus(422)
                ->assertJsonPath('errors.server_id.0', $message)
                ->assertJsonPath('error_types.server_id', 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#server-not-assigned');

            $this->postJson($uri, $body + ['server_id' => 1], $this->tenantHeaders('clientA'))
                ->assertStatus(422)
                ->assertJsonPath('errors.server_id.0', $message)
                ->assertJsonPath('error_types.server_id', 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#server-not-assigned');
        }

        $this->assertSame($datalog, DB::table('sys_datalog')->count());
    }

    public function test_invalid_server_id_values_are_rejected(): void
    {
        $this->assignServers('clientA', ['mail' => [3]]);

        foreach ([0, -1, 'abc'] as $value) {
            $this->postJson('/api/v1/mail/domains', [
                'domain' => 'bad-value.test', 'server_id' => $value,
            ], $this->tenantHeaders('clientA'))
                ->assertStatus(422)
                ->assertJsonCount(1, 'errors.server_id')
                ->assertJsonMissingPath('error_types');
        }
    }

    public function test_reseller_key_uses_its_own_lists_also_for_its_clients(): void
    {
        $this->assignServers('reseller', ['mail' => [3]]);
        $this->assignServers('clientA', ['mail' => []]);

        $this->postJson('/api/v1/mail/domains', [
            'domain' => 'for-client-a.test', 'active' => true, 'dkim' => false,
            'client_id' => $this->tenant('clientA')['client_id'],
        ], $this->tenantHeaders('reseller'))->assertStatus(201);

        $this->assertSame(3, (int) DB::table('mail_domain')->where('domain', 'for-client-a.test')->value('server_id'));

        $this->assignServers('reseller', ['mail' => []]);

        $this->postJson('/api/v1/mail/domains', [
            'domain' => 'blocked-reseller.test', 'active' => true, 'dkim' => false,
        ], $this->tenantHeaders('reseller'))
            ->assertStatus(422)
            ->assertJsonPath('errors.server_id.0', 'No mail server is assigned to this account.')
            ->assertJsonPath('error_types.server_id', 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#server-not-assigned');
    }

    public function test_vhost_children_use_the_parent_server_without_server_id(): void
    {
        $this->assignServers('clientA', ['web' => [1]]);
        $parent = $this->seedVhost('clientA', 2, 'parent-child.test');

        $this->postJson('/api/v1/sites/web-domains', [
            'type' => 'vhostsubdomain', 'parent_domain_id' => $parent, 'domain' => 'sub.parent-child.test',
        ], $this->tenantHeaders('clientA'))->assertStatus(201);

        $this->assertSame(2, (int) DB::table('web_domain')->where('domain', 'sub.parent-child.test')->value('server_id'));
    }

    public function test_resource_on_server_removed_from_list_stays_updatable_and_deletable(): void
    {
        $this->assignServers('clientA', ['mail' => []]);

        $domain = (int) DB::table('mail_domain')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 3, 'domain' => 'legacy-owned.test', 'active' => 'y',
        ]), 'domain_id');

        $this->putJson("/api/v1/mail/domains/{$domain}", ['active' => false], $this->tenantHeaders('clientA'))
            ->assertOk();
        $this->deleteJson("/api/v1/mail/domains/{$domain}", [], $this->tenantHeaders('clientA'))
            ->assertStatus(204);
    }

    public function test_admin_keys_are_unchanged(): void
    {
        $this->assignServers('clientA', ['mail' => []]);

        $this->postJson('/api/v1/mail/domains', [
            'domain' => 'admin-no-server.test', 'active' => true, 'dkim' => false,
        ], $this->tenantHeaders('admin'))
            ->assertStatus(422)
            ->assertJsonPath('errors.server_id.0', 'The server id field is required.')
            ->assertJsonMissingPath('error_types');

        $this->postJson('/api/v1/sites/web-domains', [
            'domain' => 'admin-bad-server.test', 'server_id' => 99,
        ], $this->tenantHeaders('admin'))
            ->assertStatus(422)
            ->assertJsonPath('errors.server_id.0', 'The selected server id is invalid.')
            ->assertJsonMissingPath('error_types');

        $this->postJson('/api/v1/mail/domains', [
            'domain' => 'admin-any-server.test', 'server_id' => 3, 'active' => true, 'dkim' => false,
        ], $this->tenantHeaders('admin'))->assertStatus(201);
    }
}
