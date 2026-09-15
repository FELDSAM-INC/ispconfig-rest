<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DnsSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Spec 032 — DNSSEC state of a zone (GET /dns/soa/{id}/dnssec).
 *
 * Legacy (ISPConfig 3.3.1p1): bind_plugin.inc.php::soa_dnssec_sign() 178-194
 * writes the dsset file and every public key file into dns_soa.dnssec_info and
 * sets dnssec_initialized/dnssec_last_signed with a direct UPDATE;
 * dns_soa_edit.php 92-102 hides the whole DNSSEC block when the zone's DNS
 * server has mirrors. The notes below are the real ones captured on isp-test
 * during the spec 029 verification.
 */
class DnsSoaDnssecApiTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    /** 2026-09-16 01:38:02 +02:00 */
    protected const SIGNED_AT = 1789515482;

    /**
     * Real bind9 notes: a tab between IN and DS, a digest the signer wrapped
     * across a line, comment lines, a key-signing key (257) and a
     * zone-signing key (256).
     */
    protected const NOTES = "DS-Records:\nexample.com. IN\tDS 5269 13 2 44F315FBF85AC547DBE621FEB51C011A735ECA5D44E0BDF07C47C4A7 217F7D81\n\n------------------------------------\n\nDNSKEY-Records:\n; This is a key-signing key, keyid 5269, for example.com.\n; Created: 20260915233801 (Wed Sep 16 01:38:01 2026)\nexample.com. IN DNSKEY 257 3 13 HId4lryEWgDLIwtbwAyHy6N/O1jrw0+afJ6LDzVO/S6F8i9a2iCv4YT5 E+eJ/2GYAS3Ytm/6lw0/n+Cgk7aZgQ==\n\n\n; This is a zone-signing key, keyid 53069, for example.com.\nexample.com. IN DNSKEY 256 3 13 6KqunwKuQyqUuw5Am3IY12gELTDVq05so+2Vv2uBeRzS46YV9hEue+iw 8hEo1dAbCdEfGhIjKlMnOpQrStUvWxYz==\n";

    protected const DIGEST = '44F315FBF85AC547DBE621FEB51C011A735ECA5D44E0BDF07C47C4A7217F7D81';

    protected const KSK = 'HId4lryEWgDLIwtbwAyHy6N/O1jrw0+afJ6LDzVO/S6F8i9a2iCv4YT5E+eJ/2GYAS3Ytm/6lw0/n+Cgk7aZgQ==';

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
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    protected function zone(array $attrs = [], string $owner = 'clientA'): int
    {
        return (int) DB::table('dns_soa')->insertGetId($this->ownedBy($owner, array_merge([
            'server_id' => 1,
            'origin' => 'example.com.',
            'ns' => 'ns1.provider.net.',
            'mbox' => 'hostmaster.example.com.',
            'serial' => 2026091601,
            'refresh' => 7200, 'retry' => 540, 'expire' => 604800, 'minimum' => 3600, 'ttl' => 3600,
            'active' => 'Y',
            'dnssec_wanted' => 'N',
            'dnssec_initialized' => 'N',
            'dnssec_algo' => 'ECDSAP256SHA256',
            'dnssec_last_signed' => 0,
        ], $attrs)), 'id');
    }

    protected function mirrorServer(): void
    {
        DB::table('server')->insert([
            'server_id' => 2, 'server_name' => 'ns2', 'dns_server' => 1, 'mirror_server_id' => 1, 'active' => 1,
        ]);
    }

    public function test_state_is_off_when_signing_was_not_requested(): void
    {
        $id = $this->zone();

        $this->getJson("/api/v1/dns/soa/{$id}/dnssec", $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('zone_id', $id)
            ->assertJsonPath('origin', 'example.com.')
            ->assertJsonPath('state', 'off')
            ->assertJsonPath('available', true)
            ->assertJsonPath('wanted', false)
            ->assertJsonPath('initialized', false)
            ->assertJsonPath('algorithm', 'ECDSAP256SHA256')
            ->assertJsonPath('last_signed', null)
            ->assertJsonPath('ds_records', [])
            ->assertJsonPath('dnskey_records', []);
    }

    public function test_state_is_pending_until_the_server_signs(): void
    {
        $id = $this->zone(['dnssec_wanted' => 'Y']);

        $this->getJson("/api/v1/dns/soa/{$id}/dnssec", $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('state', 'pending')
            ->assertJsonPath('wanted', true)
            ->assertJsonPath('initialized', false)
            ->assertJsonPath('ds_records', [])
            ->assertJsonPath('dnskey_records', []);
    }

    public function test_signed_zone_returns_parsed_ds_and_dnskey_records(): void
    {
        $id = $this->zone([
            'dnssec_wanted' => 'Y',
            'dnssec_initialized' => 'Y',
            'dnssec_last_signed' => self::SIGNED_AT,
            'dnssec_info' => self::NOTES,
        ]);

        $response = $this->getJson("/api/v1/dns/soa/{$id}/dnssec", $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('state', 'signed')
            ->assertJsonPath('initialized', true)
            ->assertJsonCount(1, 'ds_records')
            ->assertJsonCount(2, 'dnskey_records');

        $this->assertNotNull($response->json('last_signed'));

        $this->assertSame([
            'key_tag' => 5269,
            'algorithm' => 13,
            'digest_type' => 2,
            // The signer wrapped the digest across a line; the space is removed.
            'digest' => self::DIGEST,
            'record' => 'example.com. IN DS 5269 13 2 '.self::DIGEST,
        ], $response->json('ds_records.0'));

        $this->assertSame([
            'flags' => 257,
            'protocol' => 3,
            'algorithm' => 13,
            'public_key' => self::KSK,
            'type' => 'ksk',
            'record' => 'example.com. IN DNSKEY 257 3 13 '.self::KSK,
        ], $response->json('dnskey_records.0'));

        $this->assertSame(256, $response->json('dnskey_records.1.flags'));
        $this->assertSame('zsk', $response->json('dnskey_records.1.type'));
    }

    public function test_unparsable_or_empty_notes_yield_empty_lists(): void
    {
        // A PowerDNS-style blob: public keys followed by a raw command log.
        $powerdns = "Zone has been secured\n\n== Raw log ============================\n2026-09-16 pdnsutil add-zone-key example.com ksk active 2048 rsasha256\nCreated KSK with algorithm 8\n";

        foreach ([$powerdns, '', 'nonsense'] as $notes) {
            $id = $this->zone([
                'origin' => 'zone'.uniqid().'.test.',
                'dnssec_wanted' => 'Y',
                'dnssec_initialized' => 'Y',
                'dnssec_last_signed' => self::SIGNED_AT,
                'dnssec_info' => $notes,
            ]);

            $this->getJson("/api/v1/dns/soa/{$id}/dnssec", $this->tenantHeaders('clientA'))
                ->assertOk()
                ->assertJsonPath('state', 'signed')
                ->assertJsonPath('ds_records', [])
                ->assertJsonPath('dnskey_records', []);
        }
    }

    public function test_state_is_unavailable_when_the_dns_server_is_mirrored(): void
    {
        $this->mirrorServer();
        $id = $this->zone([
            'dnssec_wanted' => 'Y',
            'dnssec_initialized' => 'Y',
            'dnssec_last_signed' => self::SIGNED_AT,
            'dnssec_info' => self::NOTES,
        ]);

        $this->getJson("/api/v1/dns/soa/{$id}/dnssec", $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('state', 'unavailable')
            ->assertJsonPath('available', false);
    }

    // ------------------------------------------------------------------
    // US3 — DNSSEC is not offered where ISPConfig cannot sign
    // ------------------------------------------------------------------

    protected const FEATURE_NOT_ALLOWED = 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#feature-not-allowed';

    protected function lastDatalogId(): int
    {
        return (int) (DB::table('sys_datalog')->max('datalog_id') ?? 0);
    }

    protected function journalCountAfter(int $id): int
    {
        return DB::table('sys_datalog')->where('datalog_id', '>', $id)->count();
    }

    public function test_enabling_dnssec_on_a_mirrored_server_is_refused_for_every_key(): void
    {
        $this->mirrorServer();
        $id = $this->zone();
        $before = $this->lastDatalogId();

        foreach (['clientA', 'reseller', 'admin'] as $tenant) {
            $this->putJson("/api/v1/dns/soa/{$id}", ['dnssec_wanted' => true], $this->tenantHeaders($tenant))
                ->assertStatus(422)
                ->assertJsonValidationErrors('dnssec_wanted')
                ->assertJsonPath('error_types.dnssec_wanted', self::FEATURE_NOT_ALLOWED);
        }

        $this->assertSame(0, $this->journalCountAfter($before), 'a refusal writes nothing');
        $this->assertSame('N', DB::table('dns_soa')->where('id', $id)->value('dnssec_wanted'));
    }

    public function test_switching_dnssec_off_and_resending_the_stored_value_stay_allowed(): void
    {
        $this->mirrorServer();
        $signed = $this->zone(['dnssec_wanted' => 'Y', 'dnssec_initialized' => 'Y']);

        // Switching off is always possible, even where signing is unavailable.
        $this->putJson("/api/v1/dns/soa/{$signed}", ['dnssec_wanted' => false], $this->tenantHeaders('clientA'))
            ->assertOk();

        // Re-sending the stored value is not a change (spec 016/033 rule).
        $off = $this->zone(['origin' => 'kept.example.test.']);
        $this->putJson("/api/v1/dns/soa/{$off}", ['dnssec_wanted' => false, 'ttl' => 7200], $this->tenantHeaders('clientA'))
            ->assertOk();
    }

    public function test_enabling_dnssec_without_mirrors_is_accepted(): void
    {
        $id = $this->zone();

        $this->putJson("/api/v1/dns/soa/{$id}", ['dnssec_wanted' => true], $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('dnssec_wanted', true);

        $this->assertSame('Y', DB::table('dns_soa')->where('id', $id)->value('dnssec_wanted'));
    }

    public function test_creating_a_zone_with_dnssec_on_a_mirrored_server_is_refused(): void
    {
        $this->mirrorServer();
        $before = $this->lastDatalogId();

        $this->postJson('/api/v1/dns/soa', [
            'origin' => 'created.example.test.',
            'ns' => 'ns1.provider.net.',
            'mbox' => 'hostmaster.created.example.test.',
            'dnssec_wanted' => true,
        ], $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('dnssec_wanted')
            ->assertJsonPath('error_types.dnssec_wanted', self::FEATURE_NOT_ALLOWED);

        $this->assertSame(0, $this->journalCountAfter($before));
        $this->assertSame(0, DB::table('dns_soa')->where('origin', 'created.example.test.')->count());
    }

    public function test_the_wizard_cannot_request_dnssec_on_a_mirrored_server(): void
    {
        $this->mirrorServer();

        $templateId = (int) DB::table('dns_template')->insertGetId([
            'sys_userid' => 1, 'sys_groupid' => 1,
            'sys_perm_user' => 'riud', 'sys_perm_group' => 'riud', 'sys_perm_other' => '',
            'name' => 'Default', 'fields' => 'DOMAIN,IP,NS1,EMAIL,DNSSEC',
            'template' => "[ZONE]\norigin={DOMAIN}.\nns={NS1}.\nmbox={EMAIL}.\nrefresh=7200\nretry=540\nexpire=604800\nminimum=3600\nttl=3600\n\n[DNS_RECORDS]\nA|{DOMAIN}.|{IP}|0|3600\n",
            'visible' => 'Y',
        ], 'template_id');

        $before = $this->lastDatalogId();

        $this->postJson('/api/v1/dns/soa/from-template', [
            'template_id' => $templateId,
            'domain' => 'wizard.example.test',
            'ip' => '192.0.2.10',
            'ns1' => 'ns1.provider.net',
            'email' => 'hostmaster@wizard.example.test',
            'dnssec' => true,
        ], $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('dnssec')
            ->assertJsonPath('error_types.dnssec', self::FEATURE_NOT_ALLOWED);

        $this->assertSame(0, $this->journalCountAfter($before));
        $this->assertSame(0, DB::table('dns_soa')->where('origin', 'wizard.example.test.')->count());

        // Without the flag the same wizard call still works there.
        $this->postJson('/api/v1/dns/soa/from-template', [
            'template_id' => $templateId,
            'domain' => 'wizard2.example.test',
            'ip' => '192.0.2.10',
            'ns1' => 'ns1.provider.net',
            'email' => 'hostmaster@wizard2.example.test',
        ], $this->tenantHeaders('clientA'))
            ->assertCreated();
    }

    // ------------------------------------------------------------------
    // US2 — switching signing on and off (guards the existing write path)
    // ------------------------------------------------------------------

    public function test_enabling_and_disabling_dnssec_round_trips_and_journals(): void
    {
        $id = $this->zone();
        $before = $this->lastDatalogId();

        $this->putJson("/api/v1/dns/soa/{$id}", ['dnssec_wanted' => true], $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertHeader('X-Change-Set-Id')
            ->assertJsonPath('dnssec_wanted', true);

        $this->assertSame(1, $this->journalCountAfter($before));

        // The DNS server signs and writes its notes with a direct UPDATE
        // (bind_plugin.inc.php:193-194) — the API never writes these columns.
        DB::table('dns_soa')->where('id', $id)->update([
            'dnssec_initialized' => 'Y',
            'dnssec_last_signed' => self::SIGNED_AT,
            'dnssec_info' => self::NOTES,
        ]);

        $this->putJson("/api/v1/dns/soa/{$id}", ['dnssec_wanted' => false], $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('dnssec_wanted', false);

        // Legacy keeps the keys and the notes when signing is switched off;
        // only the .signed zone file is removed (research R5).
        $row = DB::table('dns_soa')->where('id', $id)->first();
        $this->assertSame('Y', $row->dnssec_initialized);
        $this->assertNotSame('', (string) $row->dnssec_info);

        $this->getJson("/api/v1/dns/soa/{$id}/dnssec", $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('state', 'off')
            ->assertJsonPath('initialized', true)
            ->assertJsonPath('ds_records', []);
    }

    public function test_unsupported_dnssec_algorithm_is_refused(): void
    {
        $id = $this->zone();

        $this->putJson("/api/v1/dns/soa/{$id}", ['dnssec_algo' => 'RSASHA512'], $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('dnssec_algo');
    }

    // ------------------------------------------------------------------
    // US4 — no key material or server logs for customers
    // ------------------------------------------------------------------

    /** A PowerDNS blob: public keys followed by the raw command log. */
    protected const POWERDNS_NOTES = "Zone has been secured\n\n== Raw log ============================\n2026-09-16 pdnsutil add-zone-key example.com ksk active 2048 rsasha256\nKeys stored under /etc/bind/keys\n";

    public function test_raw_dnssec_notes_are_administrator_only(): void
    {
        $id = $this->zone([
            'dnssec_wanted' => 'Y',
            'dnssec_initialized' => 'Y',
            'dnssec_last_signed' => self::SIGNED_AT,
            'dnssec_info' => self::NOTES,
        ]);

        foreach (['clientA', 'reseller'] as $tenant) {
            $show = $this->getJson("/api/v1/dns/soa/{$id}", $this->tenantHeaders($tenant))->assertOk();

            $this->assertNull($show->json('dnssec_info'), "the raw notes must be masked for {$tenant}");

            // Every other DNSSEC column stays visible — the panel needs them.
            $show->assertJsonPath('dnssec_wanted', true)
                ->assertJsonPath('dnssec_initialized', true)
                ->assertJsonPath('dnssec_algo', 'ECDSAP256SHA256');
            $this->assertSame(self::SIGNED_AT, $show->json('dnssec_last_signed'));

            $list = $this->getJson('/api/v1/dns/soa', $this->tenantHeaders($tenant))->assertOk();
            $this->assertNull($list->json('data.0.dnssec_info'), "masked in lists too for {$tenant}");
        }

        $this->assertSame(
            self::NOTES,
            $this->getJson("/api/v1/dns/soa/{$id}", $this->tenantHeaders('admin'))->assertOk()->json('dnssec_info')
        );
    }

    public function test_the_subresource_never_exposes_server_internals(): void
    {
        $id = $this->zone([
            'dnssec_wanted' => 'Y',
            'dnssec_initialized' => 'Y',
            'dnssec_last_signed' => self::SIGNED_AT,
            'dnssec_info' => self::POWERDNS_NOTES,
        ]);

        $body = (string) json_encode(
            $this->getJson("/api/v1/dns/soa/{$id}/dnssec", $this->tenantHeaders('clientA'))->assertOk()->json()
        );

        foreach (['pdnsutil', 'Raw log', '/etc/bind', 'PRIVATE KEY'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "the response must not carry '{$needle}'");
        }
    }

    public function test_reading_is_scoped_to_the_keys_own_zones(): void
    {
        $id = $this->zone();

        $this->getJson("/api/v1/dns/soa/{$id}/dnssec", $this->tenantHeaders('clientB'))
            ->assertNotFound();

        $this->getJson('/api/v1/dns/soa/999999/dnssec', $this->tenantHeaders('clientA'))
            ->assertNotFound();

        $this->getJson("/api/v1/dns/soa/{$id}/dnssec")
            ->assertUnauthorized();

        // The reseller manages clientA, and admin sees everything.
        foreach (['reseller', 'admin'] as $tenant) {
            $this->getJson("/api/v1/dns/soa/{$id}/dnssec", $this->tenantHeaders($tenant))
                ->assertOk()
                ->assertJsonPath('zone_id', $id);
        }
    }
}
