#!/bin/sh
set -eu
[ "$(id -u)" = 0 ] || { echo 'Run as root.' >&2; exit 1; }
[ -f /usr/local/ispconfig/server/lib/config.inc.php ] || { echo 'Install on an ISPConfig web server.' >&2; exit 1; }
php -r 'exit(PHP_VERSION_ID >= 80300 && PHP_INT_SIZE >= 8 && extension_loaded("pdo_mysql") && extension_loaded("mbstring") && extension_loaded("zlib") && extension_loaded("posix") ? 0 : 1);'
worker_source=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
install -d -m 0700 -o root -g root /usr/local/lib/ispconfig-rest-web-log-worker /var/lib/ispconfig-rest-web-log-worker
install -m 0600 -o root -g root "$worker_source/run.php" /usr/local/lib/ispconfig-rest-web-log-worker/
install -m 0600 -o root -g root "$worker_source/../app/Support/WebLogReader.php" /usr/local/lib/ispconfig-rest-web-log-worker/
cat > /etc/cron.d/ispconfig-rest-web-log-worker <<'CRON'
* * * * * root /usr/bin/php /usr/local/lib/ispconfig-rest-web-log-worker/run.php
CRON
chmod 0644 /etc/cron.d/ispconfig-rest-web-log-worker
echo 'Installed. The first heartbeat and log reads will be available within one minute.'
