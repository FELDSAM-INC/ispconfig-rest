#!/bin/sh
set -eu
[ "$(id -u)" = 0 ] || { echo 'Run as root.' >&2; exit 1; }
[ -f /usr/local/ispconfig/server/lib/config.inc.php ] || { echo 'Install on an ISPConfig web server.' >&2; exit 1; }
php -r 'exit(PHP_VERSION_ID >= 80300 && PHP_INT_SIZE >= 8 && extension_loaded("pdo_mysql") && extension_loaded("mbstring") && extension_loaded("zlib") && extension_loaded("posix") ? 0 : 1);'
worker_source=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
install -d -m 0700 -o root -g root /usr/local/lib/ispconfig-rest-web-log-worker /var/lib/ispconfig-rest-web-log-worker
rm -f /usr/local/lib/ispconfig-rest-web-log-worker/runtime-ready
install -m 0600 -o root -g root "$worker_source/run.php" /usr/local/lib/ispconfig-rest-web-log-worker/
install -m 0600 -o root -g root "$worker_source/../app/Support/WebLogReader.php" /usr/local/lib/ispconfig-rest-web-log-worker/
install -m 0600 -o root -g root "$worker_source/../app/Support/WebRuntimeDirectory.php" /usr/local/lib/ispconfig-rest-web-log-worker/
install -m 0600 -o root -g root "$worker_source/nginx-runtime.conf" /usr/local/lib/ispconfig-rest-web-log-worker/
if [ -L /usr/local/ispconfig/server/plugins-enabled/nginx_plugin.inc.php ]; then
    runtime_target=/etc/nginx/conf.d/ispcp-runtime.conf
    if [ -e "$runtime_target" ] && ! cmp -s "$runtime_target" "$worker_source/nginx-runtime.conf"; then
        echo 'The nginx runtime constants file has local changes; installation stopped.' >&2
        exit 1
    fi
    runtime_new=0
    [ -e "$runtime_target" ] || runtime_new=1
    install -m 0644 -o root -g root "$worker_source/nginx-runtime.conf" "$runtime_target"
    runtime_check=$(mktemp)
    if ! nginx -T > "$runtime_check" 2>&1 || ! grep -q 'geo $ispcp_literal_dollar' "$runtime_check"; then
        [ "$runtime_new" = 0 ] || rm -f "$runtime_target"
        rm -f "$runtime_check"
        echo 'nginx validation failed or conf.d is not included in its http context. No reload performed.' >&2
        exit 1
    fi
    rm -f "$runtime_check"
    # ISPConfig reloads nginx when it applies the subsequent website change.
fi
printf '1\n' > /usr/local/lib/ispconfig-rest-web-log-worker/runtime-ready
chmod 0600 /usr/local/lib/ispconfig-rest-web-log-worker/runtime-ready
cat > /etc/cron.d/ispconfig-rest-web-log-worker <<'CRON'
* * * * * root /usr/bin/php /usr/local/lib/ispconfig-rest-web-log-worker/run.php
CRON
chmod 0644 /etc/cron.d/ispconfig-rest-web-log-worker
echo 'Installed. The first heartbeat and log reads will be available within one minute.'
