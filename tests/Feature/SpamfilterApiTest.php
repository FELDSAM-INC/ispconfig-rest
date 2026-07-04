<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\MailCompletionApiTestCase;

/**
 * /mail/spamfilter/{policies,users,wblist,config} (spec 005 US4).
 */
class SpamfilterApiTest extends MailCompletionApiTestCase
{
    protected function seedPolicy(string $name = 'Default Policy'): int
    {
        return (int) DB::table('spamfilter_policy')->insertGetId([
            'sys_userid' => 1, 'sys_groupid' => 1,
            'sys_perm_user' => 'riud', 'sys_perm_group' => 'riud', 'sys_perm_other' => 'r',
            'policy_name' => $name,
        ], 'id');
    }

    protected function seedSpamfilterUser(array $overrides = []): int
    {
        return (int) DB::table('spamfilter_users')->insertGetId(array_merge([
            'sys_userid' => 1, 'sys_groupid' => 1,
            'sys_perm_user' => 'riud', 'sys_perm_group' => 'riud', 'sys_perm_other' => '',
            'server_id' => 1, 'priority' => 5, 'policy_id' => 0,
            'email' => 'mapped@example.com', 'fullname' => 'Mapped', 'local' => 'Y',
        ], $overrides), 'id');
    }

    // ------------------------------------------------------------------
    // Policies
    // ------------------------------------------------------------------

    public function test_policies_crud_lifecycle(): void
    {
        $this->runCrudLifecycle(
            '/api/v1/mail/spamfilter/policies',
            ['policy_name' => 'Strict'],
            ['spam_lover' => 'Y'],
            'spamfilter_policy',
            'id'
        );
    }

