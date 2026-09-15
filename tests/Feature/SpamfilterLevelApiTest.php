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
class SpamfilterLevelApiTest extends TestCase
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

    /**
     * Decoded datalog entries of spamfilter_users, oldest first.
     *
     * @return array<int, array{action: string, dbidx: string, data: array<string, mixed>}>
     */
    protected function spamfilterDatalog(): array
    {
        return DB::table('sys_datalog')->where('dbtable', 'spamfilter_users')->orderBy('datalog_id')->get()
            ->map(function (object $row): array {
                $data = @unserialize((string) $row->data);

                return [
                    'action' => (string) $row->action,
                    'dbidx' => (string) $row->dbidx,
                    'data' => is_array($data) ? $data : (array) json_decode((string) $row->data, true),
                ];
            })->all();
    }

    protected function spamfilterRow(string $email): ?object
    {
        return DB::table('spamfilter_users')->where('email', $email)->first();
    }

    protected function spamfilterUrl(int $mailbox): string
    {
        return "/api/v1/mail/users/{$mailbox}/spamfilter";
    }

    // ------------------------------------------------------------------
    // US1 — mailbox level
    // ------------------------------------------------------------------

    public function test_mailbox_level_defaults_to_inherit_and_reads_the_stored_value(): void
    {
        $headers = $this->tenantHeaders('clientA');

        $this->getJson($this->spamfilterUrl($this->boxA), $headers)->assertOk()->assertJsonPath('policy_id', 0);

        DB::table('spamfilter_users')->insert($this->ownedBy('clientA', [
            'server_id' => 1, 'priority' => 7, 'policy_id' => 2, 'email' => 'box@a-dom.test', 'fullname' => 'box@a-dom.test', 'local' => 'Y',
        ]));

        $this->getJson($this->spamfilterUrl($this->boxA), $headers)->assertOk()->assertJsonPath('policy_id', 2);
    }

    public function test_setting_a_mailbox_level_inserts_then_updates_the_companion_row(): void
    {
        $headers = $this->tenantHeaders('clientA');
        $tenant = $this->tenant('clientA');

        $this->putJson($this->spamfilterUrl($this->boxA), ['policy_id' => 1], $headers)
            ->assertOk()
            ->assertJsonPath('policy_id', 1)
            ->assertJsonPath('move_junk', 'y');

        $row = $this->spamfilterRow('box@a-dom.test');
        $this->assertNotNull($row);
        $this->assertSame(
            [1, 7, 1, 'box@a-dom.test', 'Y', $tenant['groupid'], $tenant['userid'], 'riud', 'riud', ''],
            [(int) $row->server_id, (int) $row->priority, (int) $row->policy_id, $row->fullname, $row->local,
                (int) $row->sys_groupid, (int) $row->sys_userid, $row->sys_perm_user, $row->sys_perm_group, $row->sys_perm_other]
        );

        $log = $this->spamfilterDatalog();
        $this->assertCount(1, $log);
        $this->assertSame('i', $log[0]['action']);
        $this->assertSame('id:'.$row->id, $log[0]['dbidx']);
        $this->assertSame('1', (string) $log[0]['data']['new']['policy_id']);
        $this->assertSame(0, DB::table('sys_datalog')->where('dbtable', 'mail_user')->count());

        // Same value: nothing journaled.
        $this->putJson($this->spamfilterUrl($this->boxA), ['policy_id' => 1], $headers)->assertOk();
        $this->assertCount(1, $this->spamfilterDatalog());

        // New value: policy_id update only.
        $this->putJson($this->spamfilterUrl($this->boxA), ['policy_id' => 2], $headers)->assertOk()->assertJsonPath('policy_id', 2);
        $log = $this->spamfilterDatalog();
        $this->assertCount(2, $log);
        $this->assertSame('u', $log[1]['action']);
        $this->assertSame(['policy_id'], array_keys(array_diff_assoc(
            array_map('strval', $log[1]['data']['new']),
            array_map('strval', $log[1]['data']['old'])
        )));

        // 0 = inherit again.
        $this->putJson($this->spamfilterUrl($this->boxA), ['policy_id' => 0], $headers)->assertOk()->assertJsonPath('policy_id', 0);
        $this->assertSame(0, (int) $this->spamfilterRow('box@a-dom.test')->policy_id);
        $this->assertSame(1, DB::table('spamfilter_users')->where('email', 'box@a-dom.test')->count());
    }

    public function test_a_row_created_by_another_owner_is_still_updated(): void
    {
        DB::table('spamfilter_users')->insert($this->ownedBy('admin', [
            'server_id' => 1, 'priority' => 7, 'policy_id' => 0, 'email' => 'box@a-dom.test', 'fullname' => 'box@a-dom.test', 'local' => 'Y',
        ]));

        $this->putJson($this->spamfilterUrl($this->boxA), ['policy_id' => 2], $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('policy_id', 2);

        $this->assertSame(2, (int) $this->spamfilterRow('box@a-dom.test')->policy_id);
        $this->assertSame(1, (int) $this->spamfilterRow('box@a-dom.test')->sys_groupid);
    }

    public function test_invalid_mailbox_levels_are_refused(): void
    {
        $headers = $this->tenantHeaders('clientA');

        $missing = $this->putJson($this->spamfilterUrl($this->boxA), ['policy_id' => 999], $headers)->assertStatus(422);
        $private = $this->putJson($this->spamfilterUrl($this->boxA), ['policy_id' => 3], $headers)->assertStatus(422);

        $this->assertSame('The selected policy id is invalid.', $missing->json('errors.policy_id.0'));
        $this->assertSame($missing->json('errors.policy_id'), $private->json('errors.policy_id'));

        $this->putJson($this->spamfilterUrl($this->boxA), ['policy_id' => -1], $headers)->assertStatus(422);
        $this->putJson($this->spamfilterUrl($this->boxA), ['policy_id' => 'abc'], $headers)->assertStatus(422);

        $this->assertSame(0, DB::table('sys_datalog')->count());
        $this->assertNull($this->spamfilterRow('box@a-dom.test'));
    }

    public function test_level_is_not_bound_to_the_mail_filter_tab(): void
    {
        DB::table('sys_ini')->insert(['sysini_id' => 1, 'config' => "[mail]\nmailbox_show_mail_filter_tab=n\n"]);
        $headers = $this->tenantHeaders('clientA');

        $this->putJson($this->spamfilterUrl($this->boxA), ['policy_id' => 1], $headers)->assertOk();
        $this->putJson($this->spamfilterUrl($this->boxA), ['policy_id' => 2, 'move_junk' => 'n'], $headers)->assertStatus(403);
        $this->assertSame(1, (int) $this->spamfilterRow('box@a-dom.test')->policy_id);
    }

    // ------------------------------------------------------------------
    // US2 — domain level
    // ------------------------------------------------------------------

    public function test_domain_level_is_shown_on_show_and_list(): void
    {
        $headers = $this->tenantHeaders('clientA');

        $this->getJson("/api/v1/mail/domains/{$this->domainA}", $headers)->assertOk()->assertJsonPath('spamfilter_policy_id', 0);

        DB::table('spamfilter_users')->insert($this->ownedBy('clientA', [
            'server_id' => 1, 'priority' => 5, 'policy_id' => 2, 'email' => '@a-dom.test', 'fullname' => '@a-dom.test', 'local' => 'Y',
        ]));

        $this->getJson("/api/v1/mail/domains/{$this->domainA}", $headers)->assertOk()->assertJsonPath('spamfilter_policy_id', 2);
        $this->getJson('/api/v1/mail/domains', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.domain', 'a-dom.test')
            ->assertJsonPath('data.0.spamfilter_policy_id', 2);

        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/mail/domains?sort=domain', $this->tenantHeaders('admin'))->assertOk();
        $lookups = array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'spamfilter_users'));
        DB::disableQueryLog();

        $this->assertCount(1, $lookups);
        $this->assertSame([2, 0], array_column($response->json('data'), 'spamfilter_policy_id'));
    }

    public function test_setting_a_domain_level_upserts_the_domain_row(): void
    {
        $headers = $this->tenantHeaders('clientA');
        $tenant = $this->tenant('clientA');

        $this->putJson("/api/v1/mail/domains/{$this->domainA}", ['spamfilter_policy_id' => 1], $headers)
            ->assertOk()
            ->assertJsonPath('spamfilter_policy_id', 1)
            ->assertJsonPath('domain', 'a-dom.test');

        $row = $this->spamfilterRow('@a-dom.test');
        $this->assertNotNull($row);
        $this->assertSame(
            [1, 5, 1, '@a-dom.test', 'Y', $tenant['groupid'], 'riud', 'riud', ''],
            [(int) $row->server_id, (int) $row->priority, (int) $row->policy_id, $row->fullname, $row->local,
                (int) $row->sys_groupid, $row->sys_perm_user, $row->sys_perm_group, $row->sys_perm_other]
        );
        $this->assertSame(['i'], array_column($this->spamfilterDatalog(), 'action'));

        // A domain update without the field leaves the row alone.
        $this->putJson("/api/v1/mail/domains/{$this->domainA}", ['local_delivery' => false], $headers)
            ->assertOk()
            ->assertJsonPath('spamfilter_policy_id', 1);
        $this->assertCount(1, $this->spamfilterDatalog());

        $this->putJson("/api/v1/mail/domains/{$this->domainA}", ['spamfilter_policy_id' => 2], $headers)->assertOk();
        $this->assertSame(['i', 'u'], array_column($this->spamfilterDatalog(), 'action'));
        $this->assertSame(2, (int) $this->spamfilterRow('@a-dom.test')->policy_id);
    }

    public function test_creating_a_domain_with_a_level(): void
    {
        $headers = $this->tenantHeaders('clientA');

        $this->postJson('/api/v1/mail/domains', ['domain' => 'new-a.test', 'spamfilter_policy_id' => 2], $headers)
            ->assertStatus(201)
            ->assertJsonPath('spamfilter_policy_id', 2);

        $this->assertSame(2, (int) $this->spamfilterRow('@new-a.test')->policy_id);
        $this->assertSame($this->tenant('clientA')['groupid'], (int) $this->spamfilterRow('@new-a.test')->sys_groupid);

        // Without the field no domain row is written.
        $this->postJson('/api/v1/mail/domains', ['domain' => 'plain-a.test', 'server_id' => 1], $this->tenantHeaders('admin'))
            ->assertStatus(201)
            ->assertJsonPath('spamfilter_policy_id', 0);
        $this->assertNull($this->spamfilterRow('@plain-a.test'));

        $datalog = DB::table('sys_datalog')->count();
        $this->postJson('/api/v1/mail/domains', ['domain' => 'bad-a.test', 'spamfilter_policy_id' => 3], $headers)
            ->assertStatus(422)
            ->assertJsonPath('errors.spamfilter_policy_id.0', 'The selected spamfilter policy id is invalid.');
        $this->assertDatabaseMissing('mail_domain', ['domain' => 'bad-a.test']);
        $this->assertSame($datalog, DB::table('sys_datalog')->count());
    }

    // ------------------------------------------------------------------
    // US3 — tenant matrix
    // ------------------------------------------------------------------

    public function test_other_accounts_mailboxes_and_domains_are_not_found(): void
    {
        $headers = $this->tenantHeaders('clientB');

        $this->putJson($this->spamfilterUrl($this->boxA), ['policy_id' => 1], $headers)->assertStatus(404);
        $this->putJson("/api/v1/mail/domains/{$this->domainA}", ['spamfilter_policy_id' => 1], $headers)->assertStatus(404);
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_private_policies_are_limited_to_accounts_that_can_read_them(): void
    {
        $this->putJson($this->spamfilterUrl($this->boxA), ['policy_id' => 4], $this->tenantHeaders('clientA'))->assertOk();
        $this->putJson($this->spamfilterUrl($this->boxB), ['policy_id' => 4], $this->tenantHeaders('clientB'))->assertStatus(422);
        $this->putJson("/api/v1/mail/domains/{$this->domainB}", ['spamfilter_policy_id' => 4], $this->tenantHeaders('clientB'))->assertStatus(422);

        // Admin keys may use any policy.
        $this->putJson($this->spamfilterUrl($this->boxB), ['policy_id' => 3], $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertJsonPath('policy_id', 3);

        $ids = fn (string $tenant): array => collect($this->getJson('/api/v1/mail/spamfilter/policies', $this->tenantHeaders($tenant))
            ->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([1, 2, 4], $ids('clientA'));
        $this->assertSame([1, 2], $ids('clientB'));
    }

    public function test_reseller_sets_levels_for_its_clients(): void
    {
        $headers = $this->tenantHeaders('reseller');

        $this->putJson($this->spamfilterUrl($this->boxA), ['policy_id' => 1], $headers)->assertOk()->assertJsonPath('policy_id', 1);
        $this->putJson("/api/v1/mail/domains/{$this->domainA}", ['spamfilter_policy_id' => 2], $headers)
            ->assertOk()
            ->assertJsonPath('spamfilter_policy_id', 2);
        $this->putJson($this->spamfilterUrl($this->boxB), ['policy_id' => 1], $headers)->assertStatus(404);
    }

    public function test_readable_but_not_updatable_records_are_refused(): void
    {
        $domain = $this->seedDomain('admin', 'shared.test', ['sys_perm_other' => 'r']);
        $mailbox = $this->seedMailbox('admin', 'box@shared.test', ['sys_perm_other' => 'r']);
        $headers = $this->tenantHeaders('clientA');

        $this->getJson($this->spamfilterUrl($mailbox), $headers)->assertOk();
        $this->putJson($this->spamfilterUrl($mailbox), ['policy_id' => 1], $headers)
            ->assertStatus(403)
            ->assertJsonPath('detail', 'You do not have permission to update this resource.');
        $this->putJson("/api/v1/mail/domains/{$domain}", ['spamfilter_policy_id' => 1], $headers)->assertStatus(403);

        $this->assertSame(0, DB::table('sys_datalog')->count());
        $this->assertNull($this->spamfilterRow('box@shared.test'));
        $this->assertNull($this->spamfilterRow('@shared.test'));
    }
}
