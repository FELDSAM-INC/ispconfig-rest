<?php
// Installed root-owned helper. Only non-secret website identities are emitted to the reconciler.
if (PHP_SAPI !== 'cli' || posix_geteuid() !== 0) { exit(1); }
define('SCRIPT_PATH', '/usr/local/ispconfig/server');
require SCRIPT_PATH . '/lib/config.inc.php';
$db = new PDO('mysql:host=' . $conf['db_host'] . ';port=' . ($conf['db_port'] ?? 3306) . ';dbname=' . $conf['db_database'] . ';charset=utf8mb4', $conf['db_user'], $conf['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$query = $db->prepare("SELECT domain_id AS id, server_id, sys_groupid, domain, type, document_root, web_folder, system_user, system_group FROM web_domain WHERE server_id = ? AND active = 'y' AND type IN ('vhost','vhostsubdomain','vhostalias')");
$query->execute([(int) $conf['server_id']]);
echo json_encode($query->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR);
