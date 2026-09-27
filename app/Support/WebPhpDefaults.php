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

    private static function read(string $path): string
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
        $handle = @fopen($real, 'rb');
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

    public static function collect(string $path): array
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
        $opcache = false;
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
        $settings += ['disable_functions' => '', 'opcache.enable' => $opcache ? 'on' : 'off'];

        return ['values' => $settings, 'scan' => $scan, 'opcache' => $opcache];
    }

    public static function refresh(PDO $db, int $server): void
    {
        $query = $db->prepare('SELECT config FROM server WHERE server_id = ?');
        $query->execute([$server]);
        $config = parse_ini_string((string) $query->fetchColumn(), true, INI_SCANNER_RAW) ?: [];
        $web = $config['web'] ?? [];
        $versions = $db->prepare('SELECT server_php_id, php_fastcgi_ini_dir, php_fpm_ini_dir FROM server_php WHERE server_id = ?');
        $versions->execute([$server]);
        $rows = array_merge([[
            'server_php_id' => 0,
            'php_fastcgi_ini_dir' => $config['fastcgi']['fastcgi_phpini_path'] ?? $web['php_ini_path_cgi'] ?? '',
            'php_fpm_ini_dir' => $web['php_fpm_ini_path'] ?? '',
        ]], $versions->fetchAll(PDO::FETCH_ASSOC));
        $save = $db->prepare('INSERT INTO api_web_php_defaults (server_id, server_php_id, mode, settings, measured_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE settings = VALUES(settings), measured_at = VALUES(measured_at)');
        foreach ($rows as $row) {
            foreach (['cgi' => 'php_fastcgi_ini_dir', 'fpm' => 'php_fpm_ini_dir'] as $mode => $column) {
                try {
                    $settings = self::collect((string) ($row[$column] ?? ''));
                } catch (RuntimeException) {
                    $settings = ['values' => [], 'scan' => [], 'opcache' => false, 'unavailable' => true];
                }
                $save->execute([$server, $row['server_php_id'], $mode, json_encode($settings, JSON_THROW_ON_ERROR), time()]);
            }
        }
    }
}
