#!/bin/bash
set -euo pipefail
[[ $(id -u) = 0 ]] || { echo 'Run as root from a root-owned checkout.' >&2; exit 1; }
[[ $# = 0 || ( $# = 2 && $1 = --catalog ) ]] || { echo 'Usage: install.sh [--catalog NAME]' >&2; exit 1; }
source_dir=$(cd -- "$(dirname -- "$0")" && pwd)
# Refuse root execution of code from a web-writable checkout, including writable ancestors.
for catalog_source in "$source_dir" "$source_dir/../app/Support"; do
    catalog_source=$(realpath "$catalog_source")
    while [[ "$catalog_source" != / ]]; do
        [[ $(stat -c %u "$catalog_source") = 0 ]] && [[ $(( 8#$(stat -c %a "$catalog_source") & 0022 )) = 0 ]] || { echo 'Stage the release in a root-owned, non-writable directory first.' >&2; exit 1; }
        catalog_source=$(dirname "$catalog_source")
    done
done
for catalog_file in "$source_dir"/{install.sh,run.php,ispconfig-dns-catalog} "$source_dir/../app/Support/DnsCatalog.php"; do
    [[ -f "$catalog_file" && ! -L "$catalog_file" && $(stat -c %u "$catalog_file") = 0 ]] && [[ $(( 8#$(stat -c %a "$catalog_file") & 0022 )) = 0 ]] || { echo 'Installer files must be regular root-owned files without group/other write access.' >&2; exit 1; }
done
[[ -f /usr/local/ispconfig/server/lib/config.inc.php ]] || { echo 'Install on an ISPConfig DNS server.' >&2; exit 1; }
[[ -L /usr/local/ispconfig/server/plugins-enabled/bind_plugin.inc.php ]] || { echo 'The ISPConfig BIND plugin is not enabled on this server.' >&2; exit 1; }
php -r 'exit(PHP_VERSION_ID >= 80300 && extension_loaded("posix") ? 0 : 1);' || { echo 'PHP CLI 8.3+ with posix is required.' >&2; exit 1; }
for tool in named named-checkconf named-checkzone rndc tsig-keygen systemctl; do
    command -v "$tool" >/dev/null || { echo "Missing $tool (BIND 9.18+ with its utilities is required)." >&2; exit 1; }
done
install -d -m 0700 -o root -g root /usr/local/lib/ispconfig-dns-catalog
install -d -m 0700 -o root -g root /etc/ispconfig-dns-catalog
install -m 0600 -o root -g root "$source_dir/run.php" "$source_dir/../app/Support/DnsCatalog.php" /usr/local/lib/ispconfig-dns-catalog/
install -m 0755 -o root -g root "$source_dir/ispconfig-dns-catalog" /usr/local/sbin/ispconfig-dns-catalog
/usr/local/sbin/ispconfig-dns-catalog install "$@"
# ISPConfig rewrites named.conf.local whenever a zone is added, changed, disabled or removed: follow it within
# seconds, with a timer as a safety net.
cat > /etc/systemd/system/ispconfig-dns-catalog.service <<'UNIT'
[Unit]
Description=Update the ISPConfig DNS catalog zone
After=named.service

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/ispconfig-dns-catalog sync
UNIT
cat > /etc/systemd/system/ispconfig-dns-catalog.path <<'UNIT'
[Unit]
Description=Follow ISPConfig DNS zone changes for the catalog zone

[Path]
PathChanged=/etc/bind/named.conf.local
Unit=ispconfig-dns-catalog.service

[Install]
WantedBy=multi-user.target
UNIT
cat > /etc/systemd/system/ispconfig-dns-catalog.timer <<'UNIT'
[Unit]
Description=Check the ISPConfig DNS catalog zone regularly

[Timer]
OnBootSec=2min
OnUnitActiveSec=5min
Unit=ispconfig-dns-catalog.service

[Install]
WantedBy=timers.target
UNIT
chmod 0644 /etc/systemd/system/ispconfig-dns-catalog.{service,path,timer}
systemctl daemon-reload
systemctl enable --now ispconfig-dns-catalog.path ispconfig-dns-catalog.timer
/usr/local/sbin/ispconfig-dns-catalog sync
echo 'Installed. Check with: ispconfig-dns-catalog status'
