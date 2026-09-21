<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\DnsSchema;
use Tests\Support\MailCompletionSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Spec 026: spam filter level of mailboxes (`policy_id` on
 * /mail/users/{id}/spamfilter) and mail domains (`spamfilter_policy_id`) for
 * every key type, stored in the companion spamfilter_users rows like legacy
 * mail_user_edit.php / mail_domain_edit.php.
 */
class MailRecipientWBListTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private int $boxA;

    private int $boxB;

    private int $domainA;

    private int $domainB;

    protected function setUp(): void
    {
        parent::setUp();

        MailCompletionSchema::create();
        DnsSchema::create();
        TenantSchema::create();

        if (! Schema::hasTable('sys_ini')) {
            Schema::create('sys_ini', function (Blueprint $table): void {
                $table->increments('sysini_id');
                $table->text('config')->nullable();
            });
        }

        $this->seedTenants();
        $this->setClientLimit('reseller', 'limit_spamfilter_wblist', -1);

        foreach (['clientA', 'clientB', 'reseller'] as $tenant) {
            $this->assignServers($tenant, ['mail' => [1]]);
        }

        DB::table('server')->insert([
            'server_id' => 1, 'server_name' => 'mail1', 'mail_server' => 1, 'mirror_server_id' => 0, 'active' => 1,
            'config' => "[mail]\nmail_filter_syntax=sieve\n",
        ]);

        $this->domainA = $this->seedDomain('clientA', 'a-dom.test');
        $this->domainB = $this->seedDomain('clientB', 'b-dom.test');
        $this->boxA = $this->seedMailbox('clientA', 'box@a-dom.test');
        $this->boxB = $this->seedMailbox('clientB', 'box@b-dom.test');

        // 1, 2: administrator policies readable by everyone (ISPConfig default);
        // 3: administrator policy without world read; 4: client A's own policy.
        foreach ([[1, 'Normal', 'r'], [2, 'Permissive', 'r'], [3, 'Private', '']] as [$id, $name, $other]) {
            DB::table('spamfilter_policy')->insert($this->ownedBy('admin', ['id' => $id, 'policy_name' => $name, 'sys_perm_other' => $other]));
        }

        DB::table('spamfilter_policy')->insert($this->ownedBy('clientA', ['id' => 4, 'policy_name' => 'Own A']));
    }

    protected function seedDomain(string $owner, string $domain, array $attrs = []): int
    {
        return (int) DB::table('mail_domain')->insertGetId($this->ownedBy($owner, array_merge([
            'server_id' => 1, 'domain' => $domain, 'active' => 'y',
        ], $attrs)), 'domain_id');
    }

    protected function seedMailbox(string $owner, string $email, array $attrs = []): int
    {
        return (int) DB::table('mail_user')->insertGetId($this->ownedBy($owner, array_merge([
            'server_id' => 1, 'email' => $email, 'login' => $email, 'name' => 'Box', 'postfix' => 'y',
            'maildir' => '/var/vmail/x', 'homedir' => '/var/vmail',
        ], $attrs)), 'mailuser_id');
    }

    public function test_creates_recipient_profile_and_preserves_policy(): void
    {
        $this->setClientLimit('clientA', 'limit_spamfilter_wblist', -1);
        $headers = $this->tenantHeaders('clientA');
        $url = "/api/v1/mail/users/{$this->boxA}/wblist";
        $this->postJson($url, ['email' => '@sender.test', 'wb' => 'B'], $headers)->assertCreated()
            ->assertJsonPath('server_id', 1)->assertJsonPath('active', true);
        $profile = DB::table('spamfilter_users')->where('email', 'box@a-dom.test')->first();
        $this->assertSame(0, (int) $profile->policy_id);
        $this->assertSame(7, (int) $profile->priority);
        $this->assertDatabaseHas('spamfilter_wblist', ['rid' => $profile->id, 'email' => '@sender.test']);
        DB::table('spamfilter_users')->where('id', $profile->id)->update(['policy_id' => 2]);
        $this->postJson($url, ['email' => 'friend@sender.test', 'wb' => 'W'], $headers)->assertCreated();
        $this->assertSame(1, DB::table('spamfilter_users')->where('email', 'box@a-dom.test')->count());
        $this->assertDatabaseHas('spamfilter_users', ['id' => $profile->id, 'policy_id' => 2]);
        $this->postJson("/api/v1/mail/domains/{$this->domainA}/wblist", ['email' => '@sender.test', 'wb' => 'W'], $headers)->assertCreated();
        $this->assertDatabaseHas('spamfilter_users', ['email' => '@a-dom.test', 'priority' => 5]);
    }

    public function test_recipient_ownership_permissions_and_limits(): void
    {
        $this->setClientLimit('clientA', 'limit_spamfilter_wblist', 1);
        $headers = $this->tenantHeaders('clientA');
        $body = ['email' => '@sender.test', 'wb' => 'B'];
        $this->postJson("/api/v1/mail/users/{$this->boxB}/wblist", $body, $headers)->assertNotFound();
        $this->postJson("/api/v1/mail/domains/{$this->domainB}/wblist", $body, $headers)->assertNotFound();
        $readonly = $this->seedMailbox('clientA', 'readonly@a-dom.test', ['sys_perm_user' => 'r', 'sys_perm_group' => 'r']);
        $this->postJson("/api/v1/mail/users/{$readonly}/wblist", $body, $headers)->assertForbidden();
        $this->postJson("/api/v1/mail/users/{$this->boxA}/wblist", $body + ['rid' => 123, 'server_id' => 99], $headers)->assertUnprocessable();
        $this->postJson("/api/v1/mail/users/{$this->boxA}/wblist", $body, $headers)->assertCreated();
        $this->postJson("/api/v1/mail/domains/{$this->domainA}/wblist", $body, $headers)->assertForbidden();
        $this->assertDatabaseMissing('spamfilter_users', ['email' => '@a-dom.test']);
        $this->setClientLimit('clientA', 'limit_spamfilter_wblist', 0);
        $this->postJson("/api/v1/mail/users/{$this->boxA}/wblist", $body, $headers)->assertForbidden();
    }

    public function test_fetchmail_list_filters_exact_destination_and_never_returns_password(): void
    {
        $this->setClientLimit('clientA', 'limit_fetchmail', -1);
        $this->setClientLimit('reseller', 'limit_fetchmail', -1);
        $this->seedMailbox('clientA', 'second@a-dom.test');
        $headers = $this->tenantHeaders('clientA');
        foreach (['box@a-dom.test', 'second@a-dom.test'] as $destination) {
            $this->postJson('/api/v1/mail/fetchmail', [
                'destination' => $destination, 'type' => 'imapssl',
                'source_server' => 'mail.remote.test', 'source_username' => 'remote',
                'source_password' => 'never-return-this',
            ], $headers)->assertCreated()->assertJsonMissingPath('source_password');
        }
        $this->getJson('/api/v1/mail/fetchmail?destination=box%40a-dom.test', $headers)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.destination', 'box@a-dom.test')
            ->assertJsonMissingPath('data.0.source_password');
        $this->getJson('/api/v1/mail/fetchmail?destination=box%40b-dom.test', $headers)
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_reseller_created_rule_remains_visible_to_recipient_customer(): void
    {
        $this->setClientLimit('clientA', 'limit_spamfilter_wblist', -1);
        $response = $this->postJson("/api/v1/mail/users/{$this->boxA}/wblist", [
            'email' => '@sender.test', 'wb' => 'B',
        ], $this->tenantHeaders('reseller'))->assertCreated();
        $this->getJson('/api/v1/mail/spamfilter/wblist/'.$response->json('id'), $this->tenantHeaders('clientA'))
            ->assertOk()->assertJsonPath('email', '@sender.test');
        $this->getJson('/api/v1/mail/spamfilter/wblist/'.$response->json('id'), $this->tenantHeaders('clientB'))
            ->assertNotFound();
    }
}
