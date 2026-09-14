<?php

namespace Tests\Feature;

use App\Services\ServerAssignmentService;
use App\Support\AuthScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Server assignment resolution for non-admin keys (spec 016, data-model.md
 * "Resolution algorithm"; legacy web_vhost_domain_edit.php:115-122 reads the
 * acting identity's client row through its default group).
 */
class ServerAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        TenantSchema::create();
        $this->seedTenants();

        $servers = [
            [1, 'web1', 'web', 0, 1],
            [2, 'web2', 'web', 0, 1],
            [3, 'mail1', 'mail', 0, 1],
            [4, 'ns1', 'dns', 0, 1],
            [5, 'ns1-mirror', 'dns', 4, 1],
            [6, 'web3-inactive', 'web', 0, 0],
            [7, 'db1', 'db', 0, 1],
        ];

        foreach ($servers as [$id, $name, $role, $mirror, $active]) {
            DB::table('server')->insert([
                'server_id' => $id,
                'server_name' => $name,
                'web_server' => $role === 'web' ? 1 : 0,
                'mail_server' => $role === 'mail' ? 1 : 0,
                'dns_server' => $role === 'dns' ? 1 : 0,
                'db_server' => $role === 'db' ? 1 : 0,
                'mirror_server_id' => $mirror,
                'active' => $active,
            ]);
        }
    }

    protected function scopeFor(string $tenant): AuthScope
    {
        $t = $this->tenant($tenant);

        return new AuthScope($t['userid'], $t['groupid'], [$t['groupid']], false, $t['client_id']);
    }

    protected function setList(string $tenant, string $column, mixed $value): void
    {
        DB::table('client')->where('client_id', $this->tenant($tenant)['client_id'])->update([$column => $value]);
    }

    public function test_csv_parsing_ignores_whitespace_duplicates_empty_and_non_positive_entries(): void
    {
        $this->setList('clientA', 'web_servers', ' 2, 1,2,,0,-3 ,abc,');

        $this->assertSame([2, 1], (new ServerAssignmentService)->assignedServerIds($this->scopeFor('clientA'), 'web'));
    }

    public function test_list_order_is_preserved_and_active_flag_is_ignored(): void
    {
        $this->setList('clientA', 'web_servers', '6,2,1');

        $service = new ServerAssignmentService;

        $this->assertSame([6, 2, 1], $service->assignedServerIds($this->scopeFor('clientA'), 'web'));
        $this->assertSame(6, $service->defaultServerId($this->scopeFor('clientA'), 'web'));
    }

    public function test_deleted_mirror_and_wrong_role_servers_are_skipped(): void
    {
        $this->setList('clientA', 'web_servers', '3,99,2');
        $this->setList('clientA', 'dns_servers', '5,4');
        $this->setList('clientA', 'db_servers', '1,7');

        $service = new ServerAssignmentService;
        $scope = $this->scopeFor('clientA');

        $this->assertSame([2], $service->assignedServerIds($scope, 'web'));
        $this->assertSame([4], $service->assignedServerIds($scope, 'dns'));
        $this->assertSame([7], $service->assignedServerIds($scope, 'db'));
        $this->assertSame([], $service->assignedServerIds($scope, 'mail'));
        $this->assertNull($service->defaultServerId($scope, 'mail'));
    }

    public function test_scopes_without_client_row_and_admin_scopes_have_no_assigned_servers(): void
    {
        $this->setList('clientA', 'web_servers', '1');
        $service = new ServerAssignmentService;

        $orphan = new AuthScope(99, 99, [99], false, 0);

        $this->assertSame([], $service->assignedServerIds($orphan, 'web'));
        $this->assertNull($service->slaveDnsServerId($orphan));
        $this->assertSame([], $service->assignedServerIds(AuthScope::admin(), 'web'));
    }

    public function test_reseller_scope_uses_its_own_client_row(): void
    {
        $this->setList('reseller', 'mail_servers', '3');
        $this->setList('clientA', 'mail_servers', '');

        $service = new ServerAssignmentService;

        $this->assertSame([3], $service->assignedServerIds($this->scopeFor('reseller'), 'mail'));
        $this->assertSame([], $service->assignedServerIds($this->scopeFor('clientA'), 'mail'));
    }

    public function test_client_row_is_read_once_per_instance(): void
    {
        $this->setList('clientA', 'web_servers', '1,2');
        $this->setList('clientA', 'mail_servers', '3');
        $this->setList('clientA', 'default_slave_dnsserver', 4);

        $service = new ServerAssignmentService;
        $scope = $this->scopeFor('clientA');

        DB::enableQueryLog();
        $service->assignedServerIds($scope, 'web');
        $service->assignedServerIds($scope, 'web');
        $service->defaultServerId($scope, 'mail');
        $service->slaveDnsServerId($scope);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $clientReads = array_filter($log, fn (array $query): bool => str_contains($query['query'], 'from "client"'));
        $webServerReads = array_filter($log, fn (array $query): bool => str_contains($query['query'], '"web_server" = '));

        $this->assertCount(1, $clientReads);
        $this->assertCount(1, $webServerReads);
    }

    public function test_slave_dns_server_must_be_an_existing_non_mirror_dns_server(): void
    {
        $scope = $this->scopeFor('clientA');

        $this->setList('clientA', 'default_slave_dnsserver', 4);
        $this->assertSame(4, (new ServerAssignmentService)->slaveDnsServerId($scope));

        $this->setList('clientA', 'default_slave_dnsserver', 5);
        $this->assertNull((new ServerAssignmentService)->slaveDnsServerId($scope));

        $this->setList('clientA', 'default_slave_dnsserver', 3);
        $this->assertNull((new ServerAssignmentService)->slaveDnsServerId($scope));

        $this->setList('clientA', 'default_slave_dnsserver', 0);
        $this->assertNull((new ServerAssignmentService)->slaveDnsServerId($scope));
    }

    public function test_service_labels(): void
    {
        $service = new ServerAssignmentService;

        $this->assertSame('web', $service->serviceLabel('web'));
        $this->assertSame('mail', $service->serviceLabel('mail'));
        $this->assertSame('database', $service->serviceLabel('db'));
        $this->assertSame('DNS', $service->serviceLabel('dns'));
    }
}
