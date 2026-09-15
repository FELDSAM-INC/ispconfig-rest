<?php

namespace Tests\Feature;

use App\Models\MailDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DnsSchema;
use Tests\Support\MailCompletionSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Spec 027: DKIM status and key generation for mail domains
 * (GET/POST /mail/domains/{id}/dkim) and private key visibility on the
 * mail domain resource (legacy ajax_get_json.php create_dkim,
 * mail_domain_edit.php DKIM handling).
 */
class MailDomainDkimApiTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const VIEW_KEYS = [
        'id', 'domain', 'enabled', 'selector', 'public_key', 'key_bits', 'dns_record', 'dns_managed', 'available',
    ];

    private int $domainA;

    private int $domainB;

    protected function setUp(): void
    {
        parent::setUp();

        MailCompletionSchema::create();
        DnsSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        foreach (['clientA', 'clientB', 'reseller'] as $tenant) {
            $this->assignServers($tenant, ['mail' => [1]]);
        }

        DB::table('server')->insert([
            ['server_id' => 1, 'server_name' => 'mail1', 'mail_server' => 1, 'mirror_server_id' => 0, 'active' => 1,
                'config' => "[mail]\ndkim_path=/var/lib/amavis/dkim\ndkim_strength=1024\n"],
            ['server_id' => 2, 'server_name' => 'mail2', 'mail_server' => 1, 'mirror_server_id' => 0, 'active' => 1,
                'config' => "[mail]\ndkim_path=/\ndkim_strength=1024\n"],
        ]);

        $this->domainA = $this->seedDomain('clientA', 'a-dom.test');
        $this->domainB = $this->seedDomain('clientB', 'b-dom.test');
    }

    protected function seedDomain(string $owner, string $domain, array $attrs = []): int
    {
        return (int) DB::table('mail_domain')->insertGetId($this->ownedBy($owner, array_merge([
            'server_id' => 1, 'domain' => $domain, 'active' => 'y',
        ], $attrs)), 'domain_id');
    }

    protected function seedZone(string $owner, string $origin): int
    {
        return (int) DB::table('dns_soa')->insertGetId($this->ownedBy($owner, [
            'origin' => $origin, 'serial' => 2024010101, 'ttl' => 3600, 'active' => 'Y', 'server_id' => 1,
        ]), 'id');
    }

    protected function url(int $domain): string
    {
        return "/api/v1/mail/domains/{$domain}/dkim";
    }

    protected static function stripPem(string $pem): string
    {
        return str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\r", "\n"], '', $pem);
    }

    // ------------------------------------------------------------------
    // US1/US2 — generate and read
    // ------------------------------------------------------------------

    public function test_status_without_a_key(): void
    {
        $this->getJson($this->url($this->domainA), $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertExactJson([
                'id' => $this->domainA,
                'domain' => 'a-dom.test',
                'enabled' => false,
                'selector' => 'default',
                'public_key' => null,
                'key_bits' => null,
                'dns_record' => null,
                'dns_managed' => false,
                'available' => true,
            ]);

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_generating_enables_dkim_with_the_server_key_size(): void
    {
        $response = $this->postJson($this->url($this->domainA), [], $this->tenantHeaders('clientA'));

        $response->assertOk()
            ->assertHeader('X-Change-Set-Id')
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('selector', 'default')
            ->assertJsonPath('key_bits', 1024)
            ->assertJsonPath('dns_managed', false)
            ->assertJsonPath('available', true)
            ->assertJsonPath('dns_record.name', 'default._domainkey.a-dom.test.')
            ->assertJsonPath('dns_record.type', 'TXT');

        $this->assertSame(self::VIEW_KEYS, array_keys($response->json()));
        $this->assertStringNotContainsString('PRIVATE', $response->getContent());

        $public = (string) $response->json('public_key');
        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $public);
        $this->assertSame('v=DKIM1; t=s; p='.self::stripPem($public), $response->json('dns_record.value'));

        $row = DB::table('mail_domain')->where('domain_id', $this->domainA)->first();
        $this->assertSame('y', $row->dkim);
        $this->assertSame('default', $row->dkim_selector);
        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', (string) $row->dkim_private);
        $this->assertSame($public, $row->dkim_public);
        $this->assertSame($row->dkim_public, MailDomain::derivePublicKey((string) $row->dkim_private));

        $this->assertSame(1, DB::table('sys_datalog')->where('dbtable', 'mail_domain')->where('action', 'u')->count());
        $this->assertSame(0, DB::table('dns_rr')->count());
    }

    public function test_default_key_size_when_the_server_setting_is_invalid(): void
    {
        DB::table('server')->where('server_id', 1)->update(['config' => "[mail]\ndkim_path=/var/lib/amavis/dkim\ndkim_strength=512\n"]);

        $this->postJson($this->url($this->domainA), [], $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('key_bits', 2048);
    }

    public function test_hosted_zone_gets_the_record_and_regeneration_replaces_it(): void
    {
        $zone = $this->seedZone('clientA', 'a-dom.test.');
        $headers = $this->tenantHeaders('clientA');

        $first = $this->postJson($this->url($this->domainA), [], $headers)->assertOk()->assertJsonPath('dns_managed', true);

        $record = DB::table('dns_rr')->where('type', 'TXT')->first();
        $this->assertNotNull($record);
        $this->assertSame($zone, (int) $record->zone);
        $this->assertSame('default._domainkey.a-dom.test.', $record->name);
        $this->assertSame($first->json('dns_record.value'), $record->data);

        $this->getJson($this->url($this->domainA), $headers)->assertOk()->assertJsonPath('dns_managed', true);

        $second = $this->postJson($this->url($this->domainA), ['selector' => 's2026'], $headers)
            ->assertOk()
            ->assertJsonPath('selector', 's2026')
            ->assertJsonPath('dns_record.name', 's2026._domainkey.a-dom.test.');

        $this->assertNotSame($first->json('public_key'), $second->json('public_key'));
        $this->assertSame(0, DB::table('dns_rr')->where('name', 'default._domainkey.a-dom.test.')->count());
        $this->assertSame($second->json('dns_record.value'), DB::table('dns_rr')->where('name', 's2026._domainkey.a-dom.test.')->value('data'));
        $this->assertSame(1, DB::table('sys_datalog')->where('dbtable', 'dns_rr')->where('action', 'd')->count());
        $this->assertSame(2, DB::table('sys_datalog')->where('dbtable', 'dns_rr')->where('action', 'i')->count());
        $this->assertSame('s2026', DB::table('mail_domain')->where('domain_id', $this->domainA)->value('dkim_selector'));
    }

    public function test_inactive_domain_stores_the_key_without_dns(): void
    {
        $this->seedZone('clientA', 'idle.test.');
        $domain = $this->seedDomain('clientA', 'idle.test', ['active' => 'n']);

        $this->postJson($this->url($domain), [], $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('enabled', true);

        $this->assertSame(0, DB::table('dns_rr')->count());
    }

    public function test_servers_without_a_dkim_directory_refuse_generation(): void
    {
        $domain = $this->seedDomain('clientA', 'nodkim.test', ['server_id' => 2]);
        $headers = $this->tenantHeaders('clientA');

        $this->getJson($this->url($domain), $headers)->assertOk()->assertJsonPath('available', false);

        $this->postJson($this->url($domain), [], $headers)
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('detail', 'DKIM signing is not available on the mail server of this domain.');

        $this->assertSame(0, DB::table('sys_datalog')->count());
        $this->assertSame('n', DB::table('mail_domain')->where('domain_id', $domain)->value('dkim'));
    }

    public function test_invalid_selector_is_refused(): void
    {
        $this->postJson($this->url($this->domainA), ['selector' => 'Bad_Selector!'], $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['selector']]);

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    // ------------------------------------------------------------------
    // US3 — private key visibility
    // ------------------------------------------------------------------

    public function test_private_key_is_never_returned_to_scoped_keys(): void
    {
        $this->postJson($this->url($this->domainA), [], $this->tenantHeaders('clientA'))->assertOk();
        $stored = (string) DB::table('mail_domain')->where('domain_id', $this->domainA)->value('dkim_private');

        foreach (['clientA', 'reseller'] as $tenant) {
            $this->getJson("/api/v1/mail/domains/{$this->domainA}", $this->tenantHeaders($tenant))
                ->assertOk()
                ->assertJsonMissingPath('dkim_private')
                ->assertJsonPath('dkim', true)
                ->assertJsonPath('dkim_selector', 'default');
        }

        $list = $this->getJson('/api/v1/mail/domains', $this->tenantHeaders('clientA'))->assertOk();
        $this->assertArrayNotHasKey('dkim_private', $list->json('data.0'));
        $this->assertNotEmpty($list->json('data.0.dkim_public'));
        $this->assertStringNotContainsString('PRIVATE KEY', $list->getContent());

        $this->putJson("/api/v1/mail/domains/{$this->domainA}", ['local_delivery' => false], $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonMissingPath('dkim_private');

        // A customer may bring its own key: stored, not echoed.
        $ownKey = '';
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $ownKey);

        $this->postJson('/api/v1/mail/domains', ['domain' => 'own-a.test', 'dkim' => true, 'dkim_private' => $ownKey], $this->tenantHeaders('clientA'))
            ->assertStatus(201)
            ->assertJsonMissingPath('dkim_private');
        // TrimStrings removes the PEM's trailing newline on input (existing behavior).
        $this->assertSame(trim($ownKey), trim((string) DB::table('mail_domain')->where('domain', 'own-a.test')->value('dkim_private')));

        $this->getJson("/api/v1/mail/domains/{$this->domainA}", $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertJsonPath('dkim_private', $stored);
    }

    // ------------------------------------------------------------------
    // US4 — disable, tenant matrix
    // ------------------------------------------------------------------

    public function test_disabling_keeps_the_key_and_generation_enables_again(): void
    {
        $headers = $this->tenantHeaders('clientA');
        $first = $this->postJson($this->url($this->domainA), [], $headers)->assertOk();

        $this->putJson("/api/v1/mail/domains/{$this->domainA}", ['dkim' => false], $headers)
            ->assertOk()
            ->assertJsonPath('dkim', false);

        $this->getJson($this->url($this->domainA), $headers)
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('public_key', $first->json('public_key'))
            ->assertJsonPath('dns_record.value', $first->json('dns_record.value'));

        $this->assertNotEmpty(DB::table('mail_domain')->where('domain_id', $this->domainA)->value('dkim_private'));

        $again = $this->postJson($this->url($this->domainA), [], $headers)->assertOk()->assertJsonPath('enabled', true);
        $this->assertNotSame($first->json('public_key'), $again->json('public_key'));
    }

    public function test_tenant_matrix(): void
    {
        $this->postJson($this->url($this->domainA), [], $this->tenantHeaders('clientB'))->assertStatus(404);
        $this->getJson($this->url($this->domainA), $this->tenantHeaders('clientB'))->assertStatus(404);

        $this->postJson($this->url($this->domainA), [], $this->tenantHeaders('reseller'))->assertOk()->assertJsonPath('enabled', true);
        $this->postJson($this->url($this->domainB), [], $this->tenantHeaders('admin'))->assertOk()->assertJsonPath('enabled', true);

        $shared = $this->seedDomain('admin', 'shared.test', ['sys_perm_other' => 'r']);
        $datalog = DB::table('sys_datalog')->count();

        $this->getJson($this->url($shared), $this->tenantHeaders('clientA'))->assertOk();
        $this->postJson($this->url($shared), [], $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('detail', 'You do not have permission to update this resource.');
        $this->assertSame($datalog, DB::table('sys_datalog')->count());
        $this->assertNull(DB::table('mail_domain')->where('domain_id', $shared)->value('dkim_private'));

        // DKIM is not a lock-managed switch.
        DB::table('client')->where('client_id', $this->tenant('clientA')['client_id'])->update(['locked' => 'y']);
        $this->postJson($this->url($this->domainA), ['selector' => 'locked'], $this->tenantHeaders('clientA'))->assertOk();
    }
}
