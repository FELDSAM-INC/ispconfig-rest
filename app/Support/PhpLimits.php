<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pure planning and rendering for the php-limits worker (spec 053). No I/O:
 * the root worker (php-limits/run.php) reads files and systemd state, asks this
 * class what to write, and applies it. Installed next to run.php, so it must not
 * depend on Laravel.
 */
final class PhpLimits
{
    public const MARKER = '# Managed by ispconfig-rest php-limits; changes are overwritten.';

    public const ETC = '/etc/ispconfig-rest-php-limits';

    public const UNIT_DIR = '/etc/systemd/system';

    public const CLI = '/usr/local/sbin/ispconfig-php-limits';

    public const SLICE = 'ispconfig.slice';

    private const INI_MARKER = '; Managed by ispconfig-rest php-limits; changes are overwritten.';

    private const HARDENING = ['ProtectSystem=full', 'PrivateDevices=true', 'ProtectKernelModules=true',
        'ProtectKernelTunables=true', 'ProtectControlGroups=true', 'RestrictRealtime=true',
        'RestrictAddressFamilies=AF_INET AF_INET6 AF_NETLINK AF_UNIX', 'RestrictNamespaces=true'];

    /** Keys the dedicated masters must not inherit from the distribution [global] section. */
    private const OWN_GLOBALS = ['pid', 'error_log', 'include', 'daemonize', 'syslog.ident', 'syslog.facility'];

    public static function sliceName(int $clientId): string
    {
        return 'ispconfig-client'.$clientId.'.slice';
    }

    public static function unitName(string $pool): string
    {
        return 'ispconfig-php-'.$pool.'.service';
    }

    public static function sliceCgroup(int $clientId): string
    {
        return '/sys/fs/cgroup/'.self::SLICE.'/'.self::sliceName($clientId);
    }

    public static function unitCgroup(int $clientId, string $pool): string
    {
        return self::sliceCgroup($clientId).'/'.self::unitName($pool);
    }

    public static function sharedConfPath(string $unit): string
    {
        return self::ETC.'/'.$unit.'.conf';
    }

    public static function poolConfPath(string $pool): string
    {
        return self::ETC.'/pools/'.$pool.'.conf';
    }

    public static function dropInPath(string $unit): string
    {
        return self::UNIT_DIR.'/'.$unit.'.service.d/50-ispconfig-rest-php-limits.conf';
    }

    /**
     * A distribution php-fpm unit is managed only when it starts exactly the
     * packaged master with the packaged configuration.
     *
     * @return array{unit: string, version: string, binary: string, conf: string, pool_dir: string}|null
     */
    public static function service(string $version, string $unitFile): ?array
    {
        if (! preg_match('/\A[0-9]+\.[0-9]+\z/D', $version)) {
            return null;
        }
        $binary = '/usr/sbin/php-fpm'.$version;
        $conf = '/etc/php/'.$version.'/fpm/php-fpm.conf';
        $exec = [];
        foreach (preg_split('/\R/', $unitFile) as $line) {
            if (preg_match('/\A\s*ExecStart\s*=\s*(.*?)\s*\z/D', $line, $match)) {
                $exec[] = $match[1];
            }
        }
        if ($exec !== [$binary.' --nodaemonize --fpm-config '.$conf]) {
            return null;
        }

        return ['unit' => 'php'.$version.'-fpm', 'version' => $version, 'binary' => $binary, 'conf' => $conf,
            'pool_dir' => '/etc/php/'.$version.'/fpm/pool.d'];
    }

