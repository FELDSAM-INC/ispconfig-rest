<?php

declare(strict_types=1);

// Standalone root-owned helper. Never boot Laravel, load .env, or emit credentials.
final class ServerToolsRemote
{
    public const TABLES = [
        'database' => [
            'web_database' => 'SELECT', 'sys_group' => 'SELECT', 'client' => 'SELECT',
            'api_database_workers' => 'SELECT,INSERT,UPDATE,DELETE',
            'api_database_operations' => 'SELECT,INSERT,UPDATE,DELETE',
            'api_database_operation_chunks' => 'SELECT,INSERT,UPDATE,DELETE',
        ],
        'web-logs' => [
            'web_domain' => 'SELECT', 'server' => 'SELECT', 'server_php' => 'SELECT',
            'api_web_log_workers' => 'SELECT,INSERT,UPDATE', 'api_web_log_reads' => 'SELECT,UPDATE,DELETE',
            'api_web_php_defaults' => 'SELECT,INSERT,UPDATE',
        ],
        'waf' => [
            'web_domain' => 'SELECT', 'api_web_waf_workers' => 'SELECT,INSERT,UPDATE', 'api_web_waf_events' => 'SELECT,INSERT,UPDATE,DELETE',
        ],
        'php-limits' => [
            'api_client_resource_limits' => 'SELECT', 'api_php_limits_workers' => 'SELECT,INSERT,UPDATE', 'api_php_limits_usage' => 'SELECT,INSERT,UPDATE,DELETE',
        ],
        'file-manager' => [
            'web_domain' => 'SELECT', 'server' => 'SELECT', 'server_php' => 'SELECT', 'sys_group' => 'SELECT', 'client' => 'SELECT', 'web_database' => 'SELECT', 'cron' => 'SELECT',
            'api_wordpress_cron' => 'SELECT,UPDATE',
            'api_database_workers' => 'SELECT', 'api_database_operations' => 'SELECT',
            'api_wordpress_workers' => 'SELECT,INSERT,UPDATE', 'api_wordpress_sites' => 'SELECT,INSERT,UPDATE', 'api_wordpress_jobs' => 'SELECT,INSERT,UPDATE,DELETE',
        ],
    ];

    public static function config(): array
    {
        $file = '/usr/local/ispconfig/server/lib/config.inc.php';
        if (! is_file($file)) {
            return [];
        }
        if (! defined('SCRIPT_PATH')) {
            define('SCRIPT_PATH', '/usr/local/ispconfig/server');
        }
        $conf = [];
        require $file;

        return $conf;
    }

