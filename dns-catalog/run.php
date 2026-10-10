<?php

declare(strict_types=1);

use App\Support\DnsCatalog;

// Root tool of the ISPConfig DNS server (spec 055): keeps the catalog zone equal to the primary zones ISPConfig writes.
if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    fwrite(STDERR, "Run ispconfig-dns-catalog as root.\n");
    exit(1);
}
umask(0022);
require is_file(__DIR__.'/DnsCatalog.php') ? __DIR__.'/DnsCatalog.php' : __DIR__.'/../app/Support/DnsCatalog.php';

const CONFIG = '/etc/ispconfig-dns-catalog/config.json';
const NAMED = '/etc/bind/named.conf';
const LOCAL = '/etc/bind/named.conf.local';
const OPTIONS = '/etc/bind/named.conf.options';
// ISPConfig rewrites named.conf.options from this template when an update reconfigures services.
const CUSTOM = '/usr/local/ispconfig/server/conf-custom/install/named.conf.options.master';

/** @return array{int, string} exit code and output (standard error included) */
function command(array $argv): array
{
    $process = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        return [127, ''];
    }
    $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $output];
}

function put(string $file, string $content, int $mode, ?string $group = null): void
{
    if (is_link($file)) {
        throw new RuntimeException('Refusing to replace the link '.$file.'.');
    }
    $temp = $file.'.'.bin2hex(random_bytes(6));
    if (file_put_contents($temp, $content) !== strlen($content)) {
        @unlink($temp);
        throw new RuntimeException('Could not write '.$file.'.');
    }
    chmod($temp, $mode);
    if ($group !== null) {
        chgrp($temp, $group);
    }
    rename($temp, $file);
}

/** @return array{catalog: string, key: string, notify: string[]} */
function settings(): array
{
    $data = is_file(CONFIG) ? json_decode((string) file_get_contents(CONFIG), true) : null;
    if (! is_array($data) || DnsCatalog::name((string) ($data['catalog'] ?? '')) === null || DnsCatalog::name((string) ($data['key'] ?? '')) === null
        || ! is_array($data['notify'] ?? null) || array_filter($data['notify'], static fn ($ip) => ! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) !== []) {
        throw new RuntimeException('Not installed or '.CONFIG.' is invalid. Run the dns-catalog installer.');
    }

    return ['catalog' => DnsCatalog::name($data['catalog']), 'key' => DnsCatalog::name($data['key']), 'notify' => array_values($data['notify'])];
}

