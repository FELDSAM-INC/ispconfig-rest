<?php

// Run ONLY in the disposable container described in worker/README.md.
require __DIR__.'/../../worker/SqlDump.php';
require __DIR__.'/../../worker/DatabaseWorker.php';
use IspconfigRest\Worker\DatabaseWorker;

$pdo = new PDO('mysql:host=127.0.0.1', 'root', 'fixture-only', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
foreach (['worker_control', 'fixture_source', 'fixture_copy', 'fixture_import', 'fixture_foreign'] as $cleanup) {
    $pdo->exec('DROP DATABASE IF EXISTS '.$cleanup);
}
$pdo->exec('CREATE DATABASE worker_control');
$pdo->exec('USE worker_control');
$pdo->exec('CREATE TABLE api_database_workers(server_id INT PRIMARY KEY, heartbeat INT)');
$pdo->exec('CREATE TABLE api_database_operations(id VARCHAR(36) PRIMARY KEY,database_id INT,target_database_id INT NULL,sys_groupid INT,server_id INT,database_name VARCHAR(64),action VARCHAR(8),status VARCHAR(12),created_at INT,updated_at INT,expires_at INT,error VARCHAR(80) NULL)');
$pdo->exec('CREATE TABLE api_database_operation_chunks(operation_id VARCHAR(36),sequence INT,content MEDIUMTEXT,PRIMARY KEY(operation_id,sequence))');
$pdo->exec('CREATE TABLE web_database(database_id INT PRIMARY KEY,sys_groupid INT,server_id INT,database_name VARCHAR(64),type VARCHAR(16),active CHAR(1))');
$pdo->exec('CREATE TABLE sys_group(groupid INT PRIMARY KEY, client_id INT)');
$pdo->exec('CREATE TABLE client(client_id INT PRIMARY KEY,locked CHAR(1))');
$pdo->exec("INSERT INTO sys_group VALUES(5,1); INSERT INTO client VALUES(1,'n')");
foreach ([1 => 'fixture_source', 2 => 'fixture_copy', 3 => 'fixture_import', 4 => 'fixture_foreign'] as $id => $name) {
    $pdo->exec('CREATE DATABASE '.$name);
    $pdo->exec("INSERT INTO web_database VALUES($id,5,1,'$name','mysql','y')");
}
$pdo->exec('CREATE TABLE fixture_source.t (id INT PRIMARY KEY, v TEXT)');
$pdo->exec("INSERT INTO fixture_source.t VALUES(1,'first'),(2,'CREATE DEFINER=`root`@`localhost` VIEW `fixture_source`.`t`')");
$pdo->exec('CREATE VIEW fixture_source.v AS SELECT * FROM fixture_source.t');
$pdo->exec('CREATE PROCEDURE fixture_source.p() SELECT 42');
$pdo->exec("CREATE TRIGGER fixture_source.tr BEFORE INSERT ON fixture_source.t FOR EACH ROW SET NEW.v=CONCAT(NEW.v, '!')");
$pdo->exec('CREATE EVENT fixture_source.ev ON SCHEDULE EVERY 1 DAY DISABLE DO INSERT INTO fixture_source.t VALUES (3,\'event\')');
$pdo->exec('CREATE TABLE fixture_foreign.secret (id INT)');
mkdir('/tmp/worker-jobs', 0711);
$worker = new DatabaseWorker($pdo, $pdo, 1, ['host' => '127.0.0.1', 'user' => 'root', 'password' => 'fixture-only', 'control_database' => 'worker_control'], 65534, 65534, '/tmp/worker-jobs');
function queue($id, $action, $database = 1, $target = null, $dump = null)
{
    global $pdo;
    $names = [1 => 'fixture_source', 2 => 'fixture_copy', 3 => 'fixture_import'];
    $s = $pdo->prepare('INSERT INTO api_database_operations VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
    $s->execute([$id, $database, $target, 5, 1, $names[$database], $action, 'queued', time(), time(), time() + 86400, null]);
    if ($dump !== null) {
        $pdo->prepare('INSERT INTO api_database_operation_chunks VALUES(?,?,?)')->execute([$id, 0, base64_encode($dump)]);
    }
}
function check($condition, $message)
{
    if (! $condition) {
        throw new RuntimeException($message);
    } echo "$message: OK\n";
}
function status($id)
{
    global $pdo;

    return $pdo->query("SELECT status FROM api_database_operations WHERE id='$id'")->fetchColumn();
}
queue('copy', 'copy', 1, 2);
$worker->run();
check(status('copy') === 'complete', 'Copy completes');
check($pdo->query('SELECT COUNT(*) FROM fixture_copy.t')->fetchColumn() == 2, 'Rows copied');
check($pdo->query('SELECT COUNT(*) FROM fixture_copy.v')->fetchColumn() == 2, 'View copied');
$pdo->exec("INSERT INTO fixture_copy.t VALUES(4,'trigger')");
check($pdo->query('SELECT COUNT(*) FROM fixture_source.t')->fetchColumn() == 2, 'Source unchanged');
check($pdo->query('SELECT v FROM fixture_copy.t WHERE id=4')->fetchColumn() === 'trigger!', 'Trigger copied');
check($pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='fixture_copy'")->fetchColumn() == 1, 'Routine copied');
check($pdo->query("SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA='fixture_copy'")->fetchColumn() == 1, 'Event copied');
queue('export', 'export');
$worker->run();
check(status('export') === 'complete', 'Export completes');
$archive = '';
foreach ($pdo->query("SELECT content FROM api_database_operation_chunks WHERE operation_id='export' ORDER BY sequence") as $chunk) {
    $archive .= base64_decode($chunk['content']);
}
$sql = gzdecode($archive);
check(strpos($sql, 'CREATE TABLE') !== false, 'Export contains SQL');
queue('import', 'import', 3, null, $archive);
$worker->run();
check(status('import') === 'complete', 'Export can be imported');
check($pdo->query('SELECT COUNT(*) FROM fixture_import.t')->fetchColumn() == 2, 'Import rows match');
foreach ([
    'other_db' => 'DROP TABLE fixture_foreign.secret;',
    'control_db' => 'DROP TABLE worker_control.web_database;',
    'file' => "SELECT 'unsafe' INTO OUTFILE '/tmp/worker-file-escape';",
    'localfile' => "LOAD DATA LOCAL INFILE '/etc/passwd' INTO TABLE t;",
    'shell' => "\\! touch /tmp/worker-shell-escape\n",
    'definer' => 'CREATE DEFINER=`root`@`localhost` VIEW forbidden AS SELECT * FROM worker_control.web_database;',
] as $id => $malicious) {
    queue($id, 'import', 3, null, $malicious);
    $worker->run();
    check(status($id) === 'failed', "Refuse $id");
}
$compressor = deflate_init(ZLIB_ENCODING_GZIP);
$oversized = '';
for ($i = 0; $i < 65; $i++) {
    $oversized .= deflate_add($compressor, str_repeat('x', 1048576), $i === 64 ? ZLIB_FINISH : ZLIB_NO_FLUSH);
}
queue('gzip_limit', 'import', 3, null, $oversized);
$worker->run();
check(status('gzip_limit') === 'failed', 'Oversized gzip refused');
check(! file_exists('/tmp/worker-file-escape') && ! file_exists('/tmp/worker-shell-escape'), 'No filesystem side effects');
check($pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=\'fixture_foreign\' AND TABLE_NAME=\'secret\'')->fetchColumn() == 1, 'Foreign table retained');
check(count(glob('/tmp/worker-jobs/*')) === 0, 'Temporary files cleaned');
check(strpos($pdo->query("SHOW CREATE USER 'ispcp_job_3'@'127.0.0.1'")->fetch(PDO::FETCH_NUM)[0], 'ACCOUNT LOCK') !== false, 'Import principal locked');
queue('interrupted', 'import', 3, null, 'SELECT 1;');
$pdo->exec("UPDATE api_database_operations SET status='running' WHERE id='interrupted'");
$pdo->exec("ALTER USER 'ispcp_job_3'@'127.0.0.1' ACCOUNT UNLOCK");
mkdir('/tmp/worker-jobs/'.str_repeat('a', 32), 0700);
file_put_contents('/tmp/worker-jobs/'.str_repeat('a', 32).'/client.cnf', 'abandoned');
$worker->run();
check(status('interrupted') === 'failed', 'Interrupted import is never retried');
check(count(glob('/tmp/worker-jobs/*')) === 0, 'Abandoned files cleaned');
check(strpos($pdo->query("SHOW CREATE USER 'ispcp_job_3'@'127.0.0.1'")->fetch(PDO::FETCH_NUM)[0], 'ACCOUNT LOCK') !== false, 'Interrupted principal locked');
echo "All isolated MariaDB worker checks passed.\n";
