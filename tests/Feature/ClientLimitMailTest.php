<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MailCompletionSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Client resource-limit COUNTING on the mail module (spec 012 US1 P1 +
 * US2 P2): the -1/0/n matrix on create, admin bypass, no datalog on denial,
 * and the per-type isolation of mail_forwarding's four limit columns.
 */
class ClientLimitMailTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        MailCompletionSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        DB::table('server')->insert([
            'server_id' => 1, 'server_name' => 'mail1', 'mail_server' => 1, 'mirror_server_id' => 0, 'active' => 1,
        ]);
    }

    /**
     * Seed a mail domain owned by the given tenant (so mailboxes/forwards
     * created under it count against that client).
     */
    protected function seedDomain(string $owner, string $domain): int
    {
        return (int) DB::table('mail_domain')->insertGetId($this->ownedBy($owner, [
            'server_id' => 1, 'domain' => $domain, 'active' => 'y',
        ]), 'domain_id');
    }

    // ------------------------------------------------------------------
    // limit_maildomain (P1) — the full matrix
    // ------------------------------------------------------------------

    public function test_maildomain_count_matrix(): void
    {
        // unlimited (-1): create succeeds past any number of rows.
        $this->setClientLimit('clientA', 'limit_maildomain', -1);
        $this->postJson('/api/v1/mail/domains', [
            'server_id' => 1, 'domain' => 'free.test', 'active' => true, 'dkim' => false,
        ], $this->tenantHeaders('clientA'))->assertStatus(201);

        // at cap (n = 1 with one owned row): the next create is denied.
        $this->seedDomain('clientA', 'existing.test');
        $this->setClientLimit('clientA', 'limit_maildomain', 1);
        // (one A-owned row already: free.test — so already 2; keep n=1 explicit)
        DB::table('mail_domain')->where('domain', 'free.test')->delete();

        $datalog = DB::table('sys_datalog')->count();

        $this->postJson('/api/v1/mail/domains', [
            'server_id' => 1, 'domain' => 'blocked.test', 'active' => true, 'dkim' => false,
        ], $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 403)
            ->assertJsonPath('detail', 'You have reached the maximum number of mail domains allowed for your account.');

        // A denial writes NO datalog row.
        $this->assertSame($datalog, DB::table('sys_datalog')->count());
        $this->assertDatabaseMissing('mail_domain', ['domain' => 'blocked.test']);

        // admin key: never limited (bypass past the cap).
        $this->postJson('/api/v1/mail/domains', [
            'server_id' => 1, 'domain' => 'admin.test', 'active' => true, 'dkim' => false,
        ], $this->tenantHeaders('admin'))->assertStatus(201);

        // under cap: raise the limit and the create succeeds again.
        $this->setClientLimit('clientA', 'limit_maildomain', 5);
        $this->postJson('/api/v1/mail/domains', [
            'server_id' => 1, 'domain' => 'ok.test', 'active' => true, 'dkim' => false,
        ], $this->tenantHeaders('clientA'))->assertStatus(201);
    }

    public function test_maildomain_disabled_zero_denies_with_no_rows(): void
    {
        $this->setClientLimit('clientA', 'limit_maildomain', 0);

        $this->postJson('/api/v1/mail/domains', [
            'server_id' => 1, 'domain' => 'zero.test', 'active' => true, 'dkim' => false,
        ], $this->tenantHeaders('clientA'))->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // limit_mailbox (P1)
    // ------------------------------------------------------------------

    public function test_mailbox_count_matrix(): void
    {
        $this->seedDomain('clientA', 'a-dom.test');

        // one owned mailbox, cap = 1 -> the second is denied.
        DB::table('mail_user')->insert($this->ownedBy('clientA', [
            'server_id' => 1, 'email' => 'one@a-dom.test', 'login' => 'one@a-dom.test',
        ]));
        $this->setClientLimit('clientA', 'limit_mailbox', 1);

        $this->postJson('/api/v1/mail/users', [
            'email' => 'two@a-dom.test', 'password' => 'Secret123!', 'name' => 'Two', 'quota' => 0,
        ], $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('detail', 'You have reached the maximum number of mailboxes allowed for your account.');

        // under cap.
        $this->setClientLimit('clientA', 'limit_mailbox', 2);
        $this->postJson('/api/v1/mail/users', [
            'email' => 'two@a-dom.test', 'password' => 'Secret123!', 'name' => 'Two', 'quota' => 0,
        ], $this->tenantHeaders('clientA'))->assertStatus(201);

        // admin bypass.
        $this->postJson('/api/v1/mail/users', [
            'email' => 'three@a-dom.test', 'password' => 'Secret123!', 'name' => 'Three', 'quota' => 0,
        ], $this->tenantHeaders('admin'))->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // mail_forwarding per-type isolation (P2): alias/forward/catchall/
    // aliasdomain each map to their own limit column.
    // ------------------------------------------------------------------

    public function test_forwarding_types_are_counted_independently(): void
    {
        $this->seedDomain('clientA', 'a-dom.test');

        // One existing alias, cap limit_mailalias = 1; limit_mailforward is
        // unlimited (-1 default).
        DB::table('mail_forwarding')->insert($this->ownedBy('clientA', [
            'server_id' => 1, 'source' => 'a1@a-dom.test', 'destination' => 'box@a-dom.test',
            'type' => 'alias', 'active' => 'y',
        ]));
        $this->setClientLimit('clientA', 'limit_mailalias', 1);

        // alias at cap -> 403 naming aliases.
        $this->postJson('/api/v1/mail/forwards', [
            'type' => 'alias', 'source' => 'a2@a-dom.test', 'destination' => 'box@a-dom.test',
        ], $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('detail', 'You have reached the maximum number of mail aliases allowed for your account.');

        // forward under its own (unlimited) cap still succeeds — type isolation.
        $this->postJson('/api/v1/mail/forwards', [
            'type' => 'forward', 'source' => 'f1@a-dom.test', 'destination' => 'ext@other.tld',
        ], $this->tenantHeaders('clientA'))->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // Access-gated (011) resources: the count layer engages once booked
    // (limit_mailrouting = n > 0).
    // ------------------------------------------------------------------

    public function test_mail_transport_count_layer_after_booking(): void
    {
        // Book the feature for both client and its reseller (n = 1).
        $this->setClientLimit('clientA', 'limit_mailrouting', 1);
        $this->setClientLimit('reseller', 'limit_mailrouting', 5);

        DB::table('mail_transport')->insert($this->ownedBy('clientA', [
            'server_id' => 1, 'domain' => 'r1.test', 'transport' => 'smtp:relay1.test', 'active' => 'y',
        ]));

        $this->postJson('/api/v1/mail/transports', [
            'server_id' => 1, 'domain' => 'r2.test', 'transport' => 'smtp:relay2.test',
        ], $this->tenantHeaders('clientA'))
            ->assertStatus(403)
            ->assertJsonPath('detail', 'You have reached the maximum number of mail transports allowed for your account.');

        // Raise the cap -> the create goes through.
        $this->setClientLimit('clientA', 'limit_mailrouting', 3);
        $this->postJson('/api/v1/mail/transports', [
            'server_id' => 1, 'domain' => 'r2.test', 'transport' => 'smtp:relay2.test',
        ], $this->tenantHeaders('clientA'))->assertStatus(201);
    }
}
