<?php

namespace App\Support;

use RuntimeException;

/**
 * Own output log for each scheduled task. ISPConfig's `log` option appends only the output of a command's last part to
 * private/cron.log, shared by every task of the website. A managed prefix instead redirects the whole task, as the
 * website user, into private/.ispcp-cron-<token>.log and marks each run with its time and exit status. A prefix keeps
 * the task's own text untouched at the end, so its quoting and comments behave exactly as before; `command exec` keeps
 * a failed redirection from stopping the task. Shared by the API, which writes commands, and the web-log worker, which
 * reads and trims the files on the webserver, so it must not depend on Laravel.
 */
final class CronOutputLog
{
    /** Files above this size are trimmed to the last KEEP bytes by the web-log worker. */
    public const LIMIT = 1048576;

    public const KEEP = 524288;

    private const READ = 262144;

    private const PREFIX = '/\Acommand exec >>\'(?<dir>[^\']+)\/\.ispcp-cron-(?<token>[a-f0-9]{32})\.log\' 2>&1; trap \'echo "=== exit \$\? ==="\' EXIT; echo "=== \$\(date -u\) ==="; (?<command>.*)\z/s';

    public static function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** The website's private directory as the task sees it: inside the jail for chrooted tasks. */
    public static function directory(string $type, string $documentRoot): string
    {
        return ($type === 'chrooted' ? '' : rtrim($documentRoot, '/')).'/private';
    }

    public static function wrap(string $command, string $token, string $type, string $documentRoot): string
    {
        // ISPConfig drops the document root from the start of a chrooted command; behind the prefix it no longer can.
        $root = rtrim($documentRoot, '/');
        if ($type === 'chrooted' && $root !== '' && str_starts_with($command, $root.'/')) {
            $command = substr($command, strlen($root));
        }

        return 'command exec >>\''.self::directory($type, $documentRoot).'/.ispcp-cron-'.$token.'.log\' 2>&1; trap \'echo "=== exit $? ==="\' EXIT; echo "=== $(date -u) ==="; '.$command;
    }

    /** @return array{dir: string, token: string, command: string}|null the task's own command behind a managed prefix */
    public static function parse(string $command): ?array
    {
        return preg_match(self::PREFIX, $command, $match) === 1 ? ['dir' => $match['dir'], 'token' => $match['token'], 'command' => $match['command']] : null;
    }

    /**
     * The last lines of a task's log, read as the website user so a link or special file in its place reaches nothing
     * that user could not read already, and a FIFO cannot block the worker.
     *
     * @return array{lines: string[], size: int, modified_at: ?int, truncated: bool}
     */
    public static function read(string $documentRoot, string $user, string $token, int $lines, ?callable $run = null): array
    {
        $file = self::file($documentRoot, $user, $token);
        $info = @lstat($file);
        if (! $info) {
            return ['lines' => [], 'size' => 0, 'modified_at' => null, 'truncated' => false];
        }
        if (($info['mode'] & 0170000) !== 0100000) {
            throw new RuntimeException('logs_unavailable');
        }
        [$code, $tail] = ($run ?? [self::class, 'run'])(['/usr/bin/tail', '-c', (string) self::READ, '--', $file], $user);
        if ($code !== 0) {
            throw new RuntimeException('logs_unavailable');
        }

        return self::lines($tail, $info['size'] > self::READ, $lines) + ['size' => (int) $info['size'], 'modified_at' => (int) $info['mtime']];
    }

    /** Keeps the last KEEP bytes of a log above LIMIT, as the website user. A run writing meanwhile may lose lines. */
    public static function trim(string $documentRoot, string $user, string $token, ?callable $run = null): bool
    {
        $file = self::file($documentRoot, $user, $token);
        $info = @lstat($file);
        if (! $info || ($info['mode'] & 0170000) !== 0100000 || $info['size'] <= self::LIMIT) {
            return false;
        }
        $script = 'f=$1; [ -f "$f" ] && [ ! -L "$f" ] && tail -c '.self::KEEP.' -- "$f" > "$f.trim" && mv -f -- "$f.trim" "$f"';
        [$code] = ($run ?? [self::class, 'run'])(['/bin/sh', '-c', $script, 'trim', $file], $user);

        return $code === 0;
    }

    /**
     * @return array{lines: string[], truncated: bool}
     */
    public static function lines(string $text, bool $partial, int $lines): array
    {
        $rows = explode("\n", rtrim($text, "\n"));
        if ($partial) {
            array_shift($rows);
        }
        if ($rows === ['']) {
            $rows = [];
        }
        $truncated = $partial || count($rows) > $lines;
        $rows = array_map(static function (string $row): string {
            // Terminal colour and cursor sequences, then any other control character
            $row = (string) preg_replace(['/\e\[[0-9;?]*[ -\/]*[@-~]/', '/[\x00-\x08\x0b-\x1f\x7f]/'], '', $row);

            return strlen($row) > 4096 ? substr($row, 0, 4096).'…' : $row;
        }, array_slice($rows, -max(1, $lines)));

        return ['lines' => $rows, 'truncated' => $truncated];
    }

    private static function file(string $documentRoot, string $user, string $token): string
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1 || preg_match('/\Aweb[1-9][0-9]*\z/D', $user) !== 1) {
            throw new RuntimeException('logs_unavailable');
        }

        return rtrim($documentRoot, '/').'/private/.ispcp-cron-'.$token.'.log';
    }

    /** @return array{int, string} exit code and at most READ bytes of output; ten seconds at most */
    private static function run(array $command, string $user): array
    {
        $process = proc_open(['/usr/bin/timeout', '10', '/usr/sbin/runuser', '-u', $user, '--', ...$command],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/', ['PATH' => '/usr/bin:/bin']);
        if (! is_resource($process)) {
            return [127, ''];
        }
        $output = (string) stream_get_contents($pipes[1], self::READ + 1);
        fclose($pipes[1]);

        return [proc_close($process), substr($output, 0, self::READ)];
    }
}
