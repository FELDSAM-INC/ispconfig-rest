<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DnsSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Spec 029 — the DNS zone wizard for scoped keys.
 *
 * Legacy (ISPConfig 3.3.1p1): dns/dns_wizard.php lists every template with
 * visible = 'Y' to every user (no getAuthSQL), and
 * lib/classes/dns_wizard.inc.php::create() expands the template into a zone
 * written inactive, its records, and finally the zone activation.
 */
class DnsZoneWizardApiTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    /** The shipped "Default" template of ISPConfig 3.3.1p1 (dns_template row 1). */
    protected const DEFAULT_TEMPLATE = <<<'TPL'
        [ZONE]
        origin={DOMAIN}.
        ns={NS1}.
        mbox={EMAIL}.
        refresh=7200
        retry=540
        expire=604800
        minimum=3600
        ttl=3600
        xfer=
        also_notify=
        dnssec_wanted=N
        dnssec_algo=ECDSAP256SHA256

        [DNS_RECORDS]
        A|{DOMAIN}.|{IP}|0|3600
        A|www|{IP}|0|3600
        A|mail|{IP}|0|3600
        NS|{DOMAIN}.|{NS1}.|0|3600
        NS|{DOMAIN}.|{NS2}.|0|3600
        MX|{DOMAIN}.|mail.{DOMAIN}.|10|3600
        TXT|{DOMAIN}.|v=spf1 mx a ~all|0|3600
        TPL;

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
     * An administrator-owned template, as the installer creates it: owned by
     * the admin group with no world read, so the row-level predicate hides it
     * from client keys.
     *
     * @param  array<string, mixed>  $attrs
     */
    protected function template(array $attrs = []): int
    {
        return (int) DB::table('dns_template')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'name' => 'Default',
            'fields' => 'DOMAIN,IP,NS1,NS2,EMAIL,DKIM,DNSSEC',
            'template' => self::DEFAULT_TEMPLATE,
            'visible' => 'Y',
        ], $attrs), 'template_id');
    }

    // ------------------------------------------------------------------
    // US2 — the customer can see the templates offered to it
    // ------------------------------------------------------------------

    public function test_visible_templates_are_listed_to_every_key(): void
    {
        $id = $this->template();

        foreach (['admin', 'clientA', 'reseller'] as $tenant) {
            $response = $this->getJson('/api/v1/dns/zone-templates', $this->tenantHeaders($tenant))
                ->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.id', $id)
                ->assertJsonPath('data.0.name', 'Default');

            $this->assertSame(
                ['DOMAIN', 'IP', 'NS1', 'NS2', 'EMAIL', 'DKIM', 'DNSSEC'],
                $response->json('data.0.fields'),
                "fields must be an array of tokens for {$tenant}"
            );
        }
    }

    public function test_invisible_templates_are_hidden_from_every_key(): void
    {
        $this->template(['name' => 'Visible']);
        $this->template(['name' => 'Draft', 'visible' => 'N']);

        foreach (['admin', 'clientA'] as $tenant) {
            $this->getJson('/api/v1/dns/zone-templates', $this->tenantHeaders($tenant))
                ->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.name', 'Visible');
        }
    }

    public function test_entries_expose_only_the_wizard_fields(): void
    {
        $this->template();

        $entry = $this->getJson('/api/v1/dns/zone-templates', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->json('data.0');

        $this->assertSame(['fields', 'id', 'name'], collect(array_keys($entry))->sort()->values()->all());
    }

    public function test_templates_are_sorted_by_name(): void
    {
        $this->template(['name' => 'Zeta']);
        $this->template(['name' => 'Alpha']);

        $this->getJson('/api/v1/dns/zone-templates', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Alpha')
            ->assertJsonPath('data.1.name', 'Zeta');

        $this->getJson('/api/v1/dns/zone-templates?order=desc', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Zeta');
    }

    public function test_listing_requires_a_key(): void
    {
        $this->template();

        $this->getJson('/api/v1/dns/zone-templates')->assertUnauthorized();
    }

    /**
     * Guard for spec 011: the administrator management resource keeps its
     * row-level scoping — this feature must not widen it.
     */
    public function test_template_management_resource_stays_row_scoped(): void
    {
        $id = $this->template();

        $this->getJson('/api/v1/dns/templates', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson("/api/v1/dns/templates/{$id}", $this->tenantHeaders('clientA'))
            ->assertNotFound();

        $this->getJson('/api/v1/dns/templates', $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    // ------------------------------------------------------------------
    // US1 — a customer creates a complete zone in one step
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function wizardPayload(array $overrides = []): array
    {
        return array_merge([
            'template_id' => $this->template(),
            'domain' => 'example.com',
            'ip' => '192.0.2.10',
            'ns1' => 'ns1.provider.net',
            'ns2' => 'ns2.provider.net',
            'email' => 'hostmaster@example.com',
        ], $overrides);
    }

    /**
     * @return array<int, object>
     */
    protected function journalAfter(int $datalogId): array
    {
        return DB::table('sys_datalog')->where('datalog_id', '>', $datalogId)->orderBy('datalog_id')->get()->all();
    }

    protected function lastDatalogId(): int
    {
        return (int) (DB::table('sys_datalog')->max('datalog_id') ?? 0);
    }

    public function test_wizard_creates_the_zone_and_its_records(): void
    {
        $payload = $this->wizardPayload();

        $response = $this->postJson('/api/v1/dns/soa/from-template', $payload, $this->tenantHeaders('clientA'))
            ->assertCreated()
            ->assertHeader('X-Change-Set-Id')
            ->assertJsonPath('origin', 'example.com.')
            ->assertJsonPath('ns', 'ns1.provider.net.')
            // Legacy replaces '@' with '.' in mbox.
            ->assertJsonPath('mbox', 'hostmaster.example.com.')
            ->assertJsonPath('active', true)
            // SOA timers come from the template, not from the defaults.
            ->assertJsonPath('refresh', 7200)
            ->assertJsonPath('retry', 540)
            ->assertJsonPath('expire', 604800)
            ->assertJsonPath('minimum', 3600)
            ->assertJsonPath('ttl', 3600)
            ->assertJsonPath('dnssec_wanted', false);

        $zoneId = (int) $response->json('id');
        $this->assertGreaterThan(0, (int) $response->json('serial'), 'the serial is generated server-side');

        $records = DB::table('dns_rr')->where('zone', $zoneId)->orderBy('id')->get()
            ->map(fn (object $r): array => [$r->type, $r->name, $r->data, (int) $r->aux, (int) $r->ttl, $r->active])
            ->all();

        $this->assertSame([
            ['A', 'example.com.', '192.0.2.10', 0, 3600, 'Y'],
            ['A', 'www', '192.0.2.10', 0, 3600, 'Y'],
            ['A', 'mail', '192.0.2.10', 0, 3600, 'Y'],
            ['NS', 'example.com.', 'ns1.provider.net.', 0, 3600, 'Y'],
            ['NS', 'example.com.', 'ns2.provider.net.', 0, 3600, 'Y'],
            ['MX', 'example.com.', 'mail.example.com.', 10, 3600, 'Y'],
            ['TXT', 'example.com.', 'v=spf1 mx a ~all', 0, 3600, 'Y'],
        ], $records);
    }

    public function test_wizard_journals_the_legacy_sequence_in_one_change_set(): void
    {
        $before = $this->lastDatalogId();

        $changeSet = $this->postJson('/api/v1/dns/soa/from-template', $this->wizardPayload(), $this->tenantHeaders('clientA'))
            ->assertCreated()
            ->headers->get('X-Change-Set-Id');

        $entries = $this->journalAfter($before);

        // Legacy dns_wizard.inc.php: zone inserted inactive, one entry per
        // record, then the zone activation.
        $this->assertSame(
            array_merge([['dns_soa', 'i']], array_fill(0, 7, ['dns_rr', 'i']), [['dns_soa', 'u']]),
            array_map(static fn (object $row): array => [$row->dbtable, $row->action], $entries)
        );

        foreach ($entries as $row) {
            $this->assertSame($changeSet, $row->session_id, 'every entry shares the change set');
        }

        $this->assertStringContainsString('s:6:"active";s:1:"N"', (string) $entries[0]->data);
        $this->assertStringContainsString('s:6:"active";s:1:"Y"', (string) $entries[count($entries) - 1]->data);
    }

    public function test_records_inherit_the_zones_server_and_owner(): void
    {
        $zoneId = (int) $this->postJson('/api/v1/dns/soa/from-template', $this->wizardPayload(), $this->tenantHeaders('clientA'))
            ->assertCreated()->json('id');

        $zone = DB::table('dns_soa')->where('id', $zoneId)->first();
        $this->assertSame($this->tenant('clientA')['groupid'], (int) $zone->sys_groupid);
        $this->assertSame(1, (int) $zone->server_id);

        foreach (DB::table('dns_rr')->where('zone', $zoneId)->get() as $record) {
            $this->assertSame((int) $zone->sys_groupid, (int) $record->sys_groupid);
            $this->assertSame((int) $zone->server_id, (int) $record->server_id);
            $this->assertSame('riud', $record->sys_perm_user);
            $this->assertSame('', (string) $record->sys_perm_other);
        }
    }

    public function test_admin_key_can_create_the_zone_for_a_client(): void
    {
        $payload = $this->wizardPayload(['client_id' => $this->tenant('clientA')['client_id'], 'server_id' => 1]);

        $zoneId = (int) $this->postJson('/api/v1/dns/soa/from-template', $payload, $this->tenantHeaders('admin'))
            ->assertCreated()->json('id');

        $group = $this->tenant('clientA')['groupid'];
        $this->assertSame($group, (int) DB::table('dns_soa')->where('id', $zoneId)->value('sys_groupid'));
        $this->assertSame([$group], DB::table('dns_rr')->where('zone', $zoneId)->pluck('sys_groupid')
            ->map(static fn ($v): int => (int) $v)->unique()->values()->all());
    }

    public function test_unassigned_server_is_refused_with_the_typed_error(): void
    {
        $this->postJson('/api/v1/dns/soa/from-template', $this->wizardPayload(['server_id' => 99]), $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonPath('error_types.server_id', 'https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/docs/problems.md#server-not-assigned');

        $this->assertSame(0, DB::table('dns_soa')->count());
    }

    public function test_duplicate_origin_is_refused_and_writes_nothing(): void
    {
        $this->postJson('/api/v1/dns/soa/from-template', $this->wizardPayload(), $this->tenantHeaders('clientA'))->assertCreated();

        $before = $this->lastDatalogId();

        $this->postJson('/api/v1/dns/soa/from-template', $this->wizardPayload(), $this->tenantHeaders('clientA'))
            ->assertStatus(409);

        $this->assertSame(1, DB::table('dns_soa')->count());
        $this->assertSame(7, DB::table('dns_rr')->count());
        $this->assertSame([], $this->journalAfter($before));
    }

    public function test_missing_and_undeclared_placeholder_values_are_refused(): void
    {
        $payload = $this->wizardPayload();
        unset($payload['ip']);

        $this->postJson('/api/v1/dns/soa/from-template', $payload, $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('ip');

        // The template declares no IPV6 placeholder.
        $this->postJson('/api/v1/dns/soa/from-template', $this->wizardPayload(['ipv6' => '2001:db8::10']), $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('ipv6');

        $this->assertSame(0, DB::table('dns_soa')->count());
    }

    public function test_invalid_placeholder_values_are_refused(): void
    {
        foreach ([
            ['domain' => 'not a domain'],
            ['ip' => 'nonsense'],
            ['ns1' => 'no dots'],
            ['email' => 'not-an-email'],
        ] as $override) {
            $this->postJson('/api/v1/dns/soa/from-template', $this->wizardPayload($override), $this->tenantHeaders('clientA'))
                ->assertStatus(422)
                ->assertJsonValidationErrors(array_key_first($override));
        }

        $this->assertSame(0, DB::table('dns_soa')->count());
    }

    public function test_unknown_invisible_and_malformed_templates_are_refused(): void
    {
        $cases = [
            ['template_id' => 999999],
            ['template_id' => $this->template(['name' => 'Draft', 'visible' => 'N'])],
            ['template_id' => $this->template(['name' => 'Broken section', 'template' => "[NOPE]\norigin={DOMAIN}.\n"])],
            ['template_id' => $this->template(['name' => 'No ns', 'template' => "[ZONE]\norigin={DOMAIN}.\nmbox={EMAIL}.\nrefresh=7200\nretry=540\nexpire=604800\nminimum=3600\nttl=3600\n"])],
            ['template_id' => $this->template([
                'name' => 'Bad type',
                'template' => "[ZONE]\norigin={DOMAIN}.\nns={NS1}.\nmbox={EMAIL}.\nrefresh=7200\nretry=540\nexpire=604800\nminimum=3600\nttl=3600\n\n[DNS_RECORDS]\nWAT|{DOMAIN}.|{IP}|0|3600\n",
            ])],
        ];

        foreach ($cases as $i => $override) {
            $this->postJson('/api/v1/dns/soa/from-template', $this->wizardPayload($override), $this->tenantHeaders('clientA'))
                ->assertStatus(422)
                ->assertJsonValidationErrors('template_id');
        }

        $this->assertSame(0, DB::table('dns_soa')->count());
        $this->assertSame(0, DB::table('dns_rr')->count());
    }

    public function test_ipv6_placeholder_is_expanded(): void
    {
        $templateId = $this->template([
            'name' => 'Dual stack',
            'fields' => 'DOMAIN,IP,IPV6,NS1,NS2,EMAIL',
            'template' => "[ZONE]\norigin={DOMAIN}.\nns={NS1}.\nmbox={EMAIL}.\nrefresh=7200\nretry=540\nexpire=604800\nminimum=3600\nttl=3600\n\n[DNS_RECORDS]\nA|{DOMAIN}.|{IP}|0|3600\nAAAA|{DOMAIN}.|{IPV6}|0|3600\n",
        ]);

        $zoneId = (int) $this->postJson('/api/v1/dns/soa/from-template', $this->wizardPayload([
            'template_id' => $templateId,
            'ipv6' => '2001:db8::10',
        ]), $this->tenantHeaders('clientA'))->assertCreated()->json('id');

        $this->assertSame('2001:db8::10', DB::table('dns_rr')->where('zone', $zoneId)->where('type', 'AAAA')->value('data'));
    }
}
