<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\SitesSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Spec 020 US3 — administrator-only website settings for client and reseller
 * keys (FR-007…FR-010, FR-012; legacy web_vhost_domain.tform.php:78-95,
 * 446-600, 791-1102).
 */
class WebAdminOptionsScopedKeyTest extends TestCase
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

        $this->setResellerCanUseOptions('n');
    }

    protected function setResellerCanUseOptions(string $value): void
    {
        DB::table('sys_ini')->updateOrInsert(['sysini_id' => 1], [
            'config' => implode("\n", [
                '[sites]',
                'dbname_prefix=c[CLIENTID]',
                "reseller_can_use_options={$value}",
                '[misc]',
                'ssh_authentication=',
            ]),
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
            'server_id' => 1, 'ip_address' => '*', 'ipv6_address' => '', 'domain' => 'v'.uniqid().'.test',
            'type' => 'vhost', 'parent_domain_id' => 0, 'vhost_type' => 'name', 'hd_quota' => -1,
            'traffic_quota' => -1, 'active' => 'y', 'backup_copies' => 1, 'cgi' => 'n', 'ssi' => 'n',
            'perl' => 'n', 'ruby' => 'n', 'python' => 'n', 'suexec' => 'y', 'errordocs' => 0,
            'subdomain' => 'www', 'ssl' => 'n', 'ssl_letsencrypt' => 'n', 'directive_snippets_id' => 0,
            'php' => 'fast-cgi', 'server_php_id' => 0,
            'allow_override' => 'All', 'proxy_protocol' => 'n', 'php_fpm_use_socket' => 'y',
            'php_fpm_chroot' => 'n', 'pm' => 'ondemand', 'pm_max_children' => 10, 'pm_start_servers' => 2,
            'pm_min_spare_servers' => 1, 'pm_max_spare_servers' => 5, 'pm_process_idle_timeout' => 10,
            'pm_max_requests' => 0, 'disable_symlinknotowner' => 'n', 'php_open_basedir' => '/var/www/site/web',
            'custom_php_ini' => '', 'apache_directives' => '', 'nginx_directives' => '', 'proxy_directives' => '',
            'http_port' => 80, 'https_port' => 443, 'log_retention' => 30, 'jailkit_chroot_app_sections' => '',
            'jailkit_chroot_app_programs' => '', 'delete_unused_jailkit' => 'n',
            'ssl_state' => '', 'ssl_locality' => '', 'ssl_organisation' => '', 'ssl_organisation_unit' => '',
            'ssl_country' => '', 'ssl_domain' => '',
        ], $attrs)), 'domain_id');

        DB::table('web_domain')->where('domain_id', $id)->update([
            'document_root' => "/var/www/clients/client/web{$id}",
            'system_user' => "web{$id}", 'system_group' => 'client',
        ]);

        return $id;
    }

    protected function assertFieldError(TestResponse $response, string $field, ?string $message = null): void
    {
        $response->assertStatus(422);
        $errors = $response->json('errors') ?? [];
        $this->assertArrayHasKey($field, $errors, "expected an error on {$field}");

        if ($message !== null) {
            $this->assertContains($message, $errors[$field]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function changedOptions(): array
    {
        return [
            'allow_override' => 'None',
            'proxy_protocol' => true,
            'php_fpm_use_socket' => false,
            'php_fpm_chroot' => true,
            'pm' => 'static',
            'pm_max_children' => 20,
            'pm_start_servers' => 3,
            'pm_min_spare_servers' => 2,
            'pm_max_spare_servers' => 6,
            'pm_process_idle_timeout' => 20,
            'pm_max_requests' => 100,
            'disable_symlinknotowner' => true,
            'php_open_basedir' => '/tmp',
            'custom_php_ini' => 'memory_limit = 512M',
            'apache_directives' => 'Options -Indexes',
            'nginx_directives' => 'location /x { deny all; }',
            'proxy_directives' => '# proxy',
            'http_port' => 8080,
            'https_port' => 8443,
            'log_retention' => 7,
            'jailkit_chroot_app_sections' => 'basicshell',
            'jailkit_chroot_app_programs' => '/usr/bin/git',
            'delete_unused_jailkit' => true,
        ];
    }

    public function test_client_key_cannot_change_options_fields(): void
    {
        $site = $this->seedVhost('clientA');
        $datalog = DB::table('sys_datalog')->count();
        $headers = $this->tenantHeaders('clientA');

        foreach ($this->changedOptions() as $field => $value) {
            $this->assertFieldError(
                $this->putJson("/api/v1/sites/web-domains/{$site}", [$field => $value], $headers),
                $field,
                "The {$field} setting can only be changed by an administrator."
            );
        }

        $response = $this->putJson("/api/v1/sites/web-domains/{$site}", $this->changedOptions(), $headers);
        $response->assertStatus(422);
        $this->assertSame(array_keys($this->changedOptions()), array_values(array_intersect(array_keys($this->changedOptions()), array_keys($response->json('errors')))));

        $this->assertSame($datalog, DB::table('sys_datalog')->count());
        $this->assertSame('All', DB::table('web_domain')->where('domain_id', $site)->value('allow_override'));
    }

    public function test_current_and_default_option_values_are_accepted(): void
    {
        $headers = $this->tenantHeaders('clientA');
        $site = $this->seedVhost('clientA', ['active' => 'n']);

        $this->putJson("/api/v1/sites/web-domains/{$site}", [
            'allow_override' => 'All', 'pm' => 'ondemand', 'pm_max_children' => 10, 'http_port' => 80,
            'php_fpm_use_socket' => true, 'custom_php_ini' => '', 'php_open_basedir' => '/var/www/site/web',
            'log_retention' => 30, 'active' => true,
        ], $headers)->assertStatus(200);

        $this->postJson('/api/v1/sites/web-domains', [
            'domain' => 'defaults.test', 'allow_override' => 'All', 'pm' => 'ondemand', 'pm_max_children' => 10,
            'http_port' => 80, 'https_port' => 443, 'php_fpm_use_socket' => true, 'proxy_protocol' => false,
        ], $headers)->assertStatus(201);

        $this->assertFieldError(
            $this->postJson('/api/v1/sites/web-domains', ['domain' => 'tuned.test', 'pm_max_children' => 20], $headers),
            'pm_max_children',
            'The pm_max_children setting can only be changed by an administrator.'
        );
        $this->assertSame(0, DB::table('web_domain')->where('domain', 'tuned.test')->count());
    }

    public function test_reseller_key_cannot_change_options_by_default(): void
    {
        $site = $this->seedVhost('clientA');

        $this->assertFieldError(
            $this->putJson("/api/v1/sites/web-domains/{$site}", ['allow_override' => 'None'], $this->tenantHeaders('reseller')),
            'allow_override'
        );
    }

    public function test_reseller_key_changes_options_when_reseller_can_use_options(): void
    {
        $this->setResellerCanUseOptions('y');
        $site = $this->seedVhost('clientA');

        $this->putJson("/api/v1/sites/web-domains/{$site}", ['allow_override' => 'None', 'pm_max_children' => 20], $this->tenantHeaders('reseller'))
            ->assertStatus(200);

        $row = DB::table('web_domain')->where('domain_id', $site)->first();
        $this->assertSame('None', $row->allow_override);
        $this->assertSame(20, (int) $row->pm_max_children);

        // Plain client keys stay restricted.
        $this->assertFieldError(
            $this->putJson("/api/v1/sites/web-domains/{$site}", ['allow_override' => 'All'], $this->tenantHeaders('clientA')),
            'allow_override'
        );
    }

    public function test_ssl_tab_fields_require_the_ssl_option(): void
    {
        $site = $this->seedVhost('clientA');
        $headers = $this->tenantHeaders('clientA');
        $fields = [
            'ssl_state' => 'Prague', 'ssl_locality' => 'Prague', 'ssl_organisation' => 'Acme',
            'ssl_organisation_unit' => 'IT', 'ssl_country' => 'CZ', 'ssl_domain' => 'www.example.test',
        ];

        foreach ($fields as $field => $value) {
            $this->assertFieldError(
                $this->putJson("/api/v1/sites/web-domains/{$site}", [$field => $value], $headers),
                $field,
                "The {$field} setting can only be changed by an administrator."
            );
        }

        $this->putJson("/api/v1/sites/web-domains/{$site}", ['ssl_country' => ''], $headers)->assertStatus(200);

        $this->setFlags('clientA', ['limit_ssl' => 'y']);
        $this->putJson("/api/v1/sites/web-domains/{$site}", $fields, $headers)->assertStatus(200);
        $this->assertSame('CZ', DB::table('web_domain')->where('domain_id', $site)->value('ssl_country'));
    }

    public function test_plain_client_key_cannot_change_identity_fields_of_a_vhost(): void
    {
        $site = $this->seedVhost('clientA', ['domain' => 'identity.test']);
        $headers = $this->tenantHeaders('clientA');

        $cases = [
            'domain' => 'renamed.test',
            'ip_address' => '10.0.0.1',
            'ipv6_address' => '2001:db8::1',
            'vhost_type' => 'ip',
        ];

        foreach ($cases as $field => $value) {
            $this->assertFieldError(
                $this->putJson("/api/v1/sites/web-domains/{$site}", [$field => $value], $headers),
                $field,
                "The {$field} of this website cannot be changed by this account."
            );
        }

        // Repeating the stored identity is fine.
        $this->putJson("/api/v1/sites/web-domains/{$site}", ['domain' => 'IDENTITY.test', 'ip_address' => '*', 'active' => false], $headers)
            ->assertStatus(200);

        // Reseller keys keep the domain tab writable.
        $this->putJson("/api/v1/sites/web-domains/{$site}", ['domain' => 'renamed.test'], $this->tenantHeaders('reseller'))
            ->assertStatus(200);
        $this->assertSame('renamed.test', DB::table('web_domain')->where('domain_id', $site)->value('domain'));
    }

    public function test_identity_rule_does_not_apply_to_child_websites(): void
    {
        $parent = $this->seedVhost('clientA', ['domain' => 'parent.test']);
        $child = $this->seedVhost('clientA', [
            'type' => 'vhostsubdomain', 'parent_domain_id' => $parent, 'domain' => 'blog.parent.test', 'subdomain' => 'none',
        ]);

        $response = $this->putJson("/api/v1/sites/web-domains/{$child}", ['domain' => 'shop.parent.test'], $this->tenantHeaders('clientA'));
        $this->assertNotContains(
            'The domain of this website cannot be changed by this account.',
            $response->json('errors.domain') ?? []
        );
    }

    public function test_wildcard_subdomain_is_refused_on_child_websites(): void
    {
        $this->setFlags('clientA', ['limit_wildcard' => 'y']);
        $this->setFlags('reseller', ['limit_wildcard' => 'y']);
        $parent = $this->seedVhost('clientA', ['domain' => 'wild.test']);
        $child = $this->seedVhost('clientA', [
            'type' => 'vhostalias', 'parent_domain_id' => $parent, 'domain' => 'alias.test', 'subdomain' => 'none',
        ]);

        foreach (['clientA', 'reseller'] as $tenant) {
            $this->assertFieldError(
                $this->putJson("/api/v1/sites/web-domains/{$child}", ['subdomain' => '*'], $this->tenantHeaders($tenant)),
                'subdomain',
                'Wildcard subdomains are not available for this website type.'
            );
        }

        $this->putJson("/api/v1/sites/web-domains/{$parent}", ['subdomain' => '*'], $this->tenantHeaders('clientA'))
            ->assertStatus(200);
    }

    public function test_certificate_operations_require_the_plan(): void
    {
        $site = $this->seedVhost('clientA');
        $headers = $this->tenantHeaders('clientA');
        $sslMessage = "SSL certificates are not included in the account's plan.";
        $leMessage = "Let's Encrypt certificates are not included in the account's plan.";

        $this->postJson("/api/v1/sites/web-domains/{$site}/ssl", ['ssl_cert' => 'x', 'ssl_key' => 'y'], $headers)
            ->assertStatus(403)->assertJsonPath('detail', $sslMessage);
        $this->deleteJson("/api/v1/sites/web-domains/{$site}/ssl", [], $headers)
            ->assertStatus(403)->assertJsonPath('detail', $sslMessage);
        $this->postJson("/api/v1/sites/web-domains/{$site}/ssl/renew", [], $headers)
            ->assertStatus(403)->assertJsonPath('detail', $sslMessage);
        $this->getJson("/api/v1/sites/web-domains/{$site}/ssl", $headers)->assertStatus(204);

        $this->setFlags('clientA', ['limit_ssl' => 'y']);
        DB::table('web_domain')->where('domain_id', $site)->update(['ssl' => 'y', 'ssl_letsencrypt' => 'y']);

        $this->postJson("/api/v1/sites/web-domains/{$site}/ssl/renew", [], $headers)
            ->assertStatus(403)->assertJsonPath('detail', $leMessage);
        $this->deleteJson("/api/v1/sites/web-domains/{$site}/ssl", [], $headers)->assertStatus(204);

        $this->setFlags('clientA', ['limit_ssl_letsencrypt' => 'y']);
        $this->postJson("/api/v1/sites/web-domains/{$site}/ssl/renew", [], $headers)->assertStatus(200);
    }

    public function test_reseller_certificate_operations_use_the_resellers_plan(): void
    {
        $this->setFlags('clientA', ['limit_ssl' => 'y']);
        $site = $this->seedVhost('clientA');

        $this->deleteJson("/api/v1/sites/web-domains/{$site}/ssl", [], $this->tenantHeaders('reseller'))
            ->assertStatus(403);

        $this->setFlags('reseller', ['limit_ssl' => 'y']);
        $this->deleteJson("/api/v1/sites/web-domains/{$site}/ssl", [], $this->tenantHeaders('reseller'))
            ->assertStatus(204);
    }

    public function test_admin_key_is_not_restricted(): void
    {
        $site = $this->seedVhost('clientA', ['domain' => 'admin.test']);

        $this->putJson("/api/v1/sites/web-domains/{$site}", [
            'allow_override' => 'None', 'ssl_country' => 'CZ', 'domain' => 'admin-renamed.test', 'pm_max_children' => 30,
        ], $this->tenantHeaders('admin'))->assertStatus(200);

        $row = DB::table('web_domain')->where('domain_id', $site)->first();
        $this->assertSame('None', $row->allow_override);
        $this->assertSame('CZ', $row->ssl_country);
        $this->assertSame('admin-renamed.test', $row->domain);

        $this->deleteJson("/api/v1/sites/web-domains/{$site}/ssl", [], $this->tenantHeaders('admin'))->assertStatus(204);
    }
}
