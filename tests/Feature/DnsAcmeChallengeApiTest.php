<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ClientSchema;
use Tests\Support\DnsSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * ACME DNS-01 challenges (spec 057): publishing, status, removal, and keys limited to them.
 */
class DnsAcmeChallengeApiTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const TOKEN = '9ihDbjYfTExAYeDs4DBUeuTo18KBzwvTEjUnSwd32-c';

    private const OTHER_TOKEN = 'LoqXcYV8q5ONbJQxbmR7SCTNo3tiAXDfowyjxAjEuX0';

    /** @var array<string, int> zone ids by origin */
    private array $zones = [];

    protected function setUp(): void
    {
        parent::setUp();
        ClientSchema::create();
        DnsSchema::create();
        TenantSchema::create();
        if (! Schema::hasColumn('server', 'updated')) {
            Schema::table('server', function (Blueprint $table): void {
                $table->unsignedInteger('updated')->default(0);
            });
        }
        $this->seedTenants();

        DB::table('server')->updateOrInsert(['server_id' => 1], ['server_name' => 'ns1.host.test', 'dns_server' => 1, 'active' => 1, 'mirror_server_id' => 0, 'updated' => 0]);
        $this->zone('example.com', 'clientA');
        $this->zone('sub.example.com', 'clientA');
        $this->zone('other.test', 'clientB');
    }

    private function zone(string $origin, string $owner, string $active = 'Y'): int
    {
        return $this->zones[$origin] = (int) DB::table('dns_soa')->insertGetId($this->ownedBy($owner, [
            'server_id' => 1, 'origin' => $origin.'.', 'ns' => 'ns1.host.test.', 'mbox' => 'hostmaster.'.$origin.'.',
            'serial' => 2026010101, 'active' => $active,
        ]), 'id');
    }

    /**
     * @param  array<int, string>  $zones
     * @return array<string, string>
     */
    private function acmeKey(string $owner = 'admin', array $zones = []): array
    {
        $tenant = $this->tenant($owner);
        [, $plaintext] = ApiKey::mint('acme '.$owner, $tenant['userid'], $tenant['groupid'], ApiKey::ACCESS_ACME, $zones);

        return ['X-API-Key' => $plaintext];
    }

    private function publish(array $headers, string $name, string $value = self::TOKEN)
    {
        return $this->postJson('/api/v1/dns/acme-challenges', ['name' => $name, 'value' => $value], $headers);
    }

    public function test_publishes_into_the_longest_zone_and_reports_when_the_server_applied_it(): void
    {
        $headers = $this->acmeKey();
        $response = $this->publish($headers, '_acme-challenge.WWW.sub.example.com.')
            ->assertStatus(201)
            ->assertHeader('X-Change-Set-Id')
            ->assertJson([
                'zone_id' => $this->zones['sub.example.com'],
                'zone' => 'sub.example.com',
                'name' => '_acme-challenge.www.sub.example.com',
                'value' => self::TOKEN,
                'ttl' => 60,
                'status' => 'pending',
            ]);

        $id = (int) $response->json('id');
        $this->assertDatabaseHas('dns_rr', [
            'id' => $id, 'zone' => $this->zones['sub.example.com'], 'name' => '_acme-challenge.www.sub.example.com.',
            'type' => 'TXT', 'data' => self::TOKEN, 'ttl' => 60, 'server_id' => 1, 'active' => 'Y',
            'sys_groupid' => $this->tenant('clientA')['groupid'],
        ]);
        $this->assertSame(['dns_rr:i', 'dns_soa:u'], DB::table('sys_datalog')->orderBy('datalog_id')->get()
            ->map(fn (object $entry): string => $entry->dbtable.':'.$entry->action)->all());
        $this->assertNotSame(2026010101, (int) DB::table('dns_soa')->where('id', $this->zones['sub.example.com'])->value('serial'));

        // The record alone processed is not enough: the zone serial update after it decides.
        DB::table('server')->where('server_id', 1)->update(['updated' => (int) DB::table('sys_datalog')->min('datalog_id')]);
        $this->getJson('/api/v1/dns/acme-challenges/'.$id, $headers)->assertOk()->assertJsonPath('status', 'pending');
        DB::table('server')->where('server_id', 1)->update(['updated' => (int) DB::table('sys_datalog')->max('datalog_id')]);
        $this->getJson('/api/v1/dns/acme-challenges/'.$id, $headers)->assertOk()->assertJsonPath('status', 'applied');
    }

    public function test_the_same_value_returns_the_record_and_a_second_value_adds_one(): void
    {
        $headers = $this->acmeKey();
        $first = (int) $this->publish($headers, '_acme-challenge.example.com')->assertStatus(201)->json('id');
        $entries = DB::table('sys_datalog')->count();

        $this->publish($headers, '_acme-challenge.example.com')->assertOk()->assertJsonPath('id', $first)->assertHeaderMissing('X-Change-Set-Id');
        $this->assertSame($entries, DB::table('sys_datalog')->count());

        // A wildcard and its base name share the record name with two values.
        $second = (int) $this->publish($headers, '_acme-challenge.example.com', self::OTHER_TOKEN)->assertStatus(201)->json('id');
        $this->assertNotSame($first, $second);
    }

    public function test_removes_by_name_and_value_and_by_id(): void
    {
        $headers = $this->acmeKey();
        $first = (int) $this->publish($headers, '_acme-challenge.example.com')->json('id');
        $second = (int) $this->publish($headers, '_acme-challenge.example.com', self::OTHER_TOKEN)->json('id');

        $query = '?name=_acme-challenge.example.com&value='.self::TOKEN;
        $this->deleteJson('/api/v1/dns/acme-challenges'.$query, [], $headers)->assertNoContent()->assertHeader('X-Change-Set-Id');
        $this->assertDatabaseMissing('dns_rr', ['id' => $first]);
        $this->assertDatabaseHas('dns_rr', ['id' => $second]);
        $this->assertTrue(DB::table('sys_datalog')->where('dbtable', 'dns_rr')->where('dbidx', 'id:'.$first)->where('action', 'd')->exists());
        $this->deleteJson('/api/v1/dns/acme-challenges'.$query, [], $headers)->assertNoContent();

        $this->deleteJson('/api/v1/dns/acme-challenges/'.$second, [], $headers)->assertNoContent();
        $this->assertDatabaseMissing('dns_rr', ['id' => $second]);
        $this->getJson('/api/v1/dns/acme-challenges/'.$second, $headers)->assertNotFound();

        $this->deleteJson('/api/v1/dns/acme-challenges?name=_acme-challenge.nowhere.test&value='.self::TOKEN, [], $headers)->assertNotFound();
    }

    public function test_finds_challenges_stored_relative_to_the_zone_and_ignores_other_records(): void
    {
        $headers = $this->acmeKey();
        $zone = $this->zones['example.com'];
        $relative = (int) DB::table('dns_rr')->insertGetId($this->ownedBy('clientA', [
            'zone' => $zone, 'server_id' => 1, 'name' => '_acme-challenge.www', 'type' => 'TXT', 'data' => self::TOKEN, 'ttl' => 60, 'active' => 'Y',
        ]), 'id');
        $address = (int) DB::table('dns_rr')->insertGetId($this->ownedBy('clientA', [
            'zone' => $zone, 'server_id' => 1, 'name' => 'www', 'type' => 'A', 'data' => '192.0.2.1', 'ttl' => 3600, 'active' => 'Y',
        ]), 'id');
        $text = (int) DB::table('dns_rr')->insertGetId($this->ownedBy('clientA', [
            'zone' => $zone, 'server_id' => 1, 'name' => 'example.com.', 'type' => 'TXT', 'data' => 'v=spf1 -all', 'ttl' => 3600, 'active' => 'Y',
        ]), 'id');

        $this->getJson('/api/v1/dns/acme-challenges/'.$relative, $headers)->assertOk()
            ->assertJson(['name' => '_acme-challenge.www.example.com', 'zone' => 'example.com', 'status' => 'applied']);
        $this->publish($headers, '_acme-challenge.www.example.com')->assertOk()->assertJsonPath('id', $relative);
        foreach ([$address, $text] as $other) {
            $this->getJson('/api/v1/dns/acme-challenges/'.$other, $headers)->assertNotFound();
            $this->deleteJson('/api/v1/dns/acme-challenges/'.$other, [], $headers)->assertNotFound();
        }
        $this->assertDatabaseHas('dns_rr', ['id' => $address]);
    }

    public function test_an_acme_key_reaches_only_challenges_its_change_status_and_its_identity(): void
    {
        $headers = $this->acmeKey('admin', ['Example.COM.']);
        $published = $this->publish($headers, '_acme-challenge.example.com')->assertStatus(201);

        foreach ([['GET', '/api/v1/dns/soa'], ['GET', '/api/v1/dns/records'], ['POST', '/api/v1/dns/records'], ['GET', '/api/v1/clients'],
            ['GET', '/api/v1/changes'], ['DELETE', '/api/v1/dns/soa/'.$this->zones['example.com']], ['GET', '/api/v1/me/servers']] as [$method, $uri]) {
            $this->json($method, $uri, [], $headers)->assertForbidden()
                ->assertJsonPath('detail', 'This API key may only manage ACME DNS challenges.');
        }

        $this->getJson('/api/v1/changes/'.$published->headers->get('X-Change-Set-Id'), $headers)->assertOk();
        $this->getJson('/api/v1/me', $headers)->assertOk()->assertJson(['scope' => 'admin', 'access' => 'acme', 'zones' => ['example.com']]);
        $this->assertDatabaseHas('dns_soa', ['id' => $this->zones['example.com']]);
    }

    public function test_a_client_acme_key_uses_only_the_clients_zones_and_never_a_parent_of_a_foreign_subzone(): void
    {
        $headers = $this->acmeKey('clientA');
        $this->zone('foreign.example.com', 'clientB');

        $this->publish($headers, '_acme-challenge.www.example.com')->assertStatus(201)->assertJsonPath('zone', 'example.com');
        $this->publish($headers, '_acme-challenge.other.test')->assertNotFound();
        // foreign.example.com is authoritative for the name; publishing into example.com would fail validation.
        $this->publish($headers, '_acme-challenge.www.foreign.example.com')->assertNotFound();
        $this->assertDatabaseMissing('dns_rr', ['zone' => $this->zones['example.com'], 'name' => '_acme-challenge.www.foreign.example.com.']);
    }

    public function test_a_zone_list_narrows_the_key_and_inactive_zones_are_skipped(): void
    {
        $headers = $this->acmeKey('clientA', ['example.com']);
        $this->zone('off.test', 'clientA', 'N');

        $this->publish($headers, '_acme-challenge.example.com')->assertStatus(201);
        $this->publish($headers, '_acme-challenge.www.sub.example.com')->assertNotFound();
        $this->publish($this->acmeKey('clientA'), '_acme-challenge.off.test')->assertNotFound();

        $other = (int) $this->publish($this->acmeKey('clientA'), '_acme-challenge.sub.example.com')->assertStatus(201)->json('id');
        $this->getJson('/api/v1/dns/acme-challenges/'.$other, $headers)->assertNotFound();
    }

    public function test_a_delegated_challenge_zone_takes_the_record_at_its_apex(): void
    {
        $this->zone('_acme-challenge.deleg.test', 'admin');

        $this->publish($this->acmeKey(), '_acme-challenge.deleg.test')->assertStatus(201)
            ->assertJson(['zone' => '_acme-challenge.deleg.test', 'name' => '_acme-challenge.deleg.test']);
    }

    public function test_a_zone_holds_at_most_one_hundred_challenges(): void
    {
        for ($index = 0; $index < 100; $index++) {
            DB::table('dns_rr')->insert($this->ownedBy('clientA', [
                'zone' => $this->zones['example.com'], 'server_id' => 1, 'name' => '_acme-challenge.h'.$index, 'type' => 'TXT',
                'data' => 'token'.$index, 'ttl' => 60, 'active' => 'Y',
            ]));
        }

        $this->publish($this->acmeKey(), '_acme-challenge.example.com')->assertStatus(409);
        $this->publish($this->acmeKey(), '_acme-challenge.h1.example.com', 'token1')->assertOk();
    }

    public function test_names_and_values_are_validated(): void
    {
        $headers = $this->acmeKey();

        foreach (['www.example.com', '_acme-challenge', '_acme-challenge..example.com', '_acme-challenge.exa mple.com', '_acme-challenge.-x.example.com'] as $name) {
            $this->publish($headers, $name)->assertStatus(422)->assertJsonValidationErrors(['name'], 'errors');
        }
        foreach (['', 'has space', 'quote"', str_repeat('a', 256)] as $value) {
            $this->publish($headers, '_acme-challenge.example.com', $value)->assertStatus(422)->assertJsonValidationErrors(['value'], 'errors');
        }
    }

    public function test_full_keys_use_the_endpoints_too(): void
    {
        $this->publish($this->tenantHeaders('clientA'), '_acme-challenge.example.com')->assertStatus(201);
        $this->publish($this->tenantHeaders('clientB'), '_acme-challenge.example.com')->assertNotFound();
        $this->getJson('/api/v1/dns/soa', $this->tenantHeaders('clientA'))->assertOk();
    }

    public function test_admin_creates_acme_keys_over_http(): void
    {
        $created = $this->postJson('/api/v1/system/api-keys', [
            'name' => 'vps1', 'client_id' => $this->tenant('clientA')['client_id'], 'access' => 'acme', 'zones' => ['Example.com.', 'example.com', 'sub.example.com'],
        ], $this->tenantHeaders('admin'))
            ->assertStatus(201)
            ->assertJson(['access' => 'acme', 'zones' => ['example.com', 'sub.example.com'], 'scope' => 'client']);

        $this->publish(['X-API-Key' => $created->json('key')], '_acme-challenge.example.com')->assertStatus(201);
        $this->getJson('/api/v1/system/api-keys/'.$created->json('id'), $this->tenantHeaders('admin'))->assertOk()->assertJsonPath('access', 'acme');

        $this->postJson('/api/v1/system/api-keys', ['name' => 'x', 'zones' => ['example.com']], $this->tenantHeaders('admin'))
            ->assertStatus(422)->assertJsonValidationErrors(['zones'], 'errors');
        $this->postJson('/api/v1/system/api-keys', ['name' => 'x', 'access' => 'acme', 'zones' => ['bad zone']], $this->tenantHeaders('admin'))
            ->assertStatus(422)->assertJsonValidationErrors(['zones.0'], 'errors');
        $this->postJson('/api/v1/system/api-keys', ['name' => 'x', 'access' => 'root'], $this->tenantHeaders('admin'))
            ->assertStatus(422)->assertJsonValidationErrors(['access'], 'errors');
        $this->postJson('/api/v1/system/api-keys', ['name' => 'x'], $this->tenantHeaders('admin'))
            ->assertStatus(201)->assertJson(['access' => 'full', 'zones' => []]);
    }

    public function test_the_command_line_creates_acme_keys(): void
    {
        $this->artisan('api:key:create', ['name' => 'vps2', '--acme' => true, '--zone' => ['Example.COM.']])
            ->expectsOutputToContain('Limited to ACME DNS challenges in example.com.')
            ->assertSuccessful();
        $key = ApiKey::query()->where('name', 'vps2')->firstOrFail();
        $this->assertSame(['acme', ['example.com']], [$key->access, $key->zoneList()]);

        $this->artisan('api:key:create', ['name' => 'vps3', '--zone' => ['example.com']])->assertFailed();
        $this->artisan('api:key:create', ['name' => 'vps4', '--acme' => true, '--zone' => ['bad zone']])->assertFailed();
        $this->artisan('api:key:list')->expectsOutputToContain('acme: example.com')->assertSuccessful();
    }
}
