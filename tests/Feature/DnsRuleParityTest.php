<?php

namespace Tests\Feature;

use App\Support\ProblemType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\DnsSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Spec 033 — zone and record rule parity for scoped keys.
 *
 * US1: `update_acl` is administrator-only (dns_soa.tform.php:344) and renaming
 *      a zone needs an administrator or reseller key
 *      (dns_soa_edit.php::onBeforeUpdate); re-sending the stored value is
 *      accepted, as spec 016 does for server_id.
 * US2: the four duplicate rules the legacy forms apply to every user type —
 *      identical MX (dns_mx_edit.php:50-66), TLSA (dns_tlsa_edit.php:110-130),
 *      DKIM (dns_dkim_edit.php:128-131) and one SPF record per name
 *      (dns_spf_edit.php:165-188).
 */
class DnsRuleParityTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const FEATURE_NOT_ALLOWED = ProblemType::BASE_URI.ProblemType::FEATURE_NOT_ALLOWED;

    private int $zoneId;

    protected function setUp(): void
    {
        parent::setUp();

        DnsSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        foreach (['clientA', 'clientB', 'reseller'] as $tenant) {
            $this->assignServers($tenant, ['dns' => [1]], 1);
        }

        DB::table('server')->insert([
            'server_id' => 1, 'server_name' => 'ns1', 'dns_server' => 1, 'mirror_server_id' => 0, 'active' => 1,
        ]);

        $this->zoneId = (int) DB::table('dns_soa')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 1, 'origin' => 'parity.test.', 'ns' => 'ns1.parity.test.',
            'mbox' => 'admin.parity.test.', 'serial' => '1', 'active' => 'Y', 'update_acl' => '',
        ]), 'id');
    }

    // ------------------------------------------------------------------
    // US1 — administrator-only zone fields
    // ------------------------------------------------------------------

    public function test_update_acl_can_only_be_changed_with_an_admin_key(): void
    {
        $datalog = DB::table('sys_datalog')->count();

        foreach (['clientA', 'reseller'] as $tenant) {
            $this->putZone($tenant, ['update_acl' => '192.0.2.0/24'])
                ->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('error_types.update_acl', self::FEATURE_NOT_ALLOWED)
                ->assertJsonPath('errors.update_acl.0', 'The dynamic update ACL can only be changed with an administrator key.');
        }

        $this->assertSame($datalog, DB::table('sys_datalog')->count());
        $this->assertSame('', DB::table('dns_soa')->where('id', $this->zoneId)->value('update_acl'));

        // Re-sending the stored value (empty, or null meaning empty) is not a change.
        $this->putZone('clientA', ['update_acl' => ''])->assertOk();
        $this->putZone('clientA', ['update_acl' => null])->assertOk();

        // Admin keys may set it, and the client may then re-send that value.
        $this->putZone('admin', ['update_acl' => '192.0.2.0/24'])->assertOk();
        $this->putZone('clientA', ['update_acl' => '192.0.2.0/24'])->assertOk();
        $this->putZone('clientA', ['update_acl' => '198.51.100.0/24'])->assertStatus(422);
    }

    public function test_create_with_update_acl_is_refused_for_scoped_keys(): void
    {
        $payload = [
            'server_id' => 1, 'origin' => 'created.test', 'ns' => 'ns1.created.test',
            'mbox' => 'admin@created.test', 'update_acl' => '192.0.2.0/24',
        ];

        $this->postJson('/api/v1/dns/soa', $payload, $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonPath('error_types.update_acl', self::FEATURE_NOT_ALLOWED);

        $this->postJson('/api/v1/dns/soa', $payload, $this->tenantHeaders('admin'))->assertStatus(201);

        // An empty ACL is what the form would send: accepted for every key.
        $this->postJson('/api/v1/dns/soa', [
            'server_id' => 1, 'origin' => 'empty-acl.test', 'ns' => 'ns1.empty-acl.test',
            'mbox' => 'admin@empty-acl.test', 'update_acl' => '',
        ], $this->tenantHeaders('clientA'))->assertStatus(201);
    }

    public function test_renaming_a_zone_needs_an_admin_or_reseller_key(): void
    {
        $datalog = DB::table('sys_datalog')->count();

        $this->putZone('clientA', ['origin' => 'renamed.test'])
            ->assertStatus(422)
            ->assertJsonPath('error_types.origin', self::FEATURE_NOT_ALLOWED)
            ->assertJsonPath('errors.origin.0', 'The zone name cannot be changed. Please contact your administrator to change the zone.');

        $this->assertSame($datalog, DB::table('sys_datalog')->count());
        $this->assertSame('parity.test.', DB::table('dns_soa')->where('id', $this->zoneId)->value('origin'));

        // The stored origin in another spelling is not a change.
        $this->putZone('clientA', ['origin' => 'PARITY.test'])->assertOk();

        // The reseller manages clientA (groups include clientA's group) and may rename.
        $this->putZone('reseller', ['origin' => 'reseller-renamed.test'])->assertOk();
        $this->assertSame('reseller-renamed.test.', DB::table('dns_soa')->where('id', $this->zoneId)->value('origin'));

        $this->putZone('admin', ['origin' => 'admin-renamed.test'])->assertOk();
    }

    public function test_client_visible_zone_fields_stay_writable(): void
    {
        $this->putZone('clientA', [
            'xfer' => '192.0.2.1',
            'also_notify' => '192.0.2.2',
            'dnssec_wanted' => true,
        ])->assertOk();
    }

    // ------------------------------------------------------------------
    // US2 — record duplicate rules (every key type)
    // ------------------------------------------------------------------

    public function test_identical_mx_record_is_refused(): void
    {
        $this->postRecord('clientA', ['name' => '@', 'type' => 'MX', 'hostname' => 'mail.parity.test.', 'priority' => 10])
            ->assertStatus(201);

        $datalog = DB::table('sys_datalog')->count();

        // Same target at another priority: legacy compares `data` only.
        $this->postRecord('clientA', ['name' => '@', 'type' => 'MX', 'hostname' => 'mail.parity.test.', 'priority' => 20])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'An identical MX record already exists for this name in the zone.');

        $this->assertSame($datalog, DB::table('sys_datalog')->count());

        // Admin keys are checked too (legacy has no user-type condition).
        $this->postRecord('admin', ['name' => '@', 'type' => 'MX', 'hostname' => 'mail.parity.test.', 'priority' => 30])
            ->assertStatus(422);

        // A different target is fine.
        $this->postRecord('clientA', ['name' => '@', 'type' => 'MX', 'hostname' => 'mail2.parity.test.', 'priority' => 20])
            ->assertStatus(201);

        // Updating a record without changing it is fine (self-excluded).
        $id = (int) DB::table('dns_rr')->where('data', 'mail.parity.test.')->value('id');
        $this->putJson('/api/v1/dns/records/'.$id, ['priority' => 15], $this->tenantHeaders('clientA'))->assertOk();
    }

    public function test_identical_tlsa_and_dkim_records_are_refused(): void
    {
        $tlsa = ['name' => '_443._tcp.parity.test.', 'type' => 'TLSA', 'cert_usage' => 3, 'selector' => 1,
            'matching_type' => 1, 'hash' => str_repeat('ab', 32)];

        $this->postRecord('clientA', $tlsa)->assertStatus(201);
        $this->postRecord('clientA', $tlsa)
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'An identical TLSA record already exists for this name in the zone.');
        $this->postRecord('clientA', array_merge($tlsa, ['hash' => str_repeat('cd', 32)]))->assertStatus(201);

        $dkim = ['name' => 'sel._domainkey', 'type' => 'DKIM', 'data' => 'v=DKIM1; k=rsa; p=MIIBpublickey'];

        $this->postRecord('clientA', $dkim)->assertStatus(201);
        $this->postRecord('clientA', $dkim)
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'An identical DKIM record already exists for this name in the zone.');
        $this->postRecord('clientA', array_merge($dkim, ['name' => 'sel2._domainkey']))->assertStatus(201);
    }

    public function test_only_one_spf_record_per_name(): void
    {
        $spf = ['name' => '@', 'type' => 'SPF', 'allow_mx' => true, 'policy' => 'softfail'];

        $this->postRecord('clientA', $spf)->assertStatus(201);

        $datalog = DB::table('sys_datalog')->count();

        $this->postRecord('clientA', array_merge($spf, ['allow_a' => true]))
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'An SPF record already exists for this name in the zone.');

        $this->assertSame($datalog, DB::table('sys_datalog')->count());

        $this->postRecord('admin', $spf)->assertStatus(422);

        // Another name may have its own SPF record.
        $this->postRecord('clientA', array_merge($spf, ['name' => 'sub']))->assertStatus(201);

        // Editing the existing record is not a duplicate.
        $id = (int) DB::table('dns_rr')->where('name', 'parity.test.')->where('data', 'like', 'v=spf1%')->value('id');
        $this->putJson('/api/v1/dns/records/'.$id, ['policy' => 'fail'], $this->tenantHeaders('clientA'))->assertOk();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function putZone(string $tenant, array $payload): TestResponse
    {
        return $this->putJson('/api/v1/dns/soa/'.$this->zoneId, $payload, $this->tenantHeaders($tenant));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postRecord(string $tenant, array $payload): TestResponse
    {
        return $this->postJson(
            '/api/v1/dns/records',
            array_merge(['zone' => $this->zoneId], $payload),
            $this->tenantHeaders($tenant)
        );
    }
}
