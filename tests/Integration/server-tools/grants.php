<?php

// Only in a disposable container sharing the fixture MariaDB network namespace.
// Fixed loopback address and fixture-only password; never configurable for production.
define('ISPCP_SERVER_TOOLS_TEST', true);
require '/app/server-tools/remote.php';
$root = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', 'server-tools-fixture-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec('CREATE DATABASE server_tools_fixture');
$root->exec("CREATE USER 'tools_fixture'@'%' IDENTIFIED BY 'fixture-only'");
$tables = ServerToolsRemote::permissions(['database', 'web-logs', 'waf']);
foreach ($tables as $table => $rights) {
    $root->exec('CREATE TABLE server_tools_fixture.'.$table.' (id int primary key, value varchar(40)) ENGINE=InnoDB');
    $root->exec('INSERT INTO server_tools_fixture.'.$table." VALUES (1, 'must not change')");
}
$root->exec('GRANT SELECT ON server_tools_fixture.web_domain TO tools_fixture');
$worker = new PDO('mysql:host=127.0.0.1;dbname=server_tools_fixture', 'tools_fixture', 'fixture-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$missing = ServerToolsRemote::missingGrants($worker, $tables);
if (isset($missing['web_domain']) || $missing['api_web_waf_events'] !== ['SELECT', 'INSERT', 'DELETE']) {
    throw new RuntimeException('Missing grants not detected correctly.');
}
mkdir('/usr/local/ispconfig/server/lib', 0700, true);
file_put_contents('/usr/local/ispconfig/server/lib/mysql_clientdb.conf', '<?php $clientdb_host="127.0.0.1"; $clientdb_user="root"; $clientdb_password="server-tools-fixture-only";');
ServerToolsRemote::grant(['db_database' => 'server_tools_fixture', 'db_host' => '127.0.0.1'], [
    'database' => 'server_tools_fixture', 'account' => $worker->query('SELECT CURRENT_USER()')->fetchColumn(), 'missing' => $missing,
]);
if (ServerToolsRemote::missingGrants($worker, $tables) !== []) {
    throw new RuntimeException('Configured grants do not work.');
}
foreach ($tables as $table => $rights) {
    if ($root->query('SELECT value FROM server_tools_fixture.'.$table)->fetchAll(PDO::FETCH_COLUMN) !== ['must not change']) {
        throw new RuntimeException('EXPLAIN probes changed rows!');
    }
}
foreach (['UPDATE server_tools_fixture.web_domain SET value="bad"', 'DELETE FROM server_tools_fixture.api_web_waf_workers', 'CREATE TABLE server_tools_fixture.forbidden (id int)'] as $sql) {
    try {
        $worker->exec($sql);
        throw new RuntimeException('Unexpected privilege: '.$sql);
    } catch (PDOException $e) {
        if ((int) $e->errorInfo[1] !== 1142) {
            throw $e;
        }
    }
}
echo "PASS grant discovery, minimal automatic grants, non-mutating probes and denied extra privileges\n";
