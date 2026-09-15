<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SitesSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Spec 020 US1 — plan flags hold for client and reseller keys (FR-001,
 * FR-002, FR-011, FR-012; legacy web_vhost_domain_edit.php:979-996).
 */
class WebPlanFlagsScopedKeyTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        SitesSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        foreach (['clientA', 'clientB', 'reseller'] as $tenant) {
            $this->assignServers($tenant, ['web' => [1]]);
        }

        DB::table('server')->insert([
            'server_id' => 1,
            'server_name' => 'web1',
            'web_server' => 1,
            'db_server' => 0,
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
            'config' => implode("\n", ['[sites]', 'dbname_prefix=c[CLIENTID]', '[misc]', 'ssh_authentication=']),
        ]);
    }

    /**
     * @param  array<string, string>  $flags
     */
    protected function setFlags(string $tenant, array $flags): void
    {
        DB::table('client')->where('client_id', $this->tenant($tenant)['client_id'])->update($flags);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    protected function seedVhost(string $owner, array $attrs = []): int
    {
        $id = (int) DB::table('web_domain')->insertGetId($this->ownedBy($owner, array_merge([
            'server_id' => 1, 'ip_address' => '*', 'domain' => 'v'.uniqid().'.test', 'type' => 'vhost',
            'parent_domain_id' => 0, 'vhost_type' => 'name', 'hd_quota' => -1, 'traffic_quota' => -1,
            'active' => 'y', 'allow_override' => 'All', 'backup_copies' => 1, 'cgi' => 'n', 'ssi' => 'n',
            'perl' => 'n', 'ruby' => 'n', 'python' => 'n', 'suexec' => 'y', 'errordocs' => 0,
            'subdomain' => 'www', 'ssl' => 'n', 'ssl_letsencrypt' => 'n', 'directive_snippets_id' => 0,
            'php' => 'fast-cgi', 'server_php_id' => 0,
        ], $attrs)), 'domain_id');

        DB::table('web_domain')->where('domain_id', $id)->update([
            'document_root' => "/var/www/clients/client/web{$id}",
            'system_user' => "web{$id}", 'system_group' => 'client',
        ]);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    protected function lastDatalogNew(): array
    {
        $row = DB::table('sys_datalog')->where('dbtable', 'web_domain')->orderByDesc('datalog_id')->first();
        $this->assertNotNull($row, 'expected a web_domain datalog row');

        return unserialize($row->data)['new'];
    }

    public function test_client_key_cannot_enable_plan_flags_on_update(): void
    {
        $site = $this->seedVhost('clientA');
        $datalog = DB::table('sys_datalog')->count();

        $cases = [
            'ssl' => ['ssl' => true],
            'ssl_letsencrypt' => ['ssl_letsencrypt' => true],
            'cgi' => ['cgi' => true],
            'ssi' => ['ssi' => true],
            'perl' => ['perl' => true],
            'ruby' => ['ruby' => true],
            'python' => ['python' => true],
            'errordocs' => ['errordocs' => 1],
            'subdomain' => ['subdomain' => '*'],
            'directive_snippets_id' => ['directive_snippets_id' => 3],
            'suexec' => ['suexec' => false],
        ];

        foreach ($cases as $field => $body) {
            $response = $this->putJson("/api/v1/sites/web-domains/{$site}", $body, $this->tenantHeaders('clientA'));
            $response->assertStatus(422);
            $this->assertArrayHasKey($field, $response->json('errors'), "field {$field}");
        }

        $this->assertSame($datalog, DB::table('sys_datalog')->count());
        $this->assertSame('n', DB::table('web_domain')->where('domain_id', $site)->value('cgi'));
    }

    public function test_client_key_create_lists_every_violated_flag_and_writes_nothing(): void
    {
        $response = $this->postJson('/api/v1/sites/web-domains', [
            'domain' => 'flags.test',
            'ssl' => true,
            'cgi' => true,
            'suexec' => false,
        ], $this->tenantHeaders('clientA'));

        $response->assertStatus(422);
        $errors = $response->json('errors');
        foreach (['ssl', 'cgi', 'suexec'] as $field) {
            $this->assertArrayHasKey($field, $errors);
        }
        $this->assertSame("The SSL option is not included in the account's plan.", $errors['ssl'][0]);
        $this->assertSame("suEXEC is required by the account's plan.", $errors['suexec'][0]);
        $this->assertSame(0, DB::table('sys_datalog')->count());
        $this->assertSame(0, DB::table('web_domain')->where('domain', 'flags.test')->count());
    }

    public function test_unchanged_values_are_accepted_and_forbidden_flags_are_forced_on_save(): void
    {
        $site = $this->seedVhost('clientA', [
            'cgi' => 'y', 'ssi' => 'y', 'perl' => 'y', 'ruby' => 'y', 'python' => 'y',
            'ssl' => 'y', 'ssl_letsencrypt' => 'y', 'errordocs' => 1, 'directive_snippets_id' => 3,
            'suexec' => 'n', 'active' => 'n',
        ]);

        // cgi repeats its stored value; the change is `active`.
        $this->putJson("/api/v1/sites/web-domains/{$site}", ['cgi' => true, 'active' => true], $this->tenantHeaders('clientA'))
            ->assertStatus(200);

        $new = $this->lastDatalogNew();
        foreach (['cgi', 'ssi', 'perl', 'ruby', 'python', 'ssl', 'ssl_letsencrypt'] as $flag) {
            $this->assertSame('n', $new[$flag], "{$flag} forced off");
        }
        $this->assertSame('y', $new['suexec']);
        $this->assertSame('0', (string) $new['errordocs']);
        $this->assertSame('0', (string) $new['directive_snippets_id']);
        $this->assertSame('y', $new['active']);
    }

    public function test_client_create_stores_forced_values(): void
    {
        $this->postJson('/api/v1/sites/web-domains', ['domain' => 'forced.test'], $this->tenantHeaders('clientA'))
            ->assertStatus(201);

        $row = DB::table('web_domain')->where('domain', 'forced.test')->first();
        $this->assertSame('y', $row->suexec);
        $this->assertSame(0, (int) $row->errordocs);
        $this->assertSame('n', $row->cgi);
    }

    public function test_flags_included_in_the_plan_are_accepted(): void
    {
        $this->setFlags('clientA', [
            'limit_cgi' => 'y', 'limit_ssl' => 'y', 'limit_ssl_letsencrypt' => 'y', 'limit_hterror' => 'y',
            'limit_wildcard' => 'y', 'force_suexec' => 'n',
        ]);
        $site = $this->seedVhost('clientA');

        $this->putJson("/api/v1/sites/web-domains/{$site}", [
            'cgi' => true, 'errordocs' => 1, 'subdomain' => '*', 'suexec' => false,
        ], $this->tenantHeaders('clientA'))->assertStatus(200);

        $row = DB::table('web_domain')->where('domain_id', $site)->first();
        $this->assertSame('y', $row->cgi);
        $this->assertSame(1, (int) $row->errordocs);
        $this->assertSame('*', $row->subdomain);
        $this->assertSame('n', $row->suexec);
    }

    public function test_reseller_key_is_checked_against_the_resellers_own_plan(): void
    {
        $this->setFlags('clientA', ['limit_cgi' => 'n']);
        $this->setFlags('reseller', ['limit_cgi' => 'y', 'limit_ssi' => 'n']);
        $site = $this->seedVhost('clientA');

        $this->putJson("/api/v1/sites/web-domains/{$site}", ['cgi' => true], $this->tenantHeaders('reseller'))
            ->assertStatus(200);
        $this->assertSame('y', DB::table('web_domain')->where('domain_id', $site)->value('cgi'));

        $response = $this->putJson("/api/v1/sites/web-domains/{$site}", ['ssi' => true], $this->tenantHeaders('reseller'));
        $response->assertStatus(422);
        $this->assertArrayHasKey('ssi', $response->json('errors'));
    }

    public function test_admin_key_is_not_restricted(): void
    {
        $site = $this->seedVhost('clientA', ['perl' => 'y']);

        $this->putJson("/api/v1/sites/web-domains/{$site}", [
            'cgi' => true, 'ssl' => true, 'suexec' => false, 'errordocs' => 1, 'active' => false,
        ], $this->tenantHeaders('admin'))->assertStatus(200);

        $row = DB::table('web_domain')->where('domain_id', $site)->first();
        $this->assertSame('y', $row->cgi);
        $this->assertSame('y', $row->ssl);
        $this->assertSame('n', $row->suexec);
        $this->assertSame('y', $row->perl, 'admin saves do not force plan flags');
    }
}
