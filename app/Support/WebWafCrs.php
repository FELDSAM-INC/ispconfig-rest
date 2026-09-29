<?php

namespace App\Support;

use RuntimeException;

/**
 * Upstream OWASP CRS pinned by this release (waf-server/crs.json): the signed CRS LTS archive plus the official rule
 * exclusion plugins behind the application profiles. Runs as root on the webserver from configure.php, next to the WAF
 * tools, so it must not depend on Laravel. Every download is verified against the pins before it is installed.
 */
final class WebWafCrs
{
    /** Initial /etc/ispconfig-waf/crs-setup.local.conf. The administrator owns this file; updates keep it. */
    public const LOCAL_SETUP = <<<'CONF'
# Local OWASP CRS settings for every managed website, loaded after the managed crs-setup.conf.
# WAF updates keep this file. Use the setting IDs documented in crs-setup.conf, for example:
# SecAction "id:900000,phase:1,pass,t:none,nolog,setvar:tx.blocking_paranoia_level=2"
# Application profiles are selected per website; plugin switches set here have no effect.

CONF;

    /**
     * CRS 4.25.1 rule 901181 removes XML attribute targets with `ctl:ruleRemoveTargetByTag=...;XML://@*`, which
     * libmodsecurity before 3.0.16 cannot parse. Dropping those actions keeps XML attribute inspection on, which is what
     * ModSecurity 2 does with them anyway (CRS 4.25.1 CHANGES.md).
     */
    public const PATCH_XML_ATTRIBUTES = 'xml-attribute-ctl';

    private const MARKER = '.ispconfig-crs.json';

    /**
     * The manifest, validated strictly: the official release archive and signature, SHA-256 pins, the release key
     * fingerprint, and exactly the plugins of the application profiles.
     *
     * @return array{version: string, archive: string, sha256: string, signature: string, fingerprint: string, plugins: array<string, array{name: string, version: string, files: array<string, string>}>}
     */
    public static function manifest(string $json): array
    {
        $data = json_decode($json, true);
        if (! is_array($data) || ! is_string($data['version'] ?? null) || ! preg_match('/\A4\.[0-9]{1,3}\.[0-9]{1,3}\z/D', $data['version'])) {
            throw new RuntimeException('Invalid CRS manifest version.');
        }
        $version = $data['version'];
        $release = 'https://github.com/coreruleset/coreruleset/releases/download/v'.$version.'/coreruleset-'.$version.'-minimal.tar.gz';
        if (($data['archive'] ?? null) !== $release || ($data['signature'] ?? null) !== $release.'.asc'
            || ! self::hash($data['sha256'] ?? null) || ! is_string($data['fingerprint'] ?? null) || ! preg_match('/\A[0-9A-F]{40}\z/D', $data['fingerprint'])) {
            throw new RuntimeException('Invalid CRS manifest archive.');
        }
        $plugins = $data['plugins'] ?? null;
        if (! is_array($plugins) || array_keys($plugins) !== array_keys(WebWafProfiles::PLUGINS)) {
            throw new RuntimeException('The CRS manifest must pin exactly the application profile plugins.');
        }
        foreach (WebWafProfiles::PLUGINS as $profile => $name) {
            $plugin = $plugins[$profile];
            if (! is_array($plugin) || ($plugin['name'] ?? null) !== $name || ! is_string($plugin['version'] ?? null)
                || ! preg_match('/\A[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\z/D', $plugin['version'])) {
                throw new RuntimeException('Invalid CRS manifest plugin '.$profile.'.');
            }
            $files = $plugin['files'] ?? null;
            if (! is_array($files) || array_keys($files) !== [$name.'-before.conf', $name.'-config.conf']
                || count(array_filter($files, [self::class, 'hash'])) !== 2) {
                throw new RuntimeException('Invalid CRS manifest plugin files for '.$profile.'.');
            }
        }

        return $data;
    }

    public static function pluginUrl(string $name, string $version, string $file): string
    {
        return 'https://raw.githubusercontent.com/coreruleset/'.$name.'-plugin/v'.$version.'/plugins/'.$file;
    }

    /** Whether gpgv's machine-readable status reports a good signature by the pinned key, and nothing bad. */
    public static function signatureValid(string $status, string $fingerprint): bool
    {
        if (preg_match('/^\[GNUPG:\] (?:BADSIG|ERRSIG|NO_PUBKEY|EXPKEYSIG|REVKEYSIG)\b/m', $status)) {
            return false;
        }

        return preg_match('/^\[GNUPG:\] GOODSIG [0-9A-F]{16} /m', $status) === 1
            && preg_match('/^\[GNUPG:\] VALIDSIG '.preg_quote($fingerprint, '/').' /m', $status) === 1;
    }

