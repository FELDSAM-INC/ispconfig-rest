<?php

namespace App\Support;

use RuntimeException;

/** Read only ISPConfig's per-domain log directory. Shared with the optional remote worker. */
final class WebLogReader
{
    public const MAX_BYTES = 262144;

    public const MAX_ARCHIVE_BYTES = 134217728;

    public function __construct(private string $root = '/var/log/ispconfig/httpd') {}

    public function available(string $domain): bool
    {
        try {
            $directory = $this->directory($domain);

            return is_readable($directory) && is_executable($directory);
        } catch (RuntimeException) {
            return false;
        }
    }

    private function directory(string $domain): string
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/iD', $domain) || str_contains($domain, '..')) {
            throw new RuntimeException('logs_unavailable');
        }
        $root = realpath($this->root);
        $path = realpath($this->root.'/'.$domain);
        // No alias to another domain's directory, even inside the log root.
        if ($root === false || $path !== $root.'/'.$domain || ! is_dir($path)) {
            throw new RuntimeException('logs_unavailable');
        }

        return $path;
    }

    private function allowed(string $file, string $kind): bool
    {
        return $kind === 'access'
            ? preg_match('/^(?:access\.log|[0-9]{8}-access\.log(?:\.gz)?)$/D', $file) === 1
            : preg_match('/^error\.log(?:\.[1-9][0-9]{0,3}(?:\.gz)?)?$/D', $file) === 1;
    }

    /** Latest file first; symlinked access.log and its dated target are a single file. */
    private function files(string $directory, string $kind): array
    {
        $files = [];
        $examined = 0;
        foreach (new \DirectoryIterator($directory) as $entry) {
            if (++$examined > 10000) {
                throw new RuntimeException('logs_unavailable');
            }
            $name = $entry->getFilename();
            if (! $this->allowed($name, $kind)) {
                continue;
            }
            $path = realpath($directory.'/'.$name);
            if ($path === false || dirname($path) !== $directory || ! $this->allowed(basename($path), $kind) || ! is_file($path)) {
                continue;
            }
            $files[$path] = ['file' => basename($path), 'path' => $path];
        }
        $files = array_values($files);
        usort($files, static function (array $a, array $b) use ($kind): int {
            $current = $kind.'.log';
            if ($a['file'] === $current) {
                return -1;
            }
            if ($b['file'] === $current) {
                return 1;
            }

            return $kind === 'access' ? strcmp($b['file'], $a['file']) : strnatcmp($a['file'], $b['file']);
        });

        return $files;
    }

    /** Cursor positions identify the exact inode and byte boundary, never a caller-provided path. */
    public function read(string $domain, string $kind, int $lines = 200, ?array $before = null): array
    {
        if (! in_array($kind, ['access', 'error'], true) || $lines < 1 || $lines > 1000) {
            throw new RuntimeException('logs_invalid');
        }
        $directory = $this->directory($domain);
        $files = $this->files($directory, $kind);
        $index = 0;
        $rotated = false;
        if ($before !== null) {
            $index = array_search($before['file'] ?? '', array_column($files, 'file'), true);
            if ($index === false) {
                $index = 0;
                $before = null;
                $rotated = true;
            }
        }
        while (isset($files[$index])) {
            $file = $files[$index];
            // Check the descriptor as well as the path to reject symlink swaps and non-regular files.
            $expected = @lstat($file['path']);
            if ($expected === false || ($expected['mode'] & 0170000) !== 0100000) {
                throw new RuntimeException('logs_unavailable');
            }
            $handle = @fopen($file['path'], 'rb');
            if ($handle === false) {
                throw new RuntimeException('logs_unavailable');
            }
            $stat = fstat($handle);
            if ($stat === false || $expected === false || ($expected['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0170000) !== 0100000 || $stat['ino'] !== $expected['ino'] || $stat['dev'] !== $expected['dev'] || realpath($file['path']) !== $file['path']) {
                fclose($handle);
                throw new RuntimeException('logs_unavailable');
            }
            $identity = $stat['dev'].':'.$stat['ino'];
            $fingerprint = '';
            // Also detect copytruncate followed by rapid regrowth (same inode, larger size).
            if ($stat['size'] > 0) {
                $fingerprint = hash('sha256', (string) fread($handle, min(128, $stat['size'])));
                rewind($handle);
            }
            $prefixLength = min(128, $stat['size']);
            if ($before !== null) {
                $length = min(128, max(0, (int) ($before['prefix_length'] ?? 0)));
                $oldPrefix = $length > 0 ? hash('sha256', (string) fread($handle, $length)) : '';
                rewind($handle);
                if (($before['identity'] ?? '') !== $identity || ($before['prefix'] ?? '') !== $oldPrefix) {
                    fclose($handle);
                    $fresh = $this->read($domain, $kind, $lines);
                    $fresh['rotated'] = true;

                    return $fresh;
                }
            }
            $temporary = null;
            try {
                if (str_ends_with($file['file'], '.gz')) {
                    $temporary = $this->expand($handle);
                    $source = $temporary;
                } else {
                    $source = $handle;
                }
                $size = fstat($source)['size'];
                $end = $before === null ? $size : min($size, max(0, (int) ($before['offset'] ?? 0)));
                if ($before !== null && (int) ($before['offset'] ?? 0) > $size) {
                    $rotated = true;
                    $end = $size;
                }
                if ($end === 0) {
                    $index++;
                    $before = null;

                    continue;
                }
                $start = $end;
                $text = '';
                while ($start > 0 && strlen($text) < self::MAX_BYTES && substr_count($text, "\n") <= $lines) {
                    $bytes = min(8192, $start, self::MAX_BYTES - strlen($text));
                    $start -= $bytes;
                    fseek($source, $start);
                    $chunk = fread($source, $bytes);
                    if ($chunk === false || strlen($chunk) !== $bytes) {
                        throw new RuntimeException('logs_rotated');
                    }
                    $text = $chunk.$text;
                }
                $truncated = false;
                // Retain whole lines only at the older boundary when the read begins within the file.
                if ($start > 0) {
                    $newline = strpos($text, "\n");
                    if ($newline !== false) {
                        $start += $newline + 1;
                        $text = substr($text, $newline + 1);
                    } else {
                        $truncated = true;
                    }
                }
                $parts = explode("\n", rtrim($text, "\n"));
                if (count($parts) > $lines) {
                    $remove = array_slice($parts, 0, count($parts) - $lines);
                    $skip = strlen(implode("\n", $remove)) + 1;
                    $start += $skip;
                    $text = substr($text, $skip);
                }
                $cursor = $start > 0 ? ['file' => $file['file'], 'identity' => $identity, 'prefix' => $fingerprint, 'prefix_length' => $prefixLength, 'offset' => $start] : null;
                // Zero offset transitions to the older file on the next request.
                if ($cursor === null && isset($files[$index + 1])) {
                    $cursor = ['file' => $file['file'], 'identity' => $identity, 'prefix' => $fingerprint, 'prefix_length' => $prefixLength, 'offset' => 0];
                }

                return ['text' => mb_convert_encoding($text, 'UTF-8', 'UTF-8'), 'file' => $file['file'], 'before' => $cursor, 'rotated' => $rotated, 'truncated' => $truncated, 'lines' => count($parts) > $lines ? $lines : count($parts)];
            } finally {
                fclose($handle);
                if (is_resource($temporary)) {
                    fclose($temporary);
                }
            }
        }

        return ['text' => '', 'file' => null, 'before' => null, 'rotated' => $rotated, 'truncated' => false, 'lines' => 0];
    }

    private function expand($handle)
    {
        $temporary = tmpfile();
        if ($temporary === false) {
            throw new RuntimeException('logs_unavailable');
        }
        $filter = @stream_filter_append($handle, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 31]);
        if ($filter === false) {
            fclose($temporary);
            throw new RuntimeException('logs_unavailable');
        }
        $bytes = 0;
        $started = microtime(true);
        try {
            while (! feof($handle)) {
                $chunk = @fread($handle, 65536);
                if ($chunk === false) {
                    throw new RuntimeException('logs_archive_invalid');
                }
                $bytes += strlen($chunk);
                if ($bytes > self::MAX_ARCHIVE_BYTES || microtime(true) - $started > 5) {
                    throw new RuntimeException('logs_archive_limit');
                }
                if (fwrite($temporary, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('logs_unavailable');
                }
            }
            rewind($temporary);

            return $temporary;
        } catch (\Throwable $e) {
            fclose($temporary);
            throw $e;
        }
    }
}
