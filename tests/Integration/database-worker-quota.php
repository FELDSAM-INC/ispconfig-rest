<?php

use IspconfigRest\Worker\DatabaseWorker;

// Disposable MariaDB only, as documented in worker/README.md.
ini_set('memory_limit', '128M');
require __DIR__.'/database-worker.php';

$worker = new DatabaseWorker($pdo, $pdo, 1, ['host' => '127.0.0.1', 'user' => 'root', 'password' => 'fixture-only', 'control_database' => 'worker_control'], 65534, 65534, '/tmp/worker-jobs', '/tmp/worker.log');
$pdo->exec('UPDATE web_database SET database_quota=1 WHERE database_id=3');
$pdo->exec('CREATE TABLE fixture_import.quota_data(id INT, payload MEDIUMBLOB) ENGINE=MyISAM');
$pdo->exec("INSERT INTO fixture_import.quota_data VALUES(1,REPEAT('x',2097152))");
queue('quota-before', 'import', 3, null, 'CREATE TABLE must_not_start(id INT);');
$worker->run();
check($pdo->query("SELECT error FROM api_database_operations WHERE id='quota-before'")->fetchColumn() === 'database_quota_exceeded', 'Existing exceeded quota refuses import');
check($pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='fixture_import' AND TABLE_NAME='must_not_start'")->fetchColumn() == 0, 'No SQL applied above quota');
$pdo->exec('TRUNCATE fixture_import.quota_data');
queue('quota-during', 'import', 3, null, "INSERT INTO quota_data VALUES(1,REPEAT('x',2097152)); SELECT SLEEP(5); CREATE TABLE must_not_finish(id INT);");
$worker->run();
check($pdo->query("SELECT error FROM api_database_operations WHERE id='quota-during'")->fetchColumn() === 'database_quota_exceeded', 'Import crossing quota stops');
check($pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='fixture_import' AND TABLE_NAME='must_not_finish'")->fetchColumn() == 0, 'Statements after quota failure are not executed');
check(strpos($pdo->query("SHOW CREATE USER 'ispcp_job_3'@'127.0.0.1'")->fetch(PDO::FETCH_NUM)[0], 'ACCOUNT LOCK') !== false, 'Quota failure locks import principal');
$pdo->exec('TRUNCATE fixture_import.quota_data');

// SQL bytes are not database storage: 5.36 GiB of comments plus one tiny table
// must import successfully into a 1 MiB database quota with bounded PHP memory.
$path = '/tmp/large-inflated.sql.gz';
$archive = gzopen($path, 'wb1');
$line = '--'.str_repeat(' ', 16381)."\n";
$written = 0;
$target = (int) ceil(5.36 * 1073741824);
while ($written < $target) {
    gzwrite($archive, $line);
    $written += strlen($line);
}
gzwrite($archive, "CREATE TABLE inflated_ok(id INT); INSERT INTO inflated_ok VALUES(42);\n");
gzclose($archive);
echo "Generated $written bytes of SQL, compressed to ".filesize($path)." bytes.\n";
queue('inflated-536', 'import', 3);
$input = fopen($path, 'rb');
$sequence = 0;
while (! feof($input)) {
    $chunk = fread($input, 8388608);
    if ($chunk !== '') {
        $pdo->prepare('INSERT INTO api_database_operation_chunks VALUES(?,?,?)')->execute(['inflated-536', $sequence++, base64_encode($chunk)]);
    }
}
fclose($input);
unlink($path);
$worker->run();
check(status('inflated-536') === 'complete', 'SQL larger than 5.36 GiB imports');
check($pdo->query('SELECT id FROM fixture_import.inflated_ok')->fetchColumn() == 42, 'SQL after the 5.36 GiB boundary executed');
check($pdo->query('SELECT database_quota FROM web_database WHERE database_id=3')->fetchColumn() == 1, 'Database quota unchanged');
check(memory_get_peak_usage(true) < 100663296, 'PHP memory stays below 96 MiB with 8 MiB chunks');
echo 'Quota and large-inflation checks passed; peak '.memory_get_peak_usage(true)." bytes.\n";
