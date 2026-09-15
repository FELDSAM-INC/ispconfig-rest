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
 * Database user cap for scoped keys (spec 035 US2; legacy
 * database_user_edit.php:58-63). The cap predates this spec — these tests pin
 * it against regressions and cover the `/usage/summary` count that reports it.
 */
class ClientLimitDatabaseUserTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        SitesSchema::create();
        TenantSchema::create();
        $this->seedTenants();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $name): array
    {
        return ['database_user' => $name, 'database_password' => 'Str0ng-Pass!'];
    }

    public function test_database_user_cap_matrix(): void
    {
        $this->setClientLimit('clientA', 'limit_database_user', 1);

        $this->postJson('/api/v1/sites/database-users', $this->payload('first'), $this->tenantHeaders('clientA'))
            ->assertStatus(201);

        $datalog = DB::table('sys_datalog')->count();

        $this->postJson('/api/v1/sites/database-users', $this->payload('second'), $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('type', ProblemType::uri(ProblemType::LIMIT_REACHED))
            ->assertJsonPath('limit.name', 'limit_database_user')
            ->assertJsonPath('limit.scope', 'client')
            ->assertJsonPath('limit.max', 1)
            ->assertJsonPath('limit.used', 1);

        // Refused before any write.
        $this->assertSame($datalog, DB::table('sys_datalog')->count());
        $this->assertSame(1, DB::table('web_database_user')->count());

        // Admin keys are not limited (legacy checks non-admin users only).
        $this->postJson('/api/v1/sites/database-users', $this->payload('byadmin'), $this->tenantHeaders('admin'))
            ->assertStatus(201);

        // Unlimited.
        $this->setClientLimit('clientA', 'limit_database_user', -1);
        $this->postJson('/api/v1/sites/database-users', $this->payload('third'), $this->tenantHeaders('clientA'))
            ->assertStatus(201);
    }

    public function test_reseller_cap_applies_to_its_clients(): void
    {
        $this->setClientLimit('clientA', 'limit_database_user', -1);
        $this->setClientLimit('reseller', 'limit_database_user', 1);

        $this->postJson('/api/v1/sites/database-users', $this->payload('first'), $this->tenantHeaders('clientA'))
            ->assertStatus(201);

        $this->postJson('/api/v1/sites/database-users', $this->payload('second'), $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('type', ProblemType::uri(ProblemType::LIMIT_REACHED))
            ->assertJsonPath('limit.name', 'limit_database_user')
            ->assertJsonPath('limit.scope', 'reseller');
    }

    public function test_usage_summary_reports_the_database_user_count(): void
    {
        $this->setClientLimit('clientA', 'limit_database_user', 3);

        $this->postJson('/api/v1/sites/database-users', $this->payload('first'), $this->tenantHeaders('clientA'))
            ->assertStatus(201);

        $this->getJson('/api/v1/usage/summary', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('counts.database_users', ['used' => 1, 'limit' => 3]);

        // Unlimited reports a null limit; another client's rows are not counted.
        $this->setClientLimit('clientA', 'limit_database_user', -1);

        $this->getJson('/api/v1/usage/summary', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('counts.database_users', ['used' => 1, 'limit' => null]);

        $this->getJson('/api/v1/usage/summary', $this->tenantHeaders('clientB'))
            ->assertOk()
            ->assertJsonPath('counts.database_users', ['used' => 0, 'limit' => null]);
    }
}