    /**
     * ISPConfig pools are `web{domain_id}` with the website's system group
     * `client{client_id}`. Anything else stays in the shared master.
     *
     * @return array{pool: string, domain_id: int|null, client_id: int|null, listen: string|null, isolatable: bool}
     */
    public static function pool(string $name, string $content): array
    {
        $sections = [];
        $values = [];
        foreach (preg_split('/\R/', $content) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === ';' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/\A\[([^\]]+)\]\z/D', $line, $match)) {
                $sections[] = trim($match[1]);

                continue;
            }
            if (count($sections) === 1 && preg_match('/\A([A-Za-z0-9_.]+)\s*=\s*(.*)\z/D', $line, $match)) {
                $values[strtolower($match[1])] = trim($match[2], " \t\"'");
            }
        }
        $domainId = preg_match('/\Aweb([1-9][0-9]{0,9})\z/D', $name, $match) ? (int) $match[1] : null;
        $clientId = preg_match('/\Aclient([1-9][0-9]{0,9})\z/D', $values['group'] ?? '', $match) ? (int) $match[1] : null;
        $listen = ($values['listen'] ?? '') === '' ? null : $values['listen'];

        return ['pool' => $name, 'domain_id' => $domainId, 'client_id' => $clientId, 'listen' => $listen,
            'isolatable' => $domainId !== null && $clientId !== null && $listen !== null && $sections === [$name]];
    }

    /**
     * Pools that get their own master. A pool listed by more than one PHP
     * version is in transition and stays shared everywhere.
     *
     * @param  array<string, array<string, mixed>>  $pools  pool name => self::pool() of this service
     * @param  array<int, array<string, mixed>>  $policies  client id => limits
     * @param  array<string, array<string, mixed>>  $state  pool name => recorded state
     * @param  array<string, int>  $seen  pool name => number of services listing it
     * @return array<string, int> pool => client id
     */
    public static function isolated(array $pools, array $policies, array $state, array $seen, int $now): array
    {
        $isolated = [];
        foreach ($pools as $name => $pool) {
            if (! $pool['isolatable'] || ($seen[$name] ?? 0) > 1 || ! isset($policies[$pool['client_id']])) {
                continue;
            }
            $recorded = $state[$name] ?? null;
            if (($recorded['state'] ?? '') === 'fallback' && ! self::retryFallback($recorded, $pool, $policies[$pool['client_id']], $now)) {
                continue;
            }
            $isolated[$name] = (int) $pool['client_id'];
        }
        ksort($isolated);

        return $isolated;
    }

    /** A fallback pool is retried after 30 minutes, or at once when its pool file or limits change. */
    private static function retryFallback(array $recorded, array $pool, array $policy, int $now): bool
    {
        return $now - (int) ($recorded['since'] ?? 0) >= 1800
            || ($recorded['hash'] ?? null) !== ($pool['hash'] ?? null)
            || (int) ($recorded['revision'] ?? 0) !== (int) ($policy['revision'] ?? 0);
    }

    /**
     * The distribution configuration with its pool.d glob replaced by explicit
     * includes. php-fpm only warns when an explicit include matches nothing, so a
     * pool file deleted before the next hook cannot stop the shared master.
     *
     * @param  array<int, string>  $files  pool files kept in the shared master
     */
    public static function sharedConf(string $distroConf, string $poolDir, array $files, string $placeholder): ?string
    {
        $glob = rtrim($poolDir, '/').'/*.conf';
        $lines = preg_split('/\R/', $distroConf);
        $found = 0;
        foreach ($lines as $index => $line) {
            if (preg_match('/\A\s*include\s*=\s*(.*?)\s*\z/Di', $line, $match) && trim($match[1], "\"'") === $glob) {
                sort($files);
                // php-fpm refuses to start without any pool.
                $includes = $files === [] ? [$placeholder] : $files;
                $lines[$index] = implode("\n", array_merge(
                    ['; BEGIN ispconfig-rest php-limits: pools without their own service'],
                    array_map(fn (string $file): string => 'include='.$file, $includes),
                    ['; END ispconfig-rest php-limits'],
                ));
                $found++;
            }
        }

        return $found === 1 ? self::INI_MARKER."\n".implode("\n", $lines)."\n" : null;
    }

    /** A pool that only exists so an otherwise empty shared master can start. */
    public static function placeholderPool(string $unit): string
    {
        return self::INI_MARKER."\n[ispconfig-php-limits-placeholder]\n"
            ."listen = /run/ispconfig-php-limits-".$unit.".sock\nlisten.mode = 0600\n"
            ."user = www-data\ngroup = www-data\npm = ondemand\npm.max_children = 1\n";
    }

    /** Dedicated master for one pool: distribution globals, syslog, only that pool. */
    public static function poolConf(string $distroConf, string $pool, string $poolFile): string
    {
        $globals = [];
        $inGlobal = true;
        foreach (preg_split('/\R/', $distroConf) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === ';' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/\A\[([^\]]+)\]\z/D', $line, $match)) {
                $inGlobal = strtolower(trim($match[1])) === 'global';

                continue;
            }
            if ($inGlobal && preg_match('/\A([A-Za-z0-9_.]+)\s*=/', $line, $match) && ! in_array(strtolower($match[1]), self::OWN_GLOBALS, true)) {
                $globals[] = $line;
            }
        }

        return self::INI_MARKER."\n[global]\n".implode('', array_map(fn (string $line): string => $line."\n", $globals))
            ."error_log = syslog\nsyslog.ident = ".substr(self::unitName($pool), 0, -8)."\ninclude = ".$poolFile."\n";
    }

    /** @param  array{cpu_percent: int|null, memory_mb: int|null, tasks: int|null}|null  $limits */
    private static function limitLines(?array $limits): array
    {
        $lines = ['CPUAccounting=yes', 'MemoryAccounting=yes', 'TasksAccounting=yes'];
        $memory = $limits['memory_mb'] ?? null;
        $cpu = $limits['cpu_percent'] ?? null;
        $tasks = $limits['tasks'] ?? null;
        // Hard limit only: MemoryHigh without swap stalls PHP instead of ending one worker.
        $lines[] = 'MemoryMax='.($memory === null ? 'infinity' : $memory.'M');
        if ($memory !== null) {
            $lines[] = 'MemorySwapMax=0';
        }
        if ($cpu !== null) {
            $lines[] = 'CPUQuota='.$cpu.'%';
        }
        $lines[] = 'TasksMax='.($tasks === null ? 'infinity' : $tasks);

        return $lines;
    }

    /** @param  array{account: array<string, int|null>}  $policy */
    public static function sliceUnit(int $clientId, array $policy): string
    {
        return self::MARKER."\n[Unit]\nDescription=PHP-FPM websites of ISPConfig client ".$clientId."\n\n[Slice]\n"
            .implode("\n", self::limitLines($policy['account'] ?? null))."\n";
    }

    /**
     * @param  array{unit: string, version: string, binary: string}  $service
     * @param  array{website: array<string, int|null>}  $policy
     */
    public static function poolUnit(array $service, string $pool, int $clientId, array $policy): string
    {
        $unit = $service['unit'].'.service';

        return self::MARKER."\n[Unit]\n"
            .'Description=PHP '.$service['version'].' FPM pool '.$pool.' (ISPConfig client '.$clientId.")\n"
            ."PartOf=$unit\nAfter=$unit network.target\nStartLimitIntervalSec=60\nStartLimitBurst=5\n\n[Service]\n"
            ."Type=notify\nSlice=".self::sliceName($clientId)."\n"
            .'ExecStartPre='.self::CLI.' wait-socket '.$pool."\n"
            .'ExecStart='.$service['binary'].' --nodaemonize --fpm-config '.self::poolConfPath($pool)."\n"
            ."ExecReload=/bin/kill -USR2 \$MAINPID\nRestart=on-failure\nRestartSec=2\n"
            // One killed worker must not stop the pool (systemd's default is stop).
            ."OOMPolicy=continue\n"
            .implode("\n", array_merge(self::limitLines($policy['website'] ?? null), self::HARDENING))
            ."\n\n[Install]\nWantedBy=$unit\n";
    }

    /**
     * Drop-in for a distribution unit. Hooks run with full privileges (`+`, the
     * unit protects /etc) and never fail the service (`-`). If the generated
     * configuration is missing, the shell guard restores a distribution copy.
     *
     * @param  array{unit: string, binary: string, conf: string}  $service
     */
    public static function dropIn(array $service): string
    {
        $shared = self::sharedConfPath($service['unit']);
        $hook = '-+'.self::CLI.' hook '.$service['unit'].' ';

        return self::MARKER."\n[Service]\n"
            .'ExecStartPre='.$hook."pre-start\n"
            ."ExecStartPre=+/bin/sh -c 'test -s ".$shared.' || { mkdir -p '.self::ETC.' && cp '.$service['conf'].' '.$shared."; }'\n"
            ."ExecStart=\nExecStart=".$service['binary'].' --nodaemonize --fpm-config '.$shared."\n"
            .'ExecStartPost='.$hook."post-start\n"
            ."ExecReload=\nExecReload=".$hook."pre-reload\nExecReload=/bin/kill -USR2 \$MAINPID\n"
            .'ExecReload='.$hook."post-reload\n";
    }

    /** @return array{0: string, 1: int}|array{0: string} unix path, or host and port */
    public static function listenAddress(string $listen): array
    {
        if ($listen !== '' && $listen[0] === '/') {
            return [$listen];
        }
        if (preg_match('/\A\[([0-9a-fA-F:]+)\]:([0-9]{1,5})\z/D', $listen, $match)) {
            return [$match[1], (int) $match[2]];
        }
        if (preg_match('/\A([^:]+):([0-9]{1,5})\z/D', $listen, $match)) {
            return [$match[1] === '0.0.0.0' || $match[1] === '*' ? '127.0.0.1' : $match[1], (int) $match[2]];
        }

        return ['127.0.0.1', (int) $listen];
    }

    /**
     * Fold one cgroup sample into hourly buckets for the 24-hour figures. Without
     * a previous sample, a cgroup created (`$born`) within the last 24 hours counts
     * from zero at its creation, so events before the first sample are kept.
     *
     * @param  array<string, mixed>|null  $history  previous return value
     * @param  array{cpu_usec: int|null, throttled: int|null, oom_kill: int|null, pids_max: int|null}  $sample
     * @return array<string, mixed>
     */
    public static function fold(?array $history, array $sample, int $now, ?int $born = null): array
    {
        $last = $history['last'] ?? null;
        if ($last === null && $born !== null && $born < $now && $now - $born <= 86400) {
            $last = ['t' => $born, 'cpu_usec' => 0, 'throttled' => 0, 'oom_kill' => 0, 'pids_max' => 0];
        }
        $buckets = array_filter($history['buckets'] ?? [], fn ($bucket, $hour): bool => (int) $hour > intdiv($now, 3600) - 24, ARRAY_FILTER_USE_BOTH);
        $delta = null;
        if (is_array($last) && $now > (int) $last['t']) {
            // Counters restart with the unit: when CPU time went backwards (or any
            // counter did), every counter is a fresh one.
            $reset = false;
            foreach (['cpu_usec', 'throttled', 'oom_kill', 'pids_max'] as $key) {
                $reset = $reset || (int) ($sample[$key] ?? 0) < (int) ($last[$key] ?? 0);
            }
            $diff = fn (string $key): int => $reset ? (int) ($sample[$key] ?? 0) : (int) ($sample[$key] ?? 0) - (int) ($last[$key] ?? 0);
            $delta = ['seconds' => $now - (int) $last['t'], 'cpu_usec' => $diff('cpu_usec'), 'throttled' => $diff('throttled'),
                'oom_kill' => $diff('oom_kill'), 'pids_max' => $diff('pids_max')];
            $hour = (string) intdiv($now, 3600);
            $bucket = $buckets[$hour] ?? ['seconds' => 0, 'cpu_usec' => 0, 'limited_minutes' => 0, 'oom_kill' => 0, 'pids_max' => 0];
            $bucket['seconds'] += $delta['seconds'];
            $bucket['cpu_usec'] += $delta['cpu_usec'];
            $bucket['limited_minutes'] += $delta['throttled'] > 0 ? 1 : 0;
            $bucket['oom_kill'] += $delta['oom_kill'];
            $bucket['pids_max'] += $delta['pids_max'];
            $buckets[$hour] = $bucket;
        }

        return ['last' => ['t' => $now] + $sample, 'delta' => $delta, 'buckets' => $buckets];
    }

    /** @return array{cpu_percent: float|null, cpu_percent_24h: float|null, cpu_limited_minutes_24h: int, memory_limit_hits_24h: int, tasks_limit_hits_24h: int} */
    public static function figures(array $history): array
    {
        $delta = $history['delta'] ?? null;
        $seconds = array_sum(array_column($history['buckets'] ?? [], 'seconds'));
        $cpu = array_sum(array_column($history['buckets'] ?? [], 'cpu_usec'));

        return [
            'cpu_percent' => is_array($delta) && $delta['seconds'] > 0 ? round($delta['cpu_usec'] / $delta['seconds'] / 10000, 1) : null,
            'cpu_percent_24h' => $seconds > 0 ? round($cpu / $seconds / 10000, 1) : null,
            'cpu_limited_minutes_24h' => min(1440, (int) array_sum(array_column($history['buckets'] ?? [], 'limited_minutes'))),
            'memory_limit_hits_24h' => (int) array_sum(array_column($history['buckets'] ?? [], 'oom_kill')),
            'tasks_limit_hits_24h' => (int) array_sum(array_column($history['buckets'] ?? [], 'pids_max')),
        ];
    }
}
