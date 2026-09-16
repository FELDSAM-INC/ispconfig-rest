<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MailCompletionSchema;
use Tests\Support\SitesSchema;
use Tests\Support\TenantFixtures;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * GET /me/capabilities (spec 021 US1, api/modules/me/capabilities.yaml): the
 * website plan options, PHP modes and lock state of the key's account or a
 * named client — the rules spec 020 enforces — and the mail options of spec 025.
 */
class MeCapabilitiesApiTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    private const WEB_KEYS = [
        'ssl', 'ssl_letsencrypt', 'wildcard', 'cgi', 'ssi', 'perl', 'ruby', 'python', 'error_documents',
        'directive_snippets', 'suexec_forced', 'backup', 'advanced_options', 'php_modes', 'php_default_mode',
    ];

    private const MAIL_KEYS = [
        'autoresponder', 'mail_filters', 'custom_rules', 'spamfilter_policy', 'dkim', 'custom_login', 'password_policy',
    ];

    private const SITES_KEYS = ['prefixes', 'databases', 'shell', 'cron', 'password_policy'];

    protected function setUp(): void
    {
        parent::setUp();

        SitesSchema::create();
        MailCompletionSchema::create();
        TenantSchema::create();
        $this->seedTenants();

        foreach (['clientA', 'clientB', 'reseller'] as $tenant) {
            $this->assignServers($tenant, ['web' => [1]]);
        }

        DB::table('client')->where('client_id', $this->tenant('reseller')['client_id'])->update(['limit_client' => 10]);

        DB::table('server')->insert([
            'server_id' => 1,
            'server_name' => 'web1',
            'web_server' => 1,
            'db_server' => 0,
            'mail_server' => 0,
            'mirror_server_id' => 0,
            'active' => 1,
            'config' => implode("\n", [
                '[web]',
                'server_type=apache',
                'website_path=/var/www/clients/client[client_id]/web[website_id]',
                'php_open_basedir=[website_path]/web:[website_path]/tmp',
                'htaccess_allow_override=All',
                'enable_sni=y',
                'php_fpm_default_chroot=n',
                '[server]',
                'ip_address=10.0.0.1',
                'log_retention=30',
            ]),
        ]);
    }

    protected function setSites(?string $webPhpOptions, string $resellerCanUseOptions = 'n'): void
    {
        DB::table('sys_ini')->updateOrInsert(['sysini_id' => 1], [
            'config' => implode("\n", array_filter([
                '[sites]',
                'dbname_prefix=c[CLIENTID]',
                $webPhpOptions !== null ? 'web_php_options='.$webPhpOptions : null,
                "reseller_can_use_options={$resellerCanUseOptions}",
                '[misc]',
                'ssh_authentication=',
            ])),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    protected function setClient(string $tenant, array $attrs): void
    {
        DB::table('client')->where('client_id', $this->tenant($tenant)['client_id'])->update($attrs);
    }

    protected function seedVhost(string $owner): int
    {
        $id = (int) DB::table('web_domain')->insertGetId($this->ownedBy($owner, [
            'server_id' => 1, 'ip_address' => '*', 'domain' => 'v'.uniqid().'.test', 'type' => 'vhost',
            'parent_domain_id' => 0, 'vhost_type' => 'name', 'hd_quota' => -1, 'traffic_quota' => -1,
            'active' => 'y', 'allow_override' => 'All', 'backup_copies' => 1, 'cgi' => 'n', 'ssi' => 'n',
            'perl' => 'n', 'ruby' => 'n', 'python' => 'n', 'suexec' => 'y', 'errordocs' => 0,
            'subdomain' => 'www', 'ssl' => 'n', 'ssl_letsencrypt' => 'n', 'directive_snippets_id' => 0,
            'php' => 'no', 'server_php_id' => 0,
        ]), 'domain_id');

        DB::table('web_domain')->where('domain_id', $id)->update([
            'document_root' => "/var/www/clients/client/web{$id}",
            'system_user' => "web{$id}", 'system_group' => 'client',
        ]);

        return $id;
    }

    public function test_requires_api_key(): void
    {
        $this->getJson('/api/v1/me/capabilities')->assertStatus(401);
    }

    public function test_client_key_reads_its_plan_options(): void
    {
        $this->setSites('no,fast-cgi,cgi,mod,suphp,php-fpm,hhvm');
        $this->setClient('clientA', [
            'limit_ssl' => 'y', 'limit_ssl_letsencrypt' => 'n', 'limit_wildcard' => 'n', 'force_suexec' => 'y',
            'limit_cgi' => 'y', 'limit_ssi' => 'n', 'limit_perl' => 'y', 'limit_ruby' => 'n', 'limit_python' => 'y',
            'limit_hterror' => 'y', 'limit_directive_snippets' => 'n', 'limit_backup' => 'n',
            'web_php_options' => 'no,php-fpm',
        ]);

        $response = $this->getJson('/api/v1/me/capabilities', $this->tenantHeaders('clientA'));

        $response->assertOk()->assertExactJson([
            'client_id' => $this->tenant('clientA')['client_id'],
            'account_type' => 'client',
            'locked' => false,
            'canceled' => false,
            'web' => [
                'ssl' => true,
                'ssl_letsencrypt' => false,
                'wildcard' => false,
                'cgi' => true,
                'ssi' => false,
                'perl' => true,
                'ruby' => false,
                'python' => true,
                'error_documents' => true,
                'directive_snippets' => false,
                'suexec_forced' => true,
                'backup' => false,
                'advanced_options' => false,
                'php_modes' => ['no', 'php-fpm'],
                'php_default_mode' => 'php-fpm',
            ],
            'mail' => [
                'autoresponder' => true,
                'mail_filters' => true,
                'custom_rules' => false,
                'spamfilter_policy' => false,
                'dkim' => false,
                'custom_login' => false,
                'password_policy' => ['min_length' => 8, 'min_strength' => 0, 'ascii_only' => false],
            ],
            'sites' => [
                'prefixes' => [
                    'database' => 'c'.$this->tenant('clientA')['client_id'],
                    'database_user' => '',
                    'ftp_user' => '',
                    'shell_user' => '',
                    'webdav_user' => '',
                ],
                'databases' => ['quota_limit_mb' => null, 'remote_access' => true],
                'shell' => ['available' => false, 'chroot_options' => [], 'authentication' => 'password_or_key'],
                'cron' => ['types' => ['url'], 'min_interval_minutes' => 5],
                // spec 038: installation defaults — no min_password_* in the fixture
                'password_policy' => ['min_length' => 8, 'min_strength' => 0],
            ],
        ]);
        $this->assertSame(self::WEB_KEYS, array_keys($response->json('web')));
        $this->assertSame(self::MAIL_KEYS, array_keys($response->json('mail')));
        $this->assertSame(self::SITES_KEYS, array_keys($response->json('sites')));
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_sites_prefixes_are_resolved_for_the_account(): void
    {
        DB::table('sys_ini')->updateOrInsert(['sysini_id' => 1], [
            'config' => implode("\n", [
                '[sites]',
                'dbname_prefix=c[CLIENTID]_',
                'dbuser_prefix=c[CLIENTID]_',
                'ftpuser_prefix=[CLIENTNAME]_',
                'shelluser_prefix=[CLIENTNAME]_',
                'webdavuser_prefix=web[DOMAINID]_',
            ]),
        ]);

        $clientId = $this->tenant('clientA')['client_id'];

        $response = $this->getJson('/api/v1/me/capabilities', $this->tenantHeaders('clientA'))->assertOk();

        // [CLIENTID]/[CLIENTNAME] resolved like the write endpoints; [DOMAINID]
        // stays unresolved — a capability describes the account, not a website.
        $this->assertSame([
            'database' => 'c'.$clientId.'_',
            'database_user' => 'c'.$clientId.'_',
            'ftp_user' => 'clienta_',
            'shell_user' => 'clienta_',
            'webdav_user' => 'web[DOMAINID]_',
        ], $response->json('sites.prefixes'));

        // A reseller reading one of its clients gets that client's prefixes.
        $this->getJson('/api/v1/me/capabilities?client_id='.$clientId, $this->tenantHeaders('reseller'))
            ->assertOk()
            ->assertJsonPath('sites.prefixes.database', 'c'.$clientId.'_')
            ->assertJsonPath('sites.prefixes.ftp_user', 'clienta_');

        // An installation without prefixes reports empty strings.
        DB::table('sys_ini')->updateOrInsert(['sysini_id' => 1], ['config' => "[sites]\n[misc]\n"]);

        $this->getJson('/api/v1/me/capabilities', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('sites.prefixes', [
                'database' => '',
                'database_user' => '',
                'ftp_user' => '',
                'shell_user' => '',
                'webdav_user' => '',
            ]);
    }

    public function test_sites_plan_options(): void
    {
        $this->setSites(null);
        $this->setClient('clientA', [
            'limit_database_quota' => 2048,
            'limit_shell_user' => 2,
            'ssh_chroot' => 'no,jailkit',
            'limit_cron_type' => 'chrooted',
            'limit_cron_frequency' => 15,
        ]);

        $response = $this->getJson('/api/v1/me/capabilities', $this->tenantHeaders('clientA'))->assertOk();

        $this->assertSame(['quota_limit_mb' => 2048, 'remote_access' => true], $response->json('sites.databases'));
        $this->assertSame(
            ['available' => true, 'chroot_options' => ['no', 'jailkit'], 'authentication' => 'password_or_key'],
            $response->json('sites.shell')
        );
        $this->assertSame(['types' => ['url', 'chrooted'], 'min_interval_minutes' => 15], $response->json('sites.cron'));

        // Unlimited quota, no shell access, unconstrained interval, every kind.
        $this->setClient('clientA', [
            'limit_database_quota' => -1,
            'limit_shell_user' => 0,
            'limit_cron_type' => 'full',
            'limit_cron_frequency' => 1,
        ]);

        $response = $this->getJson('/api/v1/me/capabilities', $this->tenantHeaders('clientA'))->assertOk();

        $this->assertNull($response->json('sites.databases.quota_limit_mb'));
        $this->assertSame(
            ['available' => false, 'chroot_options' => [], 'authentication' => 'password_or_key'],
            $response->json('sites.shell')
        );
        $this->assertSame(['types' => ['url', 'chrooted', 'full'], 'min_interval_minutes' => null], $response->json('sites.cron'));

        // Only modes ISPConfig offers survive the client list (legacy applyValueLimit).
        $this->setClient('clientA', ['limit_shell_user' => 1, 'ssh_chroot' => 'jailkit,bogus', 'limit_cron_type' => 'url']);

        $this->getJson('/api/v1/me/capabilities', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('sites.shell.chroot_options', ['jailkit'])
            ->assertJsonPath('sites.cron.types', ['url']);
    }

    public function test_sites_shell_authentication_mode(): void
    {
        // Spec 037: the mode lives in the [sites] section — the one the
        // administrator's Sites tab writes (legacy reads it from [misc] when
        // saving, where it never exists, so its clearing is dead code).
        $modes = [
            '' => 'password_or_key',
            'password' => 'password',
            'key' => 'key',
            'something-else' => 'password_or_key',
        ];

        foreach ($modes as $setting => $expected) {
            DB::table('sys_ini')->updateOrInsert(['sysini_id' => 1], [
                'config' => "[sites]\nssh_authentication={$setting}\n[misc]\n",
            ]);

            $this->getJson('/api/v1/me/capabilities', $this->tenantHeaders('clientA'))
                ->assertOk()
                ->assertJsonPath('sites.shell.authentication', $expected, "setting: {$setting}");
        }

        // Reported even when the plan has no SSH access.
        $this->setClient('clientA', ['limit_shell_user' => 0]);

        $this->getJson('/api/v1/me/capabilities', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('sites.shell.available', false)
            ->assertJsonPath('sites.shell.authentication', 'password_or_key');
    }

    public function test_php_modes_intersect_system_and_client_lists(): void
    {
        $this->setSites('no,fast-cgi,mod,php-fpm');
        $headers = $this->tenantHeaders('clientA');

        $cases = [
            'no,php-fpm,cgi' => [['no', 'php-fpm'], 'php-fpm'],
            'php-fpm,fast-cgi,no' => [['php-fpm', 'fast-cgi', 'no'], 'fast-cgi'],
            'no,mod' => [['no', 'mod'], 'mod'],
            'no' => [['no'], 'no'],
            'cgi,hhvm' => [[], 'no'],
        ];

        foreach ($cases as $clientModes => [$modes, $default]) {
            $this->setClient('clientA', ['web_php_options' => $clientModes]);
            $response = $this->getJson('/api/v1/me/capabilities', $headers)->assertOk();
            $this->assertSame($modes, $response->json('web.php_modes'), $clientModes);
            $this->assertSame($default, $response->json('web.php_default_mode'), $clientModes);
        }
    }

    public function test_empty_system_list_does_not_restrict_modes(): void
    {
        $this->setSites(null);
        $this->setClient('clientA', ['web_php_options' => 'no,mod,cgi']);

        $this->getJson('/api/v1/me/capabilities', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('web.php_modes', ['no', 'mod', 'cgi'])
            ->assertJsonPath('web.php_default_mode', 'mod');
    }

    public function test_locked_and_canceled_state(): void
    {
        $this->setSites(null);
        $this->setClient('clientA', ['locked' => 'y', 'canceled' => 'y']);

        $this->getJson('/api/v1/me/capabilities', $this->tenantHeaders('clientA'))
            ->assertOk()
            ->assertJsonPath('locked', true)
            ->assertJsonPath('canceled', true);
    }

    public function test_reseller_key_reads_own_and_client_views(): void
    {
        $this->setSites(null, 'y');
        $this->setClient('reseller', ['limit_ssl' => 'y']);
        $this->setClient('clientA', ['limit_ssl' => 'n']);
        $headers = $this->tenantHeaders('reseller');

        $this->getJson('/api/v1/me/capabilities', $headers)
            ->assertOk()
            ->assertJsonPath('client_id', $this->tenant('reseller')['client_id'])
            ->assertJsonPath('account_type', 'reseller')
            ->assertJsonPath('web.ssl', true)
            ->assertJsonPath('web.advanced_options', true);

        $this->getJson('/api/v1/me/capabilities?client_id='.$this->tenant('clientA')['client_id'], $headers)
            ->assertOk()
            ->assertJsonPath('client_id', $this->tenant('clientA')['client_id'])
            ->assertJsonPath('account_type', 'client')
            ->assertJsonPath('web.ssl', false)
            ->assertJsonPath('web.advanced_options', false);

        $this->getJson('/api/v1/me/capabilities?client_id='.$this->tenant('clientB')['client_id'], $headers)
            ->assertStatus(404);
    }

    public function test_admin_key_must_name_a_client(): void
    {
        $this->setSites(null);
        $this->setClient('clientB', ['limit_ssl_letsencrypt' => 'y']);
        $headers = $this->tenantHeaders('admin');

        $this->getJson('/api/v1/me/capabilities', $headers)
            ->assertStatus(422)
            ->assertJsonPath('errors.client_id.0', 'The client id is required for admin keys.');

        $this->getJson('/api/v1/me/capabilities?client_id='.$this->tenant('clientB')['client_id'], $headers)
            ->assertOk()
            ->assertJsonPath('client_id', $this->tenant('clientB')['client_id'])
            ->assertJsonPath('web.ssl_letsencrypt', true);

        $this->getJson('/api/v1/me/capabilities?client_id=9999', $headers)->assertStatus(404);
    }

    public function test_client_key_cannot_name_another_client(): void
    {
        $this->setSites(null);
        $headers = $this->tenantHeaders('clientA');

        $this->getJson('/api/v1/me/capabilities?client_id='.$this->tenant('clientB')['client_id'], $headers)->assertStatus(404);
        $this->getJson('/api/v1/me/capabilities?client_id='.$this->tenant('clientA')['client_id'], $headers)->assertOk();
    }

    public function test_query_parameters_are_validated(): void
    {
        $this->setSites(null);
        $headers = $this->tenantHeaders('clientA');

        $this->getJson('/api/v1/me/capabilities?server_id=1', $headers)->assertStatus(400);
        $this->getJson('/api/v1/me/capabilities?client_id=abc', $headers)
            ->assertStatus(422)
            ->assertJsonPath('errors.client_id.0', 'The client id must be a positive integer.');
        $this->getJson('/api/v1/me/capabilities?client_id=0', $headers)->assertStatus(422);
    }

    public function test_reported_capabilities_match_website_writes(): void
    {
        $this->setSites('no,fast-cgi,cgi,mod,suphp,php-fpm,hhvm');
        $this->setClient('clientA', ['limit_ssl' => 'n', 'web_php_options' => 'no,php-fpm']);
        $headers = $this->tenantHeaders('clientA');
        $site = $this->seedVhost('clientA');

        $capabilities = $this->getJson('/api/v1/me/capabilities', $headers)->assertOk()->json();
        $this->assertFalse($capabilities['web']['ssl']);
        $this->putJson("/api/v1/sites/web-domains/{$site}", ['ssl' => true], $headers)->assertStatus(422);

        foreach (['no', 'fast-cgi', 'cgi', 'mod', 'suphp', 'php-fpm', 'hhvm'] as $mode) {
            $status = $this->putJson("/api/v1/sites/web-domains/{$site}", ['php' => $mode], $headers)->status();
            $expected = in_array($mode, $capabilities['web']['php_modes'], true) ? 200 : 422;
            $this->assertSame($expected, $status, "php {$mode}");
        }

        $this->setClient('clientA', ['limit_ssl' => 'y']);
        $this->assertTrue($this->getJson('/api/v1/me/capabilities', $headers)->json('web.ssl'));
        $this->putJson("/api/v1/sites/web-domains/{$site}", ['ssl' => true], $headers)->assertStatus(200);
    }

    /**
     * sys_ini with the given [mail] and [misc] keys (spec 025).
     *
     * @param  array<string, string>  $mail
     * @param  array<string, string>  $misc
     */
    protected function setMailIni(array $mail, array $misc): void
    {
        $lines = ['[sites]', 'dbname_prefix=c[CLIENTID]', '[mail]'];

        foreach ($mail as $key => $value) {
            $lines[] = "{$key}={$value}";
        }

        $lines[] = '[misc]';

        foreach ($misc as $key => $value) {
            $lines[] = "{$key}={$value}";
        }

        DB::table('sys_ini')->updateOrInsert(['sysini_id' => 1], ['config' => implode("\n", $lines)]);
    }

    protected function mailServer(int $id, string $dkimPath, int $mirrorOf = 0): void
    {
        DB::table('server')->insert([
            'server_id' => $id,
            'server_name' => "mail{$id}",
            'web_server' => 0,
            'db_server' => 0,
            'mail_server' => 1,
            'mirror_server_id' => $mirrorOf,
            'active' => 1,
            'config' => "[mail]\ndkim_path={$dkimPath}\ndkim_strength=2048\n",
        ]);
    }

    public function test_mail_block_reflects_system_settings(): void
    {
        $this->setMailIni([
            'mailbox_show_autoresponder_tab' => 'y',
            'mailbox_show_mail_filter_tab' => 'n',
            'mailbox_show_custom_rules_tab' => 'y',
            'enable_custom_login' => 'y',
            'mail_password_onlyascii' => 'y',
        ], ['min_password_length' => '10', 'min_password_strength' => '4']);

        $response = $this->getJson('/api/v1/me/capabilities', $this->tenantHeaders('clientA'))->assertOk();

        $response->assertJsonPath('mail', [
            'autoresponder' => true,
            'mail_filters' => false,
            'custom_rules' => false,
            'spamfilter_policy' => false,
            'dkim' => false,
            'custom_login' => true,
            'password_policy' => ['min_length' => 10, 'min_strength' => 4, 'ascii_only' => true],
        ]);
        $this->assertSame(0, DB::table('sys_datalog')->count());
    }

    public function test_mail_settings_fall_back_to_ispconfig_defaults(): void
    {
        $headers = $this->tenantHeaders('clientA');

        // Missing keys: tabs enabled (form default), length 8 and strength 0 (auth.inc.php).
        $this->setMailIni([], []);
        $this->getJson('/api/v1/me/capabilities', $headers)
            ->assertOk()
            ->assertJsonPath('mail.autoresponder', true)
            ->assertJsonPath('mail.mail_filters', true)
            ->assertJsonPath('mail.custom_login', false)
            ->assertJsonPath('mail.password_policy', ['min_length' => 8, 'min_strength' => 0, 'ascii_only' => false]);

        // Present but empty: no minimum.
        $this->setMailIni(
            ['mailbox_show_autoresponder_tab' => 'n', 'mail_password_onlyascii' => 'n'],
            ['min_password_length' => '', 'min_password_strength' => '']
        );
        $this->getJson('/api/v1/me/capabilities', $headers)
            ->assertOk()
            ->assertJsonPath('mail.autoresponder', false)
            ->assertJsonPath('mail.mail_filters', true)
            ->assertJsonPath('mail.password_policy', ['min_length' => 0, 'min_strength' => 0, 'ascii_only' => false]);
    }

    public function test_spamfilter_policy_follows_readable_policies(): void
    {
        $this->setSites(null);
        $url = '/api/v1/me/capabilities';

        $this->getJson($url, $this->tenantHeaders('clientA'))->assertJsonPath('mail.spamfilter_policy', false);

        // Administrator policy without world read.
        DB::table('spamfilter_policy')->insert($this->ownedBy('admin', ['policy_name' => 'Private']));
        $this->getJson($url, $this->tenantHeaders('clientA'))->assertJsonPath('mail.spamfilter_policy', false);

        // Client A's own policy: readable by A only.
        DB::table('spamfilter_policy')->insert($this->ownedBy('clientA', ['policy_name' => 'Own']));
        $this->getJson($url, $this->tenantHeaders('clientA'))->assertJsonPath('mail.spamfilter_policy', true);
        $this->getJson($url, $this->tenantHeaders('clientB'))->assertJsonPath('mail.spamfilter_policy', false);
        $this->getJson($url.'?client_id='.$this->tenant('clientB')['client_id'], $this->tenantHeaders('admin'))
            ->assertOk()
            ->assertJsonPath('mail.spamfilter_policy', false);

        // World-readable policy (ISPConfig default).
        DB::table('spamfilter_policy')->insert($this->ownedBy('admin', ['policy_name' => 'Normal', 'sys_perm_other' => 'r']));
        $this->getJson($url, $this->tenantHeaders('clientB'))->assertJsonPath('mail.spamfilter_policy', true);
    }

    public function test_dkim_follows_the_account_mail_servers(): void
    {
        $this->setSites(null);
        $this->mailServer(2, '/var/lib/amavis/dkim');
        $this->mailServer(3, '/');
        $this->mailServer(4, '');
        $this->mailServer(5, '/var/lib/amavis/dkim', 2);
        $url = '/api/v1/me/capabilities';

        // Unusable paths and a mirror server.
        $this->assignServers('clientA', ['web' => [1], 'mail' => [3, 4, 5]]);
        $this->getJson($url, $this->tenantHeaders('clientA'))->assertOk()->assertJsonPath('mail.dkim', false);

        // A mail domain on a signing server adds that server.
        DB::table('mail_domain')->insert($this->ownedBy('clientA', ['server_id' => 2, 'domain' => 'a.test', 'active' => 'y']));
        $this->getJson($url, $this->tenantHeaders('clientA'))->assertJsonPath('mail.dkim', true);

        $this->assignServers('clientB', ['web' => [1], 'mail' => [2]]);
        $this->getJson($url, $this->tenantHeaders('clientB'))->assertJsonPath('mail.dkim', true);
    }
}
