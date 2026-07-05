<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DnsSchema;
use Tests\TestCase;

/**
 * Spec 013 — DNS validation hardening.
 *
 * US1: BIND-safety per-field rules — the four 2026-07-05 incident payloads
 *      (DS base64-digest, TLSA 'somehashstring', SSHFP 'fingerprinthash',
 *      NAPTR undelimited regexp) are rejected with 422 naming the field and
 *      writing no sys_datalog row; corrected variants store byte-exact data.
 * US2: deactivation tolerance — the same four payloads seeded as raw
 *      dns_rr rows can always be deactivated via PUT {"active": false}
 *      (the exact incident recovery flow), and updates never rewrite
 *      stored data they cannot parse (FR-013).
 * US3: 3.3.1p1 zone-level parity — CNAME conflict both directions across
 *      the three legacy name spellings, apex, target existence, A/AAAA/
 *      ALIAS/CAA duplicates, SRV target length, DMARC prerequisites.
 */
class DnsRecordHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'test-dev-key';

    /** The exact stored `data` of the four incident records. */
    protected const INCIDENT_DS_DATA = '2371 13 2 mdsswUyr3DPW132mOi8V9xESWE8jTo0dxCjjnopKl+GqJxpVXckHAeF+KkxLbxILfDLUT0rAK9iUzy1L53eKGQ==';

    protected const INCIDENT_TLSA_DATA = '3 1 1 somehashstring';

    protected const INCIDENT_SSHFP_DATA = '4 2 fingerprinthash';

    protected const INCIDENT_NAPTR_DATA = '100 "u" "E2U+sip" "sip:info@example.com" .';

    protected int $zoneId;

    protected function setUp(): void
    {
        parent::setUp();

        DnsSchema::create();

        config(['api.dev_key' => self::KEY]);

        DB::table('sys_user')->insert([
            'userid' => 1,
            'username' => 'apiadmin',
            'typ' => 'admin',
            'default_group' => 1,
        ]);

        DB::table('server')->insert([
            ['server_id' => 1, 'server_name' => 'dns1', 'dns_server' => 1, 'mirror_server_id' => 0, 'active' => 1],
        ]);

        $this->zoneId = (int) DB::table('dns_soa')->insertGetId([
            'sys_userid' => 1,
            'sys_groupid' => 5,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'origin' => 'example.com.',
            'ns' => 'ns1.example.com.',
            'mbox' => 'admin.example.com.',
            'serial' => 2024010101,
            'active' => 'Y',
        ], 'id');
    }

    protected function authHeaders(): array
    {
        return ['X-API-Key' => self::KEY];
    }

    protected function seedRecord(array $overrides = []): int
    {
        return (int) DB::table('dns_rr')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 5,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'zone' => $this->zoneId,
            'name' => 'www',
            'type' => 'A',
            'data' => '192.0.2.1',
            'aux' => 0,
            'ttl' => 3600,
            'active' => 'Y',
            'stamp' => '2024-01-01 00:00:00',
            'serial' => 2024010101,
        ], $overrides), 'id');
    }

    /**
     * The four incident records seeded raw into dns_rr, exactly as the
     * legacy panel stored them (bypassing the API).
     *
     * @return array<string, int> record id by label
     */
    protected function seedIncidentRows(): array
    {
        return [
            'DS' => $this->seedRecord(['name' => 'example.com.', 'type' => 'DS', 'data' => self::INCIDENT_DS_DATA]),
            'TLSA' => $this->seedRecord(['name' => '_443._tcp.example.com.', 'type' => 'TLSA', 'data' => self::INCIDENT_TLSA_DATA]),
            'SSHFP' => $this->seedRecord(['name' => 'host', 'type' => 'SSHFP', 'data' => self::INCIDENT_SSHFP_DATA]),
            'NAPTR' => $this->seedRecord(['name' => 'sip', 'type' => 'NAPTR', 'data' => self::INCIDENT_NAPTR_DATA, 'aux' => 100]),
        ];
    }

    protected function postRecord(array $payload)
    {
        return $this->postJson('/api/v1/dns/records', array_merge(['zone' => $this->zoneId], $payload), $this->authHeaders());
    }

    protected function assertRejected(array $payload, string $errorField, string $label = ''): void
    {
        $before = DB::table('sys_datalog')->count();

        $response = $this->postRecord($payload);
        $response->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 422);

        $this->assertArrayHasKey($errorField, $response->json('errors'), "case: {$label}");
        $this->assertSame($before, DB::table('sys_datalog')->count(), "datalog silent: {$label}");
    }

    protected function zoneSerial(): string
    {
        return (string) DB::table('dns_soa')->where('id', $this->zoneId)->value('serial');
    }

    // ==================================================================
    // US1 — the four incident payloads (SC-001)
    // ==================================================================

    public function test_incident_payloads_are_rejected_on_create_naming_the_field(): void
    {
        $this->assertRejected([
            'name' => 'example.com.', 'type' => 'DS',
            'key_tag' => 2371, 'algorithm' => 13, 'digest_type' => 2,
            'digest' => 'mdsswUyr3DPW132mOi8V9xESWE8jTo0dxCjjnopKl+GqJxpVXckHAeF+KkxLbxILfDLUT0rAK9iUzy1L53eKGQ==',
        ], 'digest', 'incident DS: base64 DNSKEY in the hex digest field');

        $this->assertRejected([
            'name' => '_443._tcp.example.com.', 'type' => 'TLSA',
            'cert_usage' => 3, 'selector' => 1, 'matching_type' => 1,
            'hash' => 'somehashstring',
        ], 'hash', 'incident TLSA: somehashstring');

        $this->assertRejected([
            'name' => 'host', 'type' => 'SSHFP',
            'algorithm' => 4, 'hash_type' => 2,
            'hash' => 'fingerprinthash',
        ], 'hash', 'incident SSHFP: fingerprinthash');

        $this->assertRejected([
            'name' => 'sip', 'type' => 'NAPTR',
            'order' => 100, 'naptr_flag' => 'U', 'service' => 'E2U+sip',
            'regexp' => 'sip:info@example.com',
        ], 'regexp', 'incident NAPTR: undelimited regexp');

        $this->assertSame(0, DB::table('dns_rr')->count());
        $this->assertSame('2024010101', $this->zoneSerial());
    }

    public function test_corrected_incident_variants_store_byte_exact_data(): void
    {
        $digest = strtolower(str_repeat('0123456789abcdef', 4)); // 64 hex

        $this->postRecord([
            'name' => 'example.com.', 'type' => 'DS',
            'key_tag' => 2371, 'algorithm' => 13, 'digest_type' => 2, 'digest' => $digest,
        ])->assertStatus(201)->assertJsonPath('data', "2371 13 2 {$digest}");

        $hash = str_repeat('ab', 32); // 64 hex
        $this->postRecord([
            'name' => '_443._tcp.example.com.', 'type' => 'TLSA',
            'cert_usage' => 3, 'selector' => 1, 'matching_type' => 1, 'hash' => $hash,
        ])->assertStatus(201)->assertJsonPath('data', "3 1 1 {$hash}");

        $fingerprint = str_repeat('cd', 32); // 64 hex for hash_type 2
        $this->postRecord([
            'name' => 'host', 'type' => 'SSHFP',
            'algorithm' => 4, 'hash_type' => 2, 'hash' => $fingerprint,
        ])->assertStatus(201)->assertJsonPath('data', "4 2 {$fingerprint}");

        $this->postRecord([
            'name' => 'sip', 'type' => 'NAPTR',
            'order' => 100, 'pref' => 10, 'naptr_flag' => 'U', 'service' => 'E2U+sip',
            'regexp' => '!^.*$!sip:info@example.com!',
        ])->assertStatus(201)->assertJsonPath('data', '10 "U" "E2U+sip" "!^.*$!sip:info@example.com!" .');
    }

    // ==================================================================
    // US1 — DS / TLSA / SSHFP matrices (FR-002..FR-004, NC-1)
    // ==================================================================

    public function test_ds_digest_matrix(): void
    {
        $base = ['name' => 'example.com.', 'type' => 'DS', 'key_tag' => 2371, 'algorithm' => 13];

        $this->assertRejected($base + ['digest_type' => 2, 'digest' => str_repeat('a', 63)], 'digest', 'odd length');
        $this->assertRejected($base + ['digest_type' => 2, 'digest' => str_repeat('a', 40)], 'digest', '40 hex but type 2 needs 64');
        $this->assertRejected($base + ['digest_type' => 1, 'digest' => str_repeat('a', 64)], 'digest', '64 hex but type 1 needs 40');
        // NC-1: unknown digest types are rejected outright (3 = GOST,
        // deprecated; BIND >= 9.16 rejects it).
        $this->assertRejected($base + ['digest_type' => 3, 'digest' => str_repeat('a', 64)], 'digest_type', 'GOST rejected');
        $this->assertRejected($base + ['digest_type' => 0, 'digest' => str_repeat('a', 64)], 'digest_type', 'type 0 rejected');

        foreach ([1 => 40, 2 => 64, 4 => 96] as $digestType => $length) {
            $digest = str_repeat('f', $length);
            $this->postRecord($base + ['digest_type' => $digestType, 'digest' => $digest])
                ->assertStatus(201)
                ->assertJsonPath('data', "2371 13 {$digestType} {$digest}");
        }
    }

    public function test_tlsa_hash_matrix(): void
    {
        $base = ['name' => '_443._tcp.example.com.', 'type' => 'TLSA', 'cert_usage' => 3, 'selector' => 1];

        $this->assertRejected($base + ['matching_type' => 1, 'hash' => str_repeat('a', 63)], 'hash', '63 hex');
        $this->assertRejected($base + ['matching_type' => 2, 'hash' => str_repeat('a', 64)], 'hash', '64 hex but mt 2 needs 128');
        $this->assertRejected($base + ['matching_type' => 0, 'hash' => 'abc'], 'hash', 'mt 0 odd length');
        $this->assertRejected($base + ['matching_type' => 0, 'hash' => 'nothex!'], 'hash', 'mt 0 non-hex');

        $this->postRecord($base + ['matching_type' => 1, 'hash' => str_repeat('a', 64)])->assertStatus(201);
        $this->postRecord(['name' => '_25._tcp.example.com.'] + $base + ['matching_type' => 2, 'hash' => str_repeat('b', 128)])->assertStatus(201);
        // matching_type 0 (exact certificate): any even-length hex.
        $this->postRecord(['name' => '_465._tcp.example.com.'] + $base + ['matching_type' => 0, 'hash' => 'abcdef0123'])->assertStatus(201);
    }

    public function test_sshfp_hash_matrix(): void
    {
        $base = ['name' => 'host', 'type' => 'SSHFP', 'algorithm' => 4];

        $this->assertRejected($base + ['hash_type' => 1, 'hash' => str_repeat('a', 64)], 'hash', '64 hex but ht 1 needs 40');
        $this->assertRejected($base + ['hash_type' => 2, 'hash' => str_repeat('a', 40)], 'hash', '40 hex but ht 2 needs 64');
        // NC-1: hash_type 0 has no deterministic length — rejected.
        $this->assertRejected($base + ['hash_type' => 0, 'hash' => str_repeat('a', 40)], 'hash_type', 'ht 0 rejected');

        $this->postRecord($base + ['hash_type' => 1, 'hash' => str_repeat('a', 40)])->assertStatus(201);
        $this->postRecord(['name' => 'host2'] + $base + ['hash_type' => 2, 'hash' => str_repeat('b', 64)])->assertStatus(201);
    }

    // ==================================================================
    // US1 — NAPTR + DNSKEY matrices (FR-005..FR-007)
    // ==================================================================

    public function test_naptr_matrix(): void
    {
        $base = ['name' => 'sip', 'type' => 'NAPTR', 'order' => 100, 'pref' => 10, 'service' => 'E2U+sip'];

        $this->assertRejected($base + ['regexp' => '!^.*$!sip:x'], 'regexp', 'unterminated delimiters');
        $this->assertRejected($base + ['regexp' => '!^.*$/sip:x/'], 'regexp', 'mismatched delimiters');
        $this->assertRejected($base + ['regexp' => '!^.*$!sip:x!', 'replacement' => 'backup.example.com'], 'regexp', 'regexp XOR replacement');
        $this->assertRejected($base + ['naptr_flag' => 'U', 'replacement' => 'backup.example.com'], 'naptr_flag', 'flag U without regexp');
        $this->assertRejected($base + ['naptr_flag' => 'S', 'regexp' => '!^.*$!sip:x!'], 'naptr_flag', 'flag S with regexp');
        $this->assertRejected(['service' => 'E2U+sip"x'] + $base + ['regexp' => '!^.*$!sip:x!'], 'service', 'quote in service');

        // Valid: terminal U rule, i-flag variant, S rule with replacement,
        // replacement-only form.
        $this->postRecord($base + ['naptr_flag' => 'U', 'regexp' => '!^.*$!sip:info@example.com!'])
            ->assertStatus(201)
            ->assertJsonPath('data', '10 "U" "E2U+sip" "!^.*$!sip:info@example.com!" .');
        $this->postRecord(['name' => 'sip2'] + $base + ['naptr_flag' => 'U', 'regexp' => '!^.*$!sip:info@example.com!i'])
            ->assertStatus(201);
        $this->postRecord(['name' => 'sip3'] + $base + ['naptr_flag' => 'S', 'replacement' => '_sip._udp.example.com.'])
            ->assertStatus(201)
            ->assertJsonPath('data', '10 "S" "E2U+sip" "" _sip._udp.example.com.');
        $this->postRecord(['name' => 'sip4'] + $base + ['replacement' => 'backup.example.com'])
            ->assertStatus(201);
    }

    public function test_dnskey_matrix(): void
    {
        $base = ['name' => 'example.com.', 'type' => 'DNSKEY'];
        $key = 'mdsswUyr3DPW132mOi8V9xESWE8jTo0dxCjjnopKl+GqJxpVXckHAeF+KkxLbxILfDLUT0rAK9iUzy1L53eKGQ==';

        $this->assertRejected($base + ['data' => 'free text key'], 'data', 'not a DNSKEY structure');
        $this->assertRejected($base + ['data' => '257 2 13 '.$key], 'data', 'protocol != 3');
        $this->assertRejected($base + ['data' => '257 3 13 !!broken-base64!!'], 'data', 'broken base64');

        $this->postRecord($base + ['data' => '257 3 13 '.$key])
            ->assertStatus(201)
            ->assertJsonPath('data', '257 3 13 '.$key);
    }

    // ==================================================================
    // US1 — quoted-string types + LOC (FR-008..FR-010)
    // ==================================================================

    public function test_txt_and_dkim_reject_embedded_quotes_and_newlines(): void
    {
        $this->assertRejected(['name' => 'txt', 'type' => 'TXT', 'data' => 'has "inner" quotes'], 'data', 'TXT quote');
        $this->assertRejected(['name' => 'txt', 'type' => 'TXT', 'data' => "line1\nline2"], 'data', 'TXT newline');
        $this->assertRejected(['name' => 'k._domainkey', 'type' => 'DKIM', 'data' => "v=DKIM1;\np=x"], 'data', 'DKIM newline');
        $this->assertRejected(['name' => 'k._domainkey', 'type' => 'DKIM', 'data' => 'v=DKIM1; p="key"'], 'data', 'DKIM quote');

        // Accidentally wrapped TXT is stripped (legacy parity), then clean.
        $this->postRecord(['name' => 'txt', 'type' => 'TXT', 'data' => '"wrapped value"'])
            ->assertStatus(201)
            ->assertJsonPath('data', 'wrapped value');

        $this->postRecord(['name' => 'k._domainkey', 'type' => 'DKIM', 'data' => 'v=DKIM1; t=s; p=MIIBIjANBg'])
            ->assertStatus(201)
            ->assertJsonPath('data', 'v=DKIM1; t=s; p=MIIBIjANBg');
    }

    public function test_caa_and_hinfo_reject_zone_breaking_values(): void
    {
        $caa = ['name' => 'example.com.', 'type' => 'CAA', 'caa_flag' => 0];

        $this->assertRejected($caa + ['caa_type' => 'issue', 'ca_issuer' => 'letsencrypt.org"x'], 'ca_issuer', 'CAA quote');
        $this->assertRejected($caa + ['caa_type' => 'issue', 'ca_issuer' => 'lets\\encrypt.org'], 'ca_issuer', 'CAA backslash');
        $this->assertRejected($caa + ['caa_type' => 'issue', 'ca_issuer' => 'not a domain at all'], 'ca_issuer', 'CAA issuer shape');
        $this->assertRejected($caa + ['caa_type' => 'iodef', 'additional' => 'not-a-url'], 'additional', 'iodef not a URL');
        $this->assertRejected($caa + ['caa_type' => 'iodef', 'additional' => 'mailto:not-an-email'], 'additional', 'iodef bad mailto');

        $hinfo = ['name' => 'host', 'type' => 'HINFO'];
        $this->assertRejected($hinfo + ['cpu' => 'INTEL"', 'os' => 'LINUX'], 'cpu', 'HINFO cpu quote');
        $this->assertRejected($hinfo + ['cpu' => 'INTEL', 'os' => "LI\nNUX"], 'os', 'HINFO os newline');

        // Clean values compose byte-identically to the pre-feature output.
        $this->postRecord($caa + ['caa_type' => 'issue', 'ca_issuer' => 'letsencrypt.org'])
            ->assertStatus(201)
            ->assertJsonPath('data', '0 issue "letsencrypt.org"');
        $this->postRecord($caa + ['caa_type' => 'iodef', 'additional' => 'mailto:security@example.com'])
            ->assertStatus(201)
            ->assertJsonPath('data', '0 iodef "mailto:security@example.com"');
        $this->postRecord($hinfo + ['cpu' => 'INTEL-386', 'os' => 'Linux'])
            ->assertStatus(201)
            ->assertJsonPath('data', '"INTEL-386" "Linux"');
    }

    public function test_loc_matrix(): void
    {
        $base = ['name' => 'example.com.', 'type' => 'LOC'];

        $this->assertRejected($base + ['data' => 'somewhere over the rainbow'], 'data', 'garbage LOC');
        $this->assertRejected($base + ['data' => '51 30 12.748 X 0 7 39.612 W 0.00m'], 'data', 'bad hemisphere');

        $this->postRecord($base + ['data' => '51 30 12.748 N 0 7 39.612 W 0.00m'])
            ->assertStatus(201)
            ->assertJsonPath('data', '51 30 12.748 N 0 7 39.612 W 0.00m');
    }

    // ==================================================================
    // US1 — update validates submitted fields with the same rules
    // ==================================================================

    public function test_update_validates_submitted_fields_like_create(): void
    {
        $id = $this->seedRecord(['name' => 'example.com.', 'type' => 'DS', 'data' => '2371 13 2 '.str_repeat('a', 64)]);

        $response = $this->putJson('/api/v1/dns/records/'.$id, ['digest' => 'somehashstring'], $this->authHeaders());
        $response->assertStatus(422);
        $this->assertArrayHasKey('digest', $response->json('errors'));

        // Cross-field: submitting only digest_type against the stored
        // 64-char digest must 422 on digest_type.
        $response = $this->putJson('/api/v1/dns/records/'.$id, ['digest_type' => 1], $this->authHeaders());
        $response->assertStatus(422);
        $this->assertArrayHasKey('digest_type', $response->json('errors'));

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    // ==================================================================
    // US2 — deactivation tolerance (FR-012, SC-002)
    // ==================================================================

    public function test_incident_rows_can_always_be_deactivated_with_data_untouched(): void
    {
        $expected = [
            'DS' => self::INCIDENT_DS_DATA,
            'TLSA' => self::INCIDENT_TLSA_DATA,
            'SSHFP' => self::INCIDENT_SSHFP_DATA,
            'NAPTR' => self::INCIDENT_NAPTR_DATA,
        ];

        foreach ($this->seedIncidentRows() as $label => $id) {
            $this->putJson('/api/v1/dns/records/'.$id, ['active' => false], $this->authHeaders())
                ->assertOk()
                ->assertJsonPath('active', false);

            $row = DB::table('dns_rr')->where('id', $id)->first();
            $this->assertSame('N', $row->active, "deactivated: {$label}");
            $this->assertSame($expected[$label], $row->data, "data byte-identical: {$label}");
        }

        // Every deactivation bumped the zone serial (legacy parity).
        $this->assertSame(date('Ymd').'04', $this->zoneSerial());
    }

    public function test_partial_updates_of_untouched_garbage_fields_succeed(): void
    {
        $ids = $this->seedIncidentRows();

        // name/ttl/sys_groupid-only updates are equally tolerant (FR-012).
        $this->putJson('/api/v1/dns/records/'.$ids['TLSA'], ['ttl' => 300], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('ttl', 300);

        $this->assertSame(
            self::INCIDENT_TLSA_DATA,
            DB::table('dns_rr')->where('id', $ids['TLSA'])->value('data')
        );
    }

    public function test_update_submitting_a_still_bad_hardened_field_is_rejected(): void
    {
        $ids = $this->seedIncidentRows();

        $response = $this->putJson('/api/v1/dns/records/'.$ids['DS'], ['digest' => 'still+not/hex='], $this->authHeaders());
        $response->assertStatus(422);
        $this->assertArrayHasKey('digest', $response->json('errors'));

        // The stored garbage row is untouched by the rejected update.
        $this->assertSame(self::INCIDENT_DS_DATA, DB::table('dns_rr')->where('id', $ids['DS'])->value('data'));
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_update_changing_type_applies_the_new_types_strict_rules(): void
    {
        $ids = $this->seedIncidentRows();

        // Switching type without the new type's required fields fails...
        $response = $this->putJson('/api/v1/dns/records/'.$ids['SSHFP'], ['type' => 'A'], $this->authHeaders());
        $response->assertStatus(422);
        $this->assertArrayHasKey('data', $response->json('errors'));

        // ...and succeeds with them, replacing the garbage data.
        $this->putJson('/api/v1/dns/records/'.$ids['SSHFP'], ['type' => 'A', 'data' => '192.0.2.7'], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('type', 'A')
            ->assertJsonPath('data', '192.0.2.7');
    }

    public function test_unparseable_stored_data_is_never_rewritten_by_recompose(): void
    {
        // FR-013: a structured record whose data does not decompose (SRV
        // with a single token) must survive a meta-free update verbatim —
        // the old recompose path silently rewrote it to "0 0 .".
        $id = $this->seedRecord(['name' => 'sipsrv', 'type' => 'SRV', 'data' => '5060']);

        $this->putJson('/api/v1/dns/records/'.$id, ['ttl' => 600], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('ttl', 600)
            ->assertJsonPath('data', '5060');

        $row = DB::table('dns_rr')->where('id', $id)->first();
        $this->assertSame('5060', $row->data);
        $this->assertSame(0, (int) $row->aux);

        // Deactivation of the same row is equally lossless.
        $this->putJson('/api/v1/dns/records/'.$id, ['active' => false], $this->authHeaders())
            ->assertOk();
        $this->assertSame('5060', DB::table('dns_rr')->where('id', $id)->value('data'));
    }

    public function test_meta_free_updates_do_not_normalize_parseable_stored_data(): void
    {
        // A legacy MX target stored WITHOUT the trailing dot decomposes
        // fine, but a recompose would append the dot — an update that
        // touches no data/meta field must not rewrite stored data at all
        // (FR-013, strengthened: skip recompose entirely, not only for
        // unparseable rows).
        $id = $this->seedRecord(['name' => 'example.com.', 'type' => 'MX', 'data' => 'mail.example.com', 'aux' => 10]);

        $this->putJson('/api/v1/dns/records/'.$id, ['active' => false], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data', 'mail.example.com');

        $this->assertSame('mail.example.com', DB::table('dns_rr')->where('id', $id)->value('data'));

        // Touching a meta field re-composes as before (dot-terminated).
        $this->putJson('/api/v1/dns/records/'.$id, ['priority' => 20], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('aux', 20)
            ->assertJsonPath('data', 'mail.example.com.');
    }

    // ==================================================================
    // US3 — CNAME conflict, apex, target (FR-014..FR-016)
    // ==================================================================

    public function test_cname_blocks_other_records_across_the_three_name_spellings(): void
    {
        $this->seedRecord(['name' => 'www', 'type' => 'CNAME', 'data' => 'web.example.com.']);

        // dns_edit_base.php:46 (base checkDuplicate) — as-sent and
        // '.<origin>'-appended spellings both collide for types without a
        // legacy override (TXT/MX/...).
        foreach (['www', 'www.example.com.'] as $name) {
            $this->assertRejected(['name' => $name, 'type' => 'TXT', 'data' => 'hello'], 'name', "TXT at CNAME node ({$name})");
        }

        // A records use the legacy override (dns_a_edit.php:48-53): the
        // CNAME leg matches the exact name only — verbatim parity.
        $this->assertRejected(['name' => 'www', 'type' => 'A', 'data' => '192.0.2.10'], 'name', 'A at CNAME node (exact name)');

        // The conflict ignores `active` (an inactive CNAME still blocks).
        $this->seedRecord(['name' => 'old', 'type' => 'CNAME', 'data' => 'web.example.com.', 'active' => 'N']);
        $this->assertRejected(['name' => 'old', 'type' => 'MX', 'priority' => 10, 'hostname' => 'mail.example.com'], 'name', 'inactive CNAME still blocks');

        // FQDN-stored CNAME caught via the stripped spelling.
        $this->seedRecord(['name' => 'app.example.com.', 'type' => 'CNAME', 'data' => 'web.example.com.']);
        $this->assertRejected(['name' => 'app', 'type' => 'TXT', 'data' => 'hello'], 'name', 'stripped spelling');

        // A different name is fine.
        $this->postRecord(['name' => 'other', 'type' => 'A', 'data' => '192.0.2.10'])->assertStatus(201);
    }

    public function test_cname_cannot_coexist_with_any_existing_record(): void
    {
        $this->seedRecord(['name' => 'mail', 'type' => 'A', 'data' => '192.0.2.1']);

        // dns_cname_edit.php:48-54 — ANY record at the node blocks a CNAME.
        foreach (['mail', 'mail.example.com.'] as $name) {
            $this->assertRejected(['name' => $name, 'type' => 'CNAME', 'data' => 'web.example.com.'], 'name', "CNAME onto A ({$name})");
        }
    }

    public function test_cname_update_excludes_itself_and_still_detects_conflicts(): void
    {
        $cnameId = $this->seedRecord(['name' => 'alias1', 'type' => 'CNAME', 'data' => 'web.example.com.']);
        $this->seedRecord(['name' => 'taken', 'type' => 'A', 'data' => '192.0.2.1']);

        // Renaming onto itself (same node) is allowed — self is excluded.
        $this->putJson('/api/v1/dns/records/'.$cnameId, ['name' => 'alias1'], $this->authHeaders())
            ->assertOk();

        // Renaming onto an occupied node is rejected.
        $response = $this->putJson('/api/v1/dns/records/'.$cnameId, ['name' => 'taken'], $this->authHeaders());
        $response->assertStatus(422);
        $this->assertArrayHasKey('name', $response->json('errors'));
    }

    public function test_cname_apex_is_rejected(): void
    {
        // dns_cname_edit.php:61-65 (RFC 1912): '@' (already rewritten to
        // the origin), the origin itself and the dotless origin.
        foreach (['@', 'example.com.', 'example.com'] as $name) {
            $this->assertRejected(['name' => $name, 'type' => 'CNAME', 'data' => 'web.example.com.'], 'name', "apex CNAME ({$name})");
        }
    }

    public function test_cname_relative_target_must_exist_and_at_expands_to_origin(): void
    {
        // Relative target naming nothing in the zone → 422 (dns_cname_edit
        // .php:72-84); fully qualified targets are not checked.
        $this->assertRejected(['name' => 'alias1', 'type' => 'CNAME', 'data' => 'missing'], 'data', 'relative target absent');

        $this->postRecord(['name' => 'alias2', 'type' => 'CNAME', 'data' => 'anywhere.example.net.'])->assertStatus(201);

        // Relative target matching an existing record (as-sent or
        // '.<origin>'-appended) is accepted.
        $this->seedRecord(['name' => 'web', 'type' => 'A', 'data' => '192.0.2.1']);
        $this->postRecord(['name' => 'alias3', 'type' => 'CNAME', 'data' => 'web'])->assertStatus(201);

        // data '@' is replaced with the zone origin (dns_cname_edit.php:67-70).
        $this->postRecord(['name' => 'alias4', 'type' => 'CNAME', 'data' => '@'])
            ->assertStatus(201)
            ->assertJsonPath('data', 'example.com.');
    }

    // ==================================================================
    // US3 — A/AAAA/ALIAS duplicates (FR-017), SRV length (FR-018)
    // ==================================================================

    public function test_a_aaaa_alias_duplicate_matrix(): void
    {
        $this->seedRecord(['name' => 'www', 'type' => 'A', 'data' => '192.0.2.1']);

        // Identical A (same name+data) → 422; different data → 201.
        $this->assertRejected(['name' => 'www', 'type' => 'A', 'data' => '192.0.2.1'], 'name', 'identical A');
        $this->postRecord(['name' => 'www', 'type' => 'A', 'data' => '192.0.2.2'])->assertStatus(201);

        // AAAA analog (dns_aaaa_edit.php:48).
        $this->seedRecord(['name' => 'v6', 'type' => 'AAAA', 'data' => '2001:db8::1']);
        $this->assertRejected(['name' => 'v6', 'type' => 'AAAA', 'data' => '2001:db8::1'], 'name', 'identical AAAA');
        $this->postRecord(['name' => 'v6', 'type' => 'AAAA', 'data' => '2001:db8::2'])->assertStatus(201);

        // An ALIAS at the name blocks an A (dns_a_edit.php:48-53) and any
        // record at the name blocks an ALIAS (dns_alias_edit.php:47).
        $this->seedRecord(['name' => 'app', 'type' => 'ALIAS', 'data' => 'web.example.com.']);
        $this->assertRejected(['name' => 'app', 'type' => 'A', 'data' => '192.0.2.9'], 'name', 'ALIAS blocks A');
        $this->assertRejected(['name' => 'www', 'type' => 'ALIAS', 'data' => 'web.example.com.'], 'name', 'A blocks ALIAS');
    }

    public function test_srv_hostname_accepts_up_to_255_chars(): void
    {
        $base = ['name' => '_sip._udp', 'type' => 'SRV', 'priority' => 10, 'weight' => 5, 'port' => 5060];

        // 100 chars — rejected by the pre-013 64-char cap, legal per legacy
        // dns_srv_edit.php:88.
        $long = str_repeat('a', 96).'.com';
        $this->assertSame(100, strlen($long));
        $this->postRecord($base + ['hostname' => $long])->assertStatus(201);

        $tooLong = str_repeat('a', 252).'.com';
        $this->assertSame(256, strlen($tooLong));
        $this->assertRejected($base + ['hostname' => $tooLong], 'hostname', '256 chars rejected');
    }

    // ==================================================================
    // US3 — DMARC prerequisites (FR-020)
    // ==================================================================

    public function test_dmarc_requires_active_dkim_and_exactly_one_active_spf(): void
    {
        $dmarc = ['name' => 'example.com.', 'type' => 'DMARC', 'policy' => 'quarantine'];

        // Empty zone: no DKIM, no SPF.
        $this->assertRejected($dmarc, 'zone', 'no DKIM, no SPF');

        // DKIM present but inactive does not count (legacy active='Y').
        $dkimId = $this->seedRecord(['name' => 'default._domainkey.example.com.', 'type' => 'TXT', 'data' => 'v=DKIM1; t=s; p=MIIBIjANBg', 'active' => 'N']);
        $this->assertRejected($dmarc, 'zone', 'inactive DKIM does not count');

        DB::table('dns_rr')->where('id', $dkimId)->update(['active' => 'Y']);

        // Active DKIM but no SPF.
        $this->assertRejected($dmarc, 'zone', 'DKIM but no SPF');

        // Two active SPF records — legacy aborts.
        $spf1 = $this->seedRecord(['name' => 'example.com.', 'type' => 'TXT', 'data' => 'v=spf1 mx ~all']);
        $this->seedRecord(['name' => '', 'type' => 'TXT', 'data' => 'v=spf1 a ~all']);
        $this->assertRejected($dmarc, 'zone', 'two active SPF records');

        DB::table('dns_rr')->where('id', '!=', $spf1)->where('data', 'like', 'v=spf1%')->delete();

        // Exactly one active SPF + one active DKIM → 201.
        $this->postRecord($dmarc)
            ->assertStatus(201)
            ->assertJsonPath('name', '_dmarc.example.com.')
            ->assertJsonPath('data', 'v=DMARC1; p=quarantine');
    }

    // ==================================================================
    // US3 — CAA duplicate check (NC-2, dns_caa_edit.php:176-177)
    // ==================================================================

    public function test_caa_duplicate_name_and_data_is_rejected(): void
    {
        $caa = ['name' => 'example.com.', 'type' => 'CAA', 'caa_flag' => 0, 'caa_type' => 'issue', 'ca_issuer' => 'letsencrypt.org'];

        $this->postRecord($caa)->assertStatus(201);
        $this->assertRejected($caa, 'name', 'identical CAA');

        // A different value or tag is not a duplicate.
        $this->postRecord(['ca_issuer' => 'sectigo.com'] + $caa)->assertStatus(201);
        $this->postRecord(['caa_type' => 'issuewild'] + $caa)->assertStatus(201);
    }
}
