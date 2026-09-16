<?php

namespace Tests\Feature;

use App\Support\ProblemType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SitesSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * SSH authentication mode for scoped keys (spec 037 US2): a client or reseller
 * key that sends the credential the installation does not accept is refused on
 * that field instead of having it silently discarded. The mode is read from
 * the `[sites]` section — the one the administrator's Sites tab writes
 * (research R1–R3).
 */
class ShellUserAuthenticationModeTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const URL = '/api/v1/sites/shell-users';

    private const KEY = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABgQDqa...';

    protected function setUp(): void
    {
        parent::setUp();

        SitesSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        DB::table('server')->insert([
            'server_id' => 1, 'server_name' => 'web1', 'web_server' => 1, 'mirror_server_id' => 0, 'active' => 1,
        ]);

        foreach (['clientA', 'reseller'] as $tenant) {
            $this->assignServers($tenant, ['web' => [1]]);
            $this->setClientLimit($tenant, 'limit_shell_user', 5);
        }
    }

    private function setMode(string $mode): void
    {
        DB::table('sys_ini')->updateOrInsert(['sysini_id' => 1], [
            'config' => implode("\n", [
                '[sites]',
                'shelluser_prefix=',
                'ssh_authentication='.$mode,
                '[misc]',
            ]),
        ]);
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(int $parentId, array $overrides = []): array
    {
        return array_merge([
            'parent_domain_id' => $parentId,
            'username' => 'ssh'.random_int(1000, 9999),
        ], $overrides);
    }

    public function test_password_is_refused_on_a_key_only_installation(): void
    {
        $this->setMode('key');
        $parentId = $this->seedVhost('clientA');
        $datalog = DB::table('sys_datalog')->count();

        $this->postJson(self::URL, $this->payload($parentId, ['password' => 'Str0ng-Pass!']), $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['password']])
            ->assertJsonPath('error_types.password', ProblemType::uri(ProblemType::FEATURE_NOT_ALLOWED));

        $this->assertSame($datalog, DB::table('sys_datalog')->count());
        $this->assertSame(0, DB::table('shell_user')->count());

        // The accepted credential goes through.
        $this->postJson(self::URL, $this->payload($parentId, ['ssh_rsa' => self::KEY]), $this->tenantHeaders('clientA'))
            ->assertStatus(201);
    }

    public function test_key_is_refused_on_a_password_only_installation(): void
    {
        $this->setMode('password');
        $parentId = $this->seedVhost('clientA');

        $this->postJson(self::URL, $this->payload($parentId, ['ssh_rsa' => self::KEY]), $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['ssh_rsa']])
            ->assertJsonPath('error_types.ssh_rsa', ProblemType::uri(ProblemType::FEATURE_NOT_ALLOWED));

        $this->postJson(self::URL, $this->payload($parentId, ['password' => 'Str0ng-Pass!']), $this->tenantHeaders('clientA'))
            ->assertStatus(201);
    }

    public function test_empty_values_and_both_allowed_mode_are_accepted(): void
    {
        $parentId = $this->seedVhost('clientA');

        // Nothing to discard: an empty or null credential is not a refusal.
        $this->setMode('key');
        $this->postJson(self::URL, $this->payload($parentId, ['ssh_rsa' => self::KEY, 'password' => '']), $this->tenantHeaders('clientA'))
            ->assertStatus(201);
        $this->postJson(self::URL, $this->payload($parentId, ['ssh_rsa' => self::KEY, 'password' => null]), $this->tenantHeaders('clientA'))
            ->assertStatus(201);

        // Both allowed: the installation accepts either credential.
        $this->setMode('');
        $this->postJson(self::URL, $this->payload($parentId, ['password' => 'Str0ng-Pass!', 'ssh_rsa' => self::KEY]), $this->tenantHeaders('clientA'))
            ->assertStatus(201);
    }

    public function test_update_accepts_the_stored_value_and_refuses_a_change(): void
    {
        $this->setMode('');
        $parentId = $this->seedVhost('clientA');

        $id = $this->postJson(self::URL, $this->payload($parentId, ['ssh_rsa' => self::KEY]), $this->tenantHeaders('clientA'))
            ->assertStatus(201)
            ->json('id');

        // The installation switches to password-only afterwards.
        $this->setMode('password');
        $datalog = DB::table('sys_datalog')->count();

        // Re-sending the stored key unchanged is accepted (specs 016/033 rule).
        $this->putJson(self::URL.'/'.$id, ['ssh_rsa' => self::KEY, 'quota_size' => 10], $this->tenantHeaders('clientA'))
            ->assertStatus(200);

        // Changing it is refused.
        $this->putJson(self::URL.'/'.$id, ['ssh_rsa' => 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABgQDdifferent...'], $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonPath('error_types.ssh_rsa', ProblemType::uri(ProblemType::FEATURE_NOT_ALLOWED));

        $this->assertSame(self::KEY, DB::table('shell_user')->where('shell_user_id', $id)->value('ssh_rsa'));
        $this->assertSame($datalog + 1, DB::table('sys_datalog')->count(), 'only the accepted update is journaled');
    }

    public function test_reseller_key_is_refused_like_a_client_key(): void
    {
        $this->setMode('key');
        $parentId = $this->seedVhost('reseller');

        $this->postJson(self::URL, $this->payload($parentId, ['password' => 'Str0ng-Pass!']), $this->tenantHeaders('reseller'))
            ->assertStatus(422)
            ->assertJsonPath('error_types.password', ProblemType::uri(ProblemType::FEATURE_NOT_ALLOWED));
    }

    public function test_admin_key_is_not_refused_and_the_credential_is_cleared(): void
    {
        $this->setMode('key');
        $parentId = $this->seedVhost('clientA');

        $id = $this->postJson(self::URL, $this->payload($parentId, ['password' => 'Str0ng-Pass!', 'ssh_rsa' => self::KEY]), $this->tenantHeaders('admin'))
            ->assertStatus(201)
            ->json('id');

        $row = DB::table('shell_user')->where('shell_user_id', $id)->first();
        $this->assertSame(self::KEY, $row->ssh_rsa);
        $this->assertTrue($row->password === null || $row->password === '');
    }
}
