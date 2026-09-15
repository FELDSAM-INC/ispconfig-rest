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
 * Spec 025 US3: mailbox sub-resources follow the legacy tab rules for client
 * and reseller keys (mail_user.tform.php:356 autoresponder tab, :427 mail
 * filter tab, :474 custom rules tab for administrators only). Admin keys are
 * not restricted; refusals journal nothing.
 */
class MailTabEnforcementTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private int $mailbox;

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
        $this->assignServers('clientA', ['mail' => [1]]);

        DB::table('server')->insert([
            'server_id' => 1, 'server_name' => 'mail1', 'mail_server' => 1, 'mirror_server_id' => 0, 'active' => 1,
            'config' => "[mail]\nmail_filter_syntax=sieve\npop3_imap_daemon=dovecot\n",
        ]);

        DB::table('mail_domain')->insert($this->ownedBy('clientA', ['server_id' => 1, 'domain' => 'a-dom.test', 'active' => 'y']));

        $this->mailbox = (int) DB::table('mail_user')->insertGetId($this->ownedBy('clientA', [
            'server_id' => 1, 'email' => 'box@a-dom.test', 'login' => 'box@a-dom.test', 'name' => 'Box',
            'maildir' => '/var/vmail/a-dom.test/box', 'homedir' => '/var/vmail', 'postfix' => 'y',
        ]), 'mailuser_id');
    }

    protected function setTabs(?string $autoresponder, ?string $mailFilter): void
    {
        $lines = ['[mail]', 'mailbox_show_custom_rules_tab=y'];

        if ($autoresponder !== null) {
            $lines[] = "mailbox_show_autoresponder_tab={$autoresponder}";
        }

        if ($mailFilter !== null) {
            $lines[] = "mailbox_show_mail_filter_tab={$mailFilter}";
        }

        DB::table('sys_ini')->updateOrInsert(['sysini_id' => 1], ['config' => implode("\n", $lines)]);
    }

    protected function url(string $suffix): string
    {
        return "/api/v1/mail/users/{$this->mailbox}{$suffix}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function autoresponder(): array
    {
        return ['autoresponder' => true, 'autoresponder_subject' => 'Away', 'autoresponder_text' => 'Back soon.'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function filter(): array
    {
        return [
            'rulename' => 'News', 'source' => 'Subject', 'op' => 'contains', 'searchterm' => 'newsletter',
            'action' => 'move', 'target' => 'Junk',
        ];
    }

    protected function seedFilter(): int
    {
        return (int) DB::table('mail_user_filter')->insertGetId($this->ownedBy('clientA', array_merge($this->filter(), [
            'mailuser_id' => $this->mailbox, 'active' => 'y',
        ])), 'filter_id');
    }

    protected function assertFeatureRefused($response, string $setting): void
    {
        $response->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', ProblemType::uri(ProblemType::FEATURE_NOT_ALLOWED))
            ->assertJsonPath('feature', $setting);
    }

    public function test_custom_mail_filter_is_administrator_only(): void
    {
        $this->setTabs('y', 'y');
        $datalog = DB::table('sys_datalog')->count();

        foreach (['clientA', 'reseller'] as $tenant) {
            $this->putJson($this->url('/spamfilter'), [
                'move_junk' => 'n',
                'custom_mailfilter' => 'require "fileinto";',
            ], $this->tenantHeaders($tenant))
                ->assertStatus(422)
                ->assertJsonPath('errors.custom_mailfilter.0', 'Custom mail filter rules can only be changed with an administrator key.')
                ->assertJsonPath('error_types.custom_mailfilter', ProblemType::uri(ProblemType::FEATURE_NOT_ALLOWED));
        }

        // An unchanged or empty value is refused as well: the field is not part of the customer form.
        $this->putJson($this->url('/spamfilter'), ['custom_mailfilter' => null], $this->tenantHeaders('clientA'))
            ->assertStatus(422);

        $this->assertSame($datalog, DB::table('sys_datalog')->count());
        $this->assertDatabaseHas('mail_user', ['mailuser_id' => $this->mailbox, 'move_junk' => 'y', 'custom_mailfilter' => null]);

        // The field stays readable.
        $this->getJson($this->url('/spamfilter'), $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('custom_mailfilter', null);

        $this->putJson($this->url('/spamfilter'), ['custom_mailfilter' => 'require "fileinto";'], $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertJsonPath('custom_mailfilter', 'require "fileinto";');
    }

    public function test_autoresponder_writes_need_the_autoresponder_tab(): void
    {
        $this->setTabs('n', 'y');
        $datalog = DB::table('sys_datalog')->count();

        foreach (['clientA', 'reseller'] as $tenant) {
            $this->assertFeatureRefused(
                $this->putJson($this->url('/autoresponder'), $this->autoresponder(), $this->tenantHeaders($tenant)),
                'mailbox_show_autoresponder_tab'
            );
        }

        $response = $this->deleteJson($this->url('/autoresponder'), [], $this->tenantHeaders('clientA'));
        $this->assertFeatureRefused($response, 'mailbox_show_autoresponder_tab');
        $response->assertJsonPath('detail', 'Autoresponders are not enabled on this installation.');

        $this->getJson($this->url('/autoresponder'), $this->tenantHeaders('clientA'))->assertOk();
        $this->assertSame($datalog, DB::table('sys_datalog')->count());

        // Mail filter writes are not affected by the autoresponder tab.
        $this->putJson($this->url('/spamfilter'), ['move_junk' => 'n'], $this->tenantHeaders('clientA'))->assertOk();

        $this->putJson($this->url('/autoresponder'), $this->autoresponder(), $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertJsonPath('autoresponder', true);
        $this->deleteJson($this->url('/autoresponder'), [], $this->tenantHeaders('admin'))->assertNoContent();
    }

    public function test_mail_filter_writes_need_the_mail_filter_tab(): void
    {
        $this->setTabs('y', 'n');
        $filterId = $this->seedFilter();
        $headers = $this->tenantHeaders('clientA');
        $datalog = DB::table('sys_datalog')->count();

        $this->assertFeatureRefused($this->postJson($this->url('/filters'), $this->filter(), $headers), 'mailbox_show_mail_filter_tab');
        $this->assertFeatureRefused($this->putJson($this->url("/filters/{$filterId}"), ['rulename' => 'Renamed'], $headers), 'mailbox_show_mail_filter_tab');
        $this->assertFeatureRefused($this->deleteJson($this->url("/filters/{$filterId}"), [], $headers), 'mailbox_show_mail_filter_tab');
        $this->assertFeatureRefused($this->putJson($this->url('/spamfilter'), ['move_junk' => 'n'], $headers), 'mailbox_show_mail_filter_tab');
        $this->assertFeatureRefused($this->putJson($this->url('/spamfilter'), ['purge_trash_days' => 7], $headers), 'mailbox_show_mail_filter_tab');
        $this->assertFeatureRefused(
            $this->putJson($this->url('/spamfilter'), ['purge_junk_days' => 7], $this->tenantHeaders('reseller')),
            'mailbox_show_mail_filter_tab'
        );

        $this->assertSame($datalog, DB::table('sys_datalog')->count());

        $this->getJson($this->url('/filters'), $headers)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->url("/filters/{$filterId}"), $headers)->assertOk();
        $this->getJson($this->url('/spamfilter'), $headers)->assertOk();

        // Autoresponder writes are not affected by the mail filter tab.
        $this->putJson($this->url('/autoresponder'), $this->autoresponder(), $headers)->assertOk();

        $admin = $this->tenantHeaders('admin');
        $this->postJson($this->url('/filters'), $this->filter(), $admin)->assertStatus(201);
        $this->putJson($this->url('/spamfilter'), ['move_junk' => 'a'], $admin)->assertOk()->assertJsonPath('move_junk', 'a');
    }

    public function test_client_writes_succeed_when_tabs_are_enabled(): void
    {
        $this->setTabs('y', 'y');
        $headers = $this->tenantHeaders('clientA');

        $this->putJson($this->url('/autoresponder'), $this->autoresponder(), $headers)->assertOk();
        $this->deleteJson($this->url('/autoresponder'), [], $headers)->assertNoContent();
        $this->putJson($this->url('/spamfilter'), ['move_junk' => 'n', 'purge_trash_days' => 30], $headers)
            ->assertOk()
            ->assertJsonPath('move_junk', 'n');

        $filterId = $this->postJson($this->url('/filters'), $this->filter(), $headers)->assertStatus(201)->json('id');
        $this->putJson($this->url("/filters/{$filterId}"), ['rulename' => 'Renamed'], $headers)->assertOk();
        $this->deleteJson($this->url("/filters/{$filterId}"), [], $headers)->assertNoContent();
    }

    public function test_missing_settings_count_as_enabled(): void
    {
        $headers = $this->tenantHeaders('clientA');

        // No sys_ini row at all.
        $this->putJson($this->url('/autoresponder'), $this->autoresponder(), $headers)->assertOk();
        $this->postJson($this->url('/filters'), $this->filter(), $headers)->assertStatus(201);

        // A [mail] section without the switches.
        $this->setTabs(null, null);
        $this->putJson($this->url('/spamfilter'), ['move_junk' => 'n'], $headers)->assertOk();
        $this->deleteJson($this->url('/autoresponder'), [], $headers)->assertNoContent();
    }
}
