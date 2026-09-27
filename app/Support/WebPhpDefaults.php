<?php

namespace App\Support;

use PDO;
use RuntimeException;

/** Reads only administrator-owned configuration; never executes website PHP or exposes the whole INI. */
final class WebPhpDefaults
{
    public const KEYS = ['memory_limit', 'max_execution_time', 'max_input_time', 'post_max_size', 'upload_max_filesize',
        'opcache.enable', 'disable_functions', 'error_reporting', 'display_errors', 'log_errors', 'allow_url_fopen', 'file_uploads', 'short_open_tag'];

    public const BOOLEAN_KEYS = ['opcache.enable', 'display_errors', 'log_errors', 'allow_url_fopen', 'file_uploads', 'short_open_tag'];

    public static function values(string $ini): array
    {
        // Per-host/per-path sections cannot be represented as a single website-wide value.
        if (preg_match('/^\s*\[(?:PATH|HOST)\s*=/mi', $ini)) {
            throw new RuntimeException('php_settings_unavailable');
        }
        $parsed = @parse_ini_string($ini, false, INI_SCANNER_RAW);
        if (! is_array($parsed)) {
            throw new RuntimeException('php_settings_unavailable');
        }
        $out = [];
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $parsed)) {
                $value = $parsed[$key];
                if (! is_string($value) || strlen($value) > 16384 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                    throw new RuntimeException('php_settings_unavailable');
                }
                $out[$key] = in_array($key, self::BOOLEAN_KEYS, true) ? self::boolean($value) : $value;
            }
        }

        return $out;
    }

    public static function boolean(string $value): ?string
    {
        return match (strtolower(trim($value))) {
            '1', 'on', 'true', 'yes' => 'on',
            '', '0', 'off', 'false', 'no', 'none' => 'off',
            default => null,
        };
    }

    private static function trustedPath(string $path): string
    {
        $real = realpath($path);
        if ($real === false) {
            throw new RuntimeException('php_settings_unavailable');
        }
        // A trusted symlink (Debian conf.d) may resolve to another trusted configuration directory.
        foreach (array_unique([$path, $real]) as $candidate) {
            do {
                $stat = lstat($candidate);
                if (! $stat || $stat['uid'] !== 0 || (($stat['mode'] & 0022) && ! is_link($candidate)
                    && ! (is_dir($candidate) && ($stat['mode'] & 01000)))) {
                    throw new RuntimeException('php_settings_unavailable');
                }
                $candidate = dirname($candidate);
            } while ($candidate !== '/');
        }

        return $real;
    }

    private static function read(string $path): string
    {
        $handle = @fopen(self::trustedPath($path), 'rb');
        if (! $handle) {
            throw new RuntimeException('php_settings_unavailable');
        }
        try {
            $stat = fstat($handle);
            if (! $stat || ($stat['mode'] & 0170000) !== 0100000 || $stat['uid'] !== 0 || ($stat['mode'] & 0022) || $stat['size'] > 1048576) {
                throw new RuntimeException('php_settings_unavailable');
            }

            return stream_get_contents($handle, 1048577);
        } finally {
            fclose($handle);
        }
    }

    /** PHP 8.5 includes OPcache in the binary, without a zend_extension INI entry. */
    public static function builtInOpcache(string $binary): ?bool
    {
        if ($binary === '' || $binary[0] !== '/' || ! preg_match('/\Aphp(?:-cgi)?[0-9.]*\z/', basename($binary))) {
            return null;
        }
        try {
            $binary = self::trustedPath($binary);
            $runner = self::trustedPath('/usr/sbin/runuser');
        } catch (RuntimeException) {
            return null;
        }
        // Only a trusted native PHP binary, never an administrator's shell wrapper.
        $handle = @fopen($binary, 'rb');
        if (! $handle) {
            return null;
        }
        $native = fread($handle, 4) === "\x7fELF";
        fclose($handle);
        if (! $native || ! is_executable($binary)) {
            return null;
        }
        $process = @proc_open([$runner, '-u', 'nobody', '--', $binary, '-n', '-v'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes,
            '/', ['PATH' => '/usr/bin:/bin', 'PHP_INI_SCAN_DIR' => '']);
        if (! is_resource($process)) {
            return null;
        }
        stream_set_blocking($pipes[1], false);
        $output = '';
        $deadline = microtime(true) + 2;
        try {
            do {
                $output .= stream_get_contents($pipes[1], 8193 - strlen($output));
                $status = proc_get_status($process);
                if (! $status['running']) {
                    $output .= stream_get_contents($pipes[1], 8193 - strlen($output));

                    return strlen($output) <= 8192 && $status['exitcode'] === 0 && preg_match('/^PHP [0-9]+\.[0-9]+\./', $output)
                        ? str_contains($output, 'with Zend OPcache') : null;
                }
                usleep(10000);
            } while (strlen($output) <= 8192 && microtime(true) < $deadline);
        } finally {
            fclose($pipes[1]);
            if ($status['running'] ?? true) {
                proc_terminate($process);
            }
            proc_close($process);
        }

        return null;
    }

    public static function collect(string $path, ?bool $builtInOpcache = null): array
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, '..') || str_contains($path, "\0")) {
            throw new RuntimeException('php_settings_unavailable');
        }
        $ini = str_ends_with($path, '/php.ini') ? $path : rtrim($path, '/').'/php.ini';
        $base = self::read($ini);
        $settings = self::values($base);
        $scan = [];
        $files = glob(dirname($ini).'/conf.d/*.ini') ?: [];
        if (count($files) > 256) {
            throw new RuntimeException('php_settings_unavailable');
        }
        sort($files, SORT_STRING);
        $opcache = $builtInOpcache;
        foreach (array_merge([$ini], $files) as $file) {
            $content = $file === $ini ? $base : self::read($file);
            if ($file !== $ini) {
                $scan = array_replace($scan, self::values($content));
            }
            $parsed = @parse_ini_string($content, false, INI_SCANNER_RAW);
            $extension = $parsed['zend_extension'] ?? '';
            if (is_string($extension) && preg_match('~(?:^|[/\\\\])(?:php_)?opcache(?:\.(?:so|dll))?$~i', $extension)) {
                $opcache = true;
            }
        }
        $settings = array_replace($settings, $scan);
        // PHP's documented defaults, only for directives whose absence is unambiguous.
        $settings += ['disable_functions' => '', 'opcache.enable' => $opcache === null ? null : ($opcache ? 'on' : 'off')];

        return ['values' => $settings, 'scan' => $scan, 'opcache' => $opcache];
    }

    public static function refresh(PDO $db, int $server): void
    {
        $query = $db->prepare('SELECT config FROM server WHERE server_id = ?');
        $query->execute([$server]);
        $config = parse_ini_string((string) $query->fetchColumn(), true, INI_SCANNER_RAW) ?: [];
        $web = $config['web'] ?? [];
        $versions = $db->prepare('SELECT server_php_id, php_fastcgi_binary, php_fastcgi_ini_dir, php_fpm_ini_dir FROM server_php WHERE server_id = ?');
        $versions->execute([$server]);
        $rows = array_merge([[
            'server_php_id' => 0,
            'php_fastcgi_binary' => $config['fastcgi']['fastcgi_bin'] ?? '',
            'php_fastcgi_ini_dir' => $config['fastcgi']['fastcgi_phpini_path'] ?? $web['php_ini_path_cgi'] ?? '',
            'php_fpm_ini_dir' => $web['php_fpm_ini_path'] ?? '',
        ]], $versions->fetchAll(PDO::FETCH_ASSOC));
        $save = $db->prepare('INSERT INTO api_web_php_defaults (server_id, server_php_id, mode, settings, measured_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), measured_at = VALUES(measured_at)');
        foreach ($rows as $row) {
            $builtIn = self::builtInOpcache((string) ($row['php_fastcgi_binary'] ?? ''));
            foreach (['cgi' => 'php_fastcgi_ini_dir', 'fpm' => 'php_fpm_ini_dir'] as $mode => $column) {
                try {
                    $paired = dirname(rtrim((string) ($row['php_fastcgi_ini_dir'] ?? ''), '/'))
                        === dirname(rtrim((string) ($row['php_fpm_ini_dir'] ?? ''), '/'));
                    $settings = self::collect((string) ($row[$column] ?? ''), $mode === 'cgi' || $paired ? $builtIn : null);
                } catch (RuntimeException) {
                    $settings = ['values' => [], 'scan' => [], 'opcache' => false, 'unavailable' => true];
                }
                $save->execute([$server, $row['server_php_id'], $mode, json_encode($settings, JSON_THROW_ON_ERROR), time()]);
            }
        }
    }
}
