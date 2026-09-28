<?php

declare(strict_types=1);

use App\Support\PhpLimits;

/*
 * php-limits worker (spec 053). Commands:
 *   run                      cron: fetch limits, reconcile, sample usage, heartbeat
 *   hook UNIT PHASE          called by the php-fpm drop-in around start/reload
 *   wait-socket POOL         ExecStartPre of a dedicated pool service
 *   install-services [--no-restart]
 *   status
 *   uninstall
 */
if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    fwrite(STDERR, "Run the installed php-limits worker as root.\n");
    exit(1);
}
require __DIR__.'/PhpLimits.php';
ini_set('memory_limit', '64M');
openlog('ispconfig-rest-php-limits', LOG_PID, LOG_DAEMON);

const STATE_DIR = '/var/lib/ispconfig-rest-php-limits';
const WORKER_VERSION = 1;
const HOOK_PHASES = ['pre-start', 'post-start', 'pre-reload', 'post-reload'];

try {
    exit(match ($argv[1] ?? '') {
        'run' => command_run(),
        'hook' => command_hook((string) ($argv[2] ?? ''), (string) ($argv[3] ?? '')),
        'wait-socket' => command_wait_socket((string) ($argv[2] ?? '')),
        'install-services' => command_install_services(in_array('--no-restart', $argv, true)),
        'status' => command_status(),
        'uninstall' => command_uninstall(),
        default => usage(),
    });
} catch (Throwable $e) {
    // No credentials, paths or exception messages in diagnostics.
    syslog(LOG_ERR, 'php-limits '.($argv[1] ?? '').' failed ('.get_class($e).' at line '.$e->getLine().').');
    fwrite(STDERR, "php-limits failed; see the system log (ispconfig-rest-php-limits).\n");
    exit(1);
}

function usage(): int
{
    fwrite(STDERR, "Usage: ispconfig-php-limits run|status|install-services [--no-restart]|uninstall\n");

    return 2;
}

// ---------------------------------------------------------------- commands

function command_run(): int
{
    $lock = lock('run', false);
    if ($lock === null) {
        return 0;
    }
    $support = support();
    $db = database();
    $server = (int) $GLOBALS['conf']['server_id'];
    $policies = [];
    foreach ($db->query('SELECT client_id, settings, revision FROM api_client_resource_limits') as $row) {
        $policies[(int) $row['client_id']] = json_decode($row['settings'], true, 8, JSON_THROW_ON_ERROR) + ['revision' => (int) $row['revision']];
    }
    ksort($policies);
    write_json(STATE_DIR.'/policies.json', ['policies' => $policies]);

    $services = services();
    $reload = [];
    if ($support === null) {
        foreach ($services as $unit => $service) {
            if ($service['state'] !== 'managed') {
                continue;
            }
            $sync = lock('sync', true);
            if (reconcile($service, $services, $policies)) {
                $reload[] = $unit;
            }
            unset($sync);
        }
    }
    foreach ($reload as $unit) {
        // The reload runs the hooks, which take the sync lock themselves.
        if (systemctl(['is-active', '--quiet', $unit.'.service'])[0] === 0) {
            [$code] = systemctl(['reload', $unit.'.service'], 120);
            if ($code !== 0) {
                syslog(LOG_WARNING, 'Reloading '.$unit.' for PHP resource limits failed.');
            }
        }
    }
    report($db, $server, $support, $services, $policies);

    return 0;
}

/**
 * Detect failed pool services and limit changes. Returns true when pool
 * membership changed, which needs a reload of the shared master.
 */
