<?php

namespace Tests\Feature;

use App\Support\ProblemType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MailCompletionSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * Spec 028: mailbox passwords follow the installation password policy for
 * every key type (legacy validate_password on mail_user.tform.php), the four
 * access switches with their Dovecot companion columns
 * (mail_user_edit.php onAfterInsert/Update), and the spec 019 lock guard on
 * disablesmtp.
 */
class MailboxAccessPasswordPolicyTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const GOOD = 'The chosen password does not match the security guidelines. It has to be at least 8 chars in length and have a strength of "Good".';

    private int $box;

    protected function setUp(): void
    {
        parent::setUp();

        MailCompletionSchema::create();
        TenantSchema::create();

        if (! Schema::hasTable('sys_ini')) {
            Schema::create('sys_ini', function (Blueprint $table): void {
                $table->increments('sysini_id');
                $table->text('config')->nullable();
            });
        }

        $this->seedTenants();

        foreach (['clientA', 'reseller'] as $tenant) {
            $this->assignServers($tenant, ['mail' => [1]]);
        }

        DB::table('server')->insert([
            'server_id' => 1, 'server_name' => 'mail1', 'mail_server' => 1, 'mirror_server_id' => 0, 'active' => 1,
            'config' => "[mail]\nmaildir_path=/var/vmail/[domain]/[localpart]\nhomedir_path=/var/vmail\n",
        ]);

        DB::table('mail_domain')->insert($this->ownedBy('clientA', ['server_id' => 1, 'domain' => 'a-dom.test', 'active' => 'y']));
        $this->box = $this->seedMailbox('box@a-dom.test');
    }

    protected function seedMailbox(string $email, array $attrs = []): int
    {
        return (int) DB::table('mail_user')->insertGetId($this->ownedBy('clientA', array_merge([
            'server_id' => 1, 'email' => $email, 'login' => $email, 'name' => 'Box', 'postfix' => 'y',
            'password' => 'OLD_HASH', 'maildir' => '/var/vmail/a-dom.test/box', 'homedir' => '/var/vmail',
        ], $attrs)), 'mailuser_id');
    }

    protected function setPolicy(?string $length, ?string $strength, ?string $asciiOnly = null): void
    {
        $lines = ['[mail]'];

        if ($asciiOnly !== null) {
            $lines[] = "mail_password_onlyascii={$asciiOnly}";
        }

        $lines[] = '[misc]';

        if ($length !== null) {
            $lines[] = "min_password_length={$length}";
        }

        if ($strength !== null) {
            $lines[] = "min_password_strength={$strength}";
        }

        DB::table('sys_ini')->updateOrInsert(['sysini_id' => 1], ['config' => implode("\n", $lines)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function mailbox(string $local, string $password, array $extra = []): array
    {
        return array_merge(['email' => "{$local}@a-dom.test", 'name' => 'New box', 'password' => $password, 'quota' => 0], $extra);
    }

    protected function column(int $mailbox, string $column): string
    {
        return (string) DB::table('mail_user')->where('mailuser_id', $mailbox)->value($column);
    }

    // ------------------------------------------------------------------
    // US1 — password policy
    // ------------------------------------------------------------------

    public function test_mailbox_passwords_follow_length_and_strength_policy(): void
    {
        $this->setPolicy('8', '3');

        foreach (['clientA', 'admin'] as $tenant) {
            $this->postJson('/api/v1/mail/users', $this->mailbox("weak-{$tenant}", 'abcdefgh'), $this->tenantHeaders($tenant))
                ->assertStatus(422)
                ->assertJsonPath('errors.password.0', self::GOOD);
        }

        $this->assertSame(0, DB::table('sys_datalog')->count());
        $this->assertDatabaseMissing('mail_user', ['email' => 'weak-clientA@a-dom.test']);

        $this->postJson('/api/v1/mail/users', $this->mailbox('strong', 'Abcdef12'), $this->tenantHeaders('clientA'))
            ->assertStatus(201);
    }

    public function test_length_only_message_and_default_policy(): void
    {
        $headers = $this->tenantHeaders('clientA');

        $this->setPolicy('10', '');
        $this->postJson('/api/v1/mail/users', $this->mailbox('nine', 'abcdefghi'), $headers)
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'The chosen password does not match the security guidelines. It has to be at least 10 chars in length.');
        $this->postJson('/api/v1/mail/users', $this->mailbox('ten', 'abcdefghij'), $headers)->assertStatus(201);

        // No settings: ISPConfig defaults (8 characters, no strength).
        DB::table('sys_ini')->delete();
        $this->postJson('/api/v1/mail/users', $this->mailbox('seven', 'abcdefg'), $headers)
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'The chosen password does not match the security guidelines. It has to be at least 8 chars in length.');
        $this->postJson('/api/v1/mail/users', $this->mailbox('eight', 'abcdefgh'), $headers)->assertStatus(201);
    }

    public function test_empty_minimum_and_ascii_only_mode(): void
    {
        $headers = $this->tenantHeaders('clientA');

        $this->setPolicy('', '');
        $this->postJson('/api/v1/mail/users', $this->mailbox('tiny', 'abc'), $headers)->assertStatus(201);

        $this->setPolicy('12', '5', 'y');
        $this->postJson('/api/v1/mail/users', $this->mailbox('unicode', 'pässwort-long-1'), $headers)
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'Please do not use special unicode characters for your password. This could lead to problems with your mail client.');
        $this->postJson('/api/v1/mail/users', $this->mailbox('asciishort', 'short'), $headers)->assertStatus(201);
    }

    public function test_password_changes_follow_the_policy(): void
    {
        $this->setPolicy('8', '3');
        $headers = $this->tenantHeaders('clientA');

        $this->putJson("/api/v1/mail/users/{$this->box}/password", ['password' => 'abcdefgh'], $headers)
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', self::GOOD);
        $this->putJson("/api/v1/mail/users/{$this->box}", ['password' => 'weakweak'], $headers)
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', self::GOOD);
        $this->putJson("/api/v1/mail/users/{$this->box}/password", ['password' => 'abcdefgh'], $this->tenantHeaders('admin'))
            ->assertStatus(422);

        $this->assertSame(0, DB::table('sys_datalog')->count());
        $this->assertSame('OLD_HASH', $this->column($this->box, 'password'));

        $this->putJson("/api/v1/mail/users/{$this->box}/password", ['password' => 'Abcdef12'], $headers)->assertOk();
        $hash = $this->column($this->box, 'password');
        $this->assertStringStartsWith('$6$', $hash);

        // An empty password on the mailbox update keeps the password and skips the policy.
        $this->putJson("/api/v1/mail/users/{$this->box}", ['password' => '', 'name' => 'Renamed'], $headers)->assertOk();
        $this->assertSame($hash, $this->column($this->box, 'password'));
    }

    // ------------------------------------------------------------------
    // US2 — access switches
    // ------------------------------------------------------------------

    public function test_switches_on_create_set_the_companion_columns(): void
    {
        $response = $this->postJson('/api/v1/mail/users', $this->mailbox('switched', 'Abcdef12', [
            'disableimap' => true, 'disablepop3' => false, 'disabledeliver' => 'y',
        ]), $this->tenantHeaders('clientA'));

        $response->assertStatus(201)
            ->assertJsonPath('disableimap', true)
            ->assertJsonPath('disablepop3', false)
            ->assertJsonPath('disablesmtp', false)
            ->assertJsonPath('disabledeliver', true);

        $id = (int) $response->json('id');

        foreach (['disableimap' => 'y', 'disablesieve' => 'y', 'disablesieve-filter' => 'y', 'disablepop3' => 'n',
            'disablesmtp' => 'n', 'disabledeliver' => 'y', 'disablelda' => 'y', 'disablelmtp' => 'y'] as $column => $value) {
            $this->assertSame($value, $this->column($id, $column), $column);
        }

        $entry = DB::table('sys_datalog')->where('dbtable', 'mail_user')->where('action', 'i')->first();
        $data = unserialize((string) $entry->data);
        $this->assertSame('y', $data['new']['disablesieve-filter']);
        $this->assertSame('y', $data['new']['disablelmtp']);

        $listed = collect($this->getJson('/api/v1/mail/users', $this->tenantHeaders('clientA'))->assertOk()->json('data'))
            ->firstWhere('id', $id);
        $this->assertTrue($listed['disableimap']);
        $this->assertFalse($listed['disablesmtp']);
    }

    public function test_updating_switches_writes_one_datalog_entry(): void
    {
        $headers = $this->tenantHeaders('clientA');
        $url = "/api/v1/mail/users/{$this->box}";

        $this->getJson($url, $headers)
            ->assertOk()
            ->assertJsonPath('disableimap', false)
            ->assertJsonPath('disablepop3', false)
            ->assertJsonPath('disablesmtp', false)
            ->assertJsonPath('disabledeliver', false);

        $this->putJson($url, ['disablepop3' => true], $headers)->assertOk()->assertJsonPath('disablepop3', true);
        $this->assertSame('y', $this->column($this->box, 'disablepop3'));
        $this->assertSame('n', $this->column($this->box, 'disablesieve'));
        $this->assertSame(1, DB::table('sys_datalog')->where('dbtable', 'mail_user')->where('action', 'u')->count());

        $this->putJson($url, ['disableimap' => 'y'], $headers)->assertOk()->assertJsonPath('disableimap', true);
        $this->assertSame('y', $this->column($this->box, 'disablesieve'));
        $this->assertSame('y', $this->column($this->box, 'disablesieve-filter'));

        $this->putJson($url, ['disabledeliver' => true], $headers)->assertOk();
        $this->assertSame('y', $this->column($this->box, 'disablelda'));
        $this->assertSame('y', $this->column($this->box, 'disablelmtp'));

        $this->putJson($url, ['disableimap' => false, 'disabledeliver' => 'n'], $headers)->assertOk();
        foreach (['disableimap', 'disablesieve', 'disablesieve-filter', 'disabledeliver', 'disablelda', 'disablelmtp'] as $column) {
            $this->assertSame('n', $this->column($this->box, $column), $column);
        }
        $this->assertSame(4, DB::table('sys_datalog')->where('dbtable', 'mail_user')->where('action', 'u')->count());

        // Companion columns are only recalculated when their switch is sent.
        $legacy = $this->seedMailbox('legacy@a-dom.test', ['disablesieve' => 'y']);
        $this->putJson("/api/v1/mail/users/{$legacy}", ['name' => 'Renamed'], $headers)->assertOk();
        $this->assertSame('y', $this->column($legacy, 'disablesieve'));

        $this->putJson($url, ['disableimap' => 'maybe'], $headers)->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // US3 — lock guard
    // ------------------------------------------------------------------

    public function test_locked_account_cannot_switch_sending_back_on(): void
    {
        DB::table('client')->where('client_id', $this->tenant('clientA')['client_id'])->update(['locked' => 'y']);
        DB::table('mail_user')->where('mailuser_id', $this->box)->update(['disablesmtp' => 'y']);
        $sending = $this->seedMailbox('sending@a-dom.test');
        $url = "/api/v1/mail/users/{$this->box}";
        $datalog = DB::table('sys_datalog')->count();

        foreach (['clientA', 'reseller'] as $tenant) {
            $this->putJson($url, ['disablesmtp' => false], $this->tenantHeaders($tenant))
                ->assertStatus(403)
                ->assertJsonPath('type', ProblemType::uri(ProblemType::ACCOUNT_LOCKED));
        }

        $this->assertSame($datalog, DB::table('sys_datalog')->count());
        $this->assertSame('y', $this->column($this->box, 'disablesmtp'));

        $this->putJson($url, ['disablepop3' => true], $this->tenantHeaders('clientA'))->assertOk();
        $this->putJson("/api/v1/mail/users/{$sending}", ['disablesmtp' => true], $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('disablesmtp', true);

        $this->putJson($url, ['disablesmtp' => false], $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertJsonPath('disablesmtp', false);
    }
}
