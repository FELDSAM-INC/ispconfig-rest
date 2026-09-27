<?php

// Run in a disposable root container; tests the root-owned worker's real filesystem checks.
require __DIR__.'/../../app/Support/WebPhpDefaults.php';

use App\Support\WebPhpDefaults;

if (posix_geteuid() !== 0) {
    throw new RuntimeException('Root fixture required');
}
$root = '/tmp/ispcp-php-defaults-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
mkdir($root.'/conf.d', 0700);
try {
    file_put_contents($root.'/php.ini', "memory_limit=1024M\nmax_input_time=30\ndisable_functions=exec,shell_exec\ndisplay_errors=Off\n");
    file_put_contents($root.'/conf.d/10-opcache.ini', 'zend_extension=opcache.so');
    file_put_contents($root.'/conf.d/20-policy.ini', "memory_limit=512M\nlog_errors=On");
    file_put_contents($root.'/policy.ini', 'memory_limit=768M');
    symlink($root.'/policy.ini', $root.'/conf.d/99-policy.ini');
    $values = WebPhpDefaults::collect($root);
    if ($values['values']['memory_limit'] !== '768M' || $values['scan']['memory_limit'] !== '768M'
        || $values['values']['opcache.enable'] !== 'on' || ! $values['opcache']) {
        throw new RuntimeException('Incorrect scan precedence');
    }
    foreach (['writable', 'untrusted_owner', 'untrusted_link', 'oversized'] as $case) {
        chmod($root.'/php.ini', 0600);
        chown($root.'/php.ini', 0);
        chmod($root.'/policy.ini', 0600);
        if ($case === 'writable') {
            chmod($root.'/php.ini', 0666);
        }
        if ($case === 'untrusted_owner') {
            chown($root.'/php.ini', 65534);
        }
        if ($case === 'untrusted_link') {
            chmod($root.'/policy.ini', 0666);
        }
        if ($case === 'oversized') {
            file_put_contents($root.'/php.ini', str_repeat('x', 1048577));
        }
        $refused = false;
        try {
            WebPhpDefaults::collect($root);
        } catch (RuntimeException $e) {
            $refused = true;
        }
        if (! $refused) {
            throw new RuntimeException('Unsafe configuration accepted: '.$case);
        }
    }
    echo "PASS root-owned INI, scan precedence, safe links, ownership, write permissions and size bounds\n";
} finally {
    foreach (glob($root.'/conf.d/*') as $file) {
        unlink($file);
    }
    rmdir($root.'/conf.d');
    foreach (glob($root.'/*') as $file) {
        unlink($file);
    }
    rmdir($root);
}