function reconcile(array $service, array $services, array $policies): bool
{
    $unit = $service['unit'];
    $state = load_state();
    $pools = scan_pools($service);
    foreach ($state['pools'] as $name => $entry) {
        if ($entry['service'] !== $unit || $entry['state'] !== 'isolated' || systemctl(['is-failed', '--quiet', PhpLimits::unitName($name)])[0] !== 0) {
            continue;
        }
        [$code] = run_command([$service['binary'], '-t', '--fpm-config', PhpLimits::poolConfPath($name)], 30);
        $state['pools'][$name] = ['state' => $code === 0 ? 'fallback' : 'failed', 'reason' => $code === 0 ? 'unit_failed' : 'pool_invalid',
            'since' => time()] + $entry;
        syslog(LOG_WARNING, 'PHP pool '.$name.' service failed; '.($code === 0 ? 'using the shared master' : 'its configuration is invalid').'.');
    }
    save_state($state);
    $desired = $service['uses_shared'] ? PhpLimits::isolated($pools, $policies, $state['pools'], seen_pools($services), time()) : [];
    $current = [];
    foreach ($state['pools'] as $name => $entry) {
        if ($entry['service'] === $unit && in_array($entry['state'], ['isolated', 'failed'], true)) {
            $current[$name] = (int) $entry['client_id'];
        }
    }
    ksort($current);
    $shared = shared_files($pools, $desired, []);
    $expected = PhpLimits::sharedConf((string) file_get_contents($service['conf']), $service['pool_dir'], $shared, placeholder_path($unit));
    if ($desired !== $current || $expected !== read_file(PhpLimits::sharedConfPath($unit))) {
        return true;
    }
    // Same membership: apply changed limit values in place (daemon-reload
    // updates running cgroups without restarting PHP).
    $changed = write_units($service, $desired, $policies, $state);
    if ($changed) {
        systemctl(['daemon-reload']);
    }
    save_state($state);

    return false;
}

function command_hook(string $unit, string $phase): int
{
    if (! in_array($phase, HOOK_PHASES, true) || ! preg_match('/\Aphp[0-9]+\.[0-9]+-fpm\z/D', $unit)) {
        return usage();
    }
    $lock = lock('sync', true);
    $services = services();
    $service = $services[$unit] ?? null;
    if ($service === null || $service['state'] === 'unmanaged') {
        return 0;
    }
    $starting = $phase === 'pre-start' || $phase === 'post-start';
    if (str_starts_with($phase, 'pre-')) {
        hook_before($service, $services, $starting);
    } else {
        hook_after($service, $starting);
    }

    return 0;
}

/** Before the shared master starts or reloads: decide membership and write files. */
function hook_before(array $service, array $services, bool $starting): void
{
    $unit = $service['unit'];
    $state = load_state();
    $pools = scan_pools($service);
    $policies = read_json(STATE_DIR.'/policies.json')['policies'] ?? null;
    $usesShared = $starting || $service['uses_shared'];
    if (! is_array($policies)) {
        // Unreadable limits: keep the current membership rather than guessing.
        $desired = [];
        foreach ($state['pools'] as $name => $entry) {
            if ($entry['service'] === $unit && in_array($entry['state'], ['isolated', 'failed'], true) && isset($pools[$name])) {
                $desired[$name] = (int) $entry['client_id'];
            }
        }
        $policies = [];
        foreach ($desired as $clientId) {
            $policies[$clientId] = $state['policies'][$clientId] ?? ['account' => null, 'website' => null, 'revision' => 0];
        }
    } else {
        $desired = $usesShared ? PhpLimits::isolated($pools, $policies, $state['pools'], seen_pools($services), time()) : [];
    }
    $changed = false;
    $busy = [];
    foreach ($state['pools'] as $name => $entry) {
        if ($entry['service'] !== $unit || isset($desired[$name])) {
            continue;
        }
        if (in_array($entry['state'], ['isolated', 'failed'], true)) {
            systemctl(['stop', PhpLimits::unitName($name)], 30);
        }
        $changed = remove_pool_unit($name) || $changed;
        if (isset($pools[$name]) && $pools[$name]['listen'] !== null && listening($pools[$name]['listen'])) {
            // Never let two masters claim one socket: keep it out this round.
            $busy[] = $name;
            syslog(LOG_WARNING, 'PHP pool '.$name.' is still listening after its service stopped; it stays out of the shared master until the next run.');
        }
        if ($entry['state'] === 'fallback' && isset($pools[$name])) {
            continue;
        }
        unset($state['pools'][$name]);
    }
    $changed = write_units($service, $desired, $policies, $state) || $changed;
    $shared = shared_files($pools, $desired, $busy);
    $conf = PhpLimits::sharedConf((string) file_get_contents($service['conf']), $service['pool_dir'], $shared, placeholder_path($unit));
    if ($conf === null) {
        syslog(LOG_ERR, 'The '.$unit.' configuration no longer includes its pool directory; PHP resource limits cannot manage it.');
        $conf = (string) file_get_contents($service['conf']);
    }
    if ($shared === []) {
        write_file(placeholder_path($unit), PhpLimits::placeholderPool($unit), 0644);
    }
    write_file(PhpLimits::sharedConfPath($unit), $conf, 0644);
    if ($changed) {
        systemctl(['daemon-reload']);
    }
    save_state($state);
}

