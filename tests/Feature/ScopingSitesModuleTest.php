<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SitesSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Sites-module authorization matrix (spec 011 SC-001/SC-002/SC-004): the
 * four-key matrix over every row-scoped sites resource, nested SSL scoping
 * through the parent web-domain binding, forced stamping on create and the
 * cross-tenant mutation sweep.
 */
class ScopingSitesModuleTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    /** @var array<string, int> web_domain ids per owner (type vhost) */
    protected array $vhosts = [];

    protected function setUp(): void
    {
        parent::setUp();

        SitesSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        DB::table('server')->insert([
            'server_id' => 1, 'server_name' => 'web1', 'web_server' => 1, 'mirror_server_id' => 0, 'active' => 1,
        ]);

        foreach (['clientA' => 'a-site.test', 'clientB' => 'b-site.test'] as $owner => $domain) {
            $this->vhosts[$owner] = (int) DB::table('web_domain')->insertGetId($this->ownedBy($owner, [
                'server_id' => 1,
                'domain' => $domain,
                'type' => 'vhost',
                'active' => 'y',
            ]), 'domain_id');
        }
    }

    /**
     * @return array<string, array{table: string, pk: string, rows: array<string, array<string, mixed>>}>
     */
    protected function resources(): array
    {
        $a = $this->vhosts['clientA'];
        $b = $this->vhosts['clientB'];

        return [
            'sites/web-child-domains' => [
                'table' => 'web_domain',
                'pk' => 'domain_id',
                'rows' => [
                    'clientA' => ['server_id' => 1, 'domain' => 'sub.a-site.test', 'type' => 'subdomain', 'parent_domain_id' => $a, 'active' => 'y'],
                    'clientB' => ['server_id' => 1, 'domain' => 'sub.b-site.test', 'type' => 'subdomain', 'parent_domain_id' => $b, 'active' => 'y'],
                ],
            ],
            'sites/ftp-users' => [
                'table' => 'ftp_user',
                'pk' => 'ftp_user_id',
                'rows' => [
                    'clientA' => ['server_id' => 1, 'parent_domain_id' => $a, 'username' => 'a-ftp', 'active' => 'y'],
                    'clientB' => ['server_id' => 1, 'parent_domain_id' => $b, 'username' => 'b-ftp', 'active' => 'y'],
                ],
            ],
            'sites/shell-users' => [
                'table' => 'shell_user',
                'pk' => 'shell_user_id',
                'rows' => [
                    'clientA' => ['server_id' => 1, 'parent_domain_id' => $a, 'username' => 'a-shell', 'active' => 'y'],
                    'clientB' => ['server_id' => 1, 'parent_domain_id' => $b, 'username' => 'b-shell', 'active' => 'y'],
                ],
            ],
            'sites/databases' => [
                'table' => 'web_database',
                'pk' => 'database_id',
                'rows' => [
                    'clientA' => ['server_id' => 1, 'parent_domain_id' => $a, 'type' => 'mysql', 'database_name' => 'a_db', 'active' => 'y'],
                    'clientB' => ['server_id' => 1, 'parent_domain_id' => $b, 'type' => 'mysql', 'database_name' => 'b_db', 'active' => 'y'],
                ],
            ],
            'sites/database-users' => [
                'table' => 'web_database_user',
                'pk' => 'database_user_id',
                'rows' => [
                    'clientA' => ['server_id' => 1, 'database_user' => 'a_dbu'],
                    'clientB' => ['server_id' => 1, 'database_user' => 'b_dbu'],
                ],
            ],
            'sites/cron-jobs' => [
                'table' => 'cron',
                'pk' => 'id',
                'rows' => [
                    'clientA' => ['server_id' => 1, 'parent_domain_id' => $a, 'type' => 'url', 'command' => 'https://a-site.test/cron', 'run_min' => '*', 'run_hour' => '*', 'run_mday' => '*', 'run_month' => '*', 'run_wday' => '*', 'active' => 'y'],
                    'clientB' => ['server_id' => 1, 'parent_domain_id' => $b, 'type' => 'url', 'command' => 'https://b-site.test/cron', 'run_min' => '*', 'run_hour' => '*', 'run_mday' => '*', 'run_month' => '*', 'run_wday' => '*', 'active' => 'y'],
                ],
            ],
            'sites/web-folders' => [
                'table' => 'web_folder',
                'pk' => 'web_folder_id',
                'rows' => [
                    'clientA' => ['server_id' => 1, 'parent_domain_id' => $a, 'path' => 'protected/a', 'active' => 'y'],
                    'clientB' => ['server_id' => 1, 'parent_domain_id' => $b, 'path' => 'protected/b', 'active' => 'y'],
                ],
            ],
            'sites/web-folder-users' => [
                'table' => 'web_folder_user',
                'pk' => 'web_folder_user_id',
                'rows' => [
                    'clientA' => ['server_id' => 1, 'web_folder_id' => 1, 'username' => 'a-folder-user', 'active' => 'y'],
                    'clientB' => ['server_id' => 1, 'web_folder_id' => 2, 'username' => 'b-folder-user', 'active' => 'y'],
                ],
            ],
            'sites/webdav-users' => [
                'table' => 'webdav_user',
                'pk' => 'webdav_user_id',
                'rows' => [
                    'clientA' => ['server_id' => 1, 'parent_domain_id' => $a, 'username' => 'a-dav', 'dir' => 'a', 'active' => 'y'],
                    'clientB' => ['server_id' => 1, 'parent_domain_id' => $b, 'username' => 'b-dav', 'dir' => 'b', 'active' => 'y'],
                ],
            ],
        ];
    }

    public function test_four_key_matrix_over_web_domains(): void
    {
        $this->getJson('/api/v1/sites/web-domains', $this->tenantHeaders('admin'))
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/sites/web-domains', $this->tenantHeaders('clientA'))
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/sites/web-domains', $this->tenantHeaders('reseller'))
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->getJson("/api/v1/sites/web-domains/{$this->vhosts['clientA']}", $this->tenantHeaders('clientA'))->assertOk();
        $this->getJson("/api/v1/sites/web-domains/{$this->vhosts['clientA']}", $this->tenantHeaders('reseller'))->assertOk();
        $this->getJson("/api/v1/sites/web-domains/{$this->vhosts['clientB']}", $this->tenantHeaders('clientA'))
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
        $this->getJson("/api/v1/sites/web-domains/{$this->vhosts['clientB']}", $this->tenantHeaders('reseller'))->assertStatus(404);
    }

    public function test_four_key_matrix_over_sites_sub_resources(): void
    {
        foreach ($this->resources() as $route => $definition) {
            $ids = [];

            foreach ($definition['rows'] as $owner => $attrs) {
                $ids[$owner] = (int) DB::table($definition['table'])
                    ->insertGetId($this->ownedBy($owner, $attrs), $definition['pk']);
            }

            $this->getJson("/api/v1/{$route}", $this->tenantHeaders('admin'))
                ->assertOk()->assertJsonPath('meta.total', 2);
            $this->getJson("/api/v1/{$route}", $this->tenantHeaders('clientA'))
                ->assertOk()->assertJsonPath('meta.total', 1);
            $this->getJson("/api/v1/{$route}", $this->tenantHeaders('clientB'))
                ->assertOk()->assertJsonPath('meta.total', 1);
            $this->getJson("/api/v1/{$route}", $this->tenantHeaders('reseller'))
                ->assertOk()->assertJsonPath('meta.total', 1);

            $this->getJson("/api/v1/{$route}/{$ids['clientA']}", $this->tenantHeaders('clientA'))->assertOk();
            $this->getJson("/api/v1/{$route}/{$ids['clientB']}", $this->tenantHeaders('clientA'))->assertStatus(404);
            $this->getJson("/api/v1/{$route}/{$ids['clientA']}", $this->tenantHeaders('reseller'))->assertOk();
            $this->getJson("/api/v1/{$route}/{$ids['clientB']}", $this->tenantHeaders('reseller'))->assertStatus(404);
        }
    }

    public function test_nested_ssl_subresource_scopes_through_the_parent_binding(): void
    {
        $this->getJson("/api/v1/sites/web-domains/{$this->vhosts['clientB']}/ssl", $this->tenantHeaders('clientA'))
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');

        // The owner still reaches the subtree (empty SSL config is not 404).
        $ownStatus = $this->getJson("/api/v1/sites/web-domains/{$this->vhosts['clientB']}/ssl", $this->tenantHeaders('clientB'))->getStatusCode();
        $this->assertNotSame(404, $ownStatus);
    }

    public function test_create_web_folder_is_stamped_with_the_key_identity(): void
    {
        $this->postJson('/api/v1/sites/web-folders', [
            'parent_domain_id' => $this->vhosts['clientA'],
            'path' => 'members/a',
            // Forged ownership must be ignored (FR-012).
            'sys_userid' => 1,
            'sys_groupid' => 1,
        ], $this->tenantHeaders('clientA'))->assertStatus(201);

        $this->assertDatabaseHas('web_folder', [
            'path' => 'members/a',
            'sys_userid' => $this->tenant('clientA')['userid'],
            'sys_groupid' => $this->tenant('clientA')['groupid'],
        ]);
    }

    public function test_cross_tenant_mutation_sweep_leaves_no_datalog(): void
    {
        $ids = [];

        foreach ($this->resources() as $route => $definition) {
            $ids[$route] = (int) DB::table($definition['table'])
                ->insertGetId($this->ownedBy('clientB', $definition['rows']['clientB']), $definition['pk']);
        }

        $datalogCount = DB::table('sys_datalog')->count();

        // Every B-owned id is unreachable for A's key: PUT and DELETE 404,
        // and the failed attempts produce no datalog rows (SC-004).
        $this->putJson("/api/v1/sites/web-domains/{$this->vhosts['clientB']}", ['active' => false], $this->tenantHeaders('clientA'))
            ->assertStatus(404);
        $this->deleteJson("/api/v1/sites/web-domains/{$this->vhosts['clientB']}", [], $this->tenantHeaders('clientA'))
            ->assertStatus(404);

        foreach ($ids as $route => $id) {
            $this->putJson("/api/v1/{$route}/{$id}", ['active' => false], $this->tenantHeaders('clientA'))
                ->assertStatus(404);
            $this->deleteJson("/api/v1/{$route}/{$id}", [], $this->tenantHeaders('clientA'))
                ->assertStatus(404);
        }

        $this->assertSame($datalogCount, DB::table('sys_datalog')->count());
    }
}
