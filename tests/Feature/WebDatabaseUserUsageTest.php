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
 * Database user usage and unlink safety (spec 039; legacy
 * database_user_del.php::onBeforeDelete()): a user a database still
 * references cannot be deleted, and the resource reports how many databases
 * depend on it.
 */
class WebDatabaseUserUsageTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const URL = '/api/v1/sites/database-users';

    /** Legacy error_del_db_user_in_use_txt. */
    private const IN_USE = 'The user cannot be deleted. It is still being used by a database.';

    protected function setUp(): void
    {
        parent::setUp();

        SitesSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        DB::table('server')->insert([
            'server_id' => 1, 'server_name' => 'web1', 'web_server' => 1, 'db_server' => 1,
            'mirror_server_id' => 0, 'active' => 1,
        ]);

        foreach (['clientA', 'clientB'] as $tenant) {
            $this->assignServers($tenant, ['web' => [1], 'db' => [1]]);
        }
    }

    private function seedUser(string $owner, string $name): int
    {
        return (int) DB::table('web_database_user')->insertGetId($this->ownedBy($owner, [
            'server_id' => 0, 'database_user' => $name, 'database_user_prefix' => '',
        ]), 'database_user_id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedDatabase(string $owner, string $name, array $overrides = []): int
    {
        return (int) DB::table('web_database')->insertGetId($this->ownedBy($owner, array_merge([
            'server_id' => 1, 'database_name' => $name, 'type' => 'mysql', 'active' => 'y',
            'parent_domain_id' => 0,
        ], $overrides)), 'database_id');
    }

    public function test_a_user_used_as_credentials_cannot_be_deleted(): void
    {
        $userId = $this->seedUser('clientA', 'shopuser');
        $this->seedDatabase('clientA', 'shop', ['database_user_id' => $userId]);

        $datalog = DB::table('sys_datalog')->count();

        $this->deleteJson(self::URL.'/'.$userId, [], $this->tenantHeaders('clientA'))
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', ProblemType::uri(ProblemType::RESOURCE_IN_USE))
            ->assertJsonPath('detail', self::IN_USE);

        $this->assertSame($datalog, DB::table('sys_datalog')->count());
        $this->assertSame(1, DB::table('web_database_user')->where('database_user_id', $userId)->count());
        $this->assertSame(1, DB::table('web_database')->count());
    }

    public function test_a_user_used_as_read_only_user_cannot_be_deleted(): void
    {
        $owner = $this->seedUser('clientA', 'owner');
        $readOnly = $this->seedUser('clientA', 'readonly');
        $this->seedDatabase('clientA', 'shop', [
            'database_user_id' => $owner, 'database_ro_user_id' => $readOnly,
        ]);

        $this->deleteJson(self::URL.'/'.$readOnly, [], $this->tenantHeaders('clientA'))
            ->assertStatus(409)
            ->assertJsonPath('type', ProblemType::uri(ProblemType::RESOURCE_IN_USE));
    }

    public function test_an_unused_user_is_deleted_and_a_freed_one_too(): void
    {
        $unused = $this->seedUser('clientA', 'spare');

        $this->deleteJson(self::URL.'/'.$unused, [], $this->tenantHeaders('clientA'))
            ->assertStatus(204);

        $userId = $this->seedUser('clientA', 'shopuser');
        $databaseId = $this->seedDatabase('clientA', 'shop', ['database_user_id' => $userId]);

        $this->deleteJson(self::URL.'/'.$userId, [], $this->tenantHeaders('clientA'))->assertStatus(409);

        DB::table('web_database')->where('database_id', $databaseId)->delete();

        $this->deleteJson(self::URL.'/'.$userId, [], $this->tenantHeaders('clientA'))->assertStatus(204);
    }

    public function test_admin_keys_are_refused_too(): void
    {
        $userId = $this->seedUser('clientA', 'shopuser');
        $this->seedDatabase('clientA', 'shop', ['database_user_id' => $userId]);

        $this->deleteJson(self::URL.'/'.$userId, [], $this->tenantHeaders('admin'))
            ->assertStatus(409)
            ->assertJsonPath('detail', self::IN_USE);
    }

    public function test_usage_count_on_the_single_resource(): void
    {
        $userId = $this->seedUser('clientA', 'shopuser');
        $unused = $this->seedUser('clientA', 'spare');

        $this->getJson(self::URL.'/'.$userId, $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('databases_in_use', 0);

        // Two databases: one as credentials, one as read-only user.
        $this->seedDatabase('clientA', 'shop', ['database_user_id' => $userId]);
        $this->seedDatabase('clientA', 'blog', ['database_ro_user_id' => $userId]);

        $this->getJson(self::URL.'/'.$userId, $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('databases_in_use', 2);

        $this->getJson(self::URL.'/'.$unused, $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('databases_in_use', 0);
    }

    public function test_a_database_naming_the_user_twice_counts_once(): void
    {
        $userId = $this->seedUser('clientA', 'shopuser');
        $this->seedDatabase('clientA', 'shop', [
            'database_user_id' => $userId, 'database_ro_user_id' => $userId,
        ]);

        $this->getJson(self::URL.'/'.$userId, $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('databases_in_use', 1);
    }

    public function test_usage_count_on_the_list_without_a_query_per_row(): void
    {
        $first = $this->seedUser('clientA', 'auser');
        $second = $this->seedUser('clientA', 'buser');
        $this->seedUser('clientA', 'cuser');

        $this->seedDatabase('clientA', 'one', ['database_user_id' => $first]);
        $this->seedDatabase('clientA', 'two', ['database_user_id' => $first]);
        $this->seedDatabase('clientA', 'three', ['database_ro_user_id' => $second]);

        DB::enableQueryLog();

        $response = $this->getJson(self::URL, $this->tenantHeaders('clientA'))->assertOk();

        // Only statements that actually read the table: Schema::hasTable()
        // issues an sqlite schema probe whose SQL merely mentions the name.
        $matched = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_contains($sql, 'from "web_database"'))
            ->values();
        $queries = $matched->count();

        DB::disableQueryLog();

        $counts = collect($response->json('data'))->pluck('databases_in_use', 'database_user')->all();
        $this->assertSame(2, $counts['auser']);
        $this->assertSame(1, $counts['buser']);
        $this->assertSame(0, $counts['cuser']);

        $this->assertLessThanOrEqual(
            1,
            $queries,
            "the counts must come from one query, not one per row:\n".$matched->implode("\n")
        );
    }

    public function test_the_count_follows_the_keys_read_scope(): void
    {
        $userId = $this->seedUser('clientA', 'shared');
        // An administrator-made cross assignment: another client's database
        // points at this user.
        $this->seedDatabase('clientB', 'foreign', ['database_user_id' => $userId]);

        // The owner cannot see that database, so its count stays 0 …
        $this->getJson(self::URL.'/'.$userId, $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('databases_in_use', 0);

        // … while an administrator sees the dependency and is refused.
        $this->getJson(self::URL.'/'.$userId, $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertJsonPath('databases_in_use', 1);

        $this->deleteJson(self::URL.'/'.$userId, [], $this->tenantHeaders('admin'))
            ->assertStatus(409);
    }
}
