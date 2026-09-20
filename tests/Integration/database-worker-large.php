<?php

use IspconfigRest\Worker\DatabaseWorker;

// Opt-in destructive fixture ONLY for the disposable MariaDB container documented in worker/README.md.
// Exercises actual 1 GiB of database data, not a declared file size or sparse file.
ini_set('memory_limit', '128M');
require __DIR__.'/database-worker.php';

$worker = new DatabaseWorker($pdo, $pdo, 1, ['host' => '127.0.0.1', 'user' => 'root', 'password' => 'fixture-only', 'control_database' => 'worker_control'], 65534, 65534, '/tmp/worker-jobs', '/tmp/worker.log');
$pdo->exec('CREATE TABLE fixture_source.large_data(id INT PRIMARY KEY, payload LONGBLOB)');
$insert = $pdo->prepare('INSERT INTO fixture_source.large_data VALUES(?,?)');
$expected = [];
for ($i = 0; $i < 1024; $i++) {
    $data = random_bytes(1048576);
    $expected[$i] = hash('sha256', $data);
    $insert->execute([$i, $data]);
    if ($i % 128 === 0) {
        echo "Seeded $i MiB\n";
    }
}
unset($data, $insert);
check((int) $pdo->query('SELECT SUM(OCTET_LENGTH(payload)) FROM fixture_source.large_data')->fetchColumn() === 1073741824, 'Source contains exactly 1 GiB of binary data');
function verifyLarge(string $database): void
{
    global $pdo, $expected;
    $actual = $pdo->query('SELECT id,SHA2(payload,256) AS hash FROM '.$database.'.large_data ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    check($actual === $expected, $database.' all 1024 payload hashes match');
}
$pdo->exec('DROP DATABASE fixture_copy; CREATE DATABASE fixture_copy');
queue('large-copy', 'copy', 1, 2);
echo "Copying 1 GiB…\n";
$worker->run();
check(status('large-copy') === 'complete', '1 GiB copy completes');
verifyLarge('fixture_copy');
queue('large-export', 'export');
echo "Exporting 1 GiB…\n";
$worker->run();
check(status('large-export') === 'complete', '1 GiB export completes');
$archiveBytes = (int) $pdo->query("SELECT download_bytes FROM api_database_operations WHERE id='large-export'")->fetchColumn();
check($archiveBytes > 1073741824, 'Compressed export exceeds 1 GiB');
queue('large-import', 'import', 3);
$pdo->exec("INSERT INTO api_database_operation_chunks SELECT 'large-import',sequence,content FROM api_database_operation_chunks WHERE operation_id='large-export'");
$pdo->prepare("UPDATE api_database_operations SET upload_bytes=?,uploaded_bytes=? WHERE id='large-import'")->execute([$archiveBytes, $archiveBytes]);
echo "Importing $archiveBytes bytes of gzip SQL…\n";
$worker->run();
check(status('large-import') === 'complete', 'Import of >1 GiB gzip completes');
verifyLarge('fixture_import');
verifyLarge('fixture_source');
check(memory_get_peak_usage(true) < 67108864, 'PHP peak memory remains under 64 MiB');
check(count(glob('/tmp/worker-jobs/*')) === 0, 'Large job temporary files cleaned');
$log = file_get_contents('/tmp/worker.log');
check(strpos($log, '"mysql_error":"') !== false, 'Log includes native MySQL error numbers');
check(strpos($log, 'fixture-only') === false && strpos($log, 'DROP TABLE') === false, 'Log excludes passwords and SQL');
echo 'Large database integration passed; peak PHP memory '.memory_get_peak_usage(true)." bytes.\n";