/** After the shared master started or reloaded: start or reload dedicated pools. */
function hook_after(array $service, bool $starting): void
{
    $state = load_state();
    foreach ($state['pools'] as $name => $entry) {
        if ($entry['service'] !== $service['unit'] || ! in_array($entry['state'], ['isolated', 'failed'], true) || ! is_file(PhpLimits::UNIT_DIR.'/'.PhpLimits::unitName($name))) {
            continue;
        }
        $unitName = PhpLimits::unitName($name);
        $active = systemctl(['is-active', '--quiet', $unitName])[0] === 0;
        if ($entry['state'] === 'failed' && ($entry['started_hash'] ?? null) === $entry['hash']) {
            continue;
        }
        if (! $active) {
            systemctl(['reset-failed', $unitName]);
            systemctl(['start', '--no-block', $unitName]);
        } elseif (! $starting && ($entry['started_hash'] ?? null) !== $entry['hash']) {
            systemctl(['reload', '--no-block', $unitName]);
        }
        $state['pools'][$name]['started_hash'] = $entry['hash'];
    }
    save_state($state);
}

/** ExecStartPre of a dedicated pool: wait until the shared master released the socket. */
function command_wait_socket(string $pool): int
{
    if (! preg_match('/\Aweb[1-9][0-9]{0,9}\z/D', $pool)) {
        return usage();
    }
    $file = null;
    foreach (glob('/etc/php/*/fpm/pool.d/'.$pool.'.conf') ?: [] as $candidate) {
        $file = $candidate;
    }
    $listen = $file === null ? null : PhpLimits::pool($pool, (string) file_get_contents($file))['listen'];
    if ($listen === null) {
        return 0;
    }
    $deadline = microtime(true) + 15;
    while (listening($listen)) {
        if (microtime(true) > $deadline) {
            fwrite(STDERR, "Another master still listens for pool $pool.\n");

            return 1;
        }
        usleep(200000);
    }

    return 0;
}

function command_install_services(bool $noRestart): int
{
    $support = support();
    if ($support !== null) {
        fwrite(STDERR, 'PHP resource limits are not supported on this server: '.$support.".\n");

        return 1;
    }
    foreach (['', '/pools'] as $dir) {
        if (! is_dir(PhpLimits::ETC.$dir)) {
            mkdir(PhpLimits::ETC.$dir, 0755, true);
        }
    }
    $services = services();
    $installed = [];
    foreach ($services as $unit => $service) {
        if ($service['state'] === 'unmanaged') {
            echo "Skipping $unit: {$service['reason']}.\n";

            continue;
        }
        if (! is_file(PhpLimits::sharedConfPath($unit))) {
            // Until the first hook every pool stays in the shared master.
            $pools = scan_pools($service);
            $shared = shared_files($pools, [], []);
            write_file(PhpLimits::sharedConfPath($unit), (string) PhpLimits::sharedConf((string) file_get_contents($service['conf']), $service['pool_dir'], $shared, placeholder_path($unit)), 0644);
        }
        write_file(PhpLimits::dropInPath($unit), PhpLimits::dropIn($service), 0644);
        $installed[] = $unit;
    }
    systemctl(['daemon-reload']);
    foreach (services() as $unit => $service) {
        if (! in_array($unit, $installed, true) || $service['uses_shared'] || systemctl(['is-active', '--quiet', $unit.'.service'])[0] !== 0) {
            continue;
        }
        if ($noRestart) {
            echo "$unit must be restarted before its websites can be isolated.\n";

            continue;
        }
        echo "Restarting $unit once to use the managed configuration.\n";
        [$code] = systemctl(['restart', $unit.'.service'], 120);
        if ($code !== 0) {
            fwrite(STDERR, "Restarting $unit failed; check 'systemctl status $unit'.\n");

            return 1;
        }
    }
    echo 'Managed PHP-FPM services: '.($installed === [] ? 'none' : implode(', ', $installed)).".\n";

    return 0;
}

