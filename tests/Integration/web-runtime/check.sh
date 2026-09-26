#!/bin/sh
set -eu
mkdir -p /var/www/runtime/web/public /run/php
cat > /var/www/runtime/web/public/index.php <<'PHP'
<?php
$result = ['root'=>$_SERVER['DOCUMENT_ROOT'], 'script'=>$_SERVER['SCRIPT_FILENAME'], 'env'=>[]];
foreach (array_keys(json_decode(file_get_contents(dirname(__DIR__, 2).'/expected.json'), true)) as $key) { $result['env'][$key] = getenv($key); }
header('Content-Type: application/json'); echo json_encode($result);
PHP
cp /out/expected.json /var/www/runtime/expected.json
printf 'outside' > /var/www/runtime/web/base.txt
printf 'inside' > /var/www/runtime/web/public/child.txt
cat /out/apache.conf /out/apache-chroot.conf > /etc/apache2/sites-available/runtime.conf
printf 'Listen 8080\nListen 8082\n' > /etc/apache2/ports.conf
a2dissite 000-default >/dev/null
a2ensite runtime >/dev/null
a2enmod proxy proxy_fcgi setenvif >/dev/null
cat /out/nginx.conf /out/nginx-chroot.conf > /etc/nginx/conf.d/runtime.conf
cp /app/web-log-worker/nginx-runtime.conf /etc/nginx/conf.d/ispcp-runtime.conf
rm /etc/nginx/sites-enabled/default
sed -i 's|^listen = .*|listen = 127.0.0.1:9000|' /etc/php/8.4/fpm/pool.d/www.conf
cat > /etc/php/8.4/fpm/pool.d/runtime.conf <<'POOL'
[runtime_chroot]
user = www-data
group = www-data
listen = 127.0.0.1:9001
chroot = /var/www/runtime
pm = ondemand
pm.max_children = 2
POOL
php-fpm8.4 -D
apache2ctl configtest
nginx -t
apache2ctl start
nginx
for port in 8080 8081 8082 8083; do
    curl -fsS http://127.0.0.1:$port/index.php > /out/result-$port.json
    [ "$(curl -fsS http://127.0.0.1:$port/child.txt)" = inside ]
    [ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:$port/base.txt)" = 404 ]
done
php -r '$expected=json_decode(file_get_contents("/out/expected.json"),true); foreach([8080,8081,8082,8083] as $port){$actual=json_decode(file_get_contents("/out/result-$port.json"),true); if($actual["env"]!==$expected || $actual["root"]!==($port < 8082 ? "/var/www/runtime/web/public" : "/web/public")){fwrite(STDERR,"FAIL $port: ".json_encode($actual)."\n");exit(1);}echo "PASS $port: real PHP-FPM receives literal environment and public subfolder root\n";}'
