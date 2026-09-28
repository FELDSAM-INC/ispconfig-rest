<?php

namespace Tests\Unit;

use App\Support\PhpLimits;
use PHPUnit\Framework\TestCase;

/** Planning and rendering of the php-limits worker (spec 053). */
class PhpLimitsTest extends TestCase
{
    private const UNIT = <<<'UNIT'
        [Unit]
        Description=The PHP 8.3 FastCGI Process Manager

        [Service]
        Type=notify
        ExecStart=/usr/sbin/php-fpm8.3 --nodaemonize --fpm-config /etc/php/8.3/fpm/php-fpm.conf
        ExecReload=/bin/kill -USR2 $MAINPID
        UNIT;

    private const DISTRO_CONF = <<<'CONF'
        ;;;;;;;;;;;;;;;;;;;;;
        ; FPM Configuration ;
        ;;;;;;;;;;;;;;;;;;;;;
        [global]
        pid = /run/php/php8.3-fpm.pid
        error_log = /var/log/php8.3-fpm.log
        ;emergency_restart_threshold = 0
        emergency_restart_threshold = 10
        process_control_timeout = 10s
        include=/etc/php/8.3/fpm/pool.d/*.conf
        CONF;

    private const POOL = <<<'POOL'
        [web34]

        listen = /var/lib/php8.3-fpm/web34.sock
        listen.owner = web34
        user = web34
        group = client59
        pm = ondemand
        php_admin_value[open_basedir] = /var/www/clients/client59/web34/web:/tmp
        POOL;

    private function service(): array
    {
        return PhpLimits::service('8.3', self::UNIT);
    }

    public function test_only_the_packaged_master_command_is_managed(): void
    {
        $this->assertSame(['unit' => 'php8.3-fpm', 'version' => '8.3', 'binary' => '/usr/sbin/php-fpm8.3',
            'conf' => '/etc/php/8.3/fpm/php-fpm.conf', 'pool_dir' => '/etc/php/8.3/fpm/pool.d'], $this->service());
        $this->assertNull(PhpLimits::service('8.3', str_replace('--nodaemonize', '--nodaemonize -R', self::UNIT)));
        $this->assertNull(PhpLimits::service('8.3', self::UNIT."\nExecStart=/usr/sbin/php-fpm8.3 -y /tmp/x"));
        $this->assertNull(PhpLimits::service('8.3; rm', self::UNIT));
    }

    public function test_ispconfig_pools_map_to_their_account(): void
    {
        $this->assertSame(['pool' => 'web34', 'domain_id' => 34, 'client_id' => 59, 'listen' => '/var/lib/php8.3-fpm/web34.sock', 'isolatable' => true],
            PhpLimits::pool('web34', self::POOL));
        $this->assertFalse(PhpLimits::pool('www', str_replace('[web34]', '[www]', self::POOL))['isolatable']);
        $this->assertFalse(PhpLimits::pool('web34', str_replace('group = client59', 'group = www-data', self::POOL))['isolatable']);
        $this->assertFalse(PhpLimits::pool('web34', self::POOL."\n[web35]\nlisten = /tmp/x.sock\n")['isolatable']);
        $this->assertFalse(PhpLimits::pool('web35', self::POOL)['isolatable']);
        $this->assertSame('127.0.0.1:9034', PhpLimits::pool('web34', str_replace('/var/lib/php8.3-fpm/web34.sock', '127.0.0.1:9034', self::POOL))['listen']);
    }

    public function test_isolation_needs_limits_and_a_single_php_version(): void
    {
        $pools = ['web34' => PhpLimits::pool('web34', self::POOL) + ['hash' => 'a'], 'www' => PhpLimits::pool('www', "[www]\nlisten=/run/php/x.sock\n") + ['hash' => 'b']];
        $policies = [59 => ['revision' => 3]];
        $this->assertSame(['web34' => 59], PhpLimits::isolated($pools, $policies, [], ['web34' => 1], 1000));
        $this->assertSame([], PhpLimits::isolated($pools, [], [], ['web34' => 1], 1000));
        $this->assertSame([], PhpLimits::isolated($pools, $policies, [], ['web34' => 2], 1000), 'a pool in two versions is moving');

        $fallback = ['web34' => ['state' => 'fallback', 'since' => 1000, 'hash' => 'a', 'revision' => 3]];
        $this->assertSame([], PhpLimits::isolated($pools, $policies, $fallback, ['web34' => 1], 2000));
        $this->assertSame(['web34' => 59], PhpLimits::isolated($pools, $policies, $fallback, ['web34' => 1], 2800), 'retried after 30 minutes');
        $this->assertSame(['web34' => 59], PhpLimits::isolated($pools, [59 => ['revision' => 4]], $fallback, ['web34' => 1], 2000), 'retried on new limits');
        $pools['web34']['hash'] = 'changed';
        $this->assertSame(['web34' => 59], PhpLimits::isolated($pools, $policies, $fallback, ['web34' => 1], 2000), 'retried on a new pool file');
    }

    public function test_shared_master_includes_each_remaining_pool_explicitly(): void
    {
        $conf = PhpLimits::sharedConf(self::DISTRO_CONF, '/etc/php/8.3/fpm/pool.d', ['/etc/php/8.3/fpm/pool.d/www.conf', '/etc/php/8.3/fpm/pool.d/web12.conf'], '/p.pool');
        $this->assertStringNotContainsString('pool.d/*.conf', $conf);
        $this->assertStringContainsString("include=/etc/php/8.3/fpm/pool.d/web12.conf\ninclude=/etc/php/8.3/fpm/pool.d/www.conf\n", $conf);
        $this->assertStringContainsString('pid = /run/php/php8.3-fpm.pid', $conf);
        $this->assertStringContainsString("include=/p.pool\n", PhpLimits::sharedConf(self::DISTRO_CONF, '/etc/php/8.3/fpm/pool.d/', [], '/p.pool'));
        $this->assertNull(PhpLimits::sharedConf(str_replace('include=/etc/php/8.3/fpm/pool.d/*.conf', 'include=/srv/pools/*.conf', self::DISTRO_CONF), '/etc/php/8.3/fpm/pool.d', [], '/p.pool'));
        $this->assertNull(PhpLimits::sharedConf(self::DISTRO_CONF."\ninclude=/etc/php/8.3/fpm/pool.d/*.conf", '/etc/php/8.3/fpm/pool.d', [], '/p.pool'));
        $this->assertStringContainsString('pm.max_children = 1', PhpLimits::placeholderPool('php8.3-fpm'));
    }

    public function test_dedicated_master_keeps_distribution_globals_and_loads_one_pool(): void
    {
        $conf = PhpLimits::poolConf(self::DISTRO_CONF."\n[www]\nuser = www-data\n", 'web34', '/etc/php/8.3/fpm/pool.d/web34.conf');
        $this->assertStringContainsString("emergency_restart_threshold = 10\nprocess_control_timeout = 10s\n", $conf);
        $this->assertStringNotContainsString('pid =', $conf);
        $this->assertStringNotContainsString('/var/log/php8.3-fpm.log', $conf);
        $this->assertStringNotContainsString('www-data', $conf);
        $this->assertStringEndsWith("error_log = syslog\nsyslog.ident = ispconfig-php-web34\ninclude = /etc/php/8.3/fpm/pool.d/web34.conf\n", $conf);
    }

    public function test_units_use_hard_limits_and_survive_a_killed_worker(): void
    {
        $policy = ['account' => ['cpu_percent' => 200, 'memory_mb' => 2048, 'tasks' => 256], 'website' => ['cpu_percent' => null, 'memory_mb' => 512, 'tasks' => null]];
        $slice = PhpLimits::sliceUnit(59, $policy);
        $this->assertStringStartsWith(PhpLimits::MARKER."\n", $slice);
        foreach (['MemoryMax=2048M', 'MemorySwapMax=0', 'CPUQuota=200%', 'TasksMax=256', 'CPUAccounting=yes'] as $line) {
            $this->assertStringContainsString($line."\n", $slice);
        }
        $this->assertStringNotContainsString('MemoryHigh', $slice);

        $unit = PhpLimits::poolUnit($this->service(), 'web34', 59, $policy);
        foreach (['Slice=ispconfig-client59.slice', 'OOMPolicy=continue', 'Type=notify', 'MemoryMax=512M', 'MemorySwapMax=0', 'TasksMax=infinity',
            'PartOf=php8.3-fpm.service', 'WantedBy=php8.3-fpm.service', 'ExecStartPre=/usr/local/sbin/ispconfig-php-limits wait-socket web34',
            'ExecStart=/usr/sbin/php-fpm8.3 --nodaemonize --fpm-config /etc/ispconfig-rest-php-limits/pools/web34.conf', 'ProtectSystem=full'] as $line) {
            $this->assertStringContainsString($line."\n", $unit);
        }
        $this->assertStringNotContainsString('CPUQuota', $unit);
        $this->assertStringNotContainsString('MemoryHigh', $unit);

        $open = PhpLimits::sliceUnit(59, ['account' => ['cpu_percent' => null, 'memory_mb' => null, 'tasks' => null]]);
        $this->assertStringContainsString("MemoryMax=infinity\nTasksMax=infinity\n", $open);
        $this->assertStringNotContainsString('MemorySwapMax', $open);
    }

    public function test_drop_in_hooks_never_fail_the_distribution_service(): void
    {
        $dropIn = PhpLimits::dropIn($this->service());
        $this->assertStringContainsString("ExecStart=\nExecStart=/usr/sbin/php-fpm8.3 --nodaemonize --fpm-config /etc/ispconfig-rest-php-limits/php8.3-fpm.conf\n", $dropIn);
        $this->assertStringContainsString("ExecReload=\nExecReload=-+/usr/local/sbin/ispconfig-php-limits hook php8.3-fpm pre-reload\nExecReload=/bin/kill -USR2 \$MAINPID\nExecReload=-+/usr/local/sbin/ispconfig-php-limits hook php8.3-fpm post-reload\n", $dropIn);
        $this->assertStringContainsString('ExecStartPre=-+/usr/local/sbin/ispconfig-php-limits hook php8.3-fpm pre-start', $dropIn);
        $this->assertStringContainsString("test -s /etc/ispconfig-rest-php-limits/php8.3-fpm.conf || { mkdir -p /etc/ispconfig-rest-php-limits && cp /etc/php/8.3/fpm/php-fpm.conf /etc/ispconfig-rest-php-limits/php8.3-fpm.conf; }", $dropIn);
    }

    public function test_listen_addresses(): void
    {
        $this->assertSame(['/var/lib/php8.3-fpm/web34.sock'], PhpLimits::listenAddress('/var/lib/php8.3-fpm/web34.sock'));
        $this->assertSame(['127.0.0.1', 9034], PhpLimits::listenAddress('127.0.0.1:9034'));
        $this->assertSame(['127.0.0.1', 9034], PhpLimits::listenAddress('9034'));
        $this->assertSame(['::1', 9000], PhpLimits::listenAddress('[::1]:9000'));
    }

    public function test_usage_figures_over_one_minute_and_24_hours(): void
    {
        $start = 1790000000 - 1790000000 % 3600;
        $history = PhpLimits::fold(null, ['cpu_usec' => 1000000, 'throttled' => 0, 'oom_kill' => 0, 'pids_max' => 0], $start);
        $this->assertNull(PhpLimits::figures($history)['cpu_percent']);
        $history = PhpLimits::fold($history, ['cpu_usec' => 31000000, 'throttled' => 5, 'oom_kill' => 2, 'pids_max' => 1], $start + 60);
        $this->assertSame(['cpu_percent' => 50.0, 'cpu_percent_24h' => 50.0, 'cpu_limited_minutes_24h' => 1, 'memory_limit_hits_24h' => 2, 'tasks_limit_hits_24h' => 1],
            PhpLimits::figures($history));
        // The unit restarted: counters start again from zero.
        $history = PhpLimits::fold($history, ['cpu_usec' => 6000000, 'throttled' => 5, 'oom_kill' => 0, 'pids_max' => 0], $start + 120);
        $figures = PhpLimits::figures($history);
        $this->assertSame(10.0, $figures['cpu_percent']);
        $this->assertSame(30.0, $figures['cpu_percent_24h']);
        $this->assertSame(2, $figures['cpu_limited_minutes_24h']);
        // Buckets older than 24 hours are dropped.
        $history = PhpLimits::fold($history, ['cpu_usec' => 6000000, 'throttled' => 5, 'oom_kill' => 0, 'pids_max' => 0], $start + 25 * 3600);
        $this->assertSame(0, PhpLimits::figures($history)['memory_limit_hits_24h']);
        $this->assertSame(0.0, PhpLimits::figures($history)['cpu_percent']);
    }
}