    public static function connection(array $conf, bool $local = false): PDO
    {
        $prefix = ! $local && ! empty($conf['dbmaster_host']) && ($conf['dbmaster_host'] !== $conf['db_host'] || $conf['dbmaster_database'] !== $conf['db_database'] || (int) $conf['dbmaster_port'] !== (int) $conf['db_port']) ? 'dbmaster_' : 'db_';

        return new PDO('mysql:host='.$conf[$prefix.'host'].';port='.($conf[$prefix.'port'] ?? 3306).';dbname='.$conf[$prefix.'database'].';charset=utf8mb4', $conf[$prefix.'user'], $conf[$prefix.'password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }

    public static function permissions(array $components): array
    {
        $tables = [];
        foreach ($components as $component) {
            if (! array_key_exists($component, self::TABLES)) {
                throw new RuntimeException('Unknown component.');
            }
            foreach (self::TABLES[$component] as $table => $rights) {
                $tables[$table] = array_values(array_unique(array_merge($tables[$table] ?? [], explode(',', $rights))));
            }
        }

        return $tables;
    }

    /** EXPLAIN checks privileges without executing a write, even on nontransactional tables. */
    public static function missingGrants(PDO $db, array $tables): array
    {
        $missing = [];
        foreach ($tables as $table => $rights) {
            // Also catches missing migrations before installing anything.
            try {
                $column = $db->query('SHOW COLUMNS FROM `'.$table.'`')->fetchColumn();
            } catch (PDOException $e) {
                if (in_array((int) ($e->errorInfo[1] ?? 0), [1142, 1143], true)) {
                    $missing[$table] = $rights;

                    continue;
                }
                throw new RuntimeException('Cannot inspect '.$table.' (SQL error '.(int) ($e->errorInfo[1] ?? 0).'). Run API migrations first.');
            }
            if (! is_string($column) || ! preg_match('/^[a-zA-Z0-9_]+$/D', $column)) {
                throw new RuntimeException('Missing worker table/column: '.$table.'. Run API migrations first.');
            }
            foreach ($rights as $right) {
                $sql = match ($right) {
                    'SELECT' => 'SELECT * FROM `'.$table.'` WHERE 1=0',
                    'INSERT' => 'EXPLAIN INSERT INTO `'.$table.'` (`'.$column.'`) SELECT `'.$column.'` FROM `'.$table.'` WHERE 1=0',
                    'UPDATE' => 'EXPLAIN UPDATE `'.$table.'` SET `'.$column.'`=`'.$column.'` WHERE 1=0',
                    'DELETE' => 'EXPLAIN DELETE FROM `'.$table.'` WHERE 1=0',
                };
                try {
                    $db->query($sql)->closeCursor();
                } catch (PDOException $e) {
                    if (in_array((int) ($e->errorInfo[1] ?? 0), [1142, 1143], true)) {
                        $missing[$table][] = $right;
                    } else {
                        throw new RuntimeException('Cannot check '.$table.' (SQL error '.(int) ($e->errorInfo[1] ?? 0).'). Run API migrations first.');
                    }
                }
            }
        }

        return $missing;
    }

    public static function probe(array $conf, array $components, int $expected): array
    {
        if (($conf['server_id'] ?? 0) != $expected || $expected < 1) {
            throw new RuntimeException('ISPConfig server ID does not match the selected server; check its SSH address.');
        }
        $requirements = [];
        if (PHP_VERSION_ID < 80300 || PHP_INT_SIZE < 8) {
            $requirements[] = '64-bit PHP CLI 8.3+';
        }
        $extensions = ['pdo_mysql', 'posix'];
        if (array_intersect($components, ['waf', 'web-logs', 'file-manager']) !== []) {
            $extensions[] = 'mbstring';
        }
        if (array_intersect($components, ['database', 'web-logs']) !== []) {
            $extensions[] = 'zlib';
        }
        foreach ($extensions as $extension) {
            if (! extension_loaded($extension)) {
                $requirements[] = 'PHP extension '.$extension;
            }
        }
        $programs = ['tar', 'sha256sum', 'sh', 'bash'];
        if (in_array('database', $components, true)) {
            $programs = array_merge($programs, ['mysql', 'mysqldump', 'setpriv']);
            if (! is_file('/usr/local/ispconfig/server/lib/mysql_clientdb.conf')) {
                $requirements[] = 'ISPConfig local mysql_clientdb.conf';
            }
        }
        if (in_array('file-manager', $components, true)) {
            $programs = array_merge($programs, ['python3', 'ssh-keygen', 'openssl', 'mount', 'logger', 'useradd', 'groupadd', 'sshd']);
        }
        if (in_array('waf', $components, true)) {
            $programs[] = 'apt-get';
        }
        if (in_array('php-limits', $components, true)) {
            $programs = array_merge($programs, ['systemctl', 'timeout']);
            $controllers = is_file('/sys/fs/cgroup/cgroup.controllers') ? preg_split('/\s+/', trim((string) file_get_contents('/sys/fs/cgroup/cgroup.controllers'))) : [];
            if (array_diff(['cpu', 'memory', 'pids'], $controllers) !== []) {
                $requirements[] = 'cgroup v2 with cpu, memory and pids controllers';
            }
        }
        foreach ($programs as $program) {
            $process = proc_open(['sh', '-c', 'command -v "$1" >/dev/null', 'check', $program], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (! is_resource($process) || proc_close($process) !== 0) {
                $requirements[] = $program;
            }
        }
        if (array_intersect($components, ['waf', 'web-logs']) !== []) {
            $apache = is_link('/usr/local/ispconfig/server/plugins-enabled/apache2_plugin.inc.php');
            $nginx = is_link('/usr/local/ispconfig/server/plugins-enabled/nginx_plugin.inc.php');
            if ($apache === $nginx) {
                $requirements[] = 'exactly one enabled ISPConfig Apache/nginx plugin';
            }
        }
        $paths = [
            'database' => '/etc/cron.d/ispconfig-rest-database-worker',
            'web-logs' => '/etc/cron.d/ispconfig-rest-web-log-worker',
            'file-manager' => '/etc/cron.d/ispconfig-rest-file-manager-worker',
            'waf' => '/etc/cron.d/ispconfig-rest-waf',
            'php-limits' => '/etc/cron.d/ispconfig-rest-php-limits',
        ];
        $installed = array_keys(array_filter($paths, 'is_file'));
        if (is_file('/etc/cron.d/ispcp-files')) {
            $installed[] = 'file-manager';
        }
        $result = ['id' => (int) $conf['server_id'], 'installed' => array_values(array_unique($installed)), 'requirements' => $requirements,
            'file_manager_configured' => is_file('/etc/ispcp-files/client.pub') && is_file('/etc/ispcp-files/config.json'),
            'panel' => is_file('/usr/local/ispconfig/security/apache_directives.blacklist'), 'grants' => null];
        if ($requirements !== []) {
            return $result;
        }
        $db = self::connection($conf);
        $tables = self::permissions($components);
        $result['grants'] = ['account' => $db->query('SELECT CURRENT_USER()')->fetchColumn(), 'database' => $db->query('SELECT DATABASE()')->fetchColumn(), 'missing' => self::missingGrants($db, $tables)];
        if (in_array('file-manager', $components, true)) {
            self::connection($conf, true)->query('SELECT domain_id FROM web_domain WHERE 1=0');
        }
        // Verify the migrations that added fields used by current worker releases.
        foreach (['web-logs' => 'SELECT runtime_version FROM api_web_log_workers WHERE 1=0', 'waf' => 'SELECT application_profiles FROM api_web_waf_workers WHERE 1=0',
            'php-limits' => 'SELECT revision FROM api_client_resource_limits WHERE 1=0'] as $component => $sql) {
            if (in_array($component, $components, true) && $result['grants']['missing'] === []) {
                $db->query($sql);
            }
        }

        return $result;
    }

    /** Only the existing account and exact allowlisted table rights; never CREATE USER or global grants. */
    public static function grant(array $conf, array $request): void
    {
        if (! isset($request['database'], $request['account'], $request['missing']) || ! is_array($request['missing'])) {
            throw new RuntimeException('Invalid grant request.');
        }
        $database = $request['database'];
        if (! is_string($database) || ! preg_match('/^[a-zA-Z0-9_]+$/D', $database) || $database !== ($conf['db_database'] ?? '')
            || ! in_array($conf['db_host'] ?? '', ['localhost', '127.0.0.1', '::1'], true)
            || (! empty($conf['dbmaster_host']) && ! in_array($conf['dbmaster_host'], ['localhost', '127.0.0.1', '::1', $conf['db_host']], true))) {
            throw new RuntimeException('Automatic grants require the ISPConfig master database on this host. Apply the listed table grants on the database master.');
        }
        $split = strrpos((string) $request['account'], '@');
        if ($split === false) {
            throw new RuntimeException('Invalid SQL account.');
        }
        $user = substr($request['account'], 0, $split);
        $host = substr($request['account'], $split + 1);
        if ($user === '' || $host === '' || str_contains($user.$host, "\0")) {
            throw new RuntimeException('Refusing an anonymous or invalid SQL account.');
        }
        $allowed = self::permissions(array_keys(self::TABLES));
        foreach ($request['missing'] as $table => $rights) {
            if (! isset($allowed[$table]) || ! is_array($rights) || $rights === [] || array_diff($rights, $allowed[$table]) !== []) {
                throw new RuntimeException('Refusing a non-worker table privilege.');
            }
        }
        try {
            $db = new PDO('mysql:host=localhost;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            $clientdb_host = $clientdb_user = $clientdb_password = '';
            require '/usr/local/ispconfig/server/lib/mysql_clientdb.conf';
            if (! in_array($clientdb_host, ['localhost', '127.0.0.1', '::1'], true)) {
                throw new RuntimeException('Local master SQL administrator credentials are unavailable. Apply the listed table grants manually.');
            }
            $db = new PDO('mysql:host='.$clientdb_host.';charset=utf8mb4', $clientdb_user, $clientdb_password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
        $exists = $db->prepare('SELECT COUNT(*) FROM mysql.user WHERE User=? AND Host=?');
        $exists->execute([$user, $host]);
        if ((int) $exists->fetchColumn() !== 1) {
            throw new RuntimeException('The worker SQL account does not exist on this master. No account was created.');
        }
        foreach ($request['missing'] as $table => $rights) {
            $db->exec('GRANT '.implode(',', $rights).' ON `'.$database.'`.`'.$table.'` TO '.$db->quote($user).'@'.$db->quote($host));
        }
    }

    public static function install(string $root, array $components): void
    {
        $failed = [];
        foreach ($components as $component) {
            echo 'Installing '.$component."\n";
            try {
                $command = match ($component) {
                    'database' => ['sh', $root.'/worker/install.sh', '--no-run'],
                    'web-logs' => ['sh', $root.'/web-log-worker/install.sh'],
                    'waf' => ['bash', $root.'/waf-server/install.sh'],
                    'php-limits' => ['bash', $root.'/php-limits/install.sh'],
                    'file-manager' => self::fileManagerCommand($root),
                    'panel-security' => ['bash', $root.'/waf-server/install.sh', '--ispconfig-security-only'],
                    default => throw new RuntimeException('Unknown installer.'),
                };
                $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);
                if (! is_resource($process) || proc_close($process) !== 0) {
                    throw new RuntimeException('The installer returned a failure.');
                }
            } catch (Throwable $e) {
                $failed[] = $component;
                fwrite(STDERR, 'Installer failed: '.$component.' ('.$e->getMessage()."); continuing with the remaining components.\n");
            }
        }
        if ($failed !== []) {
            throw new RuntimeException('Installer failed: '.implode(', ', $failed).'. Successful components are retained; correct the error and rerun.');
        }
    }

    private static function fileManagerCommand(string $root): array
    {
        if (is_file($root.'/whmcs.pub')) {
            $key = $root.'/whmcs.pub';
            $ip = trim(file_get_contents($root.'/whmcs-ip'));
        } else {
            copy('/etc/ispcp-files/client.pub', $root.'/whmcs-existing.pub');
            $key = $root.'/whmcs-existing.pub';
            $config = json_decode(file_get_contents('/etc/ispcp-files/config.json'), true, 8, JSON_THROW_ON_ERROR);
            $ip = $config['whmcs_ip'] ?? '';
        }
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('A valid WHMCS egress IP is required for the file manager.');
        }

        return ['sh', $root.'/file-manager-worker/install.sh', '--no-run', $key, $ip];
    }
}

// Tests may load the class without invoking privileged operations.
if (! defined('ISPCP_SERVER_TOOLS_TEST')) {
    try {
        if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new RuntimeException('Run the server tools helper as root.');
        }
        umask(0077);
        $mode = $argv[1] ?? '';
        $conf = ServerToolsRemote::config();
        if ($mode === 'identity') {
            echo json_encode(['id' => (int) ($conf['server_id'] ?? 0), 'panel' => is_file('/usr/local/ispconfig/security/apache_directives.blacklist')], JSON_THROW_ON_ERROR);
        } elseif ($mode === 'probe') {
            $components = array_filter(explode(',', $argv[3] ?? ''));
            echo json_encode(ServerToolsRemote::probe($conf, $components, (int) ($argv[2] ?? 0)), JSON_THROW_ON_ERROR);
        } elseif ($mode === 'grant') {
            ServerToolsRemote::grant($conf, json_decode(stream_get_contents(STDIN), true, 12, JSON_THROW_ON_ERROR));
            echo "Worker table grants configured.\n";
        } elseif ($mode === 'install') {
            if ((int) ($conf['server_id'] ?? 0) !== (int) ($argv[2] ?? -1)) {
                throw new RuntimeException('Target server identity changed; installation refused.');
            }
            ServerToolsRemote::install(dirname(__DIR__), explode(',', $argv[3] ?? ''));
        } else {
            throw new RuntimeException('Unknown helper operation.');
        }
    } catch (Throwable $e) {
        // PDO errors can contain passwords or private infrastructure details.
        fwrite(STDERR, ($e instanceof PDOException ? 'Worker database check failed (SQL error '.(int) ($e->errorInfo[1] ?? 0).'). Check connectivity, table grants and API migrations.' : $e->getMessage())."\n");
        exit(1);
    }
}
