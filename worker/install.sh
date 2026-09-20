#!/bin/sh
set -eu
[ "$(id -u)" = 0 ] || { echo 'Run as root.' >&2; exit 1; }
[ -f /usr/local/ispconfig/server/lib/mysql_clientdb.conf ] || { echo 'ISPConfig database credentials are missing.' >&2; exit 1; }
for program in php mysql mysqldump setpriv; do command -v "$program" >/dev/null; done
php -r 'exit(PHP_VERSION_ID >= 80300 && PHP_INT_SIZE >= 8 && extension_loaded("pdo_mysql") && extension_loaded("posix") ? 0 : 1);'
getent passwd ispcp-dbworker >/dev/null || useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin ispcp-dbworker
install -d -m 0700 -o root -g root /usr/local/lib/ispconfig-rest-database-worker
install -d -m 0711 -o root -g root /var/lib/ispconfig-rest-database-worker
worker_source=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
for file in run.php DatabaseWorker.php SqlDump.php; do
    install -m 0600 -o root -g root "$worker_source/$file" /usr/local/lib/ispconfig-rest-database-worker/
done
cat > /etc/cron.d/ispconfig-rest-database-worker <<'CRON'
* * * * * root /usr/bin/php /usr/local/lib/ispconfig-rest-database-worker/run.php
CRON
chmod 0644 /etc/cron.d/ispconfig-rest-database-worker
touch /var/log/ispconfig-rest-database-worker.log
chown root:root /var/log/ispconfig-rest-database-worker.log
chmod 0600 /var/log/ispconfig-rest-database-worker.log
cat > /etc/logrotate.d/ispconfig-rest-database-worker <<'ROTATE'
/var/log/ispconfig-rest-database-worker.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    create 0600 root root
}
ROTATE
php /usr/local/lib/ispconfig-rest-database-worker/run.php
