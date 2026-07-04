<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SystemSchema;
use Tests\TestCase;

/**
 * /system/directive-snippets CRUD — legacy parity for the (name, type)
 * uniqueness (409), the in-use guards (409) and the update_sites forced
 * web_domain re-emission.
 */
class DirectiveSnippetApiTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'test-dev-key';

    protected function setUp(): void
    {
        parent::setUp();

        SystemSchema::create();

        config(['api.dev_key' => self::KEY]);

        DB::table('sys_user')->insert([
            'userid' => 1,
            'username' => 'apiadmin',
            'typ' => 'admin',
            'default_group' => 1,
        ]);
    }

    protected function authHeaders(): array
    {
        return ['X-API-Key' => self::KEY];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function seedSnippet(array $overrides = []): int
    {
        return (int) DB::table('directive_snippets')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'name' => 'Seeded',
            'type' => 'apache',
            'snippet' => 'SetEnv APP 1',
            'customer_viewable' => 'n',
            'required_php_snippets' => '',
            'active' => 'y',
            'master_directive_snippets_id' => 0,
            'update_sites' => 'n',
        ], $overrides), 'directive_snippets_id');
    }

    protected function seedWebDomain(array $overrides = []): int
    {
        return (int) DB::table('web_domain')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'domain' => 'site.tld',
            'type' => 'vhost',
            'directive_snippets_id' => 0,
            'active' => 'y',
        ], $overrides), 'domain_id');
    }

    // ------------------------------------------------------------------
    // Auth + list
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/system/directive-snippets')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Unauthorized', 'status' => 401]);
    }

    public function test_list_returns_data_meta_envelope_with_filters(): void
    {
        $this->seedSnippet(['name' => 'Apache A', 'type' => 'apache']);
        $this->seedSnippet(['name' => 'Php B', 'type' => 'php', 'active' => 'n']);
        $this->seedSnippet(['name' => 'Php C', 'type' => 'php']);

        $this->getJson('/api/v1/system/directive-snippets', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.name', 'Apache A') // default sort: name asc
            ->assertJsonPath('data.0.active', true)
            ->assertJsonMissingPath('data.0.directive_snippets_id')
            ->assertJsonMissingPath('data.0.master_directive_snippets_id');

        $this->getJson('/api/v1/system/directive-snippets?type=php', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/system/directive-snippets?type=php&active=true', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Php C');
    }

    public function test_list_rejects_bad_parameters_with_400_problem(): void
    {
        foreach (['sort=evil', 'order=up', 'limit=0', 'active=maybe', 'type=perl'] as $param) {
            $this->getJson('/api/v1/system/directive-snippets?'.$param, $this->authHeaders())
                ->assertStatus(400)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 400);
        }
    }

    // ------------------------------------------------------------------
    // Show
    // ------------------------------------------------------------------

    public function test_show_returns_contract_shape(): void
    {
        $id = $this->seedSnippet(['name' => 'Shown', 'type' => 'php', 'customer_viewable' => 'y']);

        $this->getJson('/api/v1/system/directive-snippets/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('name', 'Shown')
            ->assertJsonPath('type', 'php')
            ->assertJsonPath('customer_viewable', true)
            ->assertJsonPath('active', true)
            ->assertJsonPath('update_sites', false)
            ->assertJsonPath('sys_perm_user', 'riud');
    }

    public function test_show_missing_returns_404_problem(): void
    {
        $this->getJson('/api/v1/system/directive-snippets/999', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    public function test_create_returns_201_with_defaults_and_datalog(): void
    {
        $response = $this->postJson('/api/v1/system/directive-snippets', [
            'name' => 'Custom PHP',
            'type' => 'php',
            'snippet' => 'php_admin_value memory_limit 256M',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('name', 'Custom PHP')
            ->assertJsonPath('type', 'php')
            // defaults per the contract/DB: viewable n, active y, update_sites n
            ->assertJsonPath('customer_viewable', false)
            ->assertJsonPath('active', true)
            ->assertJsonPath('update_sites', false)
            ->assertJsonPath('required_php_snippets', '')
            // legacy auth_preset system fields
            ->assertJsonPath('sys_userid', 1)
            ->assertJsonPath('sys_groupid', 1)
            ->assertJsonPath('sys_perm_user', 'riud')
            ->assertJsonPath('sys_perm_group', 'riud')
            ->assertJsonPath('sys_perm_other', '');

        $id = $response->json('id');

        $row = DB::table('sys_datalog')->where('dbtable', 'directive_snippets')->first();
        $this->assertNotNull($row);
        $this->assertSame('i', $row->action);
        $this->assertSame('directive_snippets_id:'.$id, $row->dbidx);
        $this->assertSame(0, (int) $row->server_id); // snippets are global

        $data = unserialize($row->data);
        $this->assertSame(['new', 'old'], array_keys($data));
        $this->assertSame('Custom PHP', $data['new']['name']);
        $this->assertSame('y', $data['new']['active']);
        $this->assertSame('n', $data['new']['update_sites']);
    }

    public function test_create_strips_tags_and_newlines_from_name(): void
    {
        $this->postJson('/api/v1/system/directive-snippets', [
            'name' => "<b>Clean</b>\nName",
            'type' => 'proxy',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('name', 'CleanName');
    }

    public function test_create_duplicate_name_type_pair_returns_409(): void
    {
        $this->seedSnippet(['name' => 'Dup', 'type' => 'apache']);

        $this->postJson('/api/v1/system/directive-snippets', [
            'name' => 'Dup',
            'type' => 'apache',
        ], $this->authHeaders())
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 409);

        // Same name with another type is fine (uniqueness is per pair).
        $this->postJson('/api/v1/system/directive-snippets', [
            'name' => 'Dup',
            'type' => 'nginx',
        ], $this->authHeaders())->assertStatus(201);

        $this->assertSame(2, DB::table('directive_snippets')->count());
    }

    public function test_create_validation_failures_return_422(): void
    {
        $phpId = $this->seedSnippet(['name' => 'Php', 'type' => 'php']);
        $inactivePhpId = $this->seedSnippet(['name' => 'Inactive Php', 'type' => 'php', 'active' => 'n']);
        $apacheId = $this->seedSnippet(['name' => 'Apache', 'type' => 'apache']);

        $cases = [
            'missing name' => [['type' => 'php'], 'name'],
            'empty name' => [['name' => '', 'type' => 'php'], 'name'],
            'tags-only name' => [['name' => '<b></b>', 'type' => 'php'], 'name'],
            'invalid type' => [['name' => 'X', 'type' => 'perl'], 'type'],
            'missing type' => [['name' => 'X'], 'type'],
            'bad flag' => [['name' => 'X', 'type' => 'php', 'active' => 'maybe'], 'active'],
            'php list not csv' => [['name' => 'X', 'type' => 'apache', 'required_php_snippets' => 'a,b'], 'required_php_snippets'],
            'php list unknown id' => [['name' => 'X', 'type' => 'apache', 'required_php_snippets' => '999'], 'required_php_snippets'],
            'php list inactive snippet' => [['name' => 'X', 'type' => 'apache', 'required_php_snippets' => (string) $inactivePhpId], 'required_php_snippets'],
            'php list non-php snippet' => [['name' => 'X', 'type' => 'apache', 'required_php_snippets' => (string) $apacheId], 'required_php_snippets'],
        ];

        foreach ($cases as $label => [$payload, $errorField]) {
            $response = $this->postJson('/api/v1/system/directive-snippets', $payload, $this->authHeaders());
            $response->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json');
            $this->assertArrayHasKey($errorField, $response->json('errors'), "case: {$label}");
        }

        // Referencing the active php snippet is valid.
        $this->postJson('/api/v1/system/directive-snippets', [
            'name' => 'Uses Php',
            'type' => 'apache',
            'required_php_snippets' => (string) $phpId,
        ], $this->authHeaders())->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    public function test_update_returns_200_and_datalogs_diff(): void
    {
        $id = $this->seedSnippet(['name' => 'Old name']);

        $this->putJson('/api/v1/system/directive-snippets/'.$id, ['name' => 'New name'], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('name', 'New name')
            ->assertJsonPath('type', 'apache');

        $row = DB::table('sys_datalog')->where('dbtable', 'directive_snippets')->first();
        $this->assertNotNull($row);
        $this->assertSame('u', $row->action);

        $data = unserialize($row->data);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('Old name', $data['old']['name']);
        $this->assertSame('New name', $data['new']['name']);
    }

    public function test_update_to_existing_name_type_pair_returns_409(): void
    {
        $this->seedSnippet(['name' => 'Taken', 'type' => 'apache']);
        $id = $this->seedSnippet(['name' => 'Mine', 'type' => 'apache']);

        $this->putJson('/api/v1/system/directive-snippets/'.$id, ['name' => 'Taken'], $this->authHeaders())
            ->assertStatus(409)
            ->assertJsonPath('status', 409);

        // Re-sending its own pair stays valid (self excluded).
        $this->putJson('/api/v1/system/directive-snippets/'.$id, ['name' => 'Mine'], $this->authHeaders())
            ->assertOk();
    }

    public function test_deactivating_or_hiding_an_in_use_snippet_returns_409(): void
    {
        $id = $this->seedSnippet(['name' => 'Used', 'type' => 'nginx', 'customer_viewable' => 'y']);
        $this->seedWebDomain(['directive_snippets_id' => $id]);

        $this->putJson('/api/v1/system/directive-snippets/'.$id, ['active' => false], $this->authHeaders())
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->putJson('/api/v1/system/directive-snippets/'.$id, ['customer_viewable' => false], $this->authHeaders())
            ->assertStatus(409);

        // Nothing was written.
        $this->assertSame('y', DB::table('directive_snippets')->where('directive_snippets_id', $id)->value('active'));
        $this->assertSame('y', DB::table('directive_snippets')->where('directive_snippets_id', $id)->value('customer_viewable'));
        $this->assertSame(0, DB::table('sys_datalog')->count());

        // Not-in-use snippets may be deactivated and hidden freely.
        $freeId = $this->seedSnippet(['name' => 'Free', 'type' => 'nginx', 'customer_viewable' => 'y']);
        $this->putJson('/api/v1/system/directive-snippets/'.$freeId, ['active' => false, 'customer_viewable' => false], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('active', false)
            ->assertJsonPath('customer_viewable', false);
    }

    public function test_update_sites_re_emits_affected_web_domains_as_forced_datalog(): void
    {
        $id = $this->seedSnippet(['name' => 'Vhost tweaks', 'type' => 'apache']);
        $siteA = $this->seedWebDomain(['domain' => 'a.tld', 'directive_snippets_id' => $id]);
        $siteB = $this->seedWebDomain(['domain' => 'b.tld', 'directive_snippets_id' => $id]);
        $this->seedWebDomain(['domain' => 'other.tld', 'directive_snippets_id' => 0]);

        $this->putJson('/api/v1/system/directive-snippets/'.$id, [
            'snippet' => 'SetEnv APP 2',
            'update_sites' => true,
        ], $this->authHeaders())->assertOk();

        // One 'u' entry for the snippet itself...
        $this->assertSame(1, DB::table('sys_datalog')->where('dbtable', 'directive_snippets')->where('action', 'u')->count());

        // ...plus one FORCED full-record 'u' entry per affected web_domain.
        $siteRows = DB::table('sys_datalog')->where('dbtable', 'web_domain')->orderBy('datalog_id')->get();
        $this->assertCount(2, $siteRows);
        $this->assertSame(['domain_id:'.$siteA, 'domain_id:'.$siteB], $siteRows->pluck('dbidx')->all());

        foreach ($siteRows as $row) {
            $this->assertSame('u', $row->action);
            $data = unserialize($row->data);
            // Forced emission: full record, identical on both sides, 'new' first.
            $this->assertSame(['new', 'old'], array_keys($data));
            $this->assertSame($data['old'], $data['new']);
            $this->assertArrayHasKey('domain', $data['new']);
            $this->assertArrayHasKey('server_id', $data['new']);
        }

        // The web_domain table itself was not touched.
        $this->assertSame('a.tld', DB::table('web_domain')->where('domain_id', $siteA)->value('domain'));
    }

    public function test_update_sites_via_php_snippet_requirement_chain(): void
    {
        $phpId = $this->seedSnippet(['name' => 'Php base', 'type' => 'php']);
        $apacheId = $this->seedSnippet(['name' => 'Apache uses php', 'type' => 'apache', 'required_php_snippets' => (string) $phpId]);
        $site = $this->seedWebDomain(['directive_snippets_id' => $apacheId]);

        $this->putJson('/api/v1/system/directive-snippets/'.$phpId, [
            'snippet' => 'php_value x 1',
            'update_sites' => true,
        ], $this->authHeaders())->assertOk();

        $this->assertSame(
            1,
            DB::table('sys_datalog')->where('dbtable', 'web_domain')->where('dbidx', 'domain_id:'.$site)->where('action', 'u')->count()
        );
    }

    public function test_update_without_update_sites_emits_no_web_domain_entries(): void
    {
        $id = $this->seedSnippet(['name' => 'Quiet', 'type' => 'apache']);
        $this->seedWebDomain(['directive_snippets_id' => $id]);

        $this->putJson('/api/v1/system/directive-snippets/'.$id, ['snippet' => 'SetEnv B 2'], $this->authHeaders())
            ->assertOk();

        $this->assertSame(0, DB::table('sys_datalog')->where('dbtable', 'web_domain')->count());
    }

    // ------------------------------------------------------------------
    // Delete
    // ------------------------------------------------------------------

    public function test_delete_returns_204_and_datalogs_d(): void
    {
        $id = $this->seedSnippet(['name' => 'Gone', 'type' => 'proxy']);

        $this->deleteJson('/api/v1/system/directive-snippets/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('directive_snippets', ['directive_snippets_id' => $id]);

        $row = DB::table('sys_datalog')->where('dbtable', 'directive_snippets')->first();
        $this->assertNotNull($row);
        $this->assertSame('d', $row->action);
        $this->assertSame('directive_snippets_id:'.$id, $row->dbidx);

        $data = unserialize($row->data);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('Gone', $data['old']['name']);
    }

    public function test_delete_in_use_snippet_returns_409(): void
    {
        // apache snippet referenced by a web_domain
        $apacheId = $this->seedSnippet(['name' => 'Used apache', 'type' => 'apache']);
        $this->seedWebDomain(['directive_snippets_id' => $apacheId]);

        $this->deleteJson('/api/v1/system/directive-snippets/'.$apacheId, [], $this->authHeaders())
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 409);

        // php snippet required (transitively) by an in-use snippet
        $phpId = $this->seedSnippet(['name' => 'Used php', 'type' => 'php']);
        $requiringId = $this->seedSnippet(['name' => 'Requires php', 'type' => 'nginx', 'required_php_snippets' => (string) $phpId]);
        $this->seedWebDomain(['directive_snippets_id' => $requiringId]);

        $this->deleteJson('/api/v1/system/directive-snippets/'.$phpId, [], $this->authHeaders())
            ->assertStatus(409);

        $this->assertSame(3, DB::table('directive_snippets')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_php_in_use_check_is_exact_csv_membership(): void
    {
        // Snippet 1 must NOT count as in-use just because '11' contains '1'
        // (the legacy REGEXP would false-positive here — documented port fix).
        $phpId = $this->seedSnippet(['name' => 'Php one', 'type' => 'php']);
        $requiringId = $this->seedSnippet(['name' => 'Requires 11', 'type' => 'apache', 'required_php_snippets' => $phpId.'1']);
        $this->seedWebDomain(['directive_snippets_id' => $requiringId]);

        $this->deleteJson('/api/v1/system/directive-snippets/'.$phpId, [], $this->authHeaders())
            ->assertStatus(204);
    }

    public function test_delete_missing_returns_404_problem(): void
    {
        $this->deleteJson('/api/v1/system/directive-snippets/999', [], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }
}
