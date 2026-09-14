<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\ClientApiTestCase;
use Tests\Support\LockRecordFixtures;
use Tests\Support\MailCompletionSchema;
use Tests\Support\SitesSchema;

/**
 * Spec 019 US1 — locking and unlocking a client through PUT /clients/{id}
 * (legacy functions.inc.php func_client_lock parity, snapshot in
 * client.tmp_data).
 */
class ClientLockApiTest extends ClientApiTestCase
{
    use LockRecordFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        SitesSchema::create();
        MailCompletionSchema::create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Acme Inc.',
            'contact_name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'username' => 'johndoe',
            'password' => 's3cr3tP@ssw0rd',
        ], $overrides);
    }

    /**
     * Lock-list records of the matrix with their table and key column.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    protected function matrixKeys(): array
    {
        return [
            'cron' => ['cron', 'id'],
            'ftp_user' => ['ftp_user', 'ftp_user_id'],
            'mail_domain' => ['mail_domain', 'domain_id'],
            'mail_forwarding' => ['mail_forwarding', 'forwarding_id'],
            'mail_get' => ['mail_get', 'mailget_id'],
            'shell_user' => ['shell_user', 'shell_user_id'],
            'webdav_user' => ['webdav_user', 'webdav_user_id'],
            'web_database' => ['web_database', 'database_id'],
            'site_active' => ['web_domain', 'domain_id'],
            'web_folder' => ['web_folder', 'web_folder_id'],
            'web_folder_user' => ['web_folder_user', 'web_folder_user_id'],
        ];
    }

    protected function column(string $table, string $key, int $id, string $column): string
    {
        return (string) DB::table($table)->where($key, $id)->value($column);
    }

    /**
     * @return array{clientId: int, groupId: int, userId: int, ids: array<string, int>}
     */
    protected function seedLockableClient(array $clientOverrides = []): array
    {
        $clientId = $this->seedClient(array_merge(['username' => 'jdoe'], $clientOverrides));
        ['groupId' => $groupId, 'userId' => $userId] = $this->seedClientLogin($clientId, 'jdoe');

        return [
            'clientId' => $clientId,
            'groupId' => $groupId,
            'userId' => $userId,
            'ids' => $this->seedLockMatrix($groupId, $userId),
        ];
    }

    public function test_lock_disables_services_through_datalog_and_stores_legacy_snapshot(): void
    {
        ['clientId' => $clientId, 'userId' => $userId, 'ids' => $ids] = $this->seedLockableClient([
            'tmp_data' => serialize(['custom' => 'kept']),
        ]);

        $otherClient = $this->seedClient(['username' => 'other', 'email' => 'other@example.com']);
        ['groupId' => $otherGroup] = $this->seedClientLogin($otherClient, 'other');
        $otherSite = $this->seedLockRecord('web_domain', 'domain_id', $otherGroup, $this->siteAttrs('other.test', 'y'));

        $this->putJson('/api/v1/clients/'.$clientId, ['locked' => true], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('locked', true);

        foreach ($this->matrixKeys() as $name => [$table, $key]) {
            $this->assertSame('n', $this->column($table, $key, $ids[$name], 'active'), "{$name} should be disabled");
            $this->assertSame((string) $userId, $this->column($table, $key, $ids[$name], 'sys_userid'), "{$name} owner");
        }

        $this->assertSame('n', $this->column('web_domain', 'domain_id', $ids['site_disabled'], 'active'));
        foreach (['mail_smtp_on', 'mail_smtp_off'] as $mailbox) {
            $this->assertSame('n', $this->column('mail_user', 'mailuser_id', $ids[$mailbox], 'postfix'));
            $this->assertSame('y', $this->column('mail_user', 'mailuser_id', $ids[$mailbox], 'disablesmtp'));
            $this->assertSame((string) $userId, $this->column('mail_user', 'mailuser_id', $ids[$mailbox], 'sys_userid'));
        }

        // Other clients are untouched.
        $this->assertSame('y', $this->column('web_domain', 'domain_id', $otherSite, 'active'));

        // One datalog 'u' per changed record, legacy table order, two passes on mail_user.
        $rows = DB::table('sys_datalog')->where('dbtable', '!=', 'client')->orderBy('datalog_id')->get();
        $this->assertSame([
            ['cron', 'id:'.$ids['cron']],
            ['ftp_user', 'ftp_user_id:'.$ids['ftp_user']],
            ['mail_domain', 'domain_id:'.$ids['mail_domain']],
            ['mail_user', 'mailuser_id:'.$ids['mail_smtp_on']],
            ['mail_user', 'mailuser_id:'.$ids['mail_smtp_off']],
            ['mail_user', 'mailuser_id:'.$ids['mail_smtp_on']],
            ['mail_forwarding', 'forwarding_id:'.$ids['mail_forwarding']],
            ['mail_get', 'mailget_id:'.$ids['mail_get']],
            ['shell_user', 'shell_user_id:'.$ids['shell_user']],
            ['webdav_user', 'webdav_user_id:'.$ids['webdav_user']],
            ['web_database', 'database_id:'.$ids['web_database']],
            ['web_domain', 'domain_id:'.$ids['site_active']],
            ['web_folder', 'web_folder_id:'.$ids['web_folder']],
            ['web_folder_user', 'web_folder_user_id:'.$ids['web_folder_user']],
        ], $rows->map(fn ($row): array => [$row->dbtable, $row->dbidx])->all());

        $this->assertSame(['u'], $rows->pluck('action')->unique()->values()->all());

        $site = unserialize($rows->firstWhere('dbidx', 'domain_id:'.$ids['site_active'])->data);
        $this->assertSame('y', $site['old']['active']);
        $this->assertSame('n', $site['new']['active']);
        $this->assertSame('1', $site['old']['sys_userid']);
        $this->assertSame((string) $userId, $site['new']['sys_userid']);

        $this->assertSame(1, DB::table('sys_datalog')->distinct()->count('session_id'));

        // Snapshot byte-identical to legacy serialize().
        $prevActive = $this->emptySnapshotTables();
        $prevActive['mail_user'] = [$ids['mail_smtp_off'] => ['disablesmtp' => 'y']];
        $prevActive['web_domain'] = [$ids['site_disabled'] => ['active' => 'n']];

        $prevOwner = $this->emptySnapshotTables();
        $prevOwner['cron'] = [$ids['cron'] => '1'];
        $prevOwner['ftp_user'] = [$ids['ftp_user'] => '1'];
        $prevOwner['mail_domain'] = [$ids['mail_domain'] => '1'];
        $prevOwner['mail_user'] = [$ids['mail_smtp_on'] => '1', $ids['mail_smtp_off'] => '1'];
        $prevOwner['mail_forwarding'] = [$ids['mail_forwarding'] => '1'];
        $prevOwner['mail_get'] = [$ids['mail_get'] => '1'];
        $prevOwner['shell_user'] = [$ids['shell_user'] => '1'];
        $prevOwner['webdav_user'] = [$ids['webdav_user'] => '1'];
        $prevOwner['web_database'] = [$ids['web_database'] => '1'];
        $prevOwner['web_domain'] = [$ids['site_active'] => '1'];
        $prevOwner['web_folder'] = [$ids['web_folder'] => '1'];
        $prevOwner['web_folder_user'] = [$ids['web_folder_user'] => '1'];

        $this->assertSame(
            serialize(['custom' => 'kept', 'prev_active' => $prevActive, 'prev_sys_userid' => $prevOwner]),
            DB::table('client')->where('client_id', $clientId)->value('tmp_data')
        );
    }

    public function test_unlock_restores_previous_states_and_drops_prev_active(): void
    {
        ['clientId' => $clientId, 'userId' => $userId, 'ids' => $ids] = $this->seedLockableClient([
            'tmp_data' => serialize(['custom' => 'kept']),
        ]);

        $this->putJson('/api/v1/clients/'.$clientId, ['locked' => true], $this->authHeaders())->assertOk();
        $this->putJson('/api/v1/clients/'.$clientId, ['locked' => false], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('locked', false);

        foreach ($this->matrixKeys() as $name => [$table, $key]) {
            $this->assertSame('y', $this->column($table, $key, $ids[$name], 'active'), "{$name} should be enabled");
            $this->assertSame((string) $userId, $this->column($table, $key, $ids[$name], 'sys_userid'), "{$name} owner");
        }

        $this->assertSame('n', $this->column('web_domain', 'domain_id', $ids['site_disabled'], 'active'));
        $this->assertSame('y', $this->column('mail_user', 'mailuser_id', $ids['mail_smtp_on'], 'postfix'));
        $this->assertSame('n', $this->column('mail_user', 'mailuser_id', $ids['mail_smtp_on'], 'disablesmtp'));
        $this->assertSame('y', $this->column('mail_user', 'mailuser_id', $ids['mail_smtp_off'], 'postfix'));
        $this->assertSame('y', $this->column('mail_user', 'mailuser_id', $ids['mail_smtp_off'], 'disablesmtp'));

        $snapshot = unserialize((string) DB::table('client')->where('client_id', $clientId)->value('tmp_data'));
        $this->assertSame(['custom', 'prev_sys_userid'], array_keys($snapshot));
        $this->assertSame('1', $snapshot['prev_sys_userid']['cron'][$ids['cron']]);
    }

    public function test_unlock_of_a_legacy_written_snapshot_matches_legacy(): void
    {
        $clientId = $this->seedClient(['username' => 'jdoe', 'locked' => 'y']);
        ['groupId' => $groupId, 'userId' => $userId] = $this->seedClientLogin($clientId, 'jdoe');

        $site = $this->seedLockRecord('web_domain', 'domain_id', $groupId, $this->siteAttrs('site.test', 'n'), $userId);
        $disabledBefore = $this->seedLockRecord('web_domain', 'domain_id', $groupId, $this->siteAttrs('old.test', 'n'), $userId);
        $mailbox = $this->seedLockRecord('mail_user', 'mailuser_id', $groupId, $this->mailUserAttrs('m@site.test', 'n', 'y'), $userId);

        $prevActive = $this->emptySnapshotTables();
        $prevActive['mail_user'] = [$mailbox => ['disablesmtp' => 'y']];
        $prevActive['web_domain'] = [$disabledBefore => ['active' => 'n']];
        $prevOwner = $this->emptySnapshotTables();
        $prevOwner['web_domain'] = [$site => '1'];

        DB::table('client')->where('client_id', $clientId)->update([
            'tmp_data' => serialize(['prev_active' => $prevActive, 'prev_sys_userid' => $prevOwner]),
        ]);

        $this->putJson('/api/v1/clients/'.$clientId, ['locked' => false], $this->authHeaders())->assertOk();

        $this->assertSame('y', $this->column('web_domain', 'domain_id', $site, 'active'));
        $this->assertSame((string) $userId, $this->column('web_domain', 'domain_id', $site, 'sys_userid'));
        $this->assertSame('n', $this->column('web_domain', 'domain_id', $disabledBefore, 'active'));
        $this->assertSame('y', $this->column('mail_user', 'mailuser_id', $mailbox, 'postfix'));
        $this->assertSame('y', $this->column('mail_user', 'mailuser_id', $mailbox, 'disablesmtp'));

        $this->assertSame(
            serialize(['prev_sys_userid' => $prevOwner]),
            DB::table('client')->where('client_id', $clientId)->value('tmp_data')
        );
    }

    public function test_unlock_with_an_unreadable_snapshot_enables_everything(): void
    {
        $clientId = $this->seedClient(['username' => 'jdoe', 'locked' => 'y', 'tmp_data' => 'not a serialized array']);
        ['groupId' => $groupId, 'userId' => $userId] = $this->seedClientLogin($clientId, 'jdoe');

        $site = $this->seedLockRecord('web_domain', 'domain_id', $groupId, $this->siteAttrs('site.test', 'n'), $userId);
        $mailbox = $this->seedLockRecord('mail_user', 'mailuser_id', $groupId, $this->mailUserAttrs('m@site.test', 'n', 'y'), $userId);

        $this->putJson('/api/v1/clients/'.$clientId, ['locked' => false], $this->authHeaders())->assertOk();

        $this->assertSame('y', $this->column('web_domain', 'domain_id', $site, 'active'));
        $this->assertSame('y', $this->column('mail_user', 'mailuser_id', $mailbox, 'postfix'));
        $this->assertSame('n', $this->column('mail_user', 'mailuser_id', $mailbox, 'disablesmtp'));
        $this->assertSame(serialize([]), DB::table('client')->where('client_id', $clientId)->value('tmp_data'));
    }

    public function test_updates_that_do_not_change_locked_have_no_side_effects(): void
    {
        ['clientId' => $clientId, 'ids' => $ids] = $this->seedLockableClient();

        $this->putJson('/api/v1/clients/'.$clientId, ['contact_name' => 'Jane Doe'], $this->authHeaders())->assertOk();
        $this->assertSame(['client'], DB::table('sys_datalog')->pluck('dbtable')->unique()->values()->all());

        $this->putJson('/api/v1/clients/'.$clientId, ['locked' => false], $this->authHeaders())->assertOk();
        $this->assertSame(1, DB::table('sys_datalog')->count());
        $this->assertSame('y', $this->column('web_domain', 'domain_id', $ids['site_active'], 'active'));
        $this->assertNull(DB::table('client')->where('client_id', $clientId)->value('tmp_data'));

        $this->putJson('/api/v1/clients/'.$clientId, ['locked' => true], $this->authHeaders())->assertOk();
        $afterLock = DB::table('sys_datalog')->count();
        $snapshot = DB::table('client')->where('client_id', $clientId)->value('tmp_data');

        $this->putJson('/api/v1/clients/'.$clientId, ['locked' => true, 'contact_name' => 'Jane Doe'], $this->authHeaders())->assertOk();
        $this->assertSame($afterLock, DB::table('sys_datalog')->count());
        $this->assertSame($snapshot, DB::table('client')->where('client_id', $clientId)->value('tmp_data'));
    }

    public function test_locked_on_create_only_stores_the_flag(): void
    {
        $id = $this->postJson('/api/v1/clients', $this->validPayload(['locked' => true]), $this->authHeaders())
            ->assertStatus(201)
            ->assertJsonPath('locked', true)
            ->json('id');

        $this->assertSame('y', DB::table('client')->where('client_id', $id)->value('locked'));
        $this->assertNull(DB::table('client')->where('client_id', $id)->value('tmp_data'));
        $this->assertSame(0, DB::table('sys_datalog')->whereNotIn('dbtable', ['client', 'sys_group'])->count());
    }

    public function test_lock_of_a_client_without_login_rows_succeeds(): void
    {
        $clientId = $this->seedClient(['username' => 'nologin']);

        $this->putJson('/api/v1/clients/'.$clientId, ['locked' => true], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('locked', true);

        $this->assertSame(0, DB::table('sys_datalog')->where('dbtable', '!=', 'client')->count());
    }
}
