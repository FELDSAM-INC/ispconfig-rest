<?php

declare(strict_types=1);
use App\Support\WebWafAudit;
use App\Support\WebWafPolicy;
use App\Support\WebWafProfiles;

if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    exit(1);
}
// nginx audit timestamps use the web server's local timezone.
$zone = is_file('/etc/timezone') ? trim(file_get_contents('/etc/timezone')) : 'UTC';
if (in_array($zone, timezone_identifiers_list(), true)) {
    date_default_timezone_set($zone);
}
umask(0077);
ini_set('memory_limit', '64M');
$lock = fopen('/var/lib/ispconfig-rest-waf/worker.lock', 'c');
if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
require __DIR__.'/WebWafProfiles.php';
require __DIR__.'/WebWafPolicy.php';
require __DIR__.'/WebWafAudit.php';
define('SCRIPT_PATH', '/usr/local/ispconfig/server');
require SCRIPT_PATH.'/lib/config.inc.php';
$prefix = ! empty($conf['dbmaster_host']) && ($conf['dbmaster_host'] !== $conf['db_host'] || $conf['dbmaster_database'] !== $conf['db_database'] || (int) $conf['dbmaster_port'] !== (int) $conf['db_port']) ? 'dbmaster_' : 'db_';
try {
    $db = new PDO('mysql:host='.$conf[$prefix.'host'].';port='.($conf[$prefix.'port'] ?? 3306).';dbname='.$conf[$prefix.'database'].';charset=utf8mb4', $conf[$prefix.'user'], $conf[$prefix.'password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $server = (int) $conf['server_id'];
    $state = json_decode(file_get_contents('/etc/ispconfig-waf/installed.json'), true, 8, JSON_THROW_ON_ERROR);
    if (! in_array($state['engine'] ?? '', ['apache', 'nginx'], true)) {
        throw new RuntimeException;
    }
    if (! is_file('/etc/ispconfig-waf/base.conf') || ! is_file('/etc/ispconfig-waf/owasp.conf')
        || ($state['engine'] === 'apache' && ! is_file('/etc/apache2/mods-enabled/security2.load'))
        || ($state['engine'] === 'nginx' && ! (glob('/etc/nginx/modules-enabled/*modsecurity*.conf') ?: []))) {
        throw new RuntimeException;
    }
    $state['atomic_available'] = ! empty($state['atomic_available']) && is_file('/etc/ispconfig-waf/atomic.conf');
    $profiles = json_encode(WebWafProfiles::installed(), JSON_THROW_ON_ERROR);
    $positionFile = '/var/lib/ispconfig-rest-waf/positions.json';
    $positions = is_file($positionFile) ? (json_decode(file_get_contents($positionFile), true) ?: []) : [];
    $catalog = WebWafAudit::catalog();
    $start = microtime(true);
    do {
        $db->prepare('INSERT INTO api_web_waf_workers (server_id,heartbeat,engine,rules_version,atomic_available,application_profiles) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE heartbeat=VALUES(heartbeat),engine=VALUES(engine),rules_version=VALUES(rules_version),atomic_available=VALUES(atomic_available),application_profiles=VALUES(application_profiles)')->execute([$server, time(), $state['engine'], $state['rules_version'], ! empty($state['atomic_available']) ? 1 : 0, $profiles]);
        $sites = $db->prepare("SELECT domain_id,server_id,sys_groupid,domain,apache_directives,nginx_directives FROM web_domain WHERE server_id=? AND type IN ('vhost','vhostsubdomain','vhostalias')");
        $sites->execute([$server]);
        $active = [];
        foreach ($sites->fetchAll() as $site) {
            $identity = WebWafPolicy::identity($site);
            $active[$identity] = true;
            try {
                $settings = WebWafPolicy::extract((string) $site[$state['engine'].'_directives'], $identity);
                if ($settings === null) {
                    continue;
                }
                $read = WebWafAudit::read($identity, $positions[$identity] ?? [], $catalog);
                foreach ($read['events'] as $event) {
                    $event['event_key'] = hash('sha256', $identity.':'.$event['event_key']);
                    $event = ['server_id' => $server, 'website_id' => (int) $site['domain_id'], 'identity' => $identity] + $event;
                    $sql = 'INSERT IGNORE INTO api_web_waf_events ('.implode(',', array_keys($event)).') VALUES ('.implode(',', array_fill(0, count($event), '?')).')';
                    $db->prepare($sql)->execute(array_values($event));
                }
                $positions[$identity] = $read['position'];
                $cutoff = $db->prepare('SELECT id FROM api_web_waf_events WHERE server_id=? AND website_id=? AND identity=? ORDER BY id DESC LIMIT 1 OFFSET 999');
                $cutoff->execute([$server, $site['domain_id'], $identity]);
                if ($id = $cutoff->fetchColumn()) {
                    $db->prepare('DELETE FROM api_web_waf_events WHERE server_id=? AND website_id=? AND identity=? AND id<?')->execute([$server, $site['domain_id'], $identity, $id]);
                }
            } catch (Throwable $e) {
                error_log('WAF audit collection failed for website '.(int) $site['domain_id'].' ('.get_class($e).').');
            }
        }
        $positions = array_intersect_key($positions, $active);
        file_put_contents($positionFile.'.new', json_encode($positions, JSON_THROW_ON_ERROR));
        rename($positionFile.'.new', $positionFile);
        $db->prepare('DELETE FROM api_web_waf_events WHERE server_id=? AND occurred_at<?')->execute([$server, time() - 7 * 86400]);
        usleep(5000000);
    } while (microtime(true) - $start < 50);
} catch (Throwable $e) {
    error_log('ISPConfig WAF worker failed ('.get_class($e).'). Check installation and master database grants.');
    exit(1);
}
