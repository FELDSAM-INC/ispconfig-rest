#!/bin/sh
set -eu
[ "$(id -u)" = 0 ] || { echo 'Run as root.' >&2; exit 1; }
defer_reconcile=0
if [ "${1:-}" = --no-run ]; then defer_reconcile=1; shift; fi
[ "$#" = 2 ] || { echo 'Usage: install.sh [--no-run] /path/to/whmcs-file-manager.pub WHMCS_EGRESS_IP' >&2; exit 1; }
[ -f /usr/local/ispconfig/server/lib/config.inc.php ] || { echo 'Install on an ISPConfig webserver.' >&2; exit 1; }
worker_source=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
python3 -c 'import ipaddress,sys; ipaddress.ip_address(sys.argv[1])' "$2"
ssh-keygen -l -f "$1" >/dev/null
install -d -m 0755 /var/lib/ispcp-files /var/lib/ispcp-files/jails /etc/ispcp-files /etc/ispcp-files/keys
install -d -m 0700 -o root -g root /usr/local/lib/ispconfig-rest-file-manager-worker
install -m 0600 -o root -g root "$worker_source/reconcile.py" "$worker_source/sites.php" /usr/local/lib/ispconfig-rest-file-manager-worker/
install -m 0600 "$1" /etc/ispcp-files/client.pub
python3 -c 'import json,sys; open("/etc/ispcp-files/config.json","w").write(json.dumps({"whmcs_ip":sys.argv[1]}))' "$2"
chmod 0600 /etc/ispcp-files/config.json
getent group ispcp-files >/dev/null || groupadd --system ispcp-files
previous_include=$(mktemp)
include_existed=0
if [ -f /etc/ispcp-files/sshd.conf ]; then
    include_existed=1
    cp /etc/ispcp-files/sshd.conf "$previous_include"
fi
install -m 0600 "$worker_source/sshd.conf" /etc/ispcp-files/sshd.conf
# The include belongs at the end: Match blocks must not change the context of earlier global options.
include='Include /etc/ispcp-files/sshd.conf'
backup=$(mktemp)
cp /etc/ssh/sshd_config "$backup"
if ! grep -qxF "$include" /etc/ssh/sshd_config; then
    printf '\n%s\n' "$include" >> /etc/ssh/sshd_config
fi
if ! /usr/sbin/sshd -t; then
    cat "$backup" > /etc/ssh/sshd_config
    if [ "$include_existed" = 1 ]; then
        cat "$previous_include" > /etc/ispcp-files/sshd.conf
    else
        : > /etc/ispcp-files/sshd.conf
    fi
    rm -f "$backup"
    echo 'SSH validation failed; original configuration restored.' >&2
    exit 1
fi
rm -f "$backup"
rm -f "$previous_include"
sh "$worker_source/wordpress-install.sh"
# A normal ISPConfig installation already declares the SFTP subsystem.
/usr/sbin/sshd -T | grep -q '^subsystem sftp ' || { echo 'The SFTP subsystem is missing.' >&2; exit 1; }
systemctl reload ssh.service
cat > /etc/cron.d/ispconfig-rest-file-manager-worker <<'CRON'
* * * * * root /usr/bin/python3 /usr/local/lib/ispconfig-rest-file-manager-worker/reconcile.py 2>&1 | /usr/bin/logger -t ispconfig-rest-file-manager-worker
CRON
chmod 0644 /etc/cron.d/ispconfig-rest-file-manager-worker
# Upgrade the original WHMCS-distributed helper without touching keys, users or mounts.
if [ -f /etc/cron.d/ispcp-files ]; then
    if grep -qF '/usr/local/lib/ispcp-files/reconcile.py' /etc/cron.d/ispcp-files; then
        rm -f /etc/cron.d/ispcp-files
    else
        echo 'Unrecognized legacy cron left unchanged: /etc/cron.d/ispcp-files' >&2
    fi
fi
if [ "$defer_reconcile" = 0 ]; then
    /usr/bin/python3 /usr/local/lib/ispconfig-rest-file-manager-worker/reconcile.py
fi
echo 'Installed. New active websites are provisioned every minute; removed or inactive website keys are revoked.'
