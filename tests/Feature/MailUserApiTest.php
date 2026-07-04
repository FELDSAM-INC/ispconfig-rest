<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\MailCompletionApiTestCase;

/**
 * /mail/users + /mail/users/{id}/password (spec 005 US1).
 */
class MailUserApiTest extends MailCompletionApiTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'user@example.com',
            'password' => 'Secret123!',
            'name' => 'John Doe',
            'quota' => 1073741824,
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    public function test_endpoints_require_api_key(): void
    {
        $this->seedDomain();
        $id = $this->seedMailUser();

        foreach ([
            ['getJson', '/api/v1/mail/users'],
            ['postJson', '/api/v1/mail/users'],
            ['getJson', '/api/v1/mail/users/'.$id],
            ['putJson', '/api/v1/mail/users/'.$id],
            ['deleteJson', '/api/v1/mail/users/'.$id],
            ['putJson', '/api/v1/mail/users/'.$id.'/password'],
        ] as [$method, $uri]) {
            $this->{$method}($uri)
                ->assertStatus(401)
                ->assertHeader('Content-Type', 'application/problem+json');
        }
    }

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    public function test_list_envelope_filters_and_bad_sort(): void
    {
        $this->seedDomain();
        $this->seedDomain(['domain' => 'other.net']);
        $this->seedMailUser(['email' => 'a@example.com', 'login' => 'a@example.com']);
        $this->seedMailUser(['email' => 'b@example.com', 'login' => 'b@example.com', 'postfix' => 'n']);
        $this->seedMailUser(['email' => 'c@other.net', 'login' => 'c@other.net']);

        $this->getJson('/api/v1/mail/users', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.email', 'a@example.com') // default sort email asc
            ->assertJsonMissingPath('data.0.password');

        $this->getJson('/api/v1/mail/users?domain=example.com', $this->authHeaders())
            ->assertOk()->assertJsonPath('meta.total', 2);

        $this->getJson('/api/v1/mail/users?email=c@other.net', $this->authHeaders())
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/mail/users?login=a@example.com', $this->authHeaders())
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/mail/users?postfix=false', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', 'b@example.com');

        $this->getJson('/api/v1/mail/users?sort=evil', $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Show
    // ------------------------------------------------------------------

    public function test_show_returns_contract_shape_and_404(): void
    {
        $this->seedDomain();
        $id = $this->seedMailUser();

        $response = $this->getJson('/api/v1/mail/users/'.$id, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('email', 'box@example.com')
            ->assertJsonPath('postfix', true)
            ->assertJsonPath('sys_perm_user', 'riud')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('mailuser_id')
            ->assertJsonMissingPath('disableimap')
            ->assertJsonMissingPath('autoresponder'); // sub-resource view only

        $this->assertIsInt($response->json('quota'));

        $this->getJson('/api/v1/mail/users/999', $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    public function test_create_derives_legacy_fields_and_datalogs_hash_only(): void
    {
        $this->seedDomain(['sys_groupid' => 5, 'server_id' => 1]);

        $response = $this->postJson('/api/v1/mail/users', $this->validPayload(), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('email', 'user@example.com')
            ->assertJsonPath('login', 'user@example.com') // defaults to the email
            ->assertJsonPath('server_id', 1) // from the mail domain
            ->assertJsonPath('sys_groupid', 5) // forced to the domain's group
            ->assertJsonPath('maildir', '/var/vmail/example.com/user') // [domain]/[localpart]
            ->assertJsonPath('homedir', '/var/vmail')
            ->assertJsonPath('maildir_format', 'maildir')
            ->assertJsonPath('uid', 5000)
            ->assertJsonPath('gid', 5000)
            ->assertJsonPath('quota', 1073741824) // bytes verbatim (C-6)
            ->assertJsonPath('sys_perm_user', 'riud')
            ->assertJsonPath('sys_perm_other', '')
            ->assertJsonMissingPath('password');

        $id = $response->json('id');

        // mail_user datalog: insert shape, CRYPTMAIL hash, never plaintext.
        $data = $this->assertDatalog('mail_user', 'i', 'mailuser_id:'.$id);
        $this->assertSame(['new', 'old'], array_keys($data));
        $hash = $data['new']['password'];
        $this->assertStringStartsWith('$6$rounds=5000$', $hash);
        $this->assertNotSame('Secret123!', $hash);
        $this->assertSame($hash, crypt('Secret123!', $hash)); // verifiable with crypt()
        $this->assertSame('y', $data['new']['postfix']); // lowercase y/n in payload
        $this->assertSame('n', $data['new']['greylisting']);
        $this->assertSame('5', (string) $data['new']['sys_groupid']);

        // Companion spamfilter_users upsert (priority 7, local Y).
        $sfRow = DB::table('spamfilter_users')->where('email', 'user@example.com')->first();
        $this->assertNotNull($sfRow);
        $this->assertSame(7, (int) $sfRow->priority);
        $this->assertSame('Y', $sfRow->local);
        $this->assertSame(0, (int) $sfRow->policy_id);
        $this->assertSame(5, (int) $sfRow->sys_groupid);
        $this->assertSame(1, (int) $sfRow->server_id);
        $this->assertSame('user@example.com', $sfRow->fullname);

        $sfData = $this->assertDatalog('spamfilter_users', 'i', 'id:'.$sfRow->id);
        $this->assertSame('7', (string) $sfData['new']['priority']);
        $this->assertSame('Y', $sfData['new']['local']);
    }

    public function test_create_hashes_non_ascii_passwords_via_iso_8859_1(): void
    {
        $this->seedDomain();

        $this->postJson('/api/v1/mail/users', $this->validPayload(['password' => 'pässwörd1']), $this->authHeaders())
            ->assertStatus(201);

        $hash = (string) DB::table('mail_user')->where('email', 'user@example.com')->value('password');

        // CRYPTMAIL: UTF-8 -> ISO-8859-1 before crypt (tform_base:1372-1376).
        $latin1 = mb_convert_encoding('pässwörd1', 'ISO-8859-1', 'UTF-8');
        $this->assertSame($hash, crypt($latin1, $hash));
        $this->assertNotSame($hash, crypt('pässwörd1', $hash));
    }

    public function test_create_normalizes_idn_email_and_respects_existing_spamfilter_user(): void
    {
        $this->seedDomain(['domain' => 'xn--mller-kva.de']);

        // Pre-existing mapping keeps its policy (never overwritten by the API).
        $sfId = (int) DB::table('spamfilter_users')->insertGetId([
            'server_id' => 1, 'priority' => 5, 'policy_id' => 3,
            'email' => 'info@xn--mller-kva.de', 'fullname' => 'info@müller.de', 'local' => 'Y',
        ], 'id');

        $this->postJson('/api/v1/mail/users', $this->validPayload(['email' => 'INFO@MüLLER.De']), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('email', 'info@xn--mller-kva.de');

        $this->assertSame(3, (int) DB::table('spamfilter_users')->where('id', $sfId)->value('policy_id'));
        $this->assertSame(1, DB::table('spamfilter_users')->count());
        $this->assertSame(0, DB::table('sys_datalog')->where('dbtable', 'spamfilter_users')->count());
    }

    public function test_create_validation_failures_return_422(): void
    {
        $this->seedDomain();
        $this->seedMailUser(['email' => 'taken@example.com', 'login' => 'taken@example.com']);
        DB::table('mail_forwarding')->insert([
            'server_id' => 1, 'source' => 'fwd@example.com', 'destination' => 'x@y.tld',
            'type' => 'forward', 'active' => 'y',
        ]);

        $cases = [
            'missing password' => [$this->validPayload(['password' => null]), 'password'],
            'short password' => [$this->validPayload(['password' => 'abcd']), 'password'],
            'missing name' => [$this->validPayload(['name' => null]), 'name'],
            'bad email' => [$this->validPayload(['email' => 'not-an-email']), 'email'],
            'duplicate email' => [$this->validPayload(['email' => 'taken@example.com']), 'email'],
            'duplicate login' => [$this->validPayload(['login' => 'taken@example.com']), 'login'],
            'bad login chars' => [$this->validPayload(['login' => '!!bad!!']), 'login'],
            'negative quota' => [$this->validPayload(['quota' => -1]), 'quota'],
            'bad cc list' => [$this->validPayload(['cc' => 'not-an-email']), 'cc'],
            'active forward collision' => [$this->validPayload(['email' => 'fwd@example.com']), 'email'],
        ];

        foreach ($cases as $label => [$payload, $errorField]) {
            $response = $this->postJson('/api/v1/mail/users', $payload, $this->authHeaders());
            $response->assertStatus(422)->assertHeader('Content-Type', 'application/problem+json');
            $this->assertArrayHasKey($errorField, $response->json('errors'), "case: {$label}");
        }

        $this->assertSame(1, DB::table('mail_user')->count()); // only the seed
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_create_unknown_domain_returns_400(): void
    {
        $this->postJson('/api/v1/mail/users', $this->validPayload(['email' => 'user@nodomain.tld']), $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->assertSame(0, DB::table('mail_user')->count());
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    public function test_update_mutable_fields_and_no_change_suppression(): void
    {
        $this->seedDomain(['sys_groupid' => 5]);
        $id = $this->seedMailUser(['name' => 'Old Name', 'maildir_format' => 'mdbox']);

        $this->putJson('/api/v1/mail/users/'.$id, ['name' => 'New Name', 'quota' => 2048], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('name', 'New Name')
            ->assertJsonPath('quota', 2048)
            ->assertJsonPath('maildir_format', 'mdbox') // preserved, not overwritten
            ->assertJsonPath('sys_groupid', 5); // re-forced from the domain

        $data = $this->assertDatalog('mail_user', 'u', 'mailuser_id:'.$id);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('Old Name', $data['old']['name']);
        $this->assertSame('New Name', $data['new']['name']);
        $this->assertSame('mdbox', $data['new']['maildir_format']);

        // Companion sync inserted the missing spamfilter_users row.
        $this->assertSame(1, DB::table('spamfilter_users')->where('email', 'box@example.com')->count());

        // No-change PUT writes nothing.
        $countBefore = DB::table('sys_datalog')->count();
        $this->putJson('/api/v1/mail/users/'.$id, ['name' => 'New Name', 'quota' => 2048], $this->authHeaders())->assertOk();
        $this->assertSame($countBefore, DB::table('sys_datalog')->count());
    }

    public function test_update_email_and_login_are_immutable(): void
    {
        $this->seedDomain();
        $id = $this->seedMailUser();

        $this->putJson('/api/v1/mail/users/'.$id, ['email' => 'renamed@example.com'], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);

        $this->putJson('/api/v1/mail/users/'.$id, ['login' => 'renamed@example.com'], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['login']]);

        // Re-sending the current values is accepted (idempotent PUT).
        $this->putJson('/api/v1/mail/users/'.$id, ['email' => 'box@example.com', 'login' => 'box@example.com'], $this->authHeaders())
            ->assertOk();
    }

    public function test_update_password_only_when_non_empty(): void
    {
        $this->seedDomain();
        $id = $this->seedMailUser(['password' => 'ORIGINAL_HASH']);

        // Empty password field = unchanged (legacy behavior).
        $this->putJson('/api/v1/mail/users/'.$id, ['password' => '', 'name' => 'X'], $this->authHeaders())->assertOk();
        $this->assertSame('ORIGINAL_HASH', DB::table('mail_user')->where('mailuser_id', $id)->value('password'));

        // Non-empty password is re-hashed.
        $this->putJson('/api/v1/mail/users/'.$id, ['password' => 'newSecret1'], $this->authHeaders())->assertOk();
        $hash = (string) DB::table('mail_user')->where('mailuser_id', $id)->value('password');
        $this->assertStringStartsWith('$6$rounds=5000$', $hash);
        $this->assertSame($hash, crypt('newSecret1', $hash));
    }

    // ------------------------------------------------------------------
    // Delete
    // ------------------------------------------------------------------

    public function test_delete_returns_204_and_leaves_forwards_alone(): void
    {
        $this->seedDomain();
        $id = $this->seedMailUser();
        $fwdId = (int) DB::table('mail_forwarding')->insertGetId([
            'server_id' => 1, 'source' => 'other@example.com',
            'destination' => 'box@example.com', 'type' => 'forward', 'active' => 'y',
        ], 'forwarding_id');

        $this->deleteJson('/api/v1/mail/users/'.$id, [], $this->authHeaders())->assertStatus(204);

        $this->assertDatabaseMissing('mail_user', ['mailuser_id' => $id]);
        $this->assertDatabaseHas('mail_forwarding', ['forwarding_id' => $fwdId]); // no cascade (legacy parity)

        $data = $this->assertDatalog('mail_user', 'd', 'mailuser_id:'.$id);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame('box@example.com', $data['old']['email']);

        $this->deleteJson('/api/v1/mail/users/'.$id, [], $this->authHeaders())->assertStatus(404);
    }

    // ------------------------------------------------------------------
    // Password endpoint
    // ------------------------------------------------------------------

    public function test_password_endpoint_updates_only_the_password(): void
    {
        $this->seedDomain();
        $id = $this->seedMailUser(['password' => 'OLD_HASH']);

        $this->putJson('/api/v1/mail/users/'.$id.'/password', ['password' => 'newSecret!1'], $this->authHeaders())
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['success', 'message']);

        $hash = (string) DB::table('mail_user')->where('mailuser_id', $id)->value('password');
        $this->assertStringStartsWith('$6$rounds=5000$', $hash);
        $this->assertSame($hash, crypt('newSecret!1', $hash));

        // Datalog: only the password column differs.
        $data = $this->assertDatalog('mail_user', 'u', 'mailuser_id:'.$id);
        $this->assertSame('OLD_HASH', $data['old']['password']);
        $this->assertSame($hash, $data['new']['password']);
        $this->assertSame($data['old']['name'], $data['new']['name']);
        $this->assertSame($data['old']['email'], $data['new']['email']);
    }

    public function test_password_endpoint_validation_and_404(): void
    {
        $this->seedDomain();
        $id = $this->seedMailUser();

        $this->putJson('/api/v1/mail/users/'.$id.'/password', ['password' => 'abcd'], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['password']]);

        $this->putJson('/api/v1/mail/users/999/password', ['password' => 'validPass1'], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');
    }
}
