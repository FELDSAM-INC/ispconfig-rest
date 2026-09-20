<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\ClientSchema;
use Tests\Support\SitesApiTestCase;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;

class DatabaseOperationTest extends SitesApiTestCase
{
    use TenantFixtures;

    private int $database;

    protected function seedDbUser(array $overrides = []): int
    {
        return (int) DB::table('web_database_user')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 5,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 0,
            'database_user' => 'c3app',
            'database_user_prefix' => 'c3',
            'database_password' => '*HASH',
        ], $overrides), 'database_user_id');
    }

    protected function seedDatabase(int $parentId, int $userId, array $overrides = []): int
    {
        return (int) DB::table('web_database')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 5,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'parent_domain_id' => $parentId,
            'type' => 'mysql',
            'database_name' => 'c3mydb',
            'database_name_prefix' => 'c3',
            'database_user_id' => $userId,
            'database_ro_user_id' => 0,
            'database_charset' => '',
            'remote_access' => 'n',
            'active' => 'y',
        ], $overrides), 'database_id');
    }

    protected function setUp(): void
    {
        parent::setUp();
        ClientSchema::create();
        TenantSchema::create();
        $this->seedTenants();
        $this->assignServers('clientA', ['web' => [1], 'db' => [1]]);
        $parent = $this->seedVhost($this->ownedBy('clientA'));
        $user = $this->seedDbUser($this->ownedBy('clientA'));
        $this->database = $this->seedDatabase($parent, $user, $this->ownedBy('clientA', ['database_name' => 'c'.$this->tenant('clientA')['client_id'].'mydb', 'database_name_prefix' => 'c'.$this->tenant('clientA')['client_id']]));
        DB::table('api_database_workers')->insert(['server_id' => 1, 'heartbeat' => time()]);
    }

    private function path(?string $job = null): string
    {
        return '/api/v1/sites/databases/'.$this->database.'/operations'.($job === null ? '' : '/'.$job);
    }

    public function test_operations_require_auth_and_owned_writable_database(): void
    {
        $this->postJson($this->path(), ['action' => 'export'])->assertUnauthorized();
        $this->postJson($this->path(), ['action' => 'export'], $this->tenantHeaders('clientB'))->assertNotFound();
        DB::table('web_database')->where('database_id', $this->database)->update(['sys_perm_group' => 'r', 'sys_perm_user' => 'r']);
        $this->postJson($this->path(), ['action' => 'export'], $this->tenantHeaders('clientA'))->assertForbidden();
        $this->assertDatabaseCount('api_database_operations', 0);
    }

    public function test_export_status_download_and_tenant_scope(): void
    {
        $job = $this->postJson($this->path(), ['action' => 'export'], $this->tenantHeaders('clientA'))->assertCreated()->assertJsonPath('status', 'queued')->json('id');
        $this->getJson($this->path($job), $this->tenantHeaders('clientA'))->assertOk()->assertJsonPath('action', 'export')->assertJsonMissingPath('sys_groupid');
        $this->getJson($this->path($job), $this->tenantHeaders('clientB'))->assertNotFound();
        DB::table('web_database')->where('database_id', $this->database)->update(['sys_perm_other' => 'ru']);
        $this->getJson($this->path($job), $this->tenantHeaders('clientB'))->assertNotFound();
        $this->postJson($this->path(), ['action' => 'export'], $this->tenantHeaders('clientB'))->assertForbidden();
        $this->getJson($this->path($job).'/download', $this->tenantHeaders('clientA'))->assertConflict();
        $this->postJson($this->path(), ['action' => 'export'], $this->tenantHeaders('clientA'))->assertConflict();
        DB::table('api_database_operations')->where('id', $job)->update(['status' => 'complete']);
        $dump = gzencode('CREATE TABLE example(id INT);');
        DB::table('api_database_operation_chunks')->insert(['operation_id' => $job, 'sequence' => 0, 'content' => base64_encode($dump)]);
        $response = $this->get($this->path($job).'/download', $this->tenantHeaders('clientA'))->assertOk()->assertHeader('Content-Type', 'application/gzip');
        $this->assertSame($dump, $response->streamedContent());
        DB::table('api_database_operations')->where('id', $job)->update(['expires_at' => time() - 1]);
        $this->getJson($this->path($job), $this->tenantHeaders('clientA'))->assertNotFound();
        $this->assertDatabaseCount('sys_datalog', 0);
    }

    public function test_copy_provisions_new_database_and_keeps_source_unchanged(): void
    {
        $before = (array) DB::table('web_database')->where('database_id', $this->database)->first();
        $job = $this->postJson($this->path(), ['action' => 'copy', 'database_name' => 'copied'], $this->tenantHeaders('clientA'))->assertCreated()->json();
        $this->assertNotSame($this->database, $job['target_database_id']);
        $copy = (array) DB::table('web_database')->where('database_id', $job['target_database_id'])->first();
        $this->assertStringEndsWith('copied', $copy['database_name']);
        $this->assertSame($before['database_user_id'], $copy['database_user_id']);
        $this->assertSame($before['parent_domain_id'], $copy['parent_domain_id']);
        $this->assertSame($before, (array) DB::table('web_database')->where('database_id', $this->database)->first());
        $this->assertDatabaseHas('sys_datalog', ['dbtable' => 'web_database', 'action' => 'i']);
        $this->postJson('/api/v1/sites/databases/'.$job['target_database_id'].'/operations', ['action' => 'import', 'confirm' => true, 'dump_base64' => base64_encode('SELECT 1;')], $this->tenantHeaders('clientA'))->assertConflict();
    }

    public function test_copy_respects_limits_duplicate_names_and_locked_account(): void
    {
        $this->postJson($this->path(), ['action' => 'copy', 'database_name' => 'mydb'], $this->tenantHeaders('clientA'))->assertUnprocessable();
        $this->setClientLimit('clientA', 'limit_database', 1);
        $this->postJson($this->path(), ['action' => 'copy', 'database_name' => 'copy'], $this->tenantHeaders('clientA'))->assertForbidden();
        $this->setClientLimit('clientA', 'limit_database', -1);
        DB::table('client')->where('client_id', $this->tenant('clientA')['client_id'])->update(['locked' => 'y']);
        $this->postJson($this->path(), ['action' => 'export'], $this->tenantHeaders('clientA'))->assertForbidden();
        $this->assertDatabaseCount('api_database_operations', 0);
        $this->assertDatabaseCount('sys_datalog', 0);
    }

    public function test_import_requires_confirmation_and_bounded_plain_sql(): void
    {
        foreach ([['action' => 'import'], ['action' => 'import', 'confirm' => true, 'dump_base64' => '!'], ['action' => 'import', 'confirm' => true, 'dump_base64' => base64_encode("SELECT\0")], ['action' => 'copy'], ['action' => 'export', 'database_name' => 'unexpected']] as $payload) {
            $this->postJson($this->path(), $payload, $this->tenantHeaders('clientA'))->assertUnprocessable();
        }
        $sql = 'CREATE TABLE example (id INT);';
        $job = $this->postJson($this->path(), ['action' => 'import', 'confirm' => true, 'dump_base64' => base64_encode($sql)], $this->tenantHeaders('clientA'))->assertCreated()->json('id');
        $this->assertSame($sql, base64_decode(DB::table('api_database_operation_chunks')->where('operation_id', $job)->value('content')));
    }

    public function test_gzip_upload_is_stored_without_exposing_its_contents(): void
    {
        $gzip = gzencode('CREATE TABLE private_data(id INT);');
        $job = $this->postJson($this->path(), ['action' => 'import', 'confirm' => true, 'dump_base64' => base64_encode($gzip)], $this->tenantHeaders('clientA'))
            ->assertCreated()->assertJsonMissingPath('dump_base64')->json('id');
        $this->assertSame($gzip, base64_decode(DB::table('api_database_operation_chunks')->where('operation_id', $job)->value('content')));
    }

    public function test_capabilities_are_server_specific_and_disappear_without_worker(): void
    {
        $this->getJson('/api/v1/sites/databases/'.$this->database, $this->tenantHeaders('clientA'))->assertOk()->assertJsonPath('operations', ['import', 'export', 'copy']);
        DB::table('api_database_workers')->update(['heartbeat' => time() - 200]);
        $this->app->forgetScopedInstances();
        $this->getJson('/api/v1/sites/databases/'.$this->database, $this->tenantHeaders('clientA'))->assertOk()->assertJsonPath('operations', []);
        $this->postJson($this->path(), ['action' => 'export'], $this->tenantHeaders('clientA'))->assertConflict();
    }
}
