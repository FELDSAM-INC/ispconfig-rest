<?php

declare(strict_types=1);

use App\Support\WebDomainAutoalias;
use App\Support\WordPressPolicy;

/** Root-owned bridge. All WordPress code runs in the separate UID sandbox. */
final class WordPressWorker
{
    public function __construct(private PDO $db, private PDO $local, private int $server) {}

    private function one(string $sql, array $params = []): ?array
    {
        $q = $this->db->prepare($sql);
        $q->execute($params);

        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function write(string $sql, array $params): void
    {
        $this->db->prepare($sql)->execute($params);
    }

    private function heartbeat(bool $available = true, ?string $reason = null): void
    {
        $this->write('INSERT INTO api_wordpress_workers (server_id, heartbeat, version, available, reason) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE heartbeat=VALUES(heartbeat), version=VALUES(version), available=VALUES(available), reason=VALUES(reason)', [$this->server, time(), '3', (int) $available, $reason]);
    }

    public function run(): void
    {
        if (! is_file('/usr/local/share/ispconfig-rest-wordpress/wp-cli.phar') || ! is_executable('/usr/bin/bwrap')) {
            $this->heartbeat(false, 'runtime_missing');

            return;
        }
        if (! $this->runtimeReady()) {
            $this->heartbeat(false, 'sandbox_unavailable');

            return;
        }
        $this->heartbeat();
        $this->cleanup();
        $this->queueCron();
        $this->queueScan();
        $started = time();
        while (time() - $started < 45) {
            $job = $this->one("SELECT * FROM api_wordpress_jobs WHERE server_id = ? AND status IN ('queued','running') ORDER BY created_at LIMIT 1", [$this->server]);
            if (! $job) {
                break;
            }
            if ($job['backup_id'] && $job['status'] !== 'running') {
                $backup = $this->one('SELECT * FROM api_database_operations WHERE id = ?', [$job['backup_id']]);
                if ($backup && in_array($backup['status'], ['queued', 'running'], true)) {
                    break;
                }
                if (! $backup || $backup['status'] !== 'complete' || $backup['action'] !== 'export' || (int) $backup['download_bytes'] < 1 || (int) $backup['expires_at'] <= time()) {
                    $this->finish($job, 'failed', 'backup_failed');

                    continue;
                }
            }
            try {
                if ($this->process($job) === false) {
                    break;
                }
            } catch (Throwable $e) {
                $request = json_decode($job['request'], true);
                $running = $this->one('SELECT status FROM api_wordpress_jobs WHERE id=?', [$job['id']]);
                $status = ($job['backup_id'] || ! empty($request['database_change'])) && ($running['status'] ?? '') === 'running' ? 'recovery_required' : 'failed';
                $this->finish($job, $status, $e instanceof RuntimeException && preg_match('/\A[a-z_]{1,64}\z/D', $e->getMessage()) ? $e->getMessage() : 'worker_failed');
            }
            $this->heartbeat();
        }
    }

    private function cleanup(): void
    {
        $query = $this->db->prepare("SELECT id FROM api_wordpress_jobs WHERE server_id = ? AND status IN ('completed','failed') AND finished_at < ? LIMIT 50");
        $query->execute([$this->server, time() - 86400 * 7]);
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $process = proc_open(['/usr/bin/python3', '-I', __DIR__.'/wordpress-sandbox.py'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/', ['PATH' => '/usr/bin:/bin']);
            if (! is_resource($process)) {
                continue;
            }
            fwrite($pipes[0], json_encode(['cleanup' => [$id]], JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $result = json_decode(stream_get_contents($pipes[1], 4096), true);
            fclose($pipes[1]);
            if (proc_close($process) === 0 && ($result['cleaned'] ?? false) === true) {
                $this->write("DELETE FROM api_wordpress_jobs WHERE id = ? AND server_id = ? AND status IN ('completed','failed')", [$id, $this->server]);
            }
        }
    }

    private function runtimeReady(): bool
    {
        // Prove unprivileged namespaces work; never advertise a fallback to host PHP.
        $command = ['/usr/sbin/runuser', '-u', 'nobody', '--', '/usr/bin/bwrap', '--unshare-all', '--unshare-user', '--share-net',
            '--disable-userns', '--die-with-parent', '--new-session', '--ro-bind', '/usr', '/usr', '--symlink', 'usr/bin', '/bin',
            '--symlink', 'usr/lib', '/lib', '--symlink', 'usr/lib64', '/lib64', '--', '/usr/bin/true'];
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/', ['PATH' => '/usr/bin:/bin']);

        return is_resource($process) && proc_close($process) === 0;
    }

    private function queueCron(): void
    {
        // Native cron produces an empty trigger; one worker owns consuming it and running due events.
        $q = $this->db->prepare("SELECT p.* FROM api_wordpress_cron p JOIN web_domain w ON w.domain_id=p.website_id
            WHERE p.server_id=? AND p.state='active' AND w.active='y'
            AND (p.last_run IS NULL OR p.last_run <= ? - p.interval * 60)
            AND NOT EXISTS (SELECT 1 FROM api_wordpress_jobs j WHERE j.website_id=p.website_id AND j.status IN ('queued','running','recovery_required'))
            ORDER BY COALESCE(p.last_run,0) LIMIT 10");
        $q->execute([$this->server, time() - 1]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $site = $this->one('SELECT * FROM web_domain WHERE domain_id=?', [$row['website_id']]);
            $server = $this->one('SELECT config FROM server WHERE server_id=?', [$this->server]);
            $config = parse_ini_string($server['config'], true, INI_SCANNER_RAW);
            $root = $this->publicRoot($site, $config['web']['server_type'] ?? '');
            if (! hash_equals($row['identity'], WordPressPolicy::identity($site, $root))) {
                continue;
            }
            // A private path is checked and pinned by the sandbox before anything is consumed.
            $marker = $site['document_root'].'/private/.ispcp-wp-cron-'.$row['id'];
            if (! is_file($marker)) {
                continue;
            }
            $id = $this->uuid();
            $request = ['action' => 'cron_run', 'installation' => $row['installation'], 'path' => $row['path'], 'public_root' => $root, 'cron_token' => $row['id']];
            $this->db->beginTransaction();
            try {
                $this->one('SELECT server_id FROM api_wordpress_workers WHERE server_id=? FOR UPDATE', [$this->server]);
                $busy = $this->one("SELECT id FROM api_wordpress_jobs WHERE website_id=? AND status IN ('queued','running','recovery_required') LIMIT 1", [$site['domain_id']]);
                if (! $busy) {
                    $this->write("INSERT INTO api_wordpress_jobs (id,website_id,server_id,identity,action,status,request,created_at) VALUES (?,?,?,?,?,'queued',?,?)", [$id, $site['domain_id'], $this->server, $row['identity'], 'cron_run', json_encode($request, JSON_THROW_ON_ERROR), time()]);
                }
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        }
    }

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-8'.substr($hex, 17, 3).'-'.substr($hex, 20);
    }

    private function processCron(array $site, array $request, array $job, array $config): ?bool
    {
        $row = $this->one('SELECT * FROM api_wordpress_cron WHERE id=? AND website_id=? AND identity=?', [$request['cron_token'] ?? '', $site['domain_id'], $job['identity']]);
        if (! $row) {
            throw new RuntimeException('cron_changed');
        }
        $enable = $job['action'] === 'cron_enable';
        if ($enable || $job['action'] === 'cron_run') {
            $native = $this->one('SELECT * FROM cron WHERE id=? AND parent_domain_id=? AND active=\'y\'', [$row['cron_id'], $site['domain_id']]);
            if (! $native || ! in_array($native['type'], ['full', 'chrooted'], true)) {
                throw new RuntimeException('cron_changed');
            }
            $directory = ($native['type'] === 'chrooted' ? '' : rtrim($site['document_root'], '/')).'/private';
            $expected = ': > '.escapeshellarg($directory.'/.ispcp-wp-cron-'.$row['id']);
            if ($native['command'] !== $expected) {
                throw new RuntimeException('cron_changed');
            }
            $local = $this->local->prepare('SELECT * FROM cron WHERE id=?');
            $local->execute([$row['cron_id']]);
            $applied = $local->fetch(PDO::FETCH_ASSOC);
            $file = rtrim($config['cron']['crontab_dir'] ?? '/etc/cron.d', '/').'/ispc_'.($native['type'] === 'chrooted' ? 'chrooted_' : '').$site['system_user'];
            $schedule = implode('	', array_map(static fn ($field) => $native[$field], ['run_min', 'run_hour', 'run_mday', 'run_month', 'run_wday']));
            $same = true;
            foreach (['type', 'run_min', 'run_hour', 'run_mday', 'run_month', 'run_wday'] as $field) {
                $same = $same && ($applied[$field] ?? null) === $native[$field];
            }
            $info = @lstat($file);
            $ready = $same && $applied && $applied['active'] === 'y' && $applied['command'] === $expected && $info && $info['uid'] === 0
                && ($info['mode'] & 0170000) === 0100000 && ! ($info['mode'] & 0022) && $info['size'] < 1048576
                && str_contains(file_get_contents($file), $schedule."\t".$site['system_user']."\t".$expected);
            if ($native['type'] === 'chrooted') {
                $ready = $ready && is_executable($site['document_root'].'/bin/sh') && is_executable('/usr/sbin/jk_chrootsh');
            }
            if (! $ready) {
                if (time() - (int) $job['created_at'] > 600) {
                    throw new RuntimeException('cron_not_applied');
                }
                $this->write("UPDATE api_wordpress_jobs SET status='queued' WHERE id=?", [$job['id']]);

                return false;
            }
        }
        if ($job['action'] === 'cron_run') {
            if ($row['state'] !== 'active') {
                throw new RuntimeException('cron_changed');
            }
            $polled = $this->sandbox($site, array_replace($request, ['action' => 'cron_poll']), $job);
            if (isset($polled['error'])) {
                throw new RuntimeException($polled['error']);
            }
            if (empty($polled['triggered'])) {
                $this->finish($job, 'completed');

                return null;
            }
            $this->write('UPDATE api_wordpress_cron SET last_run=?,error=NULL WHERE id=?', [time(), $row['id']]);
        } else {
            if (! $row['previous_captured']) {
                $prepared = $this->sandbox($site, array_replace($request, ['action' => 'prepare_cron']), $job);
                if (isset($prepared['error']) || ! array_key_exists('previous_value', $prepared)) {
                    throw new RuntimeException('unsupported_constant');
                }
                $row['previous_value'] = $prepared['previous_value'];
                if (! in_array($row['previous_value'], [null, 'true', 'false', '0', '1'], true)) {
                    throw new RuntimeException('unsupported_constant');
                }
                $this->write('UPDATE api_wordpress_cron SET previous_captured=1,previous_value=? WHERE id=?', [$row['previous_value'], $row['id']]);
            }
            $request['previous_value'] = $row['previous_value'];
        }
        $result = $this->sandbox($site, $request, $job);
        if (isset($result['error'])) {
            throw new RuntimeException($result['error']);
        }
        $current = $this->one('SELECT * FROM web_domain WHERE domain_id=? AND server_id=?', [$site['domain_id'], $this->server]);
        if (! $current || ! hash_equals(WordPressPolicy::identity($current, $request['public_root']), $job['identity'])) {
            throw new RuntimeException('site_changed');
        }
        if ($job['action'] !== 'cron_run') {
            if (empty($result['cron_changed'])) {
                throw new RuntimeException('invalid_worker_result');
            }
            $this->write('UPDATE api_wordpress_cron SET state=?,error=NULL WHERE id=?', [$enable ? 'active' : 'disabled', $row['id']]);
            $snapshot = $this->one('SELECT installations FROM api_wordpress_sites WHERE website_id=? AND identity=?', [$site['domain_id'], $job['identity']]);
            $installs = json_decode($snapshot['installations'] ?? '[]', true);
            foreach ($installs as &$installation) {
                if ($installation['id'] === $row['installation']) {
                    $installation['config_hash'] = $result['config_hash'];
                }
            }
            unset($installation);
            $this->write('UPDATE api_wordpress_sites SET installations=? WHERE website_id=? AND identity=?', [json_encode($installs, JSON_THROW_ON_ERROR), $site['domain_id'], $job['identity']]);
        }
        $this->finish($job, 'completed');

        return null;
    }

    private function integrity(array $value): array
    {
        if (! in_array($value['status'] ?? '', ['clean', 'modified'], true) || ! is_array($value['files'] ?? null) || count($value['files']) > 500) {
            throw new RuntimeException('invalid_worker_result');
        }
        $files = [];
        foreach ($value['files'] as $entry) {
            if (! is_string($entry['file'] ?? null) || strlen($entry['file']) > 1024 || preg_match('~(?:\A/|(?:\A|/)\.\.?(?:/|\z)|[\x00-\x1f\x7f])~', $entry['file']) || ! in_array($entry['status'] ?? '', ['missing', 'changed', 'unexpected'], true)) {
                throw new RuntimeException('invalid_worker_result');
            }
            $files[] = ['file' => $entry['file'], 'status' => $entry['status']];
        }

        return ['status' => $value['status'], 'version' => substr((string) ($value['version'] ?? ''), 0, 64), 'locale' => substr((string) ($value['locale'] ?? ''), 0, 20),
            'checked_at' => gmdate('c'), 'files' => $files, 'total' => max(count($files), min(100000, (int) ($value['total'] ?? 0))), 'truncated' => ($value['truncated'] ?? false) === true];
    }

    private function queueScan(): void
    {
        $site = $this->one("SELECT w.* FROM web_domain w LEFT JOIN api_wordpress_sites p ON p.website_id=w.domain_id
            JOIN sys_group g ON g.groupid=w.sys_groupid LEFT JOIN client c ON c.client_id=g.client_id
            WHERE w.server_id=? AND w.active='y' AND w.type IN ('vhost','vhostsubdomain','vhostalias') AND (c.locked IS NULL OR c.locked != 'y')
            AND (p.scanned_at IS NULL OR p.scanned_at < ?)
            AND NOT EXISTS (SELECT 1 FROM api_wordpress_jobs j WHERE j.website_id=w.domain_id AND (j.created_at >= ? OR j.status IN ('queued','running','recovery_required')))
            ORDER BY COALESCE(p.scanned_at,0), w.domain_id LIMIT 1", [$this->server, time() - 3600, time() - 3600]);
        if (! $site) {
            return;
        }
        $server = $this->one('SELECT config FROM server WHERE server_id=?', [$this->server]);
        $config = parse_ini_string($server['config'], true, INI_SCANNER_RAW);
        $root = $this->publicRoot($site, $config['web']['server_type'] ?? '');
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);
        $id = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
        $this->write("INSERT INTO api_wordpress_jobs (id,website_id,server_id,identity,action,status,request,created_at) VALUES (?,?,?,?,'rescan','queued',?,?)",
            [$id, $site['domain_id'], $this->server, WordPressPolicy::identity($site, $root), json_encode(['action' => 'rescan', 'public_root' => $root], JSON_THROW_ON_ERROR), time()]);
    }

    private function publicRoot(array $site, string $engine): string
    {
        $raw = (string) $site[$engine === 'nginx' ? 'nginx_directives' : 'apache_directives'];
        $root = rtrim($site['document_root'], '/').'/'.($site['type'] === 'vhost' ? 'web' : $site['web_folder']);
        if (str_contains($raw, '# BEGIN ISPCP RUNTIME')) {
            if (! preg_match('/^# BEGIN ISPCP RUNTIME ([A-Za-z0-9+\/=]+)\r?$/m', $raw, $match)) {
                throw new RuntimeException('site_changed');
            }
            $runtime = json_decode(base64_decode($match[1], true), true, 8, JSON_THROW_ON_ERROR);
            if (! is_string($runtime['document_root_subdir'] ?? null)) {
                throw new RuntimeException('site_changed');
            }
            $root .= $runtime['document_root_subdir'] !== '' ? '/'.$runtime['document_root_subdir'] : '';
        }

        return $root;
    }

    private function finish(array $job, string $status, ?string $error = null): void
    {
        $this->write('UPDATE api_wordpress_jobs SET status = ?, error = ?, finished_at = ? WHERE id = ? AND server_id = ?', [$status, $error, time(), $job['id'], $this->server]);
        if (in_array($job['action'], ['cron_enable', 'cron_disable', 'cron_run'], true) && $status === 'failed') {
            $request = json_decode($job['request'], true);
            $this->write('UPDATE api_wordpress_cron SET error=?, state=IF(?=\'cron_run\', state, \'error\') WHERE id=? AND website_id=?', [$error, $job['action'], $request['cron_token'] ?? '', $job['website_id']]);
        }
    }

    private function process(array $job): ?bool
    {
        $site = $this->one("SELECT w.* FROM web_domain w JOIN sys_group g ON g.groupid=w.sys_groupid LEFT JOIN client c ON c.client_id=g.client_id WHERE w.domain_id=? AND w.server_id=? AND w.active='y' AND w.type IN ('vhost','vhostsubdomain','vhostalias') AND (c.locked IS NULL OR c.locked != 'y')", [$job['website_id'], $this->server]);
        if (! $site) {
            throw new RuntimeException('site_changed');
        }
        // Master approval is not enough: a remote server must have applied the same identity locally.
        $query = $this->local->prepare('SELECT * FROM web_domain WHERE domain_id = ? AND server_id = ?');
        $query->execute([$job['website_id'], $this->server]);
        $local = $query->fetch(PDO::FETCH_ASSOC);
        foreach (['sys_groupid', 'domain', 'document_root', 'web_folder', 'system_user', 'system_group', 'active', 'type'] as $key) {
            if (! $local || $site[$key] !== $local[$key]) {
                throw new RuntimeException('site_not_applied');
            }
        }
        $request = json_decode($job['request'], true, 16, JSON_THROW_ON_ERROR);
        $server = $this->one('SELECT config FROM server WHERE server_id = ?', [$this->server]);
        $config = parse_ini_string($server['config'], true, INI_SCANNER_RAW);
        $engine = $config['web']['server_type'] ?? '';
        $root = $this->publicRoot($site, $engine);
        $site['verification_hosts'] = $this->verificationHosts($site, $config['web']['website_autoalias'] ?? '');
        $identity = WordPressPolicy::identity($site, $root);
        if (! hash_equals($identity, $job['identity']) || $request['public_root'] !== $root) {
            throw new RuntimeException('site_changed');
        }
        $php = $site['server_php_id'] ? $this->one('SELECT php_fpm_ini_dir, php_fastcgi_binary FROM server_php WHERE server_php_id = ? AND server_id = ?', [$site['server_php_id'], $this->server]) : null;
        $ini = $php['php_fpm_ini_dir'] ?? $config['web']['php_fpm_ini_path'] ?? '';
        $binary = $php['php_fastcgi_binary'] ?? $config['fastcgi']['fastcgi_bin'] ?? '';
        if (preg_match('~\A/etc/php/([0-9]+\.[0-9]+)/fpm/?\z~D', $ini, $match)) {
            $site['php_cli'] = '/usr/bin/php'.$match[1];
        } elseif (preg_match('~\A/usr/bin/php-cgi([0-9.]*)\z~D', $binary, $match)) {
            $site['php_cli'] = '/usr/bin/php'.$match[1];
        } else {
            throw new RuntimeException('unsupported_php_layout');
        }
        $snapshot = $this->one('SELECT * FROM api_wordpress_sites WHERE website_id = ? AND identity = ?', [$job['website_id'], $identity]);
        $installs = json_decode($snapshot['installations'] ?? '[]', true, 32, JSON_THROW_ON_ERROR);
        $previous = null;
        foreach ($installs as $row) {
            if (($request['installation'] ?? null) === $row['id']) {
                $previous = $row;
            }
        }
        if ($job['action'] !== 'rescan' && (! $previous || $previous['path'] !== $request['path'])) {
            throw new RuntimeException('installation_changed');
        }
        $request['undo'] = $previous['undo'] ?? [];
        $request['salts_changed'] = $previous['salts_changed'] ?? false;
        $request['languages'] = array_map(static fn ($value) => $value === 'y', array_intersect_key($site, array_flip(['cgi', 'ssi', 'perl', 'python', 'ruby'])));
        $request['permissions_available'] = $site['php'] === 'php-fpm' || ($site['php'] === 'fast-cgi' && $site['suexec'] === 'y');
        $request['server_security'] = $this->serverSecurity($site, $engine, $request['path'] ?? '');
        $database = ! empty($previous['database_id']) ? $this->one("SELECT database_name FROM web_database WHERE database_id=? AND server_id=? AND sys_groupid=? AND active='y'", [$previous['database_id'], $this->server, $site['sys_groupid']]) : null;
        $request['database_available'] = $database !== null;
        $request['database_name'] = $database['database_name'] ?? null;
        if ($database && $previous) {
            $request['undo'] += $this->legacyDatabaseUndo($site, $previous, $database['database_name'], $root);
        }
        if ($job['backup_id'] && $job['status'] !== 'running') {
            $backup = $this->one('SELECT * FROM api_database_operations WHERE id = ?', [$job['backup_id']]);
            if ((int) $backup['database_id'] !== (int) ($previous['database_id'] ?? 0) || (int) $backup['sys_groupid'] !== (int) $site['sys_groupid'] || (int) $backup['server_id'] !== $this->server) {
                throw new RuntimeException('backup_failed');
            }
        }
        if ($job['status'] === 'running' && ! $job['backup_id'] && empty($request['database_change']) && ! in_array($job['action'], ['cron_enable', 'cron_disable'], true)) {
            $this->finish($job, 'failed', 'interrupted_check_required');

            return null;
        }
        $this->write("UPDATE api_wordpress_jobs SET status='running', started_at=COALESCE(started_at, ?) WHERE id=?", [time(), $job['id']]);
        if ($job['action'] === 'secure' && array_intersect($request['measures'] ?? [], ['file_editor', 'concatenate', 'pingbacks'])) {
            $prepared = $this->sandbox($site, array_replace($request, ['action' => 'prepare_security']), $job);
            if (isset($prepared['error'])) {
                throw new RuntimeException('security_check_failed');
            }
            $prior = $this->clean($prepared['installation'] ?? [], $site);
            if ($prior['id'] !== $previous['id']) {
                throw new RuntimeException('installation_changed');
            }
            $request['undo'] = $prior['undo'];
            foreach ($installs as &$row) {
                if ($row['id'] === $prior['id']) {
                    $row['undo'] = $prior['undo'];
                }
            }
            unset($row);
            $this->write('UPDATE api_wordpress_sites SET installations=? WHERE website_id=? AND identity=?', [json_encode($installs, JSON_THROW_ON_ERROR), $site['domain_id'], $identity]);
        }
        if (in_array($job['action'], ['cron_enable', 'cron_disable', 'cron_run'], true)) {
            return $this->processCron($site, $request, $job, $config);
        }
        $result = $this->sandbox($site, $request, $job);
        if (isset($result['error'])) {
            $error = is_string($result['error']) && preg_match('/\A[a-z_]{1,64}\z/D', $result['error']) ? $result['error'] : 'worker_failed';
            $this->finish($job, ! empty($result['recovery_required']) ? 'recovery_required' : 'failed', $error);

            return null;
        }
        if ($job['action'] === 'verify_integrity') {
            $integrity = $this->integrity($result['integrity'] ?? []);
            foreach ($installs as &$old) {
                if ($old['id'] === $previous['id']) {
                    $old['integrity'] = $integrity;
                }
            }
            unset($old);
        } elseif ($job['action'] === 'rescan') {
            $next = [];
            foreach (array_slice($result['installations'] ?? [], 0, 20) as $row) {
                $row = $this->clean($row, $site);
                foreach ($installs as $old) {
                    if ($row['id'] === $old['id'] && ! empty($row['database_id']) && $row['database_id'] === ($old['database_id'] ?? null)) {
                        $row['undo'] = array_intersect_key($old['undo'] ?? [], array_flip(['prefix', 'admin_login']));
                    }
                    if ($row['id'] === $old['id'] && isset($row['config_hash'], $old['config_hash']) && hash_equals($old['config_hash'], $row['config_hash'])) {
                        $row = array_replace($row, array_intersect_key($old, array_flip(['undo', 'salts_changed', 'security', 'checked_at', 'integrity'])));
                    }
                }
                $next[] = $row;
            }
            $installs = $next;
        } else {
            $row = $this->clean($result['installation'] ?? [], $site);
            if ($row['id'] !== $previous['id']) {
                throw new RuntimeException('invalid_worker_result');
            }
            foreach ($installs as &$old) {
                if ($old['id'] === $row['id']) {
                    $row['integrity'] = $old['integrity'] ?? null;
                    $old = $row;
                }
            }
            unset($old);
        }
        // Re-check identity before exposing command results after a long-running operation.
        $current = $this->one('SELECT * FROM web_domain WHERE domain_id = ? AND server_id = ?', [$job['website_id'], $this->server]);
        if (! $current || ! hash_equals(WordPressPolicy::identity($current, $root), $identity)) {
            throw new RuntimeException('site_changed');
        }
        $this->write('INSERT INTO api_wordpress_sites (website_id, server_id, identity, scanned_at, incomplete, installations) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE server_id=VALUES(server_id), identity=VALUES(identity), scanned_at=VALUES(scanned_at), incomplete=VALUES(incomplete), installations=VALUES(installations)',
            [$job['website_id'], $this->server, $identity, $job['action'] === 'rescan' ? time() : ($snapshot['scanned_at'] ?? time()), (int) ($result['incomplete'] ?? $snapshot['incomplete'] ?? false), json_encode($installs, JSON_THROW_ON_ERROR)]);
        $this->finish($job, isset($result['operation_error']) ? 'failed' : 'completed', $result['operation_error'] ?? null);

        return null;
    }

    private function verificationHosts(array $site, string $pattern): array
    {
        $hosts = [$site['domain'], 'www.'.$site['domain']];
        $client = [];
        if (str_contains($pattern, '[client_id]') || str_contains($pattern, '[client_username]')) {
            $client = $this->one('SELECT c.client_id,c.username FROM sys_group g JOIN client c ON c.client_id=g.client_id WHERE g.groupid=?', [$site['sys_groupid']]) ?? [];
        }
        $alias = WebDomainAutoalias::resolve($pattern, $site, (int) ($client['client_id'] ?? 0), (string) ($client['username'] ?? ''));
        if ($alias !== null) {
            $hosts[] = $alias;
        }

        return array_values(array_unique(array_map('strtolower', $hosts)));
    }

    private function sandbox(array $site, array $request, array $job): array
    {
        $minimal = array_intersect_key($site, array_flip(['domain_id', 'server_id', 'sys_groupid', 'domain', 'type', 'document_root', 'web_folder', 'system_user', 'system_group', 'php_cli', 'verification_hosts']));
        $process = proc_open(['/usr/bin/python3', __DIR__.'/wordpress-sandbox.py'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/', ['PATH' => '/usr/bin:/bin']);
        if (! is_resource($process)) {
            throw new RuntimeException('sandbox_failed');
        }
        fwrite($pipes[0], json_encode(['site' => $minimal, 'request' => $request, 'job' => $job['id'], 'backup_id' => $job['backup_id'], 'resuming' => $job['status'] === 'running'], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        $output = '';
        $started = time();
        $beat = 0;
        while (! feof($pipes[1])) {
            $output .= fread($pipes[1], 65536);
            if (strlen($output) > 1048576 || time() - $started > 1900) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                proc_close($process);
                throw new RuntimeException('sandbox_failed');
            }
            if (time() - $beat >= 5) {
                $this->heartbeat();
                $beat = time();
            }
            usleep(100000);
        }
        fclose($pipes[1]);
        $code = proc_close($process);
        if ($code !== 0) {
            throw new RuntimeException('sandbox_failed');
        }

        return json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    }

    private function clean(array $row, array $site): array
    {
        if (! is_string($row['path'] ?? null) || strlen($row['path']) > 1024 || preg_match('~(?:\A/|(?:\A|/)\.\.?(?:/|\z)|[\x00-\x1f\x7f])~', $row['path'])) {
            throw new RuntimeException('invalid_worker_result');
        }
        $clean = ['id' => WordPressPolicy::id($row['path']), 'path' => $row['path']];
        foreach (['url' => 2048, 'admin_url' => 2048, 'version' => 64, 'title' => 256, 'checked_at' => 32, 'error' => 64] as $key => $limit) {
            if (isset($row[$key]) && is_string($row[$key])) {
                $clean[$key] = mb_substr(preg_replace('/[\x00-\x1f\x7f]/', '', $row[$key]), 0, $limit);
            }
        }
        foreach (['url', 'admin_url'] as $key) {
            if (isset($clean[$key]) && ! preg_match('~\Ahttps?://~i', $clean[$key])) {
                $clean[$key] = '';
            }
        }
        if (is_string($row['config_hash'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $row['config_hash'])) {
            $clean['config_hash'] = $row['config_hash'];
        }
        $clean['security'] = [];
        foreach ([...WordPressPolicy::SERVER, ...WordPressPolicy::LOCAL] as $measure) {
            $status = $row['security'][$measure] ?? null;
            if (! is_array($status) || ! in_array($status['status'] ?? null, ['ok', 'warning', 'danger', 'pending', 'unavailable'], true)) {
                continue;
            }
            $clean['security'][$measure] = ['status' => $status['status'], 'can_revert' => ($status['can_revert'] ?? false) === true];
            if (is_string($status['reason'] ?? null) && preg_match('/\A[a-z_]{1,64}\z/D', $status['reason'])) {
                $clean['security'][$measure]['reason'] = $status['reason'];
            }
        }
        $clean['undo'] = [];
        foreach (['file_editor', 'concatenate', 'pingbacks'] as $key) {
            if (array_key_exists($key, $row['undo'] ?? []) && in_array($row['undo'][$key], [null, 'true', 'false', '0', '1', 'open', 'closed'], true)) {
                $clean['undo'][$key] = $row['undo'][$key];
            }
        }
        foreach (['prefix', 'admin_login'] as $key) {
            $undo = $row['undo'][$key] ?? null;
            $pattern = $key === 'prefix' ? '/\A[A-Za-z0-9_]{1,64}\z/D' : '/\A[A-Za-z0-9_.@-]{1,60}\z/D';
            if (is_array($undo) && is_string($undo['previous'] ?? null) && preg_match($pattern, $undo['previous'])
                && is_string($undo['applied'] ?? null) && preg_match($pattern, $undo['applied']) && is_string($undo['database'] ?? null) && strlen($undo['database']) <= 64
                && ($key !== 'admin_login' || (is_int($undo['id'] ?? null) && $undo['id'] > 0))) {
                $clean['undo'][$key] = array_intersect_key($undo, array_flip(['previous', 'applied', 'database', 'id']));
            }
        }
        $clean['salts_changed'] = ($row['salts_changed'] ?? false) === true;
        $clean['database_id'] = null;
        if (in_array($row['database_host'] ?? '', ['localhost', '127.0.0.1', 'localhost:3306', '127.0.0.1:3306'], true) && is_string($row['database_name'] ?? null)) {
            $database = $this->one("SELECT database_id FROM web_database WHERE database_name=? AND server_id=? AND sys_groupid=? AND active='y' AND type='mysql'", [$row['database_name'], $this->server, $site['sys_groupid']]);
            $clean['database_id'] = $database ? (int) $database['database_id'] : null;
        }

        return $clean;
    }

    private function legacyDatabaseUndo(array $site, array $installation, string $database, string $root): array
    {
        // Adopt only a successful, identity-bound old worker journal; never guess the renamed user.
        $q = $this->db->prepare("SELECT id, request FROM api_wordpress_jobs WHERE website_id=? AND server_id=? AND status='completed' AND action='secure' AND backup_id IS NOT NULL ORDER BY created_at DESC LIMIT 20");
        $q->execute([$site['domain_id'], $this->server]);
        $undo = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $job) {
            $input = json_decode($job['request'], true);
            if (($input['public_root'] ?? '') !== $root || ($input['installation'] ?? '') !== $installation['id'] || (int) ($input['database_id'] ?? 0) !== (int) $installation['database_id'] || ! preg_match('/\A[a-f0-9-]{36}\z/D', $job['id'])) {
                continue;
            }
            $path = '/var/lib/ispcp-files/wordpress/'.$job['id'].'.recovery/state.json';
            $stat = @lstat($path);
            if (! $stat || ($stat['mode'] & 0170000) !== 0100000 || $stat['uid'] !== 0 || ($stat['mode'] & 0077) || $stat['size'] > 1048576) {
                continue;
            }
            $state = json_decode(file_get_contents($path), true);
            if (($state['phase'] ?? '') !== 'completed' || ($state['identity'] ?? '') !== WordPressPolicy::identity($site, $input['public_root'])) {
                continue;
            }
            $before = $state['prepared'];
            if (in_array('prefix', $input['measures'], true) && $before['new_prefix'] !== $before['prefix']) {
                $undo += ['prefix' => ['previous' => $before['prefix'], 'applied' => $before['new_prefix'], 'database' => $database]];
            }
            if (in_array('admin_login', $input['measures'], true) && ! empty($before['admin'])) {
                $undo += ['admin_login' => ['previous' => $before['admin']['user_login'], 'applied' => $input['admin_login'], 'id' => (int) $before['admin']['ID'], 'database' => $database]];
            }
        }

        return $undo;
    }

    private function serverSecurity(array $site, string $engine, string $path): array
    {
        if ($engine !== 'apache') {
            return [];
        }
        try {
            WordPressPolicy::path($path);
        } catch (InvalidArgumentException $e) {
            return array_fill_keys(WordPressPolicy::SERVER, ['status' => 'unavailable', 'reason' => 'unsupported_installation_path']);
        }
        $blocks = WordPressPolicy::blocks((string) $site['apache_directives']);
        $configured = $blocks[WordPressPolicy::id($path)]['measures'] ?? [];
        $live = '';
        if (preg_match('/\A[a-z0-9.-]+\z/D', $site['domain'])) {
            $file = '/etc/apache2/sites-available/'.$site['domain'].'.vhost';
            $stat = @lstat($file);
            if ($stat && ($stat['mode'] & 0170000) === 0100000 && $stat['uid'] === 0 && ! ($stat['mode'] & 0022) && $stat['size'] < 1048576) {
                $live = str_replace("\r\n", "\n", file_get_contents($file));
            }
        }
        $applied = $configured && str_contains($live, trim(WordPressPolicy::compile($path, $configured)));
        $result = [];
        foreach (WordPressPolicy::SERVER as $measure) {
            $wanted = in_array($measure, $configured, true);
            $result[$measure] = ['status' => $wanted ? ($applied ? 'ok' : 'pending') : 'warning', 'can_revert' => $wanted];
        }

        return $result;
    }
}
