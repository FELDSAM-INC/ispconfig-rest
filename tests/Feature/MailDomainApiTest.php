<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MailSchema;
use Tests\TestCase;

class MailDomainApiTest extends TestCase
{
    use RefreshDatabase;

    protected const KEY = 'test-dev-key';

    private static ?string $rsaPrivateKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        MailSchema::create();

        config(['api.dev_key' => self::KEY]);

        // The dev key acts as sys_userid 1 — seed its username so datalog
        // 'user' resolution (sys_user lookup, not the 'admin' fallback) is
        // actually exercised.
        DB::table('sys_user')->insert([
            'userid' => 1,
            'username' => 'apiadmin',
            'typ' => 'admin',
            'default_group' => 1,
        ]);

        // server_id 1 is a valid mail server; server_id 2 is not.
        DB::table('server')->insert([
            ['server_id' => 1, 'server_name' => 'mail1', 'mail_server' => 1, 'mirror_server_id' => 0, 'active' => 1],
            ['server_id' => 2, 'server_name' => 'web1', 'mail_server' => 0, 'mirror_server_id' => 0, 'active' => 1],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'server_id' => 1,
            'domain' => 'example.com',
            'active' => true,
            'local_delivery' => true,
            'dkim' => false,
        ], $overrides);
    }

    protected function authHeaders(): array
    {
        return ['X-API-Key' => self::KEY];
    }

    protected function seedDomain(array $overrides = []): int
    {
        return (int) DB::table('mail_domain')->insertGetId(array_merge([
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_id' => 1,
            'domain' => 'seeded.com',
            'dkim' => 'n',
            'dkim_selector' => 'default',
            'relay_host' => '',
            'relay_user' => '',
            'relay_pass' => 'topsecret',
            'active' => 'y',
            'local_delivery' => 'y',
        ], $overrides), 'domain_id');
    }

    protected static function rsaPrivateKey(): string
    {
        if (self::$rsaPrivateKey === null) {
            $resource = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            openssl_pkey_export($resource, $pem);
            self::$rsaPrivateKey = $pem;
        }

        return self::$rsaPrivateKey;
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/mail/domains')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Unauthorized', 'status' => 401]);
    }

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    public function test_list_returns_data_meta_envelope(): void
    {
        $this->seedDomain(['domain' => 'alpha.com']);
        $this->seedDomain(['domain' => 'beta.com', 'active' => 'n']);

        $this->getJson('/api/v1/mail/domains', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.limit', 25)
            ->assertJsonPath('meta.offset', 0)
            ->assertJsonPath('data.0.domain', 'alpha.com') // default sort: domain asc
            ->assertJsonPath('data.0.active', true)
            ->assertJsonPath('data.1.active', false)
            ->assertJsonMissingPath('data.0.relay_pass');
    }

    public function test_list_pagination_and_sort_order(): void
    {
        foreach (['a.com', 'b.com', 'c.com'] as $domain) {
            $this->seedDomain(['domain' => $domain]);
        }

        $this->getJson('/api/v1/mail/domains?limit=2&offset=1&sort=domain&order=desc', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.limit', 2)
            ->assertJsonPath('meta.offset', 1)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.domain', 'b.com')
            ->assertJsonPath('data.1.domain', 'a.com');
    }

    public function test_list_filters(): void
    {
        $this->seedDomain(['domain' => 'example.com', 'active' => 'y', 'dkim' => 'y']);
        $this->seedDomain(['domain' => 'example.org', 'active' => 'n']);
        $this->seedDomain(['domain' => 'other.net', 'active' => 'y', 'local_delivery' => 'n']);

        $this->getJson('/api/v1/mail/domains?domain=example.*', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/mail/domains?active=true', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/mail/domains?dkim=1', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.domain', 'example.com');

        $this->getJson('/api/v1/mail/domains?local_delivery=false', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.domain', 'other.net');

        $this->getJson('/api/v1/mail/domains?domain=other.net', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_list_filters_by_owning_client(): void
    {
        DB::table('sys_group')->insert([
            ['groupid' => 12, 'name' => 'client5', 'client_id' => 5],
            ['groupid' => 13, 'name' => 'client6', 'client_id' => 6],
        ]);

        $this->seedDomain(['domain' => 'client5.com', 'sys_groupid' => 12]);
        $this->seedDomain(['domain' => 'client6.com', 'sys_groupid' => 13]);

        $this->getJson('/api/v1/mail/domains?client_id=5', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.domain', 'client5.com');

        $this->getJson('/api/v1/mail/domains?client_id=999', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/v1/mail/domains?client_id=abc', $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 400);
    }

    public function test_list_rejects_bad_parameters_with_400_problem(): void
    {
        foreach ([
            'sort=evil_column',
            'order=upwards',
            'limit=0',
            'limit=101',
            'limit=abc',
            'offset=-1',
            'active=maybe',
        ] as $param) {
            $this->getJson('/api/v1/mail/domains?'.$param, $this->authHeaders())
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
        $id = $this->seedDomain(['domain' => 'shown.com']);

        $response = $this->getJson('/api/v1/mail/domains/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('domain', 'shown.com')
            ->assertJsonPath('active', true)
            ->assertJsonPath('sys_perm_user', 'riud')
            ->assertJsonMissingPath('relay_pass')
            ->assertJsonMissingPath('domain_id');

        $this->assertIsInt($response->json('server_id'));
    }

    public function test_show_missing_returns_404_problem(): void
    {
        $this->getJson('/api/v1/mail/domains/999', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['title' => 'Not found', 'status' => 404]);
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    public function test_create_returns_201_and_writes_legacy_format_datalog(): void
    {
        $response = $this->postJson('/api/v1/mail/domains', $this->validPayload(), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('domain', 'example.com')
            ->assertJsonPath('active', true)
            ->assertJsonPath('dkim', false)
            ->assertJsonPath('sys_userid', 1)
            ->assertJsonPath('sys_groupid', 1)
            ->assertJsonPath('sys_perm_user', 'riud')
            ->assertJsonPath('sys_perm_group', 'riud')
            ->assertJsonPath('sys_perm_other', '')
            ->assertJsonMissingPath('relay_pass');

        $id = $response->json('id');
        $this->assertDatabaseHas('mail_domain', ['domain_id' => $id, 'domain' => 'example.com', 'active' => 'y']);

        $row = DB::table('sys_datalog')->where('dbtable', 'mail_domain')->first();
        $this->assertNotNull($row);
        $this->assertSame('i', $row->action);
        $this->assertSame('domain_id:'.$id, $row->dbidx);
        $this->assertSame(1, (int) $row->server_id);
        $this->assertSame('apiadmin', $row->user); // resolved from sys_user.username
        $this->assertSame('ok', $row->status);
        $this->assertNotSame('', $row->session_id);
        $this->assertGreaterThan(0, (int) $row->tstamp);

        $data = unserialize($row->data);
        $this->assertIsArray($data);
        // Insert payloads serialize 'new' before 'old' (legacy diffrec order).
        $this->assertSame(['new', 'old'], array_keys($data));

        // 'new' carries the complete re-read row with DB-native values —
        // lowercase y/n strings, numeric values as strings.
        $this->assertSame('example.com', $data['new']['domain']);
        $this->assertSame('y', $data['new']['active']);
        $this->assertSame('n', $data['new']['dkim']);
        $this->assertSame('y', $data['new']['local_delivery']);
        $this->assertSame('1', $data['new']['server_id']);
        $this->assertSame((string) $id, $data['new']['domain_id']);
        $this->assertSame('riud', $data['new']['sys_perm_user']);
        $this->assertArrayHasKey('dkim_public', $data['new']); // every column present

        // 'old' mirrors the key set: null for changed (non-empty) columns,
        // the identical value for empty ones.
        $this->assertNull($data['old']['domain']);
        $this->assertSame('', $data['old']['relay_host']);
    }

    public function test_create_normalizes_idn_and_uppercase_domains(): void
    {
        $this->postJson('/api/v1/mail/domains', $this->validPayload(['domain' => 'MüLLER.De']), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('domain', 'xn--mller-kva.de');
    }

    public function test_create_accepts_legacy_yn_flag_strings(): void
    {
        $this->postJson('/api/v1/mail/domains', $this->validPayload(['active' => 'n', 'local_delivery' => 'y']), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('active', false)
            ->assertJsonPath('local_delivery', true);
    }

    public function test_create_validation_failures_return_422_problem(): void
    {
        $cases = [
            'missing domain' => [$this->validPayload(['domain' => null]), 'domain'],
            'malformed domain' => [$this->validPayload(['domain' => 'not_a_domain']), 'domain'],
            'bad selector' => [$this->validPayload(['dkim_selector' => 'UPPER!']), 'dkim_selector'],
            'dkim without key' => [$this->validPayload(['dkim' => true]), 'dkim_private'],
            'dkim with garbage key' => [$this->validPayload(['dkim' => true, 'dkim_private' => 'not-a-key']), 'dkim_private'],
            // 'relay_host without user' is no longer an error — #6877
            // (spec 013 US5): relay fields are independently optional.
            'non mail server' => [$this->validPayload(['server_id' => 2]), 'server_id'],
            'unknown server' => [$this->validPayload(['server_id' => 99]), 'server_id'],
        ];

        foreach ($cases as $label => [$payload, $errorField]) {
            $response = $this->postJson('/api/v1/mail/domains', $payload, $this->authHeaders());
            $response->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('status', 422);
            $this->assertArrayHasKey($errorField, $response->json('errors'), "case: {$label}");
        }

        $this->assertSame(0, DB::table('mail_domain')->count());
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_create_rejects_duplicate_domain(): void
    {
        $this->seedDomain(['domain' => 'example.com']);

        $this->postJson('/api/v1/mail/domains', $this->validPayload(), $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonPath('status', 422);
    }

    public function test_create_with_dkim_derives_public_key_and_publishes_dns(): void
    {
        $zoneId = (int) DB::table('dns_soa')->insertGetId([
            'origin' => 'example.com.',
            'serial' => 2024010101,
            'ttl' => 3600,
            'active' => 'Y',
            'server_id' => 1,
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
        ], 'id');

        $response = $this->postJson('/api/v1/mail/domains', $this->validPayload([
            'dkim' => true,
            'dkim_private' => self::rsaPrivateKey(),
        ]), $this->authHeaders())->assertStatus(201);

        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', (string) $response->json('dkim_public'));

        // DKIM TXT record published into the enclosing zone…
        $rr = DB::table('dns_rr')->where('type', 'TXT')->first();
        $this->assertNotNull($rr);
        $this->assertSame('default._domainkey.example.com.', $rr->name);
        $this->assertSame($zoneId, (int) $rr->zone);
        $this->assertStringStartsWith('v=DKIM1; t=s; p=', $rr->data);
        $this->assertStringNotContainsString('BEGIN PUBLIC KEY', $rr->data);

        // …and the SOA serial bumped, everything datalogged.
        $this->assertSame(date('Ymd').'01', (string) DB::table('dns_soa')->where('id', $zoneId)->value('serial'));
        $this->assertSame(1, DB::table('sys_datalog')->where('dbtable', 'dns_rr')->where('action', 'i')->count());
        $this->assertSame(1, DB::table('sys_datalog')->where('dbtable', 'dns_soa')->where('action', 'u')->count());
    }

    // ------------------------------------------------------------------
    // #6877 — per-domain relay without authentication (spec 013 US5)
    // ------------------------------------------------------------------

    public function test_create_relay_fields_are_independently_optional(): void
    {
        // relay_host alone (IP-authorized smarthost, no SASL) — was 422
        // via the old required_with chain.
        $response = $this->postJson('/api/v1/mail/domains', $this->validPayload([
            'domain' => 'relay1.com',
            'relay_host' => 'smarthost.example.net',
        ]), $this->authHeaders())->assertStatus(201);

        $this->assertDatabaseHas('mail_domain', [
            'domain_id' => $response->json('id'),
            'relay_host' => 'smarthost.example.net',
            'relay_user' => '',
            'relay_pass' => '',
        ]);

        // host + user without pass.
        $this->postJson('/api/v1/mail/domains', $this->validPayload([
            'domain' => 'relay2.com',
            'relay_host' => 'smarthost.example.net',
            'relay_user' => 'sasl-user',
        ]), $this->authHeaders())->assertStatus(201);

        // user only (legacy has no validators on any relay field).
        $this->postJson('/api/v1/mail/domains', $this->validPayload([
            'domain' => 'relay3.com',
            'relay_user' => 'sasl-user',
        ]), $this->authHeaders())->assertStatus(201);

        // explicit empty strings.
        $this->postJson('/api/v1/mail/domains', $this->validPayload([
            'domain' => 'relay4.com',
            'relay_host' => '',
            'relay_user' => '',
            'relay_pass' => '',
        ]), $this->authHeaders())->assertStatus(201);
    }

    public function test_update_relay_host_without_credentials_and_explicit_empty_clears(): void
    {
        $id = $this->seedDomain(['domain' => 'relay.example', 'relay_user' => 'old-user']);

        // Setting relay_host alone succeeds (old chain required relay_user).
        $this->putJson('/api/v1/mail/domains/'.$id, ['relay_host' => 'smarthost.example.net'], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('relay_host', 'smarthost.example.net');

        // Omission preserves the stored credential…
        $this->assertSame('old-user', DB::table('mail_domain')->where('domain_id', $id)->value('relay_user'));

        // …while an explicit "" clears it (documented deviation from
        // legacy's restore-if-empty, mail_domain_edit.php:315-317).
        $this->putJson('/api/v1/mail/domains/'.$id, ['relay_user' => ''], $this->authHeaders())
            ->assertOk();
        $this->assertSame('', DB::table('mail_domain')->where('domain_id', $id)->value('relay_user'));
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    public function test_update_returns_200_and_datalogs_full_diff_record(): void
    {
        $id = $this->seedDomain(['domain' => 'example.com']);

        $this->putJson('/api/v1/mail/domains/'.$id, ['active' => false], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('active', false)
            ->assertJsonPath('domain', 'example.com');

        $row = DB::table('sys_datalog')->where('dbtable', 'mail_domain')->first();
        $this->assertNotNull($row);
        $this->assertSame('u', $row->action);
        $this->assertSame('domain_id:'.$id, $row->dbidx);

        $data = unserialize($row->data);
        // Update payloads serialize 'old' before 'new' (legacy diffrec order)…
        $this->assertSame(['old', 'new'], array_keys($data));
        // …and BOTH sides carry the complete record: the changed column with
        // its two values, unchanged columns with identical values.
        $this->assertSame('y', $data['old']['active']);
        $this->assertSame('n', $data['new']['active']);
        $this->assertSame('example.com', $data['old']['domain']);
        $this->assertSame('example.com', $data['new']['domain']);
        $this->assertSame('topsecret', $data['old']['relay_pass']); // full row, not the API projection
        $this->assertCount(count($data['old']), $data['new']);
    }

    public function test_update_without_changes_writes_no_datalog_row(): void
    {
        $id = $this->seedDomain(['domain' => 'example.com']);

        $payload = ['active' => true, 'local_delivery' => true, 'domain' => 'example.com'];

        $this->putJson('/api/v1/mail/domains/'.$id, $payload, $this->authHeaders())->assertOk();
        $this->putJson('/api/v1/mail/domains/'.$id, $payload, $this->authHeaders())->assertOk();

        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_update_rejects_domain_and_server_changes(): void
    {
        $id = $this->seedDomain(['domain' => 'example.com']);

        $this->putJson('/api/v1/mail/domains/'.$id, ['domain' => 'renamed.com'], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonPath('status', 422)
            ->assertJsonStructure(['errors' => ['domain']]);

        $this->putJson('/api/v1/mail/domains/'.$id, ['server_id' => 2], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['server_id']]);

        // Re-sending the current values is fine (idempotent full-body PUT).
        $this->putJson('/api/v1/mail/domains/'.$id, ['domain' => 'example.com', 'server_id' => 1], $this->authHeaders())
            ->assertOk();
    }

    public function test_update_missing_returns_404_problem(): void
    {
        $this->putJson('/api/v1/mail/domains/999', ['active' => false], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Delete + legacy cascade
    // ------------------------------------------------------------------

    public function test_delete_returns_204_and_cascades_with_datalog(): void
    {
        $id = $this->seedDomain(['domain' => 'example.com']);

        $mailboxId = (int) DB::table('mail_user')->insertGetId([
            'server_id' => 1, 'email' => 'box@example.com', 'login' => 'box@example.com',
        ], 'mailuser_id');
        $aliasId = (int) DB::table('mail_forwarding')->insertGetId([
            'server_id' => 1, 'source' => 'alias@example.com', 'destination' => 'ext@other.tld', 'type' => 'alias',
        ], 'forwarding_id');
        $aliasDomainId = (int) DB::table('mail_forwarding')->insertGetId([
            'server_id' => 1, 'source' => '@aliased.tld', 'destination' => '@example.com', 'type' => 'aliasdomain',
        ], 'forwarding_id');
        // Legacy keeps 'forward'-type rows that merely POINT to the domain.
        $keptForwardId = (int) DB::table('mail_forwarding')->insertGetId([
            'server_id' => 1, 'source' => 'fwd@other.tld', 'destination' => 'in@example.com', 'type' => 'forward',
        ], 'forwarding_id');
        $fetchId = (int) DB::table('mail_get')->insertGetId([
            'server_id' => 1, 'destination' => 'box@example.com',
        ], 'mailget_id');
        $sfUserId = (int) DB::table('spamfilter_users')->insertGetId([
            'server_id' => 1, 'email' => '@example.com', 'fullname' => '@example.com', 'local' => 'Y',
        ], 'id');
        $wblistId = (int) DB::table('spamfilter_wblist')->insertGetId([
            'server_id' => 1, 'rid' => $sfUserId, 'wb' => 'W', 'email' => 'friend@other.tld',
        ], 'wblist_id');

        $this->deleteJson('/api/v1/mail/domains/'.$id, [], $this->authHeaders())
            ->assertStatus(204);

        $this->assertDatabaseMissing('mail_domain', ['domain_id' => $id]);
        $this->assertDatabaseMissing('mail_user', ['mailuser_id' => $mailboxId]);
        $this->assertDatabaseMissing('mail_forwarding', ['forwarding_id' => $aliasId]);
        $this->assertDatabaseMissing('mail_forwarding', ['forwarding_id' => $aliasDomainId]);
        $this->assertDatabaseHas('mail_forwarding', ['forwarding_id' => $keptForwardId]); // survives
        $this->assertDatabaseMissing('mail_get', ['mailget_id' => $fetchId]);
        $this->assertDatabaseMissing('spamfilter_users', ['id' => $sfUserId]);
        $this->assertDatabaseMissing('spamfilter_wblist', ['wblist_id' => $wblistId]);

        // Every cascade member datalogged as 'd' with its full old record.
        $expected = [
            ['mail_forwarding', 'forwarding_id:'.$aliasId],
            ['mail_forwarding', 'forwarding_id:'.$aliasDomainId],
            ['mail_get', 'mailget_id:'.$fetchId],
            ['mail_user', 'mailuser_id:'.$mailboxId],
            ['spamfilter_wblist', 'wblist_id:'.$wblistId],
            ['spamfilter_users', 'id:'.$sfUserId],
            ['mail_domain', 'domain_id:'.$id],
        ];

        foreach ($expected as [$table, $dbidx]) {
            $this->assertSame(
                1,
                DB::table('sys_datalog')->where('dbtable', $table)->where('dbidx', $dbidx)->where('action', 'd')->count(),
                "missing datalog d row for {$table} {$dbidx}"
            );
        }

        $this->assertSame(count($expected), DB::table('sys_datalog')->count());

        // One request = one session id grouping the whole cascade (legacy
        // writes the interface session id on every row).
        $this->assertSame(1, DB::table('sys_datalog')->distinct()->count('session_id'));

        // Delete payloads carry the full old record ('old' first).
        $domainRow = DB::table('sys_datalog')->where('dbtable', 'mail_domain')->first();
        $data = unserialize($domainRow->data);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('example.com', $data['old']['domain']);
        $this->assertNull($data['new']['domain']);
    }

    public function test_delete_missing_returns_404_problem(): void
    {
        $this->deleteJson('/api/v1/mail/domains/999', [], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }
}
