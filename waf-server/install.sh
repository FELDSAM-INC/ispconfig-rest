#!/bin/bash
set -euo pipefail
[[ $(id -u) = 0 ]] || { echo 'Run as root from a root-owned checkout.' >&2; exit 1; }
[[ $# = 0 || ( $# = 1 && $1 = --ispconfig-security-only ) ]] || { echo 'Usage: install.sh [--ispconfig-security-only]' >&2; exit 1; }
source_dir=$(cd -- "$(dirname -- "$0")" && pwd)
# Refuse root execution of code from a web-writable checkout, including writable ancestors.
for waf_source in "$source_dir" "$source_dir/../app/Support"; do
    waf_source=$(realpath "$waf_source")
    while [[ "$waf_source" != / ]]; do
        [[ $(stat -c %u "$waf_source") = 0 ]] && [[ $(( 8#$(stat -c %a "$waf_source") & 0022 )) = 0 ]] || { echo 'Stage the release in a root-owned, non-writable directory first.' >&2; exit 1; }
        waf_source=$(dirname "$waf_source")
    done
done
for waf_file in "$source_dir"/{install.sh,run.php,configure.php,ispconfig-security.php,ispconfig-waf} "$source_dir/../app/Support"/{WebWafPolicy.php,WebWafAudit.php,WebWafProfiles.php,WebWafIspconfigSecurity.php}; do
    [[ -f "$waf_file" && ! -L "$waf_file" && $(stat -c %u "$waf_file") = 0 ]] && [[ $(( 8#$(stat -c %a "$waf_file") & 0022 )) = 0 ]] || { echo 'Installer files must be regular root-owned files without group/other write access.' >&2; exit 1; }
done
if [[ ${1:-} = --ispconfig-security-only ]]; then
    php "$source_dir/ispconfig-security.php"
    exit
fi
[[ -f /usr/local/ispconfig/server/lib/config.inc.php ]] || { echo 'Install on an ISPConfig web server.' >&2; exit 1; }
php -r 'exit(PHP_VERSION_ID >= 80300 && extension_loaded("pdo_mysql") && extension_loaded("mbstring") ? 0 : 1);'
. /etc/os-release
[[ "$ID" = debian || "$ID" = ubuntu ]] || { echo 'Supported package installations: Debian and Ubuntu.' >&2; exit 1; }
engine=
[[ ! -L /usr/local/ispconfig/server/plugins-enabled/apache2_plugin.inc.php ]] || engine=apache
if [[ -L /usr/local/ispconfig/server/plugins-enabled/nginx_plugin.inc.php ]]; then
    [[ -z "$engine" ]] || { echo 'Both web server plugins are enabled; resolve that before installing.' >&2; exit 1; }
    engine=nginx
fi
[[ -n "$engine" ]] || { echo 'No enabled ISPConfig Apache/nginx plugin found.' >&2; exit 1; }
# Do not take over an administrator-managed WAF.
if [[ ! -f /etc/ispconfig-waf/installed.json ]]; then
    if [[ "$engine" = apache ]] && apache2ctl -M 2>/dev/null | grep -q security2_module; then
        echo 'An existing Apache WAF is enabled. Migrate it explicitly before using this installer.' >&2; exit 1
    fi
    if [[ "$engine" = nginx ]] && nginx -T 2>/dev/null | grep -Eq '^[[:space:]]*modsecurity[[:space:]]+on'; then
        echo 'An existing nginx WAF is enabled. Migrate it explicitly before using this installer.' >&2; exit 1
    fi
fi
apt-get update
if [[ "$engine" = apache ]]; then
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends libapache2-mod-security2 modsecurity-crs
else
    # Distribution packages ensure the connector matches the installed nginx ABI.
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends libnginx-mod-http-modsecurity modsecurity-crs
fi
install -d -o root -g root -m 0700 /usr/local/lib/ispconfig-rest-waf /var/lib/ispconfig-rest-waf
install -d -o root -g root -m 0755 /etc/ispconfig-waf
install -d -o root -g root -m 0700 /var/log/ispconfig-waf
install -d -o www-data -g www-data -m 0700 /var/lib/ispconfig-rest-waf-tmp /var/lib/ispconfig-rest-waf-data
for file in run.php configure.php; do install -o root -g root -m 0600 "$source_dir/$file" /usr/local/lib/ispconfig-rest-waf/; done
for file in WebWafPolicy.php WebWafAudit.php WebWafProfiles.php; do install -o root -g root -m 0600 "$source_dir/../app/Support/$file" /usr/local/lib/ispconfig-rest-waf/; done
# nginx packages do not ship the engine's reference configuration/unicode map.
# Extract those data files from the signed distro package without installing Apache.
if [[ ! -f /etc/modsecurity/modsecurity.conf-recommended || ! -f /etc/modsecurity/unicode.mapping ]]; then
    package_dir=$(mktemp -d)
    trap 'rm -rf -- "$package_dir"' EXIT
    (cd "$package_dir" && apt-get download libapache2-mod-security2)
    packages=("$package_dir"/*.deb)
    [[ ${#packages[@]} = 1 ]] || exit 1
    dpkg-deb -x "${packages[0]}" "$package_dir/extracted"
    config_source="$package_dir/extracted/etc/modsecurity"
else
    config_source=/etc/modsecurity
fi
php /usr/local/lib/ispconfig-rest-waf/configure.php install "$engine" "$config_source"
install -o root -g root -m 0755 "$source_dir/ispconfig-waf" /usr/local/bin/ispconfig-waf
cat > /etc/cron.d/ispconfig-rest-waf <<'CRON'
* * * * * root /usr/bin/php /usr/local/lib/ispconfig-rest-waf/run.php
CRON
chmod 0644 /etc/cron.d/ispconfig-rest-waf
cat > /etc/logrotate.d/ispconfig-rest-waf <<'ROTATE'
/var/log/ispconfig-waf/*.json /var/log/ispconfig-waf/*.error {
    daily
    maxsize 25M
    rotate 7
    missingok
    notifempty
    compress
    delaycompress
    copytruncate
    su root root
}
ROTATE
chmod 0644 /etc/logrotate.d/ispconfig-rest-waf
if [[ -f /usr/local/ispconfig/security/apache_directives.blacklist ]]; then
    php "$source_dir/ispconfig-security.php"
else
    echo 'ISPConfig panel security files are not on this host. Run install.sh --ispconfig-security-only from this release on the ISPConfig panel/master host.'
fi
echo 'WAF installed. Enable detection/enforcing mode per website in WHMCS.'
echo 'Optional Atomicorp license: sudo ispconfig-waf atomic-key'
