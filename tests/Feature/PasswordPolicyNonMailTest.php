<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ClientSchema;
use Tests\Support\SitesSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Installation password policy for non-mail credentials (spec 038): every
 * field legacy validates with validate_password — client and reseller
 * passwords, FTP, shell, WebDAV and web folder users, database users and the
 * website statistics password — is judged with the same computation spec 028
 * ported for mailboxes.
 */
class PasswordPolicyNonMailTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    /** Legacy weak_password_txt with length 8 and strength 3. */
    private const GOOD = 'The chosen password does not match the security guidelines. It has to be at least 8 chars in length and have a strength of "Good".';

    private const WEAK = 'abcdefgh';

    private const STRONG = 'Qa038-Strong!x9';

    protected function setUp(): void
    {
        parent::setUp();

        // The full-width client table (email, language, password) plus the
        // sites tables; TenantSchema then adds the limit and scoping columns.
        ClientSchema::create();
        SitesSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        DB::table('server')->insert([
            'server_id' => 1, 'server_name' => 'web1', 'web_server' => 1, 'db_server' => 1,
            'mirror_server_id' => 0, 'active' => 1,
        ]);

        foreach (['clientA', 'reseller'] as $tenant) {
            $this->assignServers($tenant, ['web' => [1], 'db' => [1]]);
            $this->setClientLimit($tenant, 'limit_shell_user', 5);
            $this->setClientLimit($tenant, 'limit_webdav_user', 5);
        }

        $this->setPolicy('8', '3');
    }

    private function setPolicy(?string $length, ?string $strength): void
    {
        $lines = ['[sites]', 'shelluser_prefix=', 'ssh_authentication=', '[misc]'];

        if ($length !== null) {
            $lines[] = "min_password_length={$length}";
        }

        if ($strength !== null) {
            $lines[] = "min_password_strength={$strength}";
        }

        DB::table('sys_ini')->updateOrInsert(['sysini_id' => 1], ['config' => implode("\n", $lines)]);
    }

    private function seedVhost(string $owner): int
    {
        $id = (int) DB::table('web_domain')->insertGetId($this->ownedBy($owner, [
            'server_id' => 1, 'ip_address' => '*', 'domain' => 'v'.uniqid().'.test', 'type' => 'vhost',
            'parent_domain_id' => 0, 'vhost_type' => 'name', 'hd_quota' => -1, 'traffic_quota' => -1,
            'active' => 'y', 'allow_override' => 'All', 'backup_copies' => 1, 'cgi' => 'n', 'ssi' => 'n',
            'perl' => 'n', 'ruby' => 'n', 'python' => 'n', 'suexec' => 'y', 'errordocs' => 0,
            'subdomain' => 'www', 'ssl' => 'n', 'ssl_letsencrypt' => 'n', 'directive_snippets_id' => 0,
            'php' => 'no', 'server_php_id' => 0,
        ]), 'domain_id');

        DB::table('web_domain')->where('domain_id', $id)->update([
            'document_root' => "/var/www/clients/client/web{$id}",
            'system_user' => "web{$id}", 'system_group' => 'client',
        ]);

        return $id;
    }

    /**
     * Every non-mail credential endpoint with its password field.
     *
     * @return array<string, array{0: string, 1: string, 2: callable(int): array<string, mixed>}>
     */
    private function credentialCases(int $parentId): array
    {
        return [
            'ftp user' => ['/api/v1/sites/ftp-users', 'password', [
                'parent_domain_id' => $parentId, 'username' => 'ftp'.random_int(100, 999),
            ]],
            'shell user' => ['/api/v1/sites/shell-users', 'password', [
                'parent_domain_id' => $parentId, 'username' => 'ssh'.random_int(100, 999),
            ]],
            'webdav user' => ['/api/v1/sites/webdav-users', 'password', [
                'parent_domain_id' => $parentId, 'username' => 'dav'.random_int(100, 999), 'dir' => 'webdav',
            ]],
            'database user' => ['/api/v1/sites/database-users', 'database_password', [
                'database_user' => 'dbu'.random_int(100, 999),
            ]],
        ];
    }

    public function test_weak_credentials_are_refused_and_strong_ones_accepted(): void
    {
        $parentId = $this->seedVhost('clientA');
        $datalog = DB::table('sys_datalog')->count();

        foreach ($this->credentialCases($parentId) as $label => [$url, $field, $payload]) {
            $this->postJson($url, $payload + [$field => self::WEAK], $this->tenantHeaders('clientA'))
                ->assertStatus(422)
                ->assertJsonPath("errors.{$field}.0", self::GOOD, $label);
        }

        $this->assertSame($datalog, DB::table('sys_datalog')->count(), 'a refused password writes nothing');

        foreach ($this->credentialCases($parentId) as $label => [$url, $field, $payload]) {
            $this->postJson($url, $payload + [$field => self::STRONG], $this->tenantHeaders('clientA'))
                ->assertStatus(201, $label);
        }
    }

    public function test_client_and_reseller_passwords_follow_the_policy(): void
    {
        $weak = $this->clientPayload(['password' => self::WEAK]);

        $this->postJson('/api/v1/clients', $weak, $this->tenantHeaders('admin'))
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', self::GOOD);

        $id = $this->postJson('/api/v1/clients', $this->clientPayload(['password' => self::STRONG]), $this->tenantHeaders('admin'))
            ->assertStatus(201)
            ->json('id');

        $stored = DB::table('client')->where('client_id', $id)->value('password');

        $this->putJson('/api/v1/clients/'.$id, ['password' => self::WEAK], $this->tenantHeaders('admin'))
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', self::GOOD);

        $this->assertSame($stored, DB::table('client')->where('client_id', $id)->value('password'), 'the stored hash is unchanged');

        $this->putJson('/api/v1/clients/'.$id, ['password' => self::STRONG.'2'], $this->tenantHeaders('admin'))
            ->assertStatus(200);

        // Resellers travel the same request rule (own admin-only prefix;
        // limit_client satisfies the reseller condition, otherwise the
        // endpoint refuses with 400).
        $this->postJson('/api/v1/resellers', $this->clientPayload(['password' => self::WEAK, 'limit_client' => -1]), $this->tenantHeaders('admin'))
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', self::GOOD);
    }

    public function test_statistics_password_of_a_website(): void
    {
        $parentId = $this->seedVhost('clientA');

        $this->putJson('/api/v1/sites/web-domains/'.$parentId, ['stats_password' => self::WEAK], $this->tenantHeaders('admin'))
            ->assertStatus(422)
            ->assertJsonPath('errors.stats_password.0', self::GOOD);

        $this->putJson('/api/v1/sites/web-domains/'.$parentId, ['stats_password' => self::STRONG], $this->tenantHeaders('admin'))
            ->assertStatus(200);
    }

    public function test_message_names_length_only_without_a_strength_requirement(): void
    {
        $this->setPolicy('10', '0');
        $parentId = $this->seedVhost('clientA');

        $this->postJson('/api/v1/sites/ftp-users', [
            'parent_domain_id' => $parentId, 'username' => 'ftplen', 'password' => 'Ab3!x',
        ], $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'The chosen password does not match the security guidelines. It has to be at least 10 chars in length.');

        // A long lowercase-only password passes when no strength is required.
        $this->postJson('/api/v1/sites/ftp-users', [
            'parent_domain_id' => $parentId, 'username' => 'ftplen2', 'password' => 'abcdefghijkl',
        ], $this->tenantHeaders('clientA'))->assertStatus(201);
    }

    public function test_absent_or_empty_passwords_are_not_judged(): void
    {
        $parentId = $this->seedVhost('clientA');

        // Shell users may be created with a key only — no password at all.
        $this->postJson('/api/v1/sites/shell-users', [
            'parent_domain_id' => $parentId, 'username' => 'sshnopw', 'ssh_rsa' => 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABtest',
        ], $this->tenantHeaders('clientA'))->assertStatus(201);

        $id = $this->postJson('/api/v1/clients', $this->clientPayload(['password' => self::STRONG]), $this->tenantHeaders('admin'))
            ->assertStatus(201)
            ->json('id');

        // A blank password on a client update still means "no change".
        $this->putJson('/api/v1/clients/'.$id, ['password' => '', 'contact_name' => 'Renamed'], $this->tenantHeaders('admin'))
            ->assertStatus(200);
    }

    public function test_every_key_type_is_enforced(): void
    {
        $parentId = $this->seedVhost('clientA');
        $resellerParent = $this->seedVhost('reseller');

        foreach ([['clientA', $parentId], ['reseller', $resellerParent], ['admin', $parentId]] as [$tenant, $parent]) {
            $this->postJson('/api/v1/sites/ftp-users', [
                'parent_domain_id' => $parent, 'username' => 'ftp'.$tenant, 'password' => self::WEAK,
            ], $this->tenantHeaders($tenant))
                ->assertStatus(422, $tenant)
                ->assertJsonPath('errors.password.0', self::GOOD);
        }
    }

    public function test_a_policy_without_a_length_uses_the_legacy_default(): void
    {
        $this->setPolicy(null, '0');
        $parentId = $this->seedVhost('clientA');

        // auth::get_min_password_length() defaults to 8.
        $this->postJson('/api/v1/sites/ftp-users', [
            'parent_domain_id' => $parentId, 'username' => 'ftpdef', 'password' => 'Ab3!xy',
        ], $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'The chosen password does not match the security guidelines. It has to be at least 8 chars in length.');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function clientPayload(array $overrides = []): array
    {
        return array_merge([
            'contact_name' => 'QA038 Temp',
            'email' => 'qa038-'.random_int(1000, 9999).'@example.test',
            'username' => 'qa038'.random_int(1000, 9999),
            'company_name' => '',
        ], $overrides);
    }

    protected function tearDown(): void
    {
        // Guard against a fixture that silently loses the sys_ini table.
        $this->assertTrue(Schema::hasTable('sys_ini'));

        parent::tearDown();
    }
}