function save(array $settings): void
{
    put(CONFIG, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", 0600);
}

/** Rewrites the catalog zone when its membership differs from named.conf.local. */
function sync(array $settings, bool $reload = true): string
{
    [$code, $local] = command(['named-checkconf', '-p', LOCAL]);
    if ($code !== 0) {
        throw new RuntimeException('named-checkconf could not read '.LOCAL.'.');
    }
    $zones = DnsCatalog::zones($local, $settings['catalog']);
    $current = is_file(DnsCatalog::ZONE_FILE) ? DnsCatalog::current((string) file_get_contents(DnsCatalog::ZONE_FILE)) : ['serial' => null, 'members' => []];
    if ($current['serial'] !== null && $current['members'] === $zones['members']) {
        return 'Catalog unchanged: '.count($zones['members'])." member zones.\n";
    }
    $text = DnsCatalog::render($settings['catalog'], $zones['members'], DnsCatalog::nextSerial($current['serial'], time()));
    $temp = DnsCatalog::ZONE_FILE.'.'.bin2hex(random_bytes(6));
    put($temp, $text, 0644, 'bind');
    [$code] = command(['named-checkzone', '-q', $settings['catalog'], $temp]);
    if ($code !== 0) {
        unlink($temp);
        throw new RuntimeException('The generated catalog zone did not pass named-checkzone.');
    }
    rename($temp, DnsCatalog::ZONE_FILE);
    if ($reload) {
        [$code, $output] = command(['rndc', 'reload', $settings['catalog']]);
        if ($code !== 0) {
            throw new RuntimeException('rndc reload of the catalog zone failed: '.trim($output));
        }
    }

    return 'Catalog updated: '.count($zones['members']).' member zones'.($zones['excluded'] !== [] ? ', '.count($zones['excluded']).' left out for their own transfer list' : '').".\n";
}

/** @return array<string, array> paths and their content or absence, for restoring a rejected configuration */
function snapshot(array $files): array
{
    $saved = [];
    foreach ($files as $file) {
        $saved[$file] = is_file($file) ? ['content' => file_get_contents($file), 'mode' => fileperms($file) & 0777, 'group' => filegroup($file)] : null;
    }

    return $saved;
}

function restore(array $saved): void
{
    foreach ($saved as $file => $state) {
        if ($state === null) {
            @unlink($file);
        } else {
            put($file, $state['content'], $state['mode']);
            @chgrp($file, $state['group']);
        }
    }
}

function reconfigure(): void
{
    [$code, $output] = command(['named-checkconf', NAMED]);
    if ($code !== 0) {
        throw new RuntimeException('named-checkconf refused the configuration: '.trim($output));
    }
    [$code, $output] = command(['rndc', 'reconfig']);
    if ($code !== 0) {
        throw new RuntimeException('rndc reconfig failed: '.trim($output));
    }
}

function hostname(): string
{
    [$code, $output] = command(['hostname', '-f']);
    $name = $code === 0 ? DnsCatalog::name(trim($output)) : null;
    if ($name === null) {
        throw new RuntimeException('The server has no usable fully qualified hostname; pass --catalog NAME.');
    }

    return $name;
}

/** @return array{name: string, algorithm: string, secret: string} */
function tsigKey(): array
{
    $text = is_file(DnsCatalog::KEY_FILE) ? (string) file_get_contents(DnsCatalog::KEY_FILE) : '';
    if (preg_match('/key "([^"]+)" \{\s*algorithm ([a-z0-9-]+);\s*secret "([A-Za-z0-9+\/=]+)";\s*\};/', $text, $match) !== 1) {
        throw new RuntimeException('The TSIG key file '.DnsCatalog::KEY_FILE.' is missing or invalid.');
    }

    return ['name' => rtrim($match[1], '.'), 'algorithm' => $match[2], 'secret' => $match[3]];
}

$lock = fopen('/run/ispconfig-dns-catalog.lock', 'c');
if (! $lock || ! flock($lock, LOCK_EX)) {
    fwrite(STDERR, "Another ispconfig-dns-catalog run holds the lock.\n");
    exit(1);
}
$action = $argv[1] ?? 'sync';
try {
    if ($action === 'install') {
        [, $version] = command(['named', '-v']);
        if (preg_match('/BIND ([0-9]+\.[0-9]+)/', $version, $match) !== 1 || version_compare($match[1], '9.18', '<')) {
            throw new RuntimeException('Catalog zones (schema version 2) need BIND 9.18 or newer.');
        }
        $requested = ($argv[2] ?? '') === '--catalog' ? DnsCatalog::name((string) ($argv[3] ?? '')) : null;
        if (($argv[2] ?? '') === '--catalog' && $requested === null) {
            throw new RuntimeException('Invalid catalog zone name.');
        }
        $settings = is_file(CONFIG) ? settings() : null;
        if ($settings === null) {
            $host = hostname();
            $settings = ['catalog' => $requested ?? 'catalog.'.$host.'.invalid', 'key' => 'ispcp-catalog.'.$host, 'notify' => []];
        } elseif ($requested !== null && $requested !== $settings['catalog']) {
            throw new RuntimeException('The catalog zone is '.$settings['catalog'].'; renaming it would make secondaries drop every zone.');
        }
        $saved = snapshot([CONFIG, NAMED, OPTIONS, CUSTOM, DnsCatalog::CONF_FILE, DnsCatalog::KEY_FILE, DnsCatalog::ZONE_FILE]);
        try {
            if (! is_file(DnsCatalog::KEY_FILE)) {
                [$code, $key] = command(['tsig-keygen', '-a', 'hmac-sha256', $settings['key']]);
                if ($code !== 0) {
                    throw new RuntimeException('tsig-keygen failed.');
                }
                put(DnsCatalog::KEY_FILE, $key, 0640, 'bind');
            }
            if (tsigKey()['name'] !== $settings['key']) {
                throw new RuntimeException(DnsCatalog::KEY_FILE.' holds another key than '.$settings['key'].'.');
            }
            save($settings);
            put(DnsCatalog::CONF_FILE, DnsCatalog::configuration($settings['catalog'], $settings['key'], $settings['notify']), 0644, 'bind');
            sync($settings, false);
            $named = DnsCatalog::withInclude((string) file_get_contents(NAMED));
            if ($named !== null) {
                put(NAMED, $named, fileperms(NAMED) & 0777, 'bind');
            }
            $options = DnsCatalog::allowTransfer((string) file_get_contents(OPTIONS), $settings['key']);
            if ($options !== null) {
                put(OPTIONS, $options, fileperms(OPTIONS) & 0777, 'bind');
            }
            // Keep the transfer permission when an ISPConfig update rewrites named.conf.options.
            if (is_file(CUSTOM)) {
                $custom = DnsCatalog::allowTransfer((string) file_get_contents(CUSTOM), $settings['key']);
                if ($custom !== null) {
                    put(CUSTOM, $custom, 0644);
                }
            } else {
                if (! is_dir(dirname(CUSTOM))) {
                    mkdir(dirname(CUSTOM), 0755, true);
                }
                put(CUSTOM, (string) file_get_contents(OPTIONS), 0644);
            }
            reconfigure();
        } catch (Throwable $e) {
            restore($saved);
            command(['rndc', 'reconfig']);
            throw $e;
        }
        echo 'Catalog zone '.$settings['catalog'].' is served. Transfers need the TSIG key '.$settings['key'].".\n";
        echo "Set up a secondary with dns-catalog/README.md; ispconfig-dns-catalog export prints the key.\n";
    } elseif ($action === 'sync') {
        echo sync(settings());
    } elseif ($action === 'status') {
        $settings = settings();
        [, $local] = command(['named-checkconf', '-p', LOCAL]);
        $zones = DnsCatalog::zones($local, $settings['catalog']);
        $current = is_file(DnsCatalog::ZONE_FILE) ? DnsCatalog::current((string) file_get_contents(DnsCatalog::ZONE_FILE)) : ['serial' => null, 'members' => []];
        [, $effective] = command(['named-checkconf', '-p', NAMED]);
        echo json_encode([
            'catalog' => $settings['catalog'], 'key' => $settings['key'], 'notify' => $settings['notify'], 'serial' => $current['serial'],
            'members' => $current['members'], 'in_sync' => $current['members'] === $zones['members'],
            'left_out_for_own_transfer_list' => $zones['excluded'],
            'transfers_allowed' => str_contains($effective, 'key "'.$settings['key'].'";'),
            'kept_by_ispconfig_updates' => is_file(CUSTOM) && str_contains((string) file_get_contents(CUSTOM), 'key "'.$settings['key'].'";'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    } elseif ($action === 'export') {
        // For setting up secondaries: contains the TSIG secret, root only.
        $settings = settings();
        echo json_encode(['catalog' => $settings['catalog'], 'key' => tsigKey(), 'notify' => $settings['notify']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    } elseif ($action === 'secondary' && in_array($argv[2] ?? '', ['add', 'remove'], true)) {
        $settings = settings();
        $address = (string) ($argv[3] ?? '');
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('Give the secondary as an IPv4 or IPv6 address.');
        }
        $known = in_array($address, $settings['notify'], true);
        if (($argv[2] === 'add') === $known) {
            echo $known ? "Already registered.\n" : "Not registered.\n";
        } else {
            $settings['notify'] = $argv[2] === 'add' ? [...$settings['notify'], $address] : array_values(array_diff($settings['notify'], [$address]));
            $saved = snapshot([CONFIG, DnsCatalog::CONF_FILE, OPTIONS, CUSTOM]);
            try {
                save($settings);
                put(DnsCatalog::CONF_FILE, DnsCatalog::configuration($settings['catalog'], $settings['key'], $settings['notify']), 0644, 'bind');
                // Member zones notify their NS hosts; the managed global list adds the registered secondaries.
                $own = false;
                foreach ([OPTIONS, CUSTOM] as $file) {
                    $options = is_file($file) ? DnsCatalog::alsoNotify((string) file_get_contents($file), $settings['notify'], $settings['key']) : null;
                    $own = $own || $options === false;
                    if (is_string($options)) {
                        put($file, $options, fileperms($file) & 0777, $file === OPTIONS ? 'bind' : null);
                    }
                }
                reconfigure();
            } catch (Throwable $e) {
                restore($saved);
                command(['rndc', 'reconfig']);
                throw $e;
            }
            echo $argv[2] === 'add' ? "Added: the secondary is notified of changes to the catalog and its zones.\n" : "Removed.\n";
            if ($own) {
                echo "named.conf.options has its own also-notify list: add the secondary there for member zone changes.\n";
            }
        }
    } else {
        throw new RuntimeException('Usage: ispconfig-dns-catalog [sync|status|export|secondary add IP|secondary remove IP]');
    }
} catch (Throwable $e) {
    fwrite(STDERR, ($e instanceof RuntimeException ? $e->getMessage() : 'ispconfig-dns-catalog failed.')."\n");
    exit(1);
}