function command_status(): int
{
    $support = support();
    echo 'Support: '.($support ?? 'ready')."\n";
    foreach (services() as $unit => $service) {
        echo $unit.': '.$service['state'].($service['reason'] !== null ? ' ('.$service['reason'].')' : '')."\n";
    }
    $state = load_state();
    foreach ($state['pools'] as $name => $entry) {
        $active = systemctl(['is-active', PhpLimits::unitName($name)])[1];
        echo sprintf("%s: %s, client %d, %s, service %s%s\n", $name, $entry['state'], $entry['client_id'], $entry['service'], trim($active),
            isset($entry['reason']) ? ', '.$entry['reason'] : '');
    }

    return 0;
}

function command_uninstall(): int
{
    $lock = lock('sync', true);
    foreach (glob(PhpLimits::UNIT_DIR.'/ispconfig-php-web*.service') ?: [] as $file) {
        if (managed($file)) {
            systemctl(['stop', basename($file)], 30);
            remove_pool_unit(substr(basename($file), strlen('ispconfig-php-'), -8));
        }
    }
    foreach (glob(PhpLimits::UNIT_DIR.'/ispconfig-client*.slice') ?: [] as $file) {
        if (managed($file)) {
            unlink($file);
        }
    }
    $restart = [];
    foreach (glob(PhpLimits::UNIT_DIR.'/php*-fpm.service.d/50-ispconfig-rest-php-limits.conf') ?: [] as $file) {
        if (managed($file)) {
            unlink($file);
            @rmdir(dirname($file));
            $restart[] = basename(dirname($file), '.d');
        }
    }
    systemctl(['daemon-reload']);
    foreach ($restart as $unit) {
        if (systemctl(['is-active', '--quiet', $unit])[0] === 0) {
            echo "Restarting $unit with its distribution configuration.\n";
            systemctl(['restart', $unit], 120);
        }
    }
    foreach (array_merge(glob(PhpLimits::ETC.'/pools/*.conf') ?: [], glob(PhpLimits::ETC.'/*.conf') ?: [], glob(PhpLimits::ETC.'/*.pool') ?: []) as $file) {
        unlink($file);
    }
    @rmdir(PhpLimits::ETC.'/pools');
    @rmdir(PhpLimits::ETC);
    @unlink('/etc/cron.d/ispconfig-rest-php-limits');
    foreach (glob(STATE_DIR.'/*.json') ?: [] as $file) {
        unlink($file);
    }
    echo "PHP resource limits removed; every pool runs in its shared master again.\n";

    return 0;
}

// ---------------------------------------------------------------- systemd

/**
 * Distribution php-fpm services. `state`: managed (drop-in installed),
 * available (can be managed), unmanaged (reason says why).
 *
 * @return array<string, array<string, mixed>>
 */
function services(): array
{
    $services = [];
    foreach (glob('/etc/php/*/fpm/php-fpm.conf') ?: [] as $conf) {
        $version = basename(dirname($conf, 2));
        $unit = 'php'.$version.'-fpm';
        $fragment = trim(systemctl(['show', '-p', 'FragmentPath', '--value', $unit.'.service'])[1]);
        $service = $fragment !== '' && is_file($fragment) ? PhpLimits::service($version, (string) file_get_contents($fragment)) : null;
        $reason = $fragment === '' ? 'unit_missing' : ($service === null ? 'exec_start_changed' : null);
        if ($service !== null && PhpLimits::sharedConf((string) file_get_contents($conf), $service['pool_dir'], [], '') === null) {
            $reason = 'pool_include_missing';
        }
        $service ??= ['unit' => $unit, 'version' => $version, 'binary' => '', 'conf' => $conf, 'pool_dir' => ''];
        $managed = $reason === null && managed(PhpLimits::dropInPath($unit));
        $service['state'] = $reason !== null ? 'unmanaged' : ($managed ? 'managed' : 'available');
        $service['reason'] = $reason ?? ($managed ? null : 'service_unmanaged');
        $service['uses_shared'] = $managed && master_uses_shared($unit);
        if ($managed && ! $service['uses_shared'] && systemctl(['is-active', '--quiet', $unit.'.service'])[0] === 0) {
            $service['reason'] = 'service_restart_required';
        }
        $services[$unit] = $service;
    }
    ksort($services);

    return $services;
}

