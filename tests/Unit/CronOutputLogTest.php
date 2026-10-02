<?php

namespace Tests\Unit;

use App\Support\CronOutputLog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CronOutputLogTest extends TestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef';

    public function test_the_prefix_round_trips_any_task_text(): void
    {
        foreach (["php x.php # nightly", "echo 'a ; b' && exit 3", 'cd /web && php artisan schedule:run >/dev/null 2>&1', '{SITE_PHP} {DOCROOT_CLIENT}/cron.php'] as $command) {
            $wrapped = CronOutputLog::wrap($command, self::TOKEN, 'full', '/var/www/clients/client1/web1');
            $this->assertSame(['dir' => '/var/www/clients/client1/web1/private', 'token' => self::TOKEN, 'command' => $command], CronOutputLog::parse($wrapped));
            $this->assertStringNotContainsString('%', $wrapped, 'cron would turn a percent sign into a line break');
            $this->assertStringNotContainsString('\\', $wrapped, 'ISPConfig refuses backslashes');
        }
        $this->assertSame("command exec >>'/private/.ispcp-cron-".self::TOKEN.".log' 2>&1; trap 'echo \"=== exit \$? ===\"' EXIT; echo \"=== \$(date -u) ===\"; /web/cron.php",
            CronOutputLog::wrap('/var/www/clients/client1/web1/web/cron.php', self::TOKEN, 'chrooted', '/var/www/clients/client1/web1'));
        $this->assertSame('/web1x/cron.php', CronOutputLog::parse(CronOutputLog::wrap('/web1x/cron.php', self::TOKEN, 'chrooted', '/web1'))['command'], 'only a whole leading document root');
        foreach (['php x.php', ': > /private/.ispcp-wp-cron-x', "command exec >>'/private/.ispcp-cron-XYZ.log' 2>&1; php"] as $other) {
            $this->assertNull(CronOutputLog::parse($other));
        }
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', CronOutputLog::token());
    }

    public function test_log_name_of_own_logs_and_wordpress_takeover_only_in_the_website_directory(): void
    {
        $root = '/var/www/clients/client1/web1';
        $this->assertSame('.ispcp-cron-'.self::TOKEN.'.log', CronOutputLog::logName(CronOutputLog::wrap('php x.php', self::TOKEN, 'chrooted', $root), 'chrooted', $root));
        $this->assertSame('wp-cron.log', CronOutputLog::logName(": > '/private/.ispcp-wp-cron-7a86e429-b416-4e20-b7d3-b33580d81a38'", 'chrooted', $root));
        $this->assertSame('wp-cron.log', CronOutputLog::logName(": > '".$root."/private/.ispcp-wp-cron-7a86e429-b416-4e20-b7d3-b33580d81a38'", 'full', $root));
        $this->assertNull(CronOutputLog::logName(CronOutputLog::wrap('php x.php', self::TOKEN, 'full', '/var/www/clients/client2/web2'), 'full', $root), 'another website');
        $this->assertNull(CronOutputLog::logName(": > '/private/.ispcp-wp-cron-7a86e429-b416-4e20-b7d3-b33580d81a38'", 'full', $root));
        $this->assertNull(CronOutputLog::logName('php x.php', 'full', $root));
    }

    public function test_lines_drop_a_partial_first_line_and_control_characters(): void
    {
        $this->assertSame(['lines' => ['b', 'c'], 'truncated' => true], CronOutputLog::lines("rtial a\nb\nc\n", true, 10));
        $this->assertSame(['lines' => ['c'], 'truncated' => true], CronOutputLog::lines("a\nb\nc", false, 1));
        $this->assertSame(['lines' => ['color text'], 'truncated' => false], CronOutputLog::lines("\e[31mcolor\e[0m text\n", false, 10));
        $this->assertSame(['lines' => [], 'truncated' => false], CronOutputLog::lines('', false, 10));
    }

    public function test_reading_and_trimming_run_as_the_website_user_on_regular_files_only(): void
    {
        $root = sys_get_temp_dir().'/cron-log-'.bin2hex(random_bytes(4));
        mkdir($root.'/private', 0700, true);
        $calls = [];
        $run = function (array $command, string $user) use (&$calls): array {
            $calls[] = [$user, $command];

            return [0, "=== start ===\nhello\n=== exit 0 ===\n"];
        };
        try {
            $this->assertSame(['lines' => [], 'size' => 0, 'modified_at' => null, 'truncated' => false], CronOutputLog::read($root, 'web7', '.ispcp-cron-'.self::TOKEN.'.log', 200, $run), 'no run yet');
            $file = $root.'/private/.ispcp-cron-'.self::TOKEN.'.log';
            file_put_contents($file, "=== start ===\nhello\n=== exit 0 ===\n");
            $result = CronOutputLog::read($root, 'web7', '.ispcp-cron-'.self::TOKEN.'.log', 200, $run);
            $this->assertSame(['=== start ===', 'hello', '=== exit 0 ==='], $result['lines']);
            $this->assertSame([['web7', ['/usr/bin/tail', '-c', '262144', '--', $file]]], $calls);
            $this->assertFalse(CronOutputLog::trim($root, 'web7', self::TOKEN, $run), 'small files are left alone');
            file_put_contents($file, str_repeat("x\n", CronOutputLog::LIMIT));
            $this->assertTrue(CronOutputLog::trim($root, 'web7', self::TOKEN, $run));
            $this->assertSame('/bin/sh', $calls[1][1][0]);
            unlink($file);
            symlink('/etc/hostname', $file);
            try {
                CronOutputLog::read($root, 'web7', '.ispcp-cron-'.self::TOKEN.'.log', 200, $run);
                $this->fail('Read a link');
            } catch (RuntimeException) {
                $this->assertCount(2, $calls);
            }
            foreach ([['root', '.ispcp-cron-'.self::TOKEN.'.log'], ['web7', '../x'], ['web7', 'cron.log']] as [$user, $token]) {
                try {
                    CronOutputLog::read($root, $user, $token, 200, $run);
                    $this->fail('Accepted '.$user.' '.$token);
                } catch (RuntimeException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            exec('rm -rf '.escapeshellarg($root));
        }
    }
}
