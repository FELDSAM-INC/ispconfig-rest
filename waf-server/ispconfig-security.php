<?php

use App\Support\WebWafIspconfigSecurity;

if (PHP_SAPI !== 'cli' || posix_geteuid() !== 0) {
    exit(1);
}
require __DIR__.'/../app/Support/WebWafIspconfigSecurity.php';
umask(0077);
$directory = '/usr/local/ispconfig/security';

function trusted(string $path, bool $directory = false): array
{
    $stat = @lstat($path);
    if (! $stat || ($stat['mode'] & 0170000) !== ($directory ? 0040000 : 0100000) || $stat['uid'] !== 0 || ($stat['mode'] & 0022) !== 0) {
        throw new RuntimeException('ISPConfig security paths must be root-owned, not symlinked or group/other writable: '.$path);
    }

    return $stat;
}

try {
    for ($parent = $directory; $parent !== '/'; $parent = dirname($parent)) {
        trusted($parent, true);
    }
    $lockPath = $directory.'/.ispcp-waf.lock';
    if (file_exists($lockPath) || is_link($lockPath)) {
        trusted($lockPath);
    }
    $lock = fopen($lockPath, 'c');
    if (! $lock || ! flock($lock, LOCK_EX)) {
        throw new RuntimeException('Could not lock the ISPConfig security configuration.');
    }
    $vendorFile = $directory.'/apache_directives.blacklist';
    $target = $vendorFile.'.custom';
    $stat = trusted($vendorFile);
    $vendor = file_get_contents($vendorFile);
    $existing = null;
    if (file_exists($target) || is_link($target)) {
        trusted($target);
        $existing = file_get_contents($target);
    }
    $content = WebWafIspconfigSecurity::merge($vendor, $existing ?? '');
    if ($content === $existing) {
        echo "ISPConfig WAF include exceptions already configured.\n";
        exit(0);
    }
    // Keep a first-install snapshot; do not overwrite an administrator's backup.
    $backup = $target.'.before-ispcp-waf';
    if (! file_exists($backup) && ! is_link($backup)) {
        $handle = fopen($backup, 'x');
        $original = $existing ?? $vendor;
        if (! $handle || fwrite($handle, $original) !== strlen($original)) {
            throw new RuntimeException('Could not back up the ISPConfig directive blacklist.');
        }
        fclose($handle);
    }
    $temp = $target.'.'.bin2hex(random_bytes(8));
    if (file_put_contents($temp, $content) !== strlen($content) || ! chgrp($temp, $stat['gid']) || ! chmod($temp, 0640) || ! rename($temp, $target)) {
        throw new RuntimeException('Could not install the ISPConfig directive blacklist.');
    }
    echo "ISPConfig permits only the three exact WAF includes; other blacklist rules and directive scanning settings are preserved.\n";
} catch (Throwable $e) {
    if (isset($temp) && is_file($temp)) {
        unlink($temp);
    }
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