/** A reload re-executes the master with its original arguments, so check the running one. */
function master_uses_shared(string $unit): bool
{
    $pid = (int) trim(systemctl(['show', '-p', 'MainPID', '--value', $unit.'.service'])[1]);
    $cmdline = $pid > 0 ? (string) @file_get_contents('/proc/'.$pid.'/cmdline') : '';

    return $cmdline !== '' && str_contains(str_replace("\0", ' ', $cmdline), PhpLimits::sharedConfPath($unit));
}

/**
 * Write pool masters, pool services and account slices. Returns true when a
 * unit file changed (the caller runs daemon-reload).
 *
 * @param  array<string, int>  $desired  pool => client id
 */
function write_units(array $service, array $desired, array $policies, array &$state): bool
{
    $changed = false;
    $pools = scan_pools($service);
    $distro = (string) file_get_contents($service['conf']);
    foreach ($desired as $name => $clientId) {
        $policy = $policies[$clientId];
        write_file(PhpLimits::poolConfPath($name), PhpLimits::poolConf($distro, $name, $service['pool_dir'].'/'.$name.'.conf'), 0644);
        $changed = write_file(PhpLimits::UNIT_DIR.'/'.PhpLimits::unitName($name), PhpLimits::poolUnit($service, $name, $clientId, $policy), 0644) || $changed;
        $wants = PhpLimits::UNIT_DIR.'/'.$service['unit'].'.service.wants/'.PhpLimits::unitName($name);
        if (! is_link($wants)) {
            if (! is_dir(dirname($wants))) {
                mkdir(dirname($wants), 0755, true);
            }
            symlink(PhpLimits::UNIT_DIR.'/'.PhpLimits::unitName($name), $wants);
            $changed = true;
        }
        $previous = $state['pools'][$name] ?? [];
        $state['pools'][$name] = [
            'service' => $service['unit'],
            'client_id' => $clientId,
            'hash' => $pools[$name]['hash'],
            'state' => ($previous['state'] ?? '') === 'failed' && ($previous['hash'] ?? null) === $pools[$name]['hash'] ? 'failed' : 'isolated',
            'since' => ($previous['state'] ?? '') === 'isolated' ? (int) $previous['since'] : time(),
            'revision' => (int) $policy['revision'],
        ] + array_intersect_key($previous, ['started_hash' => true]);
        if ($state['pools'][$name]['state'] === 'failed') {
            $state['pools'][$name]['reason'] = 'pool_invalid';
        }
    }
    // Slices follow every account with a dedicated pool on any PHP version.
    $clients = [];
    foreach ($state['pools'] as $entry) {
        if (in_array($entry['state'], ['isolated', 'failed'], true)) {
            $clients[(int) $entry['client_id']] = true;
        }
    }
    foreach (array_keys($clients) as $clientId) {
        $policy = $policies[$clientId] ?? $state['policies'][$clientId] ?? null;
        if ($policy === null) {
            continue;
        }
        $changed = write_file(PhpLimits::UNIT_DIR.'/'.PhpLimits::sliceName($clientId), PhpLimits::sliceUnit($clientId, $policy), 0644) || $changed;
        $state['policies'][$clientId] = $policy;
        $state['applied'][$clientId] = (int) $policy['revision'];
    }
    foreach (glob(PhpLimits::UNIT_DIR.'/ispconfig-client*.slice') ?: [] as $file) {
        $clientId = (int) substr(basename($file), strlen('ispconfig-client'), -6);
        if (! isset($clients[$clientId]) && managed($file)) {
            unlink($file);
            unset($state['applied'][$clientId], $state['policies'][$clientId]);
            $changed = true;
        }
    }

    return $changed;
}

function remove_pool_unit(string $pool): bool
{
    $removed = false;
    foreach (array_merge([PhpLimits::UNIT_DIR.'/'.PhpLimits::unitName($pool)], glob(PhpLimits::UNIT_DIR.'/php*-fpm.service.wants/'.PhpLimits::unitName($pool)) ?: []) as $file) {
        if (is_link($file) || (is_file($file) && managed($file))) {
            unlink($file);
            $removed = true;
        }
    }
    @unlink(PhpLimits::poolConfPath($pool));

    return $removed;
}

