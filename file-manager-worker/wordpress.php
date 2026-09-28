<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || posix_geteuid() !== 0) {
    exit(1);
}
umask(0077);
ini_set('memory_limit', '64M');
$lock = fopen('/var/lib/ispcp-files/wordpress.lock', 'c');
if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
define('SCRIPT_PATH', '/usr/local/ispconfig/server');
require SCRIPT_PATH.'/lib/config.inc.php';
require __DIR__.'/WordPressPolicy.php';
require __DIR__.'/WebDomainAutoalias.php';
require __DIR__.'/WordPressWorker.php';
$prefix = ! empty($conf['dbmaster_host']) && ($conf['dbmaster_host'] !== $conf['db_host'] || $conf['dbmaster_database'] !== $conf['db_database'] || (int) $conf['dbmaster_port'] !== (int) $conf['db_port']) ? 'dbmaster_' : 'db_';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
try {
    $connect = static fn ($prefix) => new PDO('mysql:host='.$conf[$prefix.'host'].';port='.($conf[$prefix.'port'] ?? 3306).';dbname='.$conf[$prefix.'database'].';charset=utf8mb4', $conf[$prefix.'user'], $conf[$prefix.'password'], $options);
    $daemon = in_array('--daemon', $argv, true);
    $stopping = false;
    if ($daemon) {
        // Finish an in-flight database change before a service upgrade/restart.
        pcntl_async_signals(true);
        $stop = static function () use (&$stopping): void {
            $stopping = true;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }
    $worker = new WordPressWorker($connect($prefix), $connect('db_'), (int) $conf['server_id']);
    do {
        $worker->run(static function () use (&$stopping): bool {
            return $stopping;
        });
        if ($daemon && ! $stopping) {
            sleep(2);
        }
    } while ($daemon && ! $stopping);
} catch (Throwable $e) {
    error_log('ISPConfig WordPress worker failed ('.get_class($e).'). Check runtime installation and API table grants.');
    exit(1);
}