    /** Why an extracted tree is unsafe to install, or null: only directories and bounded regular files. */
    public static function unsafeTree(string $directory, int $maxFile = 4194304, int $maxTotal = 67108864): ?string
    {
        $total = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($files as $path => $info) {
            if ($info->isLink()) {
                return 'link '.basename($path);
            }
            if ($info->isDir()) {
                continue;
            }
            if (! $info->isFile()) {
                return 'special file '.basename($path);
            }
            if ($info->getSize() > $maxFile) {
                return 'oversized file '.basename($path);
            }
            $total += $info->getSize();
            if ($total > $maxTotal) {
                return 'oversized archive';
            }
        }

        return null;
    }

    /** The managed crs-setup.conf: the release's reference setup with CRS defaults, unchanged. */
    public static function setup(string $example, string $version): string
    {
        if (! preg_match('/^[ \t]+setvar:tx\.crs_setup_version=[0-9]+"$/m', $example)) {
            throw new RuntimeException('The CRS reference setup is incomplete.');
        }

        return "# Managed by ispconfig-rest from OWASP CRS ".$version." crs-setup.conf.example; WAF updates replace it.\n"
            ."# Put local settings in /etc/ispconfig-waf/crs-setup.local.conf.\n\n".$example;
    }

    /** Applies the engine compatibility patches to an extracted, verified release. */
    public static function patch(string $crs, array $patches): void
    {
        foreach ($patches as $patch) {
            if ($patch !== self::PATCH_XML_ATTRIBUTES) {
                throw new RuntimeException('Unknown CRS patch '.$patch.'.');
            }
            $file = $crs.'/rules/REQUEST-901-INITIALIZATION.conf';
            $patched = preg_replace('/^[ \t]+ctl:ruleRemoveTargetByTag=[a-z-]+;XML:\/\/@\*,\\\\\n/m', '', (string) file_get_contents($file), -1, $count);
            if (! $count || str_contains($patched, ';XML://@*')) {
                throw new RuntimeException('The CRS XML attribute compatibility patch does not apply to this release.');
            }
            file_put_contents($file, $patched);
        }
    }

    /**
     * Installs the pinned release under $root/<version> unless an intact copy is already there, and returns its path.
     * $fetch(url, file) downloads one URL; tests substitute it.
     */
    public static function install(array $manifest, string $keyring, array $patches = [], string $root = WebWafProfiles::ROOT, ?callable $fetch = null): string
    {
        $version = $manifest['version'];
        $target = $root.'/'.$version;
        $manifest['patches'] = array_values($patches);
        if (self::intact($target, $manifest)) {
            return $target;
        }
        $fetch ??= [self::class, 'download'];
        self::directory(dirname($root));
        self::directory($root);
        $work = $root.'/.staging-'.bin2hex(random_bytes(6));
        mkdir($work, 0700);
        try {
            $archive = $work.'/crs.tar.gz';
            $fetch($manifest['archive'], $archive);
            if (! is_file($archive) || hash_file('sha256', $archive) !== $manifest['sha256']) {
                throw new RuntimeException('The downloaded OWASP CRS archive does not match the pinned checksum.');
            }
            $fetch($manifest['signature'], $archive.'.asc');
            mkdir($work.'/gnupg', 0700);
            [, $status] = self::run(['gpgv', '--homedir', $work.'/gnupg', '--status-fd', '1', '--keyring', $keyring, $archive.'.asc', $archive]);
            if (! self::signatureValid($status, $manifest['fingerprint'])) {
                throw new RuntimeException('The OWASP CRS archive signature is not valid for the pinned release key.');
            }
            mkdir($work.'/extract', 0700);
            [$code] = self::run(['tar', '-xzf', $archive, '-C', $work.'/extract', '--no-same-owner', '--no-same-permissions']);
            $unsafe = $code === 0 ? self::unsafeTree($work.'/extract') : 'extraction failed';
            $crs = $work.'/extract/coreruleset-'.$version;
            if ($unsafe !== null || array_values(array_diff(scandir($work.'/extract'), ['.', '..'])) !== ['coreruleset-'.$version]
                || ! is_file($crs.'/crs-setup.conf.example') || ! is_file($crs.'/rules/REQUEST-901-INITIALIZATION.conf')
                || ! is_file($crs.'/plugins/empty-after.conf')) {
                throw new RuntimeException('Unexpected OWASP CRS archive content'.($unsafe !== null ? ': '.$unsafe : '').'.');
            }
            foreach ($manifest['plugins'] as $plugin) {
                foreach ($plugin['files'] as $file => $hash) {
                    $path = $crs.'/plugins/'.$file;
                    if (file_exists($path) || is_link($path)) {
                        throw new RuntimeException('Unexpected OWASP CRS archive content.');
                    }
                    $fetch(self::pluginUrl($plugin['name'], $plugin['version'], $file), $path);
                    if (! is_file($path) || is_link($path) || hash_file('sha256', $path) !== $hash) {
                        throw new RuntimeException('The downloaded CRS plugin '.$plugin['name'].' does not match the pinned checksum.');
                    }
                }
            }
            self::patch($crs, $manifest['patches']);
            file_put_contents($crs.'/'.self::MARKER, self::marker($manifest));
            self::normalize($crs);
            if (is_link($target) || is_file($target)) {
                unlink($target);
            } elseif (is_dir($target)) {
                // An incomplete or modified copy; the pins decide what is installed.
                self::remove($target);
            }
            if (! rename($crs, $target)) {
                throw new RuntimeException('Could not install OWASP CRS.');
            }
        } finally {
            self::remove($work);
        }

        return $target;
    }

