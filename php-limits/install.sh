#!/bin/bash
set -euo pipefail
[[ $(id -u) = 0 ]] || { echo 'Run as root from a root-owned checkout.' >&2; exit 1; }
[[ $# = 0 || ( $# = 1 && $1 = --no-restart ) ]] || { echo 'Usage: install.sh [--no-restart]' >&2; exit 1; }
source_dir=$(cd -- "$(dirname -- "$0")" && pwd)
# Refuse root execution of code from a web-writable checkout, including writable ancestors.
for limits_source in "$source_dir" "$source_dir/../app/Support"; do
    limits_source=$(realpath "$limits_source")
    while [[ "$limits_source" != / ]]; do
        [[ $(stat -c %u "$limits_source") = 0 ]] && [[ $(( 8#$(stat -c %a "$limits_source") & 0022 )) = 0 ]] || { echo 'Stage the release in a root-owned, non-writable directory first.' >&2; exit 1; }
        limits_source=$(dirname "$limits_source")
    done
done
for limits_file in "$source_dir"/{install.sh,run.php,ispconfig-php-limits} "$source_dir/../app/Support/PhpLimits.php"; do
    [[ -f "$limits_file" && ! -L "$limits_file" && $(stat -c %u "$limits_file") = 0 ]] && [[ $(( 8#$(stat -c %a "$limits_file") & 0022 )) = 0 ]] || { echo 'Installer files must be regular root-owned files without group/other write access.' >&2; exit 1; }
done
[[ -f /usr/local/ispconfig/server/lib/config.inc.php ]] || { echo 'Install on an ISPConfig web server.' >&2; exit 1; }
php -r 'exit(PHP_VERSION_ID >= 80300 && PHP_INT_SIZE >= 8 && extension_loaded("pdo_mysql") && extension_loaded("posix") ? 0 : 1);' || { echo 'PHP CLI 8.3+ with pdo_mysql and posix is required.' >&2; exit 1; }
. /etc/os-release
[[ "$ID" = debian || "$ID" = ubuntu ]] || { echo 'Supported package installations: Debian and Ubuntu.' >&2; exit 1; }
[[ $(stat -fc %T /sys/fs/cgroup) = cgroup2fs ]] || { echo 'The cgroup v2 unified hierarchy is required (systemd.unified_cgroup_hierarchy=1).' >&2; exit 1; }
for controller in cpu memory pids; do
    grep -qw "$controller" /sys/fs/cgroup/cgroup.controllers || { echo "The $controller cgroup controller is not available." >&2; exit 1; }
done
systemd_version=$(systemctl --version | awk 'NR == 1 { print $2 }')
[[ "$systemd_version" =~ ^[0-9]+$ && "$systemd_version" -ge 243 ]] || { echo 'systemd 243 or newer is required.' >&2; exit 1; }
install -d -m 0700 -o root -g root /usr/local/lib/ispconfig-rest-php-limits /var/lib/ispconfig-rest-php-limits
install -d -m 0755 -o root -g root /etc/ispconfig-rest-php-limits /etc/ispconfig-rest-php-limits/pools
install -m 0600 -o root -g root "$source_dir/run.php" "$source_dir/../app/Support/PhpLimits.php" /usr/local/lib/ispconfig-rest-php-limits/
install -m 0755 -o root -g root "$source_dir/ispconfig-php-limits" /usr/local/sbin/ispconfig-php-limits
/usr/local/sbin/ispconfig-php-limits install-services "$@"
cat > /etc/cron.d/ispconfig-rest-php-limits <<'CRON'
* * * * * root /usr/local/sbin/ispconfig-php-limits run
CRON
chmod 0644 /etc/cron.d/ispconfig-rest-php-limits
echo 'Installed. Accounts with resource limits are isolated within one minute; see ispconfig-php-limits status.'