/** @return array{0: int, 1: string} */
function systemctl(array $arguments, int $timeout = 60): array
{
    return run_command(array_merge(['systemctl'], $arguments), $timeout);
}

/** @return array{0: int, 1: string} */
function run_command(array $command, int $timeout): array
{
    $process = proc_open(array_merge(['timeout', (string) $timeout], $command), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        return [1, ''];
    }
    $output = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $output];
}

/** Null when supported, otherwise a short reason. */
function support(): ?string
{
    if (! is_file('/sys/fs/cgroup/cgroup.controllers')) {
        return 'cgroup v2 unified hierarchy required';
    }
    $controllers = preg_split('/\s+/', trim((string) file_get_contents('/sys/fs/cgroup/cgroup.controllers')));
    if (array_diff(['cpu', 'memory', 'pids'], $controllers) !== []) {
        return 'cpu, memory and pids cgroup controllers required';
    }
    if (! preg_match('/\Asystemd ([0-9]+)/', systemctl(['--version'])[1], $match) || (int) $match[1] < 243) {
        return 'systemd 243 or newer required';
    }

    return null;
}

// ---------------------------------------------------------------- pools

/** @return array<string, array<string, mixed>> */
function scan_pools(array $service): array
{
    $pools = [];
    foreach ($service['pool_dir'] === '' ? [] : (glob($service['pool_dir'].'/*.conf') ?: []) as $file) {
        $content = (string) @file_get_contents($file);
        $name = basename($file, '.conf');
        $pools[$name] = PhpLimits::pool($name, $content) + ['file' => $file, 'hash' => hash('sha256', $content)];
    }
    ksort($pools);

    return $pools;
}

/** @return array<string, int> */
function seen_pools(array $services): array
{
    $seen = [];
    foreach ($services as $service) {
        foreach (array_keys(scan_pools($service)) as $name) {
            $seen[$name] = ($seen[$name] ?? 0) + 1;
        }
    }

    return $seen;
}

/** @return array<int, string> */
function shared_files(array $pools, array $isolated, array $busy): array
{
    $files = [];
    foreach ($pools as $name => $pool) {
        if (! isset($isolated[$name]) && ! in_array($name, $busy, true)) {
            $files[] = $pool['file'];
        }
    }

    return $files;
}

function placeholder_path(string $unit): string
{
    return PhpLimits::ETC.'/'.$unit.'-placeholder.pool';
}

function listening(string $listen): bool
{
    $address = PhpLimits::listenAddress($listen);
    if (count($address) === 1 && ! file_exists($address[0])) {
        return false;
    }
    $target = count($address) === 1 ? 'unix://'.$address[0] : 'tcp://'.(str_contains($address[0], ':') ? '['.$address[0].']' : $address[0]).':'.$address[1];
    $socket = @stream_socket_client($target, $errno, $error, 0.5);
    if ($socket === false) {
        return false;
    }
    fclose($socket);

    return true;
}

// ---------------------------------------------------------------- usage

