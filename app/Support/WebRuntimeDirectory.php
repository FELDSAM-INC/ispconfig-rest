<?php

namespace App\Support;

use RuntimeException;

/** Shared by the API validator and the root-owned reader on the actual web server. */
final class WebRuntimeDirectory
{
    public static function valid(string $path): bool
    {
        return strlen($path) <= 200 && ! str_contains($path, '..') && ($path === '' || preg_match('~\A[a-zA-Z0-9_-][a-zA-Z0-9_.-]*(?:/[a-zA-Z0-9_-][a-zA-Z0-9_.-]*)*\z~D', $path) === 1);
    }

    public static function check(array $site, string $subdirectory): void
    {
        if (! self::valid($subdirectory)) {
            throw new RuntimeException('runtime_directory_invalid');
        }
        $folder = ($site['type'] ?? '') === 'vhost' ? 'web' : (string) ($site['web_folder'] ?? '');
        if ($folder === '' || ! self::valid($folder)) {
            throw new RuntimeException('runtime_directory_invalid');
        }
        $base = realpath((string) ($site['document_root'] ?? ''));
        if ($base === false || ! is_dir($base)) {
            throw new RuntimeException('runtime_directory_missing');
        }
        $subdirectory = $folder.($subdirectory === '' ? '' : '/'.$subdirectory);
        $candidate = $base;
        foreach ($subdirectory === '' ? [] : explode('/', $subdirectory) as $segment) {
            $candidate .= '/'.$segment;
            clearstatcache(true, $candidate);
            // Reject even links to another folder in this website: the selected root must remain a child folder.
            if (is_link($candidate)) {
                throw new RuntimeException('runtime_directory_symlink');
            }
            if (! is_dir($candidate) || realpath($candidate) !== $candidate) {
                throw new RuntimeException('runtime_directory_missing');
            }
        }
    }
}
