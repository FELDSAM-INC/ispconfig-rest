<?php

// Disposable container only, fixed loopback database/password.
if (! is_file('/.dockerenv')) { exit(1); }
$db = new PDO('mysql:host=127.0.0.1', 'root', 'server-tools-fixture-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE DATABASE server_tools_ssh');
$db->exec('USE server_tools_ssh');
define('ISPCP_SERVER_TOOLS_TEST', true);
require '/app/server-tools/remote.php';
// Every component's tables, with the columns the probe checks for current migrations.
foreach (ServerToolsRemote::permissions(array_keys(ServerToolsRemote::TABLES)) as $table => $rights) {
    if ($table === 'web_domain') {
        $db->exec('CREATE TABLE web_domain (domain_id int, server_id int, sys_groupid int, domain varchar(255), type varchar(30), document_root varchar(255), web_folder varchar(255), system_user varchar(64), system_group varchar(64), active char(1))');
    } else {
        $db->exec('CREATE TABLE '.$table.' (id int primary key, runtime_version int, application_profiles text, revision int)');
    }
}
file_put_contents('/usr/local/ispconfig/server/lib/config.inc.php', '<?php $conf='.var_export([
    'server_id' => 1, 'db_host' => '127.0.0.1', 'db_port' => 3306, 'db_database' => 'server_tools_ssh',
    'db_user' => 'root', 'db_password' => 'server-tools-fixture-only',
], true).';');
file_put_contents('/usr/local/ispconfig/server/lib/mysql_clientdb.conf', '<?php $clientdb_host="127.0.0.1"; $clientdb_user="root"; $clientdb_password="server-tools-fixture-only";');