    /** Points $root/current at an installed version atomically; returns the previous target or null. */
    public static function activate(string $version, string $root = WebWafProfiles::ROOT): ?string
    {
        $link = $root.'/current';
        if (file_exists($link) && ! is_link($link)) {
            throw new RuntimeException('Refusing to replace '.$link.'.');
        }
        $previous = is_link($link) ? readlink($link) : null;
        $temp = $root.'/.current-'.bin2hex(random_bytes(6));
        if (! symlink($version, $temp) || ! rename($temp, $link)) {
            @unlink($temp);
            throw new RuntimeException('Could not activate OWASP CRS '.$version.'.');
        }

        return $previous === false ? null : $previous;
    }

    /** Removes versions other than the active one and interrupted downloads, after the active one was accepted. */
    public static function prune(string $root = WebWafProfiles::ROOT): void
    {
        $active = is_link($root.'/current') ? readlink($root.'/current') : null;
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry !== $active && (preg_match('/\A4\.[0-9]{1,3}\.[0-9]{1,3}\z/D', $entry) || str_starts_with($entry, '.staging-') || str_starts_with($entry, '.current-'))) {
                is_dir($root.'/'.$entry) && ! is_link($root.'/'.$entry) ? self::remove($root.'/'.$entry) : unlink($root.'/'.$entry);
            }
        }
    }

    /** Whether $directory holds this manifest's release, with these patches and unmodified plugin files. */
    public static function intact(string $directory, array $manifest, ?array $patches = null): bool
    {
        if ($patches !== null) {
            $manifest['patches'] = array_values($patches);
        }
        if (is_link($directory) || ! is_dir($directory) || @file_get_contents($directory.'/'.self::MARKER) !== self::marker($manifest)) {
            return false;
        }
        foreach ($manifest['plugins'] as $plugin) {
            foreach ($plugin['files'] as $file => $hash) {
                $path = $directory.'/plugins/'.$file;
                if (is_link($path) || ! is_file($path) || hash_file('sha256', $path) !== $hash) {
                    return false;
                }
            }
        }

        return is_file($directory.'/rules/REQUEST-901-INITIALIZATION.conf');
    }

    public static function download(string $url, string $file): void
    {
        [$code] = self::run(['curl', '--proto', '=https', '--proto-redir', '=https', '--tlsv1.2', '--fail', '--silent', '--show-error',
            '--location', '--max-redirs', '5', '--connect-timeout', '20', '--max-time', '300', '--max-filesize', '33554432', '--output', $file, $url]);
        if ($code !== 0) {
            throw new RuntimeException('Could not download '.$url.'. The webserver needs HTTPS access to github.com.');
        }
    }

    private static function marker(array $manifest): string
    {
        $plugins = array_map(static fn (array $plugin) => ['name' => $plugin['name'], 'version' => $plugin['version'], 'files' => $plugin['files']], $manifest['plugins']);

        return json_encode(['version' => $manifest['version'], 'sha256' => $manifest['sha256'], 'plugins' => $plugins,
            'patches' => $manifest['patches'] ?? []], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /** Root-owned where running as root; readable by the web server, writable only by root. */
    private static function normalize(string $directory): void
    {
        $root = function_exists('posix_geteuid') && posix_geteuid() === 0;
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ([$directory => new \SplFileInfo($directory), ...iterator_to_array($items)] as $path => $info) {
            chmod($path, $info->isDir() ? 0755 : 0644);
            if ($root) {
                chown($path, 0);
                chgrp($path, 0);
            }
        }
    }

    /** Creates one level if missing. As root, refuses a location that another user could replace. */
    private static function directory(string $path): void
    {
        if (! is_dir($path)) {
            if (! mkdir($path, 0755) && ! is_dir($path)) {
                throw new RuntimeException('Could not create '.$path.'.');
            }
            chmod($path, 0755);
        }
        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }
        for ($dir = $path; $dir !== '/'; $dir = dirname($dir)) {
            $stat = lstat($dir);
            if (! $stat || ($stat['mode'] & 0170000) !== 0040000 || $stat['uid'] !== 0 || ($stat['mode'] & 0022) !== 0) {
                throw new RuntimeException('Refusing the CRS location: '.$dir.' must be a root-owned directory without group/other write access.');
            }
        }
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item => $info) {
            $info->isDir() && ! $info->isLink() ? rmdir($item) : unlink($item);
        }
        rmdir($path);
    }

    /** @return array{int, string} exit code and standard output; standard error is discarded */
    private static function run(array $command): array
    {
        $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not run '.$command[0].'.');
        }
        $output = (string) stream_get_contents($pipes[1], 1048576);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }

    private static function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
    }
}