function report(PDO $db, int $server, ?string $support, array $services, array $policies): void
{
    $state = load_state();
    $samples = read_json(STATE_DIR.'/samples.json') ?? [];
    $now = time();
    $rows = [];
    $nextSamples = [];
    $clients = [];
    foreach ($services as $service) {
        foreach (scan_pools($service) as $name => $pool) {
            $clientId = $pool['client_id'];
            if (! $pool['isolatable'] || $clientId === null || ! isset($policies[$clientId])) {
                continue;
            }
            $entry = $state['pools'][$name] ?? null;
            if ($entry !== null && $entry['service'] !== $service['unit']) {
                continue;
            }
            $waiting = $support !== null ? 'cgroups_unsupported' : ($service['state'] !== 'managed' ? $service['reason'] : ($service['uses_shared'] ? null : 'service_restart_required'));
            $rowState = $entry['state'] ?? ($waiting !== null ? 'waiting' : null);
            if ($rowState === null) {
                continue;
            }
            $metrics = [];
            if ($rowState === 'isolated') {
                $clients[$clientId] = true;
                $metrics = sample(PhpLimits::unitCgroup($clientId, $name), $samples, $nextSamples, $now, PhpLimits::sliceCgroup($clientId));
            }
            $rows[] = ['server_id' => $server, 'scope' => 'website', 'scope_id' => (int) $pool['domain_id'], 'client_id' => $clientId,
                'state' => $rowState, 'reason' => $rowState === 'waiting' ? $waiting : ($entry['reason'] ?? null), 'php_service' => $service['unit'],
                'applied_revision' => $entry['revision'] ?? null] + $metrics;
        }
    }
    foreach (array_keys($clients) as $clientId) {
        $rows[] = ['server_id' => $server, 'scope' => 'account', 'scope_id' => $clientId, 'client_id' => $clientId, 'state' => 'isolated',
            'reason' => null, 'php_service' => null, 'applied_revision' => $state['applied'][$clientId] ?? null]
            + sample(PhpLimits::sliceCgroup($clientId), $samples, $nextSamples, $now, null);
    }
    write_json(STATE_DIR.'/samples.json', $nextSamples);

    $db->beginTransaction();
    $keep = [];
    foreach ($rows as $row) {
        $row += array_fill_keys(['memory_bytes', 'memory_peak_bytes', 'memory_limit_bytes', 'cpu_percent', 'cpu_percent_24h', 'cpu_limit_percent', 'tasks', 'tasks_limit'], null)
            + array_fill_keys(['memory_limit_hits_24h', 'cpu_limited_minutes_24h', 'tasks_limit_hits_24h'], 0);
        $row['measured_at'] = $now;
        $columns = array_keys($row);
        $db->prepare('INSERT INTO api_php_limits_usage ('.implode(',', $columns).') VALUES ('.implode(',', array_fill(0, count($columns), '?')).') ON DUPLICATE KEY UPDATE '
            .implode(',', array_map(fn (string $column): string => $column.'=VALUES('.$column.')', array_diff($columns, ['server_id', 'scope', 'scope_id']))))->execute(array_values($row));
        $keep[$row['scope'].':'.$row['scope_id']] = true;
    }
    $existing = $db->prepare('SELECT scope, scope_id FROM api_php_limits_usage WHERE server_id = ?');
    $existing->execute([$server]);
    foreach ($existing->fetchAll() as $row) {
        if (! isset($keep[$row['scope'].':'.$row['scope_id']])) {
            $db->prepare('DELETE FROM api_php_limits_usage WHERE server_id = ? AND scope = ? AND scope_id = ?')->execute([$server, $row['scope'], $row['scope_id']]);
        }
    }
    $managed = array_filter($services, fn (array $service): bool => $service['state'] === 'managed');
    $status = $support !== null ? 'unsupported' : ($managed === [] ? 'no_managed_services' : 'ready');
    $summary = array_values(array_map(fn (array $service): array => ['unit' => $service['unit'], 'state' => $service['state'], 'reason' => $service['reason']], $services));
    $db->prepare('INSERT INTO api_php_limits_workers (server_id, heartbeat, version, status, services) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE heartbeat = VALUES(heartbeat), version = VALUES(version), status = VALUES(status), services = VALUES(services)')
        ->execute([$server, $now, WORKER_VERSION, $status, json_encode($summary, JSON_THROW_ON_ERROR)]);
    $db->commit();
}

/**
 * Read one cgroup and fold it into the 24-hour history. Effective limits are
 * the lower of the group and its account slice.
 *
 * @return array<string, int|float|null>
 */
function sample(string $path, array $samples, array &$next, int $now, ?string $parent): array
{
    if (! is_dir($path)) {
        return [];
    }
    $read = fn (string $file): ?string => is_file($path.'/'.$file) ? trim((string) file_get_contents($path.'/'.$file)) : null;
    $keyed = function (?string $content): array {
        $values = [];
        foreach (preg_split('/\R/', (string) $content) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) === 2) {
                $values[$parts[0]] = (int) $parts[1];
            }
        }

        return $values;
    };
    $cpu = $keyed($read('cpu.stat'));
    $history = PhpLimits::fold($samples[$path] ?? null, [
        'cpu_usec' => $cpu['usage_usec'] ?? null,
        'throttled' => $cpu['nr_throttled'] ?? 0,
        'oom_kill' => $keyed($read('memory.events'))['oom_kill'] ?? 0,
        'pids_max' => $keyed($read('pids.events'))['max'] ?? 0,
    ], $now);
    $next[$path] = $history;
    $limits = limits($path);
    if ($parent !== null) {
        foreach (limits($parent) as $key => $value) {
            $limits[$key] = $limits[$key] === null ? $value : ($value === null ? $limits[$key] : min($limits[$key], $value));
        }
    }
    $number = fn (?string $value): ?int => $value === null || ! is_numeric($value) ? null : (int) $value;

    return [
        'memory_bytes' => $number($read('memory.current')),
        'memory_peak_bytes' => $number($read('memory.peak')),
        'memory_limit_bytes' => $limits['memory'],
        'cpu_limit_percent' => $limits['cpu'],
        'tasks' => $number($read('pids.current')),
        'tasks_limit' => $limits['tasks'],
    ] + PhpLimits::figures($history);
}

