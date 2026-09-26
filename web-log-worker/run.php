<?php

declare(strict_types=1);

use App\Support\WebLogReader;
use App\Support\WebRuntimeDirectory;

if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    exit("Run the installed root-owned web log worker as root.\n");
}
umask(0077);
ini_set('memory_limit', '32M');
$lock = fopen('/var/lib/ispconfig-rest-web-log-worker/worker.lock', 'c');
if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
define('SCRIPT_PATH', '/usr/local/ispconfig/server');
require SCRIPT_PATH.'/lib/config.inc.php';
require __DIR__.'/WebLogReader.php';
require __DIR__.'/WebRuntimeDirectory.php';
$prefix = ! empty($conf['dbmaster_host']) && ($conf['dbmaster_host'] !== $conf['db_host'] || $conf['dbmaster_database'] !== $conf['db_database'] || (int) $conf['dbmaster_port'] !== (int) $conf['db_port']) ? 'dbmaster_' : 'db_';
try {
    $db = new PDO('mysql:host='.$conf[$prefix.'host'].';port='.($conf[$prefix.'port'] ?? 3306).';dbname='.$conf[$prefix.'database'].';charset=utf8mb4', $conf[$prefix.'user'], $conf[$prefix.'password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $server = (int) $conf['server_id'];
    $reader = new WebLogReader;
    $started = microtime(true);
    $heartbeat = 0;
    while (microtime(true) - $started < 50) {
        if (time() - $heartbeat >= 5) {
            $runtimeVersion = is_file(__DIR__.'/runtime-ready') && (is_link(SCRIPT_PATH.'/plugins-enabled/apache2_plugin.inc.php')
                || (is_link(SCRIPT_PATH.'/plugins-enabled/nginx_plugin.inc.php') && is_file('/etc/nginx/conf.d/ispcp-runtime.conf')
                && hash_file('sha256', '/etc/nginx/conf.d/ispcp-runtime.conf') === hash_file('sha256', __DIR__.'/nginx-runtime.conf'))) ? 1 : 0;
            $db->prepare('INSERT INTO api_web_log_workers (server_id, heartbeat, runtime_version) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE heartbeat = VALUES(heartbeat), runtime_version = VALUES(runtime_version)')->execute([$server, time(), $runtimeVersion]);
            $db->prepare('DELETE FROM api_web_log_reads WHERE created_at < ?')->execute([time() - 60]);
            $heartbeat = time();
        }
        $query = $db->prepare('SELECT * FROM api_web_log_reads WHERE server_id = ? AND result IS NULL AND created_at >= ? ORDER BY created_at LIMIT 1');
        $query->execute([$server, time() - 30]);
        foreach ($query->fetchAll() as $job) {
            try {
                $website = $db->prepare("SELECT domain, document_root, web_folder, type FROM web_domain WHERE domain_id = ? AND server_id = ? AND sys_groupid = ? AND domain = ? AND type IN ('vhost','vhostsubdomain','vhostalias')");
                $website->execute([$job['website_id'], $server, $job['sys_groupid'], $job['domain']]);
                $site = $website->fetch();
                if (! is_array($site)) {
                    throw new RuntimeException('logs_unavailable');
                }
                $request = json_decode($job['request'], true, 8, JSON_THROW_ON_ERROR);
                if (($request['kind'] ?? '') === 'document_root') {
                    WebRuntimeDirectory::check($site, $request['subdirectory']);
                    $result = ['directory_valid' => true];
                } else {
                    $result = $reader->read($site['domain'], $request['kind'], $request['lines'], $request['before']);
                }
            } catch (Throwable $e) {
                $result = ['error' => in_array($e->getMessage(), ['logs_archive_limit', 'logs_archive_invalid', 'logs_rotated', 'runtime_directory_invalid', 'runtime_directory_missing', 'runtime_directory_symlink'], true) ? $e->getMessage() : 'logs_unavailable'];
            }
            $db->prepare('UPDATE api_web_log_reads SET result = ? WHERE id = ? AND created_at = ? AND result IS NULL')->execute([json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), $job['id'], $job['created_at']]);
        }
        usleep(250000);
    }
} catch (Throwable $e) {
    // No credentials, log contents, paths or exception messages in diagnostics.
    error_log('ISPConfig web log worker failed ('.get_class($e).'). Check installation and master database grants.');
    exit(1);
}
