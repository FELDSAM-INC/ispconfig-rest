<?php

declare(strict_types=1);

use IspconfigRest\Worker\DatabaseWorker;

// Install a root-owned COPY of this directory, never execute a web-writable checkout as root.
if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    exit("Run the database worker as root from the installed root-owned directory.\n");
}
umask(0077);
ini_set('memory_limit', '512M');
$workspace = '/var/lib/ispconfig-rest-database-worker';
$lock = fopen($workspace.'/worker.lock', 'c');
if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
define('SCRIPT_PATH', '/usr/local/ispconfig/server');
require SCRIPT_PATH.'/lib/config.inc.php';
require SCRIPT_PATH.'/lib/mysql_clientdb.conf';
require __DIR__.'/SqlDump.php';
require __DIR__.'/DatabaseWorker.php';
if (! in_array($clientdb_host, ['localhost', '127.0.0.1', '::1'], true)) {
    exit("Install the worker on the database server with local mysql_clientdb credentials.\n");
}
$account = posix_getpwnam('ispcp-dbworker');
if (! $account) {
    exit("Database worker account is missing. Run install.sh.\n");
}
$prefix = ! empty($conf['dbmaster_host']) && ($conf['dbmaster_host'] !== $conf['db_host'] || $conf['dbmaster_database'] !== $conf['db_database'] || (int) $conf['dbmaster_port'] !== (int) $conf['db_port']) ? 'dbmaster_' : 'db_';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
try {
    $master = new PDO('mysql:host='.$conf[$prefix.'host'].';port='.($conf[$prefix.'port'] ?? 3306).';dbname='.$conf[$prefix.'database'].';charset=utf8mb4', $conf[$prefix.'user'], $conf[$prefix.'password'], $options);
    $local = new PDO('mysql:host='.$clientdb_host.';charset=utf8mb4', $clientdb_user, $clientdb_password, $options);
    (new DatabaseWorker($master, $local, (int) $conf['server_id'], [
        'host' => $clientdb_host, 'user' => $clientdb_user, 'password' => $clientdb_password,
        'control_database' => $conf['db_database'],
    ], (int) $account['uid'], (int) $account['gid'], $workspace))->run();
} catch (Throwable $e) {
    // Connection errors can include credentials or server topology; logs contain no exception body.
    fwrite(STDERR, "Database worker failed. Check installation, master table grants and local database availability.\n");
    exit(1);
}
