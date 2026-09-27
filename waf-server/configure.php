<?php

// Administrative tool. License data is accepted only on stdin and stored root-only.
if (PHP_SAPI !== 'cli' || posix_geteuid() !== 0) {
    exit(1);
}
umask(0077);
function command(array $argv): void
{
    $process = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (! is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Web server validation/reload failed; Check the web server configuration as root.');
    }
}
function put(string $file, string $content, int $mode = 0600): void
{
    if (is_link($file)) {
        throw new RuntimeException('Refusing a configuration symlink.');
    }
    $temp = $file.'.'.bin2hex(random_bytes(6));
    if (file_put_contents($temp, $content) !== strlen($content)) {
        throw new RuntimeException('Could not write configuration.');
    }
    chmod($temp, $mode);
    rename($temp, $file);
}
function check(string $engine): void
{
    command($engine === 'apache' ? ['/usr/sbin/apache2ctl', 'configtest'] : ['/usr/sbin/nginx', '-t']);
}
function reload(string $engine): void
{
    command(['/usr/bin/systemctl', 'reload', $engine === 'apache' ? 'apache2' : 'nginx']);
}
function validateRules(string $engine, bool $atomic = false): void
{
    $rules = "Include /etc/ispconfig-waf/base.conf\nInclude /etc/ispconfig-waf/owasp.conf\n";
    if ($atomic) {
        $rules .= "Include /etc/ispconfig-waf/atomic.conf\n";
    }
    $rules .= "SecRuleEngine DetectionOnly\n";
    $probe = '/etc/ispconfig-waf/validation.'.$engine;
    if ($engine === 'apache') {
        put($probe, "<VirtualHost 127.0.0.1:1>\nSecRuleInheritance Off\n".$rules."</VirtualHost>\n");
        $command = ['/usr/sbin/apache2ctl', '-t', '-c', 'Include '.$probe];
    } else {
        put($probe, "include /etc/nginx/modules-enabled/*.conf;\nevents {}\nhttp { server { listen 127.0.0.1:1; modsecurity on; modsecurity_rules '\n".$rules."'; } }\n");
        $command = ['/usr/sbin/nginx', '-t', '-c', $probe];
    }
    try {
        command($command);
    } finally {
        unlink($probe);
    }
}