    public function test_policy_create_uppercase_flags_perms_and_untouched_columns(): void
    {
        $response = $this->postJson('/api/v1/mail/spamfilter/policies', [
            'policy_name' => 'Strict',
            'virus_lover' => 'Y',
            'spam_quarantine_to' => 'quarantine@example.com',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('policy_name', 'Strict')
            ->assertJsonPath('virus_lover', 'Y') // exact uppercase casing, not booleans
            ->assertJsonPath('spam_lover', 'N')
            ->assertJsonPath('spam_quarantine_to', 'quarantine@example.com')
            ->assertJsonPath('sys_perm_other', 'r') // policy-specific permission
            ->assertJsonPath('sys_perm_user', 'riud')
            ->assertJsonMissingPath('spam_modifies_subj'); // unexposed column

        $id = $response->json('id');

        // Unexposed legacy columns keep their DB defaults.
        $row = DB::table('spamfilter_policy')->where('id', $id)->first();
        $this->assertSame('N', $row->spam_modifies_subj);
        $this->assertSame('N', $row->policyd_greylist);
        $this->assertSame('n', $row->rspamd_greylisting);
        $this->assertNull($row->spam_tag_level);

        $data = $this->assertDatalog('spamfilter_policy', 'i', 'id:'.$id);
        $this->assertSame('Y', $data['new']['virus_lover']);
        $this->assertSame('r', $data['new']['sys_perm_other']);

        // Y/N flags reject lowercase/boolean-ish input.
        $this->postJson('/api/v1/mail/spamfilter/policies', [
            'policy_name' => 'Other', 'spam_lover' => 'y',
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['spam_lover']]);

        // policy_name unique.
        $this->postJson('/api/v1/mail/spamfilter/policies', ['policy_name' => 'Strict'], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['policy_name']]);
    }

    public function test_policy_delete_guard_when_in_use(): void
    {
        $policyId = $this->seedPolicy('Used');
        $this->seedSpamfilterUser(['policy_id' => $policyId]);

        // In use -> 400 (API-added guard, legacy has no in-use check).
        $this->deleteJson('/api/v1/mail/spamfilter/policies/'.$policyId, [], $this->authHeaders())
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->assertDatabaseHas('spamfilter_policy', ['id' => $policyId]);

        DB::table('spamfilter_users')->delete();

        $this->deleteJson('/api/v1/mail/spamfilter/policies/'.$policyId, [], $this->authHeaders())
            ->assertStatus(204);
        $this->assertDatalog('spamfilter_policy', 'd', 'id:'.$policyId);
    }

    // ------------------------------------------------------------------
    // Spamfilter users
    // ------------------------------------------------------------------

    public function test_spamfilter_users_crud_lifecycle(): void
    {
        $this->runCrudLifecycle(
            '/api/v1/mail/spamfilter/users',
            ['server_id' => 1, 'policy_id' => 0, 'email' => 'user@example.com', 'fullname' => 'User'],
            ['priority' => 9],
            'spamfilter_users',
            'id'
        );
    }

    public function test_spamfilter_user_defaults_references_and_immutability(): void
    {
        $policyId = $this->seedPolicy();

        $response = $this->postJson('/api/v1/mail/spamfilter/users', [
            'server_id' => 1, 'policy_id' => $policyId, 'email' => 'USER@MüLLER.De', 'fullname' => 'User',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('email', 'user@xn--mller-kva.de') // IDN + lowercase
            ->assertJsonPath('priority', 5) // contract/form default (auto-sync uses 7)
            ->assertJsonPath('local', 'Y'); // exact uppercase casing

        $id = $response->json('id');

        // Missing policy reference -> 404 per the contract.
        $this->postJson('/api/v1/mail/spamfilter/users', [
            'server_id' => 1, 'policy_id' => 999, 'email' => 'other@example.com', 'fullname' => 'O',
        ], $this->authHeaders())
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json');

        // Duplicate email -> 409 (DB unique key).
        $this->postJson('/api/v1/mail/spamfilter/users', [
            'server_id' => 1, 'policy_id' => 0, 'email' => 'user@xn--mller-kva.de', 'fullname' => 'Dup',
        ], $this->authHeaders())->assertStatus(409);

        // email/server_id immutable; policy reference also guarded on update.
        $this->putJson('/api/v1/mail/spamfilter/users/'.$id, ['email' => 'moved@example.com'], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);

        $this->putJson('/api/v1/mail/spamfilter/users/'.$id, ['server_id' => 2], $this->authHeaders())
            ->assertStatus(422);

        $this->putJson('/api/v1/mail/spamfilter/users/'.$id, ['policy_id' => 999], $this->authHeaders())
            ->assertStatus(404);

        $this->putJson('/api/v1/mail/spamfilter/users/'.$id, ['policy_id' => 0, 'priority' => 10], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('policy_id', 0) // 0 = inherit
            ->assertJsonPath('priority', 10);
    }

    // ------------------------------------------------------------------
    // WB list
    // ------------------------------------------------------------------

    public function test_wblist_crud_lifecycle(): void
    {
        $rid = $this->seedSpamfilterUser();

        $this->runCrudLifecycle(
            '/api/v1/mail/spamfilter/wblist',
            ['server_id' => 1, 'email' => 'spammer@bad.tld', 'rid' => $rid, 'wb' => 'B'],
            ['priority' => 8],
            'spamfilter_wblist',
            'wblist_id'
        );
    }

    public function test_wblist_defaults_rid_reference_and_immutability(): void
    {
        $rid = $this->seedSpamfilterUser();

        $response = $this->postJson('/api/v1/mail/spamfilter/wblist', [
            'server_id' => 1, 'email' => 'friend@good.tld', 'rid' => $rid, 'wb' => 'W',
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('wb', 'W') // exact uppercase casing
            ->assertJsonPath('priority', 5) // legacy form default
            ->assertJsonPath('active', true);

        $id = $response->json('id');

        $data = $this->assertDatalog('spamfilter_wblist', 'i', 'wblist_id:'.$id);
        $this->assertSame('W', $data['new']['wb']);
        $this->assertSame('y', $data['new']['active']);

        // wb defaults to B.
        $this->postJson('/api/v1/mail/spamfilter/wblist', [
            'server_id' => 1, 'email' => 'other@bad.tld', 'rid' => $rid,
        ], $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('wb', 'B');

        // Unresolvable non-zero rid -> 404; rid=0 accepted (Rspamd-inert, C-10).
        $this->postJson('/api/v1/mail/spamfilter/wblist', [
            'server_id' => 1, 'email' => 'x@y.tld', 'rid' => 999,
        ], $this->authHeaders())->assertStatus(404);

        $this->postJson('/api/v1/mail/spamfilter/wblist', [
            'server_id' => 1, 'email' => 'global@y.tld', 'rid' => 0,
        ], $this->authHeaders())->assertStatus(201);

        // email/rid immutable on update.
        $this->putJson('/api/v1/mail/spamfilter/wblist/'.$id, ['email' => 'moved@x.tld'], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);

        $this->putJson('/api/v1/mail/spamfilter/wblist/'.$id, ['rid' => 0], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['rid']]);

        // wb enum rejects lowercase.
        $this->putJson('/api/v1/mail/spamfilter/wblist/'.$id, ['wb' => 'w'], $this->authHeaders())
            ->assertStatus(422);
    }

    public function test_wblist_list_filters(): void
    {
        $rid = $this->seedSpamfilterUser();
        DB::table('spamfilter_wblist')->insert([
            ['server_id' => 1, 'wb' => 'W', 'rid' => $rid, 'email' => 'a@x.tld', 'priority' => 5, 'active' => 'y'],
            ['server_id' => 1, 'wb' => 'B', 'rid' => 0, 'email' => 'b@x.tld', 'priority' => 5, 'active' => 'n'],
        ]);

        $this->getJson('/api/v1/mail/spamfilter/wblist?wb=W', $this->authHeaders())
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/mail/spamfilter/wblist?rid='.$rid, $this->authHeaders())
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/mail/spamfilter/wblist?active=false', $this->authHeaders())
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/mail/spamfilter/wblist?email=a@x.tld', $this->authHeaders())
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    // ------------------------------------------------------------------
    // Config (server.config INI view — C-8)
    // ------------------------------------------------------------------

    public function test_config_endpoints_require_api_key(): void
    {
        $this->getJson('/api/v1/mail/spamfilter/config')->assertStatus(401);
        $this->getJson('/api/v1/mail/spamfilter/config/1')->assertStatus(401);
        $this->putJson('/api/v1/mail/spamfilter/config/1')->assertStatus(401);
    }

    public function test_config_list_and_show_read_the_ini_sections(): void
    {
        // Only server 1 is a mail server; server 2 must not appear.
        $this->getJson('/api/v1/mail/spamfilter/config', $this->authHeaders())
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'limit', 'offset']])
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.server_id', 1)
            ->assertJsonPath('data.0.hostname', 'mail1.example.com')
            ->assertJsonPath('data.0.maildir_path', '/var/vmail/[domain]/[localpart]')
            ->assertJsonPath('data.0.mailuser_uid', 5000);

        $this->getJson('/api/v1/mail/spamfilter/config?hostname=mail1.example.com', $this->authHeaders())
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/mail/spamfilter/config?hostname=nope.example.com', $this->authHeaders())
            ->assertOk()->assertJsonPath('meta.total', 0);

        $this->getJson('/api/v1/mail/spamfilter/config?sort=evil', $this->authHeaders())
            ->assertStatus(400);

        $this->getJson('/api/v1/mail/spamfilter/config/1', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('server_id', 1)
            ->assertJsonPath('ip_address', '192.168.0.105')
            ->assertJsonPath('module', 'postfix_mysql')
            ->assertJsonPath('mailuser_group', 'vmail');

        // Non-mail server and unknown server both 404.
        $this->getJson('/api/v1/mail/spamfilter/config/2', $this->authHeaders())->assertStatus(404);
        $this->getJson('/api/v1/mail/spamfilter/config/99', $this->authHeaders())->assertStatus(404);
    }

    public function test_config_put_read_merge_writes_without_touching_other_keys_or_datalog(): void
    {
        $payload = [
            'ip_address' => '10.0.0.5',
            'netmask' => '255.255.0.0',
            'gateway' => '10.0.0.1',
            'hostname' => 'mx.example.com',
            'nameservers' => '10.0.0.1,10.0.0.2',
            'module' => 'postfix_mysql',
            'maildir_path' => '/srv/vmail/[domain]/[localpart]',
            'homedir_path' => '/srv/vmail',
            'mailuser_uid' => 5001,
            'mailuser_gid' => 5001,
            'mailuser_name' => 'vmail',
            'mailuser_group' => 'vmail',
        ];

        $this->putJson('/api/v1/mail/spamfilter/config/1', $payload, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('ip_address', '10.0.0.5')
            ->assertJsonPath('hostname', 'mx.example.com')
            ->assertJsonPath('maildir_path', '/srv/vmail/[domain]/[localpart]')
            ->assertJsonPath('mailuser_uid', 5001);

        $blob = (string) DB::table('server')->where('server_id', 1)->value('config');

        // Exposed keys replaced...
        $this->assertStringContainsString('ip_address=10.0.0.5', $blob);
        $this->assertStringContainsString('maildir_path=/srv/vmail/[domain]/[localpart]', $blob);
        // ...unexposed keys of the SAME sections preserved...
        $this->assertStringContainsString('firewall=ufw', $blob);
        $this->assertStringContainsString('mail_filter_syntax=sieve', $blob);
        $this->assertStringContainsString('maildir_format=maildir', $blob);
        $this->assertStringContainsString('pop3_imap_daemon=dovecot', $blob);
        // ...and other sections untouched.
        $this->assertStringContainsString("[web]\nwebsite_basedir=/var/www", $blob);

        // Legacy writes server.config directly — NO datalog entry (C-8,
        // documented constitution Principle II exception).
        $this->assertSame(0, DB::table('sys_datalog')->count());

        // PUT against a non-mail server 404s; validation failures 422.
        $this->putJson('/api/v1/mail/spamfilter/config/2', $payload, $this->authHeaders())->assertStatus(404);

        $invalid = $this->putJson('/api/v1/mail/spamfilter/config/1', array_merge($payload, [
            'module' => 'exim', 'ip_address' => 'not-an-ip',
        ]), $this->authHeaders());
        $invalid->assertStatus(422);
        $this->assertArrayHasKey('module', $invalid->json('errors'));
        $this->assertArrayHasKey('ip_address', $invalid->json('errors'));

        $missing = $this->putJson('/api/v1/mail/spamfilter/config/1', [], $this->authHeaders());
        $missing->assertStatus(422); // all fields required (legacy NOTEMPTY)
    }
}
