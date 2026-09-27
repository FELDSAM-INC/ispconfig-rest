<?php

use App\Support\WordPressPolicy;

// Disposable Apache fixture: see README.md. Never run on a hosting server.
require '/app/app/Support/WordPressPolicy.php';
$policy = WordPressPolicy::compile('', WordPressPolicy::SERVER);
file_put_contents('/etc/apache2/conf-enabled/wp-test.conf', "Listen 8088\n<VirtualHost *:8088>\nDocumentRoot /tmp/wp-test\n<Directory /tmp/wp-test>\nRequire all granted\nAllowOverride All\n</Directory>\n".$policy."</VirtualHost>\n");
mkdir('/tmp/wp-test/wp-content/uploads', 0755, true);
mkdir('/tmp/wp-test/listing', 0755, true);
file_put_contents('/tmp/wp-test/.htaccess', "Require all granted\nOptions +Indexes\n");
// Try assigning a different handler to an innocent extension in the protected directory.
file_put_contents('/tmp/wp-test/wp-content/uploads/.htaccess', "SetHandler server-status\n");
foreach (['index.html', 'xmlrpc.php', 'wp-config.php', 'wp-content/uploads/test.php', 'wp-content/uploads/static.jpg', 'listing/file.txt', 'backup.zip'] as $file) {
    file_put_contents('/tmp/wp-test/'.$file, 'STATIC FIXTURE');
}
passthru('apache2ctl -t && apache2ctl start', $code);
if ($code !== 0) {
    exit($code);
}
$checks = ['/' => 200, '/xmlrpc.php' => 403, '/wp-config.php' => 403, '/wp-content/uploads/test.php' => 403, '/listing/' => 403, '/?author=1' => 403, '/?%61uthor=%31' => 403, '/backup.zip' => 403, '/wp-content/uploads/static.jpg' => 200];
foreach ($checks as $path => $expected) {
    $body = file_get_contents('http://127.0.0.1:8088'.$path, false, stream_context_create(['http' => ['ignore_errors' => true]]));
    $status = (int) explode(' ', $http_response_header[0])[1];
    if ($status !== $expected || ($expected === 200 && $body !== 'STATIC FIXTURE')) {
        fwrite(STDERR, "FAIL $path: $status\n");
        exit(1);
    }
    echo "PASS $status $path\n";
}
