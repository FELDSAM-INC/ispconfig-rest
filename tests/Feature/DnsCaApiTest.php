<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SystemSchema;
use Tests\TestCase;

/**
 * /system/dns-cas CRUD over dns_ssl_ca (CAA policy records).
 *
 * Datalog superset note (contract + spec 008): legacy maintains dns_ssl_ca
 * with direct SQL and no sys_datalog journaling (its INSERT is broken in
 * 3.2); the API deliberately journals every write — these tests pin that
 * documented behavior.
 */
class DnsCaApiTest extends TestCase
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
    protected function seedCa(array $overrides = []): int
    {
        return (int) DB::table('dns_ssl_ca')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'active' => 'Y',
            'ca_name' => "Let's Encrypt",
            'ca_issue' => 'letsencrypt.org',
            'ca_wildcard' => 'Y',
            'ca_iodef' => '',
            'ca_critical' => 0,
        ], $overrides), 'id');
    }

    // ------------------------------------------------------------------
    // Auth + list
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/system/dns-cas')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Unauthorized', 'status' => 401]);
    }

    public function test_list_returns_data_meta_envelope_with_active_filter(): void
    {
        $this->seedCa(['ca_name' => 'Amazon', 'ca_issue' => 'amazon.com']);
        $this->seedCa(['ca_name' => 'Buypass', 'ca_issue' => 'buypass.com', 'active' => 'N']);

        $this->getJson('/api/v1/system/dns-cas', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.ca_name', 'Amazon') // default sort: ca_name asc
            ->assertJsonPath('data.0.active', true)
            ->assertJsonPath('data.0.ca_wildcard', true)
            ->assertJsonPath('data.1.active', false)
            ->assertJsonMissingPath('data.0.sys_userid')
            ->assertJsonMissingPath('data.0.sys_perm_user');

        $this->getJson('/api/v1/system/dns-cas?active=true', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.ca_issue', 'amazon.com');
    }

    public function test_list_rejects_bad_parameters_with_400_problem(): void
    {
        foreach (['sort=evil', 'order=up', 'limit=101', 'active=maybe'] as $param) {
            $this->getJson('/api/v1/system/dns-cas?'.$param, $this->authHeaders())
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
        $id = $this->seedCa(['ca_iodef' => 'mailto:security@example.com', 'ca_critical' => 1]);

        $this->getJson('/api/v1/system/dns-cas/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('ca_name', "Let's Encrypt")
            ->assertJsonPath('ca_issue', 'letsencrypt.org')
            ->assertJsonPath('ca_wildcard', true)
            ->assertJsonPath('ca_iodef', 'mailto:security@example.com')
            ->assertJsonPath('ca_critical', true)
            ->assertJsonPath('active', true)
            ->assertJsonMissingPath('sys_userid');
    }

    public function test_show_missing_returns_404_problem(): void
    {
        $this->getJson('/api/v1/system/dns-cas/999', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    public function test_create_returns_201_and_datalogs_insert(): void
    {
        $response = $this->postJson('/api/v1/system/dns-cas', [
            'ca_name' => 'ZeroSSL',
            'ca_issue' => 'sectigo.com',
            'ca_wildcard' => true,
            'active' => true,
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('ca_name', 'ZeroSSL')
            ->assertJsonPath('ca_issue', 'sectigo.com')
            ->assertJsonPath('ca_wildcard', true)
            ->assertJsonPath('ca_critical', false) // default
            ->assertJsonPath('active', true);

        $id = $response->json('id');

        // Uppercase enum storage per the DDL.
        $this->assertDatabaseHas('dns_ssl_ca', [
            'id' => $id,
            'ca_issue' => 'sectigo.com',
            'active' => 'Y',
            'ca_wildcard' => 'Y',
        ]);

        // Datalogged write — the documented superset of legacy's direct SQL.
        $row = DB::table('sys_datalog')->where('dbtable', 'dns_ssl_ca')->first();
        $this->assertNotNull($row);
        $this->assertSame('i', $row->action);
        $this->assertSame('id:'.$id, $row->dbidx);

        $data = unserialize($row->data);
        $this->assertSame(['new', 'old'], array_keys($data));
        $this->assertSame('sectigo.com', $data['new']['ca_issue']);
        $this->assertSame('Y', $data['new']['active']);
        $this->assertSame('riud', $data['new']['sys_perm_user']); // auth_preset applied
    }

    public function test_create_defaults_to_inactive_per_ddl(): void
    {
        $this->postJson('/api/v1/system/dns-cas', [
            'ca_name' => 'Plain',
            'ca_issue' => 'plain.example',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('active', false)
            ->assertJsonPath('ca_wildcard', false)
            ->assertJsonPath('ca_iodef', '');

        $this->assertDatabaseHas('dns_ssl_ca', ['ca_issue' => 'plain.example', 'active' => 'N', 'ca_wildcard' => 'N']);
    }

    public function test_create_accepts_legacy_yn_flag_strings(): void
    {
        $this->postJson('/api/v1/system/dns-cas', [
            'ca_name' => 'Flags',
            'ca_issue' => 'flags.example',
            'active' => 'Y',
            'ca_wildcard' => 'n',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('active', true)
            ->assertJsonPath('ca_wildcard', false);
    }

    public function test_create_duplicate_ca_issue_returns_409(): void
    {
        $this->seedCa(['ca_issue' => 'letsencrypt.org']);

        $this->postJson('/api/v1/system/dns-cas', [
            'ca_name' => 'Copycat',
            'ca_issue' => 'letsencrypt.org',
        ], $this->authHeaders())
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 409);

        $this->assertSame(1, DB::table('dns_ssl_ca')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_create_validation_failures_return_422(): void
    {
        $cases = [
            'missing ca_name' => [['ca_issue' => 'x.example'], 'ca_name'],
            'missing ca_issue' => [['ca_name' => 'X'], 'ca_issue'],
            'bad flag' => [['ca_name' => 'X', 'ca_issue' => 'x.example', 'active' => 'maybe'], 'active'],
        ];

        foreach ($cases as $label => [$payload, $errorField]) {
            $response = $this->postJson('/api/v1/system/dns-cas', $payload, $this->authHeaders());
            $response->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json');
            $this->assertArrayHasKey($errorField, $response->json('errors'), "case: {$label}");
        }

        $this->assertSame(0, DB::table('dns_ssl_ca')->count());
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    public function test_update_returns_200_and_datalogs_diff(): void
    {
        $id = $this->seedCa();

        $this->putJson('/api/v1/system/dns-cas/'.$id, ['active' => false, 'ca_iodef' => 'mailto:sec@x.tld'], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('active', false)
            ->assertJsonPath('ca_iodef', 'mailto:sec@x.tld')
            ->assertJsonPath('ca_name', "Let's Encrypt");

        $this->assertDatabaseHas('dns_ssl_ca', ['id' => $id, 'active' => 'N']);

        $row = DB::table('sys_datalog')->where('dbtable', 'dns_ssl_ca')->first();
        $this->assertNotNull($row);
        $this->assertSame('u', $row->action);

        $data = unserialize($row->data);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('Y', $data['old']['active']);
        $this->assertSame('N', $data['new']['active']);
    }

    public function test_update_ca_issue_to_another_records_value_returns_409(): void
    {
        $this->seedCa(['ca_issue' => 'letsencrypt.org']);
        $id = $this->seedCa(['ca_name' => 'Other', 'ca_issue' => 'other.example']);

        $this->putJson('/api/v1/system/dns-cas/'.$id, ['ca_issue' => 'letsencrypt.org'], $this->authHeaders())
            ->assertStatus(409)
            ->assertJsonPath('status', 409);

        // Re-sending its own value is fine (self excluded).
        $this->putJson('/api/v1/system/dns-cas/'.$id, ['ca_issue' => 'other.example'], $this->authHeaders())
            ->assertOk();
    }

    public function test_update_missing_returns_404_problem(): void
    {
        $this->putJson('/api/v1/system/dns-cas/999', ['active' => true], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Delete
    // ------------------------------------------------------------------

    public function test_delete_returns_204_and_datalogs_d(): void
    {
        $id = $this->seedCa();

        $this->deleteJson('/api/v1/system/dns-cas/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('dns_ssl_ca', ['id' => $id]);

        $row = DB::table('sys_datalog')->where('dbtable', 'dns_ssl_ca')->first();
        $this->assertNotNull($row);
        $this->assertSame('d', $row->action);
        $this->assertSame('id:'.$id, $row->dbidx);

        $data = unserialize($row->data);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('letsencrypt.org', $data['old']['ca_issue']);
    }

    public function test_delete_missing_returns_404_problem(): void
    {
        $this->deleteJson('/api/v1/system/dns-cas/999', [], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }
}
