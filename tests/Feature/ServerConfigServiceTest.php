<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Services\ServerConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\ServerSchema;
use Tests\TestCase;

/**
 * Unit coverage of the INI-blob engine against a sanitized copy of a REAL
 * server.config blob extracted from a live ISPConfig panel
 * (tests/fixtures/server-config.ini) — parser fidelity to legacy
 * ini_parser, byte-safe merges, and the mail-section special rules.
 */
class ServerConfigServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ServerConfigService $service;

    protected function setUp(): void
    {
        parent::setUp();

        ServerSchema::create();

        $this->service = app(ServerConfigService::class);
    }

    protected static function fixture(): string
    {
        return file_get_contents(base_path('tests/fixtures/server-config.ini'));
    }

    protected function seedServer(?string $config = null): Server
    {
        $id = (int) DB::table('server')->insertGetId([
            'sys_userid' => 1,
            'sys_groupid' => 1,
            'sys_perm_user' => 'riud',
            'sys_perm_group' => 'riud',
            'sys_perm_other' => '',
            'server_name' => 'server1',
            'mail_server' => 1,
            'web_server' => 1,
            'config' => $config ?? self::fixture(),
        ], 'server_id');

        return Server::query()->findOrFail($id);
    }

    // ------------------------------------------------------------------
    // Parser fidelity (legacy ini_parser semantics)
    // ------------------------------------------------------------------

    public function test_parse_of_real_blob_yields_legacy_sections_in_order(): void
    {
        $parsed = $this->service->parse(self::fixture());

        // Real blob section order, verbatim (xmpp last on this panel).
        $this->assertSame(
            ['global', 'server', 'mail', 'getmail', 'web', 'dns', 'fastcgi', 'jailkit', 'vlogger', 'cron', 'rescue', 'xmpp'],
            array_keys($parsed)
        );

        $this->assertSame('apache', $parsed['global']['webserver']);
        $this->assertSame('server1.example.com', $parsed['server']['hostname']);
        $this->assertSame('rspamd', $parsed['mail']['content_filter']);
        $this->assertSame('y', $parsed['mail']['rspamd_available']);
        // Installer-written keys unknown to the schemas are parsed verbatim.
        $this->assertSame('/usr/sbin/sendmail', $parsed['mail']['sendmail_path']);
        $this->assertSame('', $parsed['mail']['rspamd_redis_passwd']);
        $this->assertSame('n', $parsed['xmpp']['xmpp_use_ispv6']); // historic typo key
    }

    public function test_parse_serialize_round_trip_is_byte_identical_on_real_blob(): void
    {
        $fixture = self::fixture();

        $this->assertSame($fixture, $this->service->serialize($this->service->parse($fixture)));
    }

    public function test_parse_mirrors_legacy_ini_parser_edge_cases(): void
    {
        $ini = "junk_before_section=1\r\n".
            "; comment line\r\n".
            "[Server]\r\n".
            "key1 = dropped by legacy\r\n".
            "key2=a=b=c\r\n".
            "key3=\r\n".
            "   key4= padded \r\n".
            "not a key line\r\n".
            "\r\n".
            "[other_1]\r\n".
            "x=1\r\n";

        $parsed = $this->service->parse($ini);

        // Section headers are lowercased; keys before the first header,
        // comments and unparseable lines are dropped; values keep embedded
        // '=' and are trimmed. Legacy quirk: a space BEFORE '=' breaks the
        // key regex /^([\w\d_]+)=(.*)$/ — the line is silently dropped.
        $this->assertSame(['server', 'other_1'], array_keys($parsed));
        $this->assertArrayNotHasKey('key1', $parsed['server']);
        $this->assertSame('a=b=c', $parsed['server']['key2']);
        $this->assertSame('', $parsed['server']['key3']);
        $this->assertSame('padded', $parsed['server']['key4']);
        $this->assertArrayNotHasKey('junk_before_section', $parsed['server']);
    }

    public function test_serialize_mirrors_legacy_get_ini_string_format(): void
    {
        $blob = $this->service->serialize([
            'server' => ['a' => '1', 'b' => ' padded ', '' => 'dropped'],
            'empty_section' => [],
        ]);

        $this->assertSame("[server]\na=1\nb=padded\n\n[empty_section]\n\n", $blob);
    }

    // ------------------------------------------------------------------
    // Section read
    // ------------------------------------------------------------------

    public function test_get_section_types_integers_per_schema(): void
    {
        $server = $this->seedServer();

        $mail = $this->service->getSection($server, 'mail');

        $this->assertSame(0, $mail['mailbox_size_limit']);
        $this->assertSame(2048, $mail['dkim_strength']);
        $this->assertSame('0', $mail['mailbox_soft_delete']); // string enum, not int
        $this->assertSame('y', $mail['mailbox_quota_stats']);

        $web = $this->service->getSection($server, 'web');
        $this->assertSame(9010, $web['php_fpm_start_port']);
        $this->assertSame('20', $web['security_level']); // string enum
    }

    public function test_get_section_of_empty_blob_is_empty(): void
    {
        $server = $this->seedServer('');

        $this->assertSame([], $this->service->getSection($server, 'mail'));
    }

    // ------------------------------------------------------------------
    // Byte-safe merge
    // ------------------------------------------------------------------

    public function test_update_section_changes_only_the_target_section(): void
    {
        $server = $this->seedServer();
        $before = self::fixture();

        $this->service->updateSection($server, 'cron', [
            'init_script' => 'cronie',
        ]);

        $after = (string) DB::table('server')->where('server_id', $server->getKey())->value('config');

        $this->assertNotSame($before, $after);

        // Every section except [cron] is byte-identical.
        $beforeBlocks = $this->sectionBlocks($before);
        $afterBlocks = $this->sectionBlocks($after);
        $this->assertSame(array_keys($beforeBlocks), array_keys($afterBlocks));

        foreach ($beforeBlocks as $section => $block) {
            if ($section === 'cron') {
                continue;
            }

            $this->assertSame($block, $afterBlocks[$section], "section [{$section}] must be preserved byte-for-byte");
        }

        // Inside [cron] only init_script changed; key order preserved.
        $this->assertSame(
            "[cron]\ninit_script=cronie\ncrontab_dir=/etc/cron.d\nwget=/usr/bin/wget\n\n",
            $afterBlocks['cron']
        );

        // The whole blob still parses and round-trips.
        $this->assertSame($after, $this->service->serialize($this->service->parse($after)));
    }

    public function test_update_section_preserves_unknown_keys_in_target_section(): void
    {
        $server = $this->seedServer();

        $this->service->updateSection($server, 'mail', [
            'mailbox_quota_stats' => 'y',
            'stress_adaptive' => 'y',
            'reject_sender_login_mismatch' => 'n',
            'mailbox_virtual_uidgid_maps' => 'n',
            'overquota_notify_admin' => 'y',
            'overquota_notify_reseller' => 'y',
            'overquota_notify_client' => 'y',
            'overquota_notify_onok' => 'n',
            'content_filter' => 'rspamd',
        ]);

        $after = (string) DB::table('server')->where('server_id', $server->getKey())->value('config');
        $mail = $this->service->parse($after)['mail'];

        // Installer-written keys unknown to ServerMailConfig.yaml survive
        // verbatim, in place.
        $this->assertSame('/usr/sbin/sendmail', $mail['sendmail_path']);
        $this->assertSame('', $mail['rspamd_redis_passwd']);
        $this->assertSame('', $mail['rspamd_redis_bayes_passwd']);

        $this->assertStringContainsString("sendmail_path=/usr/sbin/sendmail\n", $after);
    }

    public function test_update_section_backfills_omitted_checkboxes_with_unchecked_value(): void
    {
        $server = $this->seedServer();

        // stress_adaptive is not stored in the fixture blob and is omitted
        // from the request; mailbox_quota_stats is stored as 'y' and
        // omitted -> both must be written as 'n' (legacy unchecked value).
        $this->service->updateSection($server, 'mail', ['dkim_strength' => 2048]);

        $mail = $this->service->getSection($server, 'mail');

        $this->assertSame('n', $mail['stress_adaptive']);
        $this->assertSame('n', $mail['mailbox_quota_stats']);
        // Omitted text/select keys keep their current value.
        $this->assertSame('rspamd', $mail['content_filter']);
        $this->assertSame('/var/vmail', $mail['homedir_path']);
    }

    public function test_rspamd_available_is_always_preserved_from_stored_blob(): void
    {
        $server = $this->seedServer(); // fixture stores rspamd_available=y

        $this->service->updateSection($server, 'mail', ['rspamd_available' => 'n']);

        $this->assertSame('y', $this->service->getSection($server, 'mail')['rspamd_available']);
    }

    public function test_update_section_on_empty_blob_creates_the_section(): void
    {
        $server = $this->seedServer('');

        $rescue = $this->service->updateSection($server, 'rescue', ['try_rescue' => 'y']);

        $this->assertSame('y', $rescue['try_rescue']);
        $this->assertSame('n', $rescue['do_not_try_rescue_httpd']); // backfilled

        $after = (string) DB::table('server')->where('server_id', $server->getKey())->value('config');
        $this->assertSame(
            "[rescue]\ntry_rescue=y\ndo_not_try_rescue_httpd=n\ndo_not_try_rescue_mongodb=n\ndo_not_try_rescue_mysql=n\ndo_not_try_rescue_mail=n\n\n",
            $after
        );
    }

    public function test_update_section_writes_exactly_one_server_datalog_row_with_full_blob(): void
    {
        $server = $this->seedServer();

        $this->service->updateSection($server, 'vlogger', ['config_dir' => '/etc/vlogger']);

        $rows = DB::table('sys_datalog')->get();
        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame('server', $row->dbtable);
        $this->assertSame('u', $row->action);
        $this->assertSame('server_id:'.$server->getKey(), $row->dbidx);

        $data = unserialize($row->data);
        $this->assertSame(['old', 'new'], array_keys($data));
        $this->assertSame(self::fixture(), $data['old']['config']);

        $stored = (string) DB::table('server')->where('server_id', $server->getKey())->value('config');
        $this->assertSame($stored, $data['new']['config']);
        $this->assertStringContainsString("[vlogger]\nconfig_dir=/etc/vlogger\n", $stored);
    }

    // ------------------------------------------------------------------
    // Mail-section guards and side effects
    // ------------------------------------------------------------------

    public function test_mailbox_size_limit_guard_uses_effective_values(): void
    {
        $server = $this->seedServer();

        try {
            $this->service->updateSection($server, 'mail', [
                'mailbox_size_limit' => 5,
                'message_size_limit' => 10,
            ]);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('mailbox_size_limit', $e->errors());
        }

        // Stored message_size_limit=0 -> nonzero mailbox limit alone is ok.
        $mail = $this->service->updateSection($server, 'mail', ['mailbox_size_limit' => 5]);
        $this->assertSame(5, $mail['mailbox_size_limit']);
    }

    public function test_switching_content_filter_to_rspamd_force_datalogs_spamfilter_rows(): void
    {
        $fixture = str_replace('content_filter=rspamd', 'content_filter=amavisd', self::fixture());
        $server = $this->seedServer($fixture);

        DB::table('spamfilter_users')->insert([
            ['server_id' => $server->getKey(), 'email' => '@one.tld'],
            ['server_id' => $server->getKey(), 'email' => '@two.tld'],
            ['server_id' => 999, 'email' => '@other-server.tld'], // untouched
        ]);
        DB::table('spamfilter_wblist')->insert([
            ['server_id' => $server->getKey(), 'rid' => 1, 'email' => 'friend@x.tld'],
        ]);

        $this->service->updateSection($server, 'mail', ['content_filter' => 'rspamd']);

        // 1 server 'u' + 2 spamfilter_users + 1 spamfilter_wblist.
        $this->assertSame(1, DB::table('sys_datalog')->where('dbtable', 'server')->count());
        $this->assertSame(2, DB::table('sys_datalog')->where('dbtable', 'spamfilter_users')->where('action', 'u')->count());
        $this->assertSame(1, DB::table('sys_datalog')->where('dbtable', 'spamfilter_wblist')->where('action', 'u')->count());

        // Force-datalog rows carry the full unchanged record, 'new' first
        // (legacy $force_update payload shape).
        $touch = DB::table('sys_datalog')->where('dbtable', 'spamfilter_users')->first();
        $data = unserialize($touch->data);
        $this->assertSame(['new', 'old'], array_keys($data));
        $this->assertSame('@one.tld', $data['new']['email']);

        // Re-putting rspamd (no switch) must NOT re-touch.
        DB::table('sys_datalog')->delete();
        $this->service->updateSection($server, 'mail', ['content_filter' => 'rspamd']);
        $this->assertSame(0, DB::table('sys_datalog')->where('dbtable', 'spamfilter_users')->count());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Split a blob into raw per-section blocks (header line + body + the
     * trailing blank line) for byte-level comparison.
     *
     * @return array<string, string>
     */
    protected function sectionBlocks(string $blob): array
    {
        $blocks = [];
        $current = null;

        foreach (explode("\n", $blob) as $line) {
            if (preg_match('/^\[([\w\d_]+)\]$/', $line, $m)) {
                $current = $m[1];
                $blocks[$current] = '';
            }

            if ($current !== null) {
                $blocks[$current] .= $line."\n";
            }
        }

        return $blocks;
    }
}