$adminLock = fopen('/etc/ispconfig-waf/admin.lock', 'c');
if (! $adminLock || ! flock($adminLock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another WAF configuration operation is running.\n");
    exit(1);
}
$rollback = [];
function snapshot(array $paths): array
{
    $snapshot = [];
    foreach ($paths as $path) {
        $snapshot[$path] = is_link($path) ? ['link' => readlink($path)] : (is_file($path) ? ['content' => file_get_contents($path), 'mode' => fileperms($path) & 0777] : []);
    }

    return $snapshot;
}
function restore(array $snapshot): void
{
    foreach ($snapshot as $path => $value) {
        if (isset($value['content'])) {
            put($path, $value['content'], $value['mode']);
        } else {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            }
            if (isset($value['link'])) {
                symlink($value['link'], $path);
            }
        }
    }
}
$stateFile = '/etc/ispconfig-waf/installed.json';
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : null;
$action = $argv[1] ?? 'status';
try {
    if ($action === 'install') {
        $engine = $argv[2];
        $source = $argv[3];
        if (! in_array($engine, ['apache', 'nginx'], true)) {
            throw new RuntimeException('Invalid web server.');
        }
        if ($state && $state['engine'] !== $engine) {
            throw new RuntimeException('Remove the existing managed WAF before changing web server engines.');
        }
        $rollback = snapshot(['/etc/ispconfig-waf/base.conf', '/etc/ispconfig-waf/unicode.mapping', '/etc/ispconfig-waf/owasp.conf', $stateFile,
            '/etc/apache2/conf-available/ispconfig-waf.conf', '/etc/apache2/conf-enabled/ispconfig-waf.conf',
            '/etc/apache2/mods-enabled/security2.load', '/etc/apache2/mods-enabled/security2.conf', '/etc/apache2/mods-enabled/unique_id.load']);
        $base = file_get_contents($source.'/modsecurity.conf-recommended');
        if (! $base || ! is_file($source.'/unicode.mapping')) {
            throw new RuntimeException('Reference ModSecurity configuration is missing.');
        }
        $base = preg_replace('/^SecRequestBodyLimit\s+\d+/m', 'SecRequestBodyLimit 1073741824', $base);
        $base = preg_replace('/^SecRequestBodyNoFilesLimit\s+\d+/m', 'SecRequestBodyNoFilesLimit 1048576', $base);
        $base = preg_replace('/^SecTmpDir\s+.+$/m', 'SecTmpDir /var/lib/ispconfig-rest-waf-tmp', $base);
        $base = preg_replace('/^SecDataDir\s+.+$/m', 'SecDataDir /var/lib/ispconfig-rest-waf-data', $base);
        $base = preg_replace('/^SecUnicodeMapFile\s+.+$/m', 'SecUnicodeMapFile /etc/ispconfig-waf/unicode.mapping 20127', $base);
        // Serial logs are set separately for each vhost. Do not create a shared customer log.
        $base = preg_replace('/^SecAuditLog\s+.+$/m', '', $base);
        $base = preg_replace('/^SecAuditLogParts\s+.+$/m', 'SecAuditLogParts ABFHZ', $base);
        if ($engine === 'nginx') {
            $base = preg_replace('/^(SecRequestBodyInMemoryLimit|SecPcreMatchLimitRecursion|SecStatusEngine)\s+.+$/m', '', $base);
        }
        $global = '';
        if ($engine === 'apache') {
            $base = preg_replace_callback('/^(SecPcreMatchLimit(?:Recursion)?|SecStatusEngine|SecUnicodeMapFile|SecTmpDir|SecDataDir)\s+.+$/m', function ($match) use (&$global) {
                $global .= $match[0]."\n";

                return '';
            }, $base);
        }
        put('/etc/ispconfig-waf/base.conf', $base, 0644);
        put('/etc/ispconfig-waf/unicode.mapping', file_get_contents($source.'/unicode.mapping'), 0644);
        put('/etc/ispconfig-waf/owasp.conf', "Include /etc/modsecurity/crs/crs-setup.conf\nInclude /usr/share/modsecurity-crs/rules/*.conf\n", 0644);
        if ($engine === 'apache') {
            // Unmanaged websites stay off; the native website block enables its own isolated rules.
            put('/etc/apache2/conf-available/ispconfig-waf.conf', "<IfModule security2_module>\nSecRuleEngine Off\n".$global."</IfModule>\n", 0644);
            command(['/usr/sbin/a2enmod', 'security2', 'unique_id']);
            command(['/usr/sbin/a2enconf', 'ispconfig-waf']);
        }
        validateRules($engine);
        check($engine);
        reload($engine);
        $version = trim((string) shell_exec('dpkg-query -W -f=\'${Version}\' modsecurity-crs 2>/dev/null'));
        put($stateFile, json_encode(['engine' => $engine, 'rules_version' => $version, 'atomic_available' => (bool) ($state['atomic_available'] ?? false)], JSON_THROW_ON_ERROR));
        $rollback = [];
        echo "Configured OWASP CRS and isolated per-website logging.\n";
    } elseif ($action === 'status') {
        if (! $state) {
            throw new RuntimeException('WAF is not installed.');
        }
        echo json_encode($state, JSON_PRETTY_PRINT)."\n";
    } elseif ($action === 'atomic-key') {
        if (! $state) {
            throw new RuntimeException('WAF is not installed.');
        }
        $key = trim((string) fgets(STDIN, 1025));
        if (! preg_match('~\A[A-Za-z0-9_.:+/=\-]{8,512}\z~D', $key)) {
            throw new RuntimeException('Invalid license key format.');
        }
        $file = '/etc/ispconfig-waf/atomic.conf';
        $previous = is_file($file) ? file_get_contents($file) : null;
        put($file, "SecRemoteRulesFailAction Abort\nSecRemoteRules $key https://waf.atomicorp.com/rules/srr.php\n");
        unset($key);
        try {
            validateRules($state['engine'], true);
            check($state['engine']);
            reload($state['engine']);
        } catch (Throwable $e) {
            if ($previous === null) {
                unlink($file);
            } else {
                put($file, $previous);
            }
            throw new RuntimeException('Atomicorp validation failed. The prior license/configuration was preserved.');
        }
        $state['atomic_available'] = true;
        put($stateFile, json_encode($state, JSON_THROW_ON_ERROR));
        echo "Atomicorp license validated. The additional ruleset can now be selected per website.\n";
    } elseif ($action === 'atomic-disable') {
        if (! $state) {
            throw new RuntimeException('WAF is not installed.');
        }
        // Refuse removal while native vhosts still reference this ruleset.
        $dir = $state['engine'] === 'apache' ? '/etc/apache2/sites-enabled' : '/etc/nginx/sites-enabled';
        foreach (glob($dir.'/*') ?: [] as $file) {
            if (is_file($file) && str_contains(file_get_contents($file), 'Include /etc/ispconfig-waf/atomic.conf')) {
                throw new RuntimeException('Disable Atomicorp on its websites and wait for ISPConfig to apply those changes first.');
            }
        }
        if (is_file('/etc/ispconfig-waf/atomic.conf')) {
            unlink('/etc/ispconfig-waf/atomic.conf');
        }
        $state['atomic_available'] = false;
        put($stateFile, json_encode($state, JSON_THROW_ON_ERROR));
        echo "Atomicorp key removed.\n";
    } elseif ($action === 'refresh') {
        if (! $state) {
            throw new RuntimeException('WAF is not installed.');
        }
        validateRules($state['engine'], ! empty($state['atomic_available']));
        check($state['engine']);
        reload($state['engine']);
        echo "Rules validated and web server reloaded.\n";
    } else {
        throw new RuntimeException('Unknown WAF command.');
    }
} catch (Throwable $e) {
    if ($rollback) {
        restore($rollback);
    }
    fwrite(STDERR, $e instanceof RuntimeException ? $e->getMessage()."\n" : "WAF configuration failed.\n");
    exit(1);
}
