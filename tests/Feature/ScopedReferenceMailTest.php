<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MailCompletionSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Spec 024 US1: mail writes by non-admin keys may only reference mail
 * domains, mailboxes and spam filter users the key can read (legacy
 * mail_user_edit.php:181-185, mail_forward_edit.php:102-104,
 * mail_alias_edit.php:104-112, mail_domain_catchall_edit.php:96-98,
 * mail_aliasdomain_edit.php:99-105). A foreign reference is reported
 * exactly like a nonexistent one and writes nothing.
 */
class ScopedReferenceMailTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        MailCompletionSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        foreach (['clientA', 'clientB', 'reseller'] as $tenant) {
            $this->assignServers($tenant, ['mail' => [1]]);
        }

        DB::table('server')->insert([
            'server_id' => 1, 'server_name' => 'mail1', 'mail_server' => 1, 'mirror_server_id' => 0, 'active' => 1,
        ]);

        $this->seedDomain('clientA', 'a-dom.test');
        $this->seedDomain('clientA', 'a2-dom.test');
        $this->seedDomain('clientB', 'b-dom.test');

        $this->seedMailbox('clientA', 'box@a-dom.test');
        $this->seedMailbox('clientB', 'box@b-dom.test');
    }

    protected function seedDomain(string $owner, string $domain): int
    {
        return (int) DB::table('mail_domain')->insertGetId($this->ownedBy($owner, [
            'server_id' => 1, 'domain' => $domain, 'active' => 'y',
        ]), 'domain_id');
    }

    protected function seedMailbox(string $owner, string $email): int
    {
        return (int) DB::table('mail_user')->insertGetId($this->ownedBy($owner, [
            'server_id' => 1, 'email' => $email, 'login' => $email, 'name' => 'Box', 'postfix' => 'y',
        ]), 'mailuser_id');
    }

    protected function seedForward(string $owner, string $type, string $source, string $destination): int
    {
        return (int) DB::table('mail_forwarding')->insertGetId($this->ownedBy($owner, [
            'server_id' => 1, 'type' => $type, 'source' => $source, 'destination' => $destination, 'active' => 'y',
        ]), 'forwarding_id');
    }

    protected function seedSpamfilterUser(string $owner, string $email): int
    {
        return (int) DB::table('spamfilter_users')->insertGetId($this->ownedBy($owner, [
            'server_id' => 1, 'email' => $email, 'policy_id' => 1,
        ]));
    }

    // ------------------------------------------------------------------
    // Mailboxes
    // ------------------------------------------------------------------

    public function test_mailbox_on_a_foreign_domain_is_rejected_like_a_missing_domain(): void
    {
        $datalog = DB::table('sys_datalog')->count();

        $payload = fn (string $email): array => [
            'email' => $email, 'password' => 'Secret123!', 'name' => 'Intruder', 'quota' => 0,
        ];

        $missing = $this->postJson('/api/v1/mail/users', $payload('x@missing.test'), $this->tenantHeaders('clientA'))
            ->assertStatus(400);
        $foreign = $this->postJson('/api/v1/mail/users', $payload('x@b-dom.test'), $this->tenantHeaders('clientA'))
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->assertSame(
            str_replace('missing.test', 'b-dom.test', (string) $missing->json('detail')),
            $foreign->json('detail')
        );
        $this->assertSame($missing->json('title'), $foreign->json('title'));

        $this->assertDatabaseMissing('mail_user', ['email' => 'x@b-dom.test']);
        $this->assertDatabaseMissing('spamfilter_users', ['email' => 'x@b-dom.test']);
        $this->assertSame($datalog, DB::table('sys_datalog')->count());

        // Own domain still works.
        $this->postJson('/api/v1/mail/users', $payload('x@a-dom.test'), $this->tenantHeaders('clientA'))
            ->assertStatus(201);
    }

    public function test_updating_a_readable_mailbox_on_a_foreign_domain_is_rejected(): void
    {
        // Legacy data: A owns a mailbox whose domain belongs to B.
        $mailbox = $this->seedMailbox('clientA', 'stray@b-dom.test');
        $datalog = DB::table('sys_datalog')->count();

        $this->putJson("/api/v1/mail/users/{$mailbox}", ['name' => 'Renamed'], $this->tenantHeaders('clientA'))
            ->assertStatus(400)
            ->assertJsonPath('detail', "The domain 'b-dom.test' is not an existing mail domain.");

        $this->assertSame($datalog, DB::table('sys_datalog')->count());
        $this->assertDatabaseHas('mail_user', [
            'mailuser_id' => $mailbox, 'name' => 'Box', 'sys_groupid' => $this->tenant('clientA')['groupid'],
        ]);
    }

    public function test_reseller_may_use_its_clients_domain_but_not_a_foreign_one(): void
    {
        $this->assignServers('reseller', ['mail' => [1]]);

        $this->postJson('/api/v1/mail/users', [
            'email' => 'res@a-dom.test', 'password' => 'Secret123!', 'name' => 'Reseller', 'quota' => 0,
        ], $this->tenantHeaders('reseller'))->assertStatus(201);

        $this->postJson('/api/v1/mail/users', [
            'email' => 'res@b-dom.test', 'password' => 'Secret123!', 'name' => 'Reseller', 'quota' => 0,
        ], $this->tenantHeaders('reseller'))->assertStatus(400);
    }

    public function test_admin_may_create_mailboxes_on_any_domain(): void
    {
        $this->postJson('/api/v1/mail/users', [
            'email' => 'adm@b-dom.test', 'password' => 'Secret123!', 'name' => 'Admin', 'quota' => 0,
        ], $this->tenantHeaders('admin'))->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // Forwards, aliases, catch-alls
    // ------------------------------------------------------------------

    public function test_forward_alias_and_catchall_sources_on_a_foreign_domain_are_rejected(): void
    {
        $datalog = DB::table('sys_datalog')->count();

        $cases = [
            ['type' => 'forward', 'source' => 'postmaster@b-dom.test', 'destination' => 'attacker@evil.tld'],
            ['type' => 'catchall', 'source' => '@b-dom.test', 'destination' => 'attacker@evil.tld'],
            ['type' => 'alias', 'source' => 'sales@b-dom.test', 'destination' => 'box@a-dom.test'],
        ];

        foreach ($cases as $case) {
            $this->postJson('/api/v1/mail/forwards', $case, $this->tenantHeaders('clientA'))
                ->assertStatus(400)
                ->assertJsonPath('detail', "The domain 'b-dom.test' is not an existing mail domain.");
        }

        $this->assertSame(0, DB::table('mail_forwarding')->where('source', 'like', '%b-dom.test')->count());
        $this->assertSame($datalog, DB::table('sys_datalog')->count());
    }

    public function test_alias_destination_must_be_a_readable_mailbox(): void
    {
        $datalog = DB::table('sys_datalog')->count();

        $missing = $this->postJson('/api/v1/mail/forwards', [
            'type' => 'alias', 'source' => 'al@a-dom.test', 'destination' => 'nobody@a-dom.test',
        ], $this->tenantHeaders('clientA'))->assertStatus(422);

        $foreign = $this->postJson('/api/v1/mail/forwards', [
            'type' => 'alias', 'source' => 'al@a-dom.test', 'destination' => 'box@b-dom.test',
        ], $this->tenantHeaders('clientA'))->assertStatus(422);

        $this->assertNotEmpty($foreign->json('errors.destination'));
        $this->assertSame($missing->json('errors.destination'), $foreign->json('errors.destination'));

        // One foreign address in a list rejects the whole alias.
        $this->postJson('/api/v1/mail/forwards', [
            'type' => 'alias', 'source' => 'al@a-dom.test', 'destination' => 'box@a-dom.test, box@b-dom.test',
        ], $this->tenantHeaders('clientA'))->assertStatus(422)->assertJsonValidationErrors('destination');

        $this->assertSame($datalog, DB::table('sys_datalog')->count());

        // Own mailbox is fine; forwards and catch-alls may still point outside.
        $this->postJson('/api/v1/mail/forwards', [
            'type' => 'alias', 'source' => 'al@a-dom.test', 'destination' => 'box@a-dom.test',
        ], $this->tenantHeaders('clientA'))->assertStatus(201);

        $this->postJson('/api/v1/mail/forwards', [
            'type' => 'forward', 'source' => 'fw@a-dom.test', 'destination' => 'box@b-dom.test, ext@other.tld',
        ], $this->tenantHeaders('clientA'))->assertStatus(201);

        $this->postJson('/api/v1/mail/forwards', [
            'type' => 'catchall', 'source' => '@a-dom.test', 'destination' => 'ext@other.tld',
        ], $this->tenantHeaders('clientA'))->assertStatus(201);
    }

    public function test_alias_update_cannot_point_to_a_foreign_mailbox(): void
    {
        $alias = $this->seedForward('clientA', 'alias', 'al2@a-dom.test', 'box@a-dom.test');
        $forward = $this->seedForward('clientA', 'forward', 'fw2@a-dom.test', 'ext@other.tld');
        $datalog = DB::table('sys_datalog')->count();

        $this->putJson("/api/v1/mail/forwards/{$alias}", ['destination' => 'box@b-dom.test'], $this->tenantHeaders('clientA'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('destination');

        $this->assertSame($datalog, DB::table('sys_datalog')->count());

        $this->putJson("/api/v1/mail/forwards/{$forward}", ['destination' => 'box@b-dom.test'], $this->tenantHeaders('clientA'))
            ->assertStatus(200);
    }

    public function test_admin_alias_destinations_are_unchanged(): void
    {
        $this->postJson('/api/v1/mail/forwards', [
            'type' => 'alias', 'source' => 'adm@a-dom.test', 'destination' => 'box@b-dom.test',
        ], $this->tenantHeaders('admin'))->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // Alias domains
    // ------------------------------------------------------------------

    public function test_alias_domain_sides_must_be_readable_mail_domains(): void
    {
        $datalog = DB::table('sys_datalog')->count();

        $this->postJson('/api/v1/mail/alias-domains', ['source' => '@b-dom.test', 'destination' => '@a-dom.test'], $this->tenantHeaders('clientA'))
            ->assertStatus(400)
            ->assertJsonPath('detail', "The domain 'b-dom.test' is not an existing mail domain.");

        $this->postJson('/api/v1/mail/alias-domains', ['source' => '@a2-dom.test', 'destination' => '@b-dom.test'], $this->tenantHeaders('clientA'))
            ->assertStatus(400)
            ->assertJsonPath('detail', "The domain 'b-dom.test' is not an existing mail domain.");

        $this->assertSame($datalog, DB::table('sys_datalog')->count());

        $id = $this->postJson('/api/v1/mail/alias-domains', ['source' => '@a2-dom.test', 'destination' => '@a-dom.test'], $this->tenantHeaders('clientA'))
            ->assertStatus(201)
            ->json('id');

        $this->putJson("/api/v1/mail/alias-domains/{$id}", ['destination' => '@b-dom.test'], $this->tenantHeaders('clientA'))
            ->assertStatus(400)
            ->assertJsonPath('detail', "The domain 'b-dom.test' is not an existing mail domain.");
    }

    // ------------------------------------------------------------------
    // Spam filter allow/deny list
    // ------------------------------------------------------------------

    public function test_wblist_rid_of_a_foreign_spamfilter_user_is_reported_as_missing(): void
    {
        $this->setClientLimit('clientA', 'limit_spamfilter_wblist', -1);
        $this->setClientLimit('reseller', 'limit_spamfilter_wblist', -1); // clientA's parent reseller cap

        $own = $this->seedSpamfilterUser('clientA', 'box@a-dom.test');
        $foreign = $this->seedSpamfilterUser('clientB', 'box@b-dom.test');
        $datalog = DB::table('sys_datalog')->count();

        $this->postJson('/api/v1/mail/spamfilter/wblist', [
            'server_id' => 1, 'rid' => $foreign, 'email' => 'friend@else.test',
        ], $this->tenantHeaders('clientA'))
            ->assertStatus(404)
            ->assertJsonPath('detail', "Spamfilter user {$foreign} does not exist.");

        $this->assertSame($datalog, DB::table('sys_datalog')->count());

        $this->postJson('/api/v1/mail/spamfilter/wblist', [
            'server_id' => 1, 'rid' => $own, 'email' => 'friend@else.test',
        ], $this->tenantHeaders('clientA'))->assertStatus(201);
    }
}