/** @return array{memory: int|null, cpu: int|null, tasks: int|null} */
function limits(string $path): array
{
    $read = fn (string $file): string => is_file($path.'/'.$file) ? trim((string) file_get_contents($path.'/'.$file)) : 'max';
    $memory = $read('memory.max');
    $tasks = $read('pids.max');
    $cpu = preg_split('/\s+/', $read('cpu.max'));

    return [
        'memory' => is_numeric($memory) ? (int) $memory : null,
        'cpu' => is_numeric($cpu[0]) && (int) ($cpu[1] ?? 0) > 0 ? (int) round((int) $cpu[0] * 100 / (int) $cpu[1]) : null,
        'tasks' => is_numeric($tasks) ? (int) $tasks : null,
    ];
}

// ---------------------------------------------------------------- storage

function database(): PDO
{
    define('SCRIPT_PATH', '/usr/local/ispconfig/server');
    require SCRIPT_PATH.'/lib/config.inc.php';
    $GLOBALS['conf'] = $conf;
    $prefix = ! empty($conf['dbmaster_host']) && ($conf['dbmaster_host'] !== $conf['db_host'] || $conf['dbmaster_database'] !== $conf['db_database'] || (int) $conf['dbmaster_port'] !== (int) $conf['db_port']) ? 'dbmaster_' : 'db_';

    return new PDO('mysql:host='.$conf[$prefix.'host'].';port='.($conf[$prefix.'port'] ?? 3306).';dbname='.$conf[$prefix.'database'].';charset=utf8mb4', $conf[$prefix.'user'], $conf[$prefix.'password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
}

/** @return resource|null */
function lock(string $name, bool $wait)
{
    $handle = fopen(STATE_DIR.'/'.$name.'.lock', 'c');
    if ($handle === false) {
        throw new RuntimeException('lock');
    }
    if ($wait) {
        $deadline = time() + 90;
        while (! flock($handle, LOCK_EX | LOCK_NB)) {
            if (time() > $deadline) {
                throw new RuntimeException('lock timeout');
            }
            usleep(100000);
        }

        return $handle;
    }

    return flock($handle, LOCK_EX | LOCK_NB) ? $handle : null;
}

function load_state(): array
{
    $state = read_json(STATE_DIR.'/pools.json') ?? [];

    return ['pools' => $state['pools'] ?? [], 'applied' => $state['applied'] ?? [], 'policies' => $state['policies'] ?? []];
}

function save_state(array $state): void
{
    ksort($state['pools']);
    write_json(STATE_DIR.'/pools.json', $state);
}

function read_json(string $file): ?array
{
    $content = read_file($file);
    $data = $content === null ? null : json_decode($content, true);

    return is_array($data) ? $data : null;
}

function write_json(string $file, array $data): void
{
    write_file($file, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n", 0600);
}

function read_file(string $file): ?string
{
    return is_file($file) ? (string) file_get_contents($file) : null;
}

/** Atomic replace; returns true when the content changed. */
function write_file(string $file, string $content, int $mode): bool
{
    if (read_file($file) === $content) {
        return false;
    }
    if (! is_dir(dirname($file))) {
        mkdir(dirname($file), 0755, true);
    }
    $temporary = $file.'.new';
    if (file_put_contents($temporary, $content) === false || ! chmod($temporary, $mode) || ! rename($temporary, $file)) {
        throw new RuntimeException('write');
    }

    return true;
}

function managed(string $file): bool
{
    $handle = is_file($file) ? fopen($file, 'r') : false;
    if ($handle === false) {
        return false;
    }
    $first = rtrim((string) fgets($handle));
    fclose($handle);

    return $first === PhpLimits::MARKER;
}
