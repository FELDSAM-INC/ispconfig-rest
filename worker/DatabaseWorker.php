<?php

declare(strict_types=1);

namespace IspconfigRest\Worker;

use PDO;
use RuntimeException;

/** Standalone, root-owned worker; no Laravel dependency on database servers. */
final class DatabaseWorker
{
    public const MAX_SQL = 4294967296;

    public const MAX_ARCHIVE = 2147483648;

    private string $directory;

    private int $started;

    private string $jobId = '';

    private int $lastHeartbeat = 0;

    private string $phase = '';

    private int $lastLog = 0;

    public function __construct(
        private PDO $master,
        private PDO $local,
        private int $serverId,
        private array $credentials,
        private int $uid,
        private int $gid,
        private string $workspace = '/var/lib/ispconfig-rest-database-worker',
        private string $logFile = '/var/log/ispconfig-rest-database-worker.log',
        private int $sqlLimit = self::MAX_SQL,
        private int $timeout = 14400,
    ) {}

    private function query(string $sql, array $values = []): \PDOStatement
    {
        $statement = $this->master->prepare($sql);
        $statement->execute($values);

        return $statement;
    }

    public function run(): void
    {
        $this->jobId = '';
        // run.php holds the server-wide flock. A running job here belongs to a
        // previous worker process that died, so never replay its partial import.
        foreach ($this->query("SELECT * FROM api_database_operations WHERE server_id=? AND (status='running' OR error='operation_expired')", [$this->serverId])->fetchAll(PDO::FETCH_ASSOC) as $interrupted) {
            $user = 'ispcp_job_'.($interrupted['target_database_id'] ?: $interrupted['database_id']);
            $account = $this->local->quote($user).'@'.$this->local->quote($this->credentials['host']);
            $this->local->exec('ALTER USER IF EXISTS '.$account.' ACCOUNT LOCK');
            foreach ($this->local->query('SHOW PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $process) {
                if ($process['User'] === $user) {
                    $this->local->exec('KILL '.(int) $process['Id']);
                }
            }
            $this->query("UPDATE api_database_operations SET status='failed',error='operation_interrupted',updated_at=? WHERE id=?", [time(), $interrupted['id']]);
        }
        // Private files abandoned by a crashed worker; never follow symlinks.
        foreach (glob($this->workspace.'/*') ?: [] as $directory) {
            if (is_dir($directory) && ! is_link($directory) && preg_match('/^[a-f0-9]{32}$/D', basename($directory))) {
                foreach (glob($directory.'/*') ?: [] as $file) {
                    if (is_file($file) || is_link($file)) {
                        unlink($file);
                    }
                }
                rmdir($directory);
            }
        }
        $this->query('INSERT INTO api_database_workers (server_id,heartbeat) VALUES (?,?) ON DUPLICATE KEY UPDATE heartbeat=VALUES(heartbeat)', [$this->serverId, time()]);
        $this->query("UPDATE api_database_operations SET status='failed', error='operation_expired', updated_at=? WHERE server_id=? AND status IN ('uploading','running') AND updated_at<?", [time(), $this->serverId, time() - 1800]);
        $this->query('DELETE c FROM api_database_operation_chunks c JOIN api_database_operations j ON j.id=c.operation_id WHERE j.server_id=? AND (j.expires_at<? OR j.status=\'failed\')', [$this->serverId, time()]);
        $this->query('DELETE FROM api_database_operations WHERE server_id=? AND expires_at<?', [$this->serverId, time()]);
        $this->log('heartbeat');
        $jobs = $this->query("SELECT * FROM api_database_operations WHERE server_id=? AND status='queued' ORDER BY created_at LIMIT 3", [$this->serverId])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($jobs as $job) {
            if (! $this->ready($job)) {
                continue;
            }
            if ($this->query("UPDATE api_database_operations SET status='running',updated_at=? WHERE id=? AND status='queued'", [time(), $job['id']])->rowCount() !== 1) {
                continue;
            }
            $error = null;
            $this->started = time();
            $this->jobId = $job['id'];
            $this->lastHeartbeat = 0;
            $this->log('started', ['action' => $job['action'], 'database_id' => (int) $job['database_id']]);
            $this->directory = $this->workspace.'/'.bin2hex(random_bytes(16));
            mkdir($this->directory, 0710);
            chgrp($this->directory, $this->gid);
            chmod($this->directory, 0710);
            try {
                $this->execute($job);
            } catch (\Throwable $e) {
                // Never store stderr, SQL, paths or credentials in public job state.
                $error = in_array($e->getMessage(), ['dump_too_large', 'operation_timeout', 'database_changed', 'database_locked'], true)
                    ? $e->getMessage() : 'operation_failed';
                $this->log('failed', ['error' => $error, 'exception' => get_class($e), 'code' => (string) $e->getCode()]);
            } finally {
                foreach (glob($this->directory.'/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($this->directory);
            }
            if ($error !== null || $job['action'] !== 'export') {
                $this->query('DELETE FROM api_database_operation_chunks WHERE operation_id=?', [$job['id']]);
            }
            $this->query('UPDATE api_database_operations SET status=?,error=?,updated_at=?,expires_at=? WHERE id=? AND status=\'running\'', [$error === null ? 'complete' : 'failed', $error, time(), time() + 86400, $job['id']]);
            if ($error === null) {
                $this->log('complete', ['seconds' => time() - $this->started]);
            }
        }
    }

    private function log(string $event, array $fields = []): void
    {
        file_put_contents($this->logFile, json_encode(['time' => gmdate('c'), 'server_id' => $this->serverId, 'job' => $this->jobId, 'event' => $event] + $fields, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
        chmod($this->logFile, 0600);
    }

    private function tick(string $phase, int $bytes = 0): void
    {
        $now = time();
        if ($now - $this->started > $this->timeout) {
            throw new RuntimeException('operation_timeout');
        }
        if ($now - $this->lastHeartbeat >= 10) {
            $this->query('UPDATE api_database_workers SET heartbeat=? WHERE server_id=?', [$now, $this->serverId]);
            $this->query("UPDATE api_database_operations SET updated_at=? WHERE id=? AND status='running'", [$now, $this->jobId]);
            $this->lastHeartbeat = $now;
        }
        if ($phase !== $this->phase || $now - $this->lastLog >= 60) {
            $this->log('progress', ['phase' => $phase, 'bytes' => $bytes, 'seconds' => $now - $this->started]);
            $this->phase = $phase;
            $this->lastLog = $now;
        }
    }

    private function database(int $id, array $job): array
    {
        $row = $this->query('SELECT d.*,c.locked AS owner_locked FROM web_database d LEFT JOIN sys_group g ON g.groupid=d.sys_groupid LEFT JOIN client c ON c.client_id=g.client_id WHERE d.database_id=?', [$id])->fetch(PDO::FETCH_ASSOC);
        if (! $row || (int) $row['sys_groupid'] !== (int) $job['sys_groupid'] || (int) $row['server_id'] !== $this->serverId || $row['type'] !== 'mysql' || $row['active'] !== 'y') {
            throw new RuntimeException('database_changed');
        }
        if (($row['owner_locked'] ?? 'n') === 'y') {
            throw new RuntimeException('database_locked');
        }
        $this->safeName($row['database_name']);

        return $row;
    }

    private function ready(array $job): bool
    {
        try {
            $source = $this->database((int) $job['database_id'], $job);
            if ($source['database_name'] !== $job['database_name']) {
                throw new RuntimeException('database_changed');
            }
            $target = $job['action'] === 'copy' ? $this->database((int) $job['target_database_id'], $job) : $source;
            $statement = $this->local->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
            foreach ([$source['database_name'], $target['database_name']] as $name) {
                $statement->execute([$name]);
                if (! $statement->fetchColumn()) {
                    return false; // normal ISPConfig datalog provisioning has not completed
                }
            }

            return true;
        } catch (RuntimeException $e) {
            $this->query("UPDATE api_database_operations SET status='failed',error='database_changed',updated_at=? WHERE id=?", [time(), $job['id']]);

            return false;
        }
    }

    private function safeName(string $name): void
    {
        if (! preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $name) || in_array(strtolower($name), array_map('strtolower', ['mysql', 'information_schema', 'performance_schema', 'sys', $this->credentials['control_database']]), true)) {
            throw new RuntimeException('database_changed');
        }
    }

    private function defaults(string $file, string $user, string $password): void
    {
        $escape = static fn (string $value): string => '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value).'"';
        file_put_contents($file, "[client]\nuser=".$escape($user)."\npassword=".$escape($password)."\nhost=".$escape($this->credentials['host'])."\n");
        // Connections to the local daemon need no TLS (MariaDB 11.8 clients
        // otherwise require TLS even on loopback against older daemons).
        if (in_array($this->credentials['host'], ['localhost', '127.0.0.1', '::1'], true)) {
            file_put_contents($file, "loose-skip-ssl\n", FILE_APPEND);
        }
        chmod($file, 0600);
    }

    private function execute(array $job): void
    {
        $source = $this->database((int) $job['database_id'], $job);
        $this->tick('preparing');
        $sqlFile = $this->directory.'/dump.sql';
        if ($job['action'] === 'import') {
            $file = fopen($sqlFile, 'xb');
            $sequence = -1;
            while ($chunk = $this->query('SELECT sequence,content FROM api_database_operation_chunks WHERE operation_id=? AND sequence>? ORDER BY sequence LIMIT 1', [$job['id'], $sequence])->fetch(PDO::FETCH_ASSOC)) {
                if ((int) $chunk['sequence'] !== ++$sequence) {
                    throw new RuntimeException('invalid_dump');
                }
                $decoded = base64_decode($chunk['content'], true);
                if ($decoded === false || fwrite($file, $decoded) !== strlen($decoded) || ftell($file) > self::MAX_ARCHIVE) {
                    throw new RuntimeException('dump_too_large');
                }
                $this->tick('receiving', ftell($file));
            }
            if (isset($job['upload_bytes']) && ftell($file) !== (int) $job['upload_bytes']) {
                throw new RuntimeException('invalid_dump');
            }
            fclose($file);
            $header = file_get_contents($sqlFile, false, null, 0, 3);
            if ($header === "\x1f\x8b\x08") {
                $compressed = $this->directory.'/upload.sql.gz';
                rename($sqlFile, $compressed);
                $input = gzopen($compressed, 'rb');
                $output = fopen($sqlFile, 'xb');
                $bytes = 0;
                try {
                    while (! gzeof($input)) {
                        $chunk = gzread($input, 196608);
                        if ($chunk === false || ($chunk === '' && ! gzeof($input))) {
                            throw new RuntimeException('invalid_dump');
                        }
                        $bytes += strlen($chunk);
                        $this->tick('decompressing', $bytes);
                        if ($bytes > $this->sqlLimit) {
                            throw new RuntimeException('dump_too_large');
                        }
                        if (fwrite($output, $chunk) !== strlen($chunk)) {
                            throw new RuntimeException('operation_failed');
                        }
                    }
                    if ($bytes === 0) {
                        throw new RuntimeException('invalid_dump');
                    }
                } finally {
                    gzclose($input);
                    fclose($output);
                }
                unlink($compressed);
            }
        } else {
            $rootDefaults = $this->directory.'/root.cnf';
            $this->defaults($rootDefaults, $this->credentials['user'], $this->credentials['password']);
            $this->process(['/usr/bin/mysqldump', '--defaults-extra-file='.$rootDefaults, '--single-transaction', '--quick', '--hex-blob', '--no-tablespaces', '--routines', '--events', '--triggers', '--', $source['database_name']], '/dev/null', $sqlFile);
            unlink($rootDefaults);
        }
        if ($job['action'] !== 'import') {
            $targetName = $job['action'] === 'copy' ? $this->database((int) $job['target_database_id'], $job)['database_name'] : '';
            $portableFile = $this->directory.'/portable.sql';
            $input = fopen($sqlFile, 'rb');
            $output = fopen($portableFile, 'xb');
            try {
                SqlDump::stream($input, $output, $source['database_name'], $targetName, function (int $bytes): void {
                    if ($bytes > $this->sqlLimit) {
                        throw new RuntimeException('dump_too_large');
                    }
                    $this->tick('normalizing', $bytes);
                });
            } finally {
                fclose($input);
                fclose($output);
            }
            rename($portableFile, $sqlFile);
        }
        if ($job['action'] === 'export') {
            $archive = $this->directory.'/dump.sql.gz';
            $input = fopen($sqlFile, 'rb');
            $output = gzopen($archive, 'wb6');
            while (! feof($input)) {
                $chunk = fread($input, 786432);
                $this->tick('compressing', ftell($input));
                if (gzwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('operation_failed');
                }
            }
            fclose($input);
            if (! gzclose($output)) {
                throw new RuntimeException('operation_failed');
            }
            if (filesize($archive) > self::MAX_ARCHIVE) {
                throw new RuntimeException('dump_too_large');
            }
            $input = fopen($archive, 'rb');
            $sequence = 0;
            while (! feof($input)) {
                $chunk = fread($input, 786432);
                $this->tick('publishing', ftell($input));
                if ($chunk !== '') {
                    $this->query('INSERT INTO api_database_operation_chunks (operation_id,sequence,content) VALUES (?,?,?)', [$job['id'], $sequence++, base64_encode($chunk)]);
                }
            }
            fclose($input);
            $this->query('UPDATE api_database_operations SET download_bytes=? WHERE id=?', [filesize($archive), $job['id']]);

            return;
        }
        $target = $job['action'] === 'copy' ? $this->database((int) $job['target_database_id'], $job) : $source;
        if ($job['action'] === 'copy') {
            $count = $this->local->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=?');
            $count->execute([$target['database_name']]);
            if ((int) $count->fetchColumn() !== 0 || (int) $target['database_id'] === (int) $source['database_id']) {
                throw new RuntimeException('database_changed');
            }
        }
        // A stable, locked-when-idle principal preserves views/routine DEFINERs.
        // It has privileges on exactly this schema, never FILE/SUPER/global access.
        $user = 'ispcp_job_'.$target['database_id'];
        $account = $this->local->quote($user).'@'.$this->local->quote($this->credentials['host']);
        $password = bin2hex(random_bytes(32));
        $schema = '`'.str_replace('_', '\\_', $target['database_name']).'`';
        try {
            $this->local->exec('CREATE USER IF NOT EXISTS '.$account.' IDENTIFIED BY '.$this->local->quote($password).' ACCOUNT LOCK');
            $this->local->exec('ALTER USER '.$account.' IDENTIFIED BY '.$this->local->quote($password).' ACCOUNT UNLOCK');
            $this->local->exec('GRANT ALL PRIVILEGES ON '.$schema.'.* TO '.$account);
            $defaults = $this->directory.'/client.cnf';
            $this->defaults($defaults, $user, $password);
            chgrp($defaults, $this->gid);
            chmod($defaults, 0640);
            $this->process(['/usr/bin/setpriv', '--reuid='.$this->uid, '--regid='.$this->gid, '--clear-groups', '--no-new-privs', '--', '/usr/bin/mysql', '--defaults-extra-file='.$defaults, '--binary-mode', '--local-infile=0', '--batch', '--', $target['database_name']], $sqlFile, $this->directory.'/output');
        } finally {
            $this->local->exec('ALTER USER '.$account.' IDENTIFIED BY '.$this->local->quote(bin2hex(random_bytes(32))).' ACCOUNT LOCK');
        }
    }

    private function process(array $command, string $input, string $output): void
    {
        $phase = $command[0] === '/usr/bin/mysqldump' ? 'dumping' : 'importing';
        $this->tick($phase);
        $error = $this->directory.'/stderr';
        $process = proc_open($command, [0 => ['file', $input, 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $error, 'w']], $pipes, $this->directory, ['PATH' => '/usr/bin:/bin', 'HOME' => $this->directory]);
        if (! is_resource($process)) {
            throw new RuntimeException('operation_failed');
        }
        try {
            do {
                $status = proc_get_status($process);
                clearstatcache();
                if (filesize($output) > $this->sqlLimit || filesize($error) > 1048576) {
                    throw new RuntimeException('dump_too_large');
                }
                $this->tick($phase, filesize($output));
                if ($status['running']) {
                    usleep(250000);
                }
            } while ($status['running']);
            if ($status['exitcode'] !== 0) {
                // Record only numeric MySQL error / SQLSTATE, never SQL or error text.
                $stderr = file_get_contents($error, false, null, 0, 65536);
                preg_match('/ERROR ([0-9]+)(?: \(([A-Z0-9]{5})\))?/', $stderr, $diagnostic);
                $this->log('process_failed', ['phase' => $phase, 'exit' => $status['exitcode'], 'mysql_error' => $diagnostic[1] ?? null, 'sqlstate' => $diagnostic[2] ?? null]);
                throw new RuntimeException('operation_failed');
            }
        } finally {
            if (($status['running'] ?? true)) {
                proc_terminate($process, 9);
            }
            proc_close($process);
        }
    }
}
