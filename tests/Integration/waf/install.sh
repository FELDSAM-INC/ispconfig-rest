#!/bin/bash
# Disposable container only. Verify the public installer from a root-owned release.
set -euo pipefail
engine=${1:?apache or nginx}
mkdir -p /usr/local/ispconfig/server/{lib,plugins-enabled} /opt/waf-release/app/Support
printf '<?php\n' > /usr/local/ispconfig/server/lib/config.inc.php
ln -s /tmp/plugin.php "/usr/local/ispconfig/server/plugins-enabled/${engine/apache/apache2}_plugin.inc.php"
a2dismod security2
cp -R /app/waf-server /opt/waf-release/
cp /app/app/Support/WebWaf{Policy,Audit,Profiles,Crs,IspconfigSecurity}.php /opt/waf-release/app/Support/
chown -R root:root /opt/waf-release
chmod -R go-w /opt/waf-release
printf '#!/bin/sh\nexit 0\n' > /usr/bin/systemctl
chmod 755 /usr/bin/systemctl
mkdir -p /usr/local/ispconfig/security
php -r 'require "/app/vendor/autoload.php"; echo Tests\Unit\WebWafIspconfigSecurityTest::VENDOR;' > /usr/local/ispconfig/security/apache_directives.blacklist
chgrp www-data /usr/local/ispconfig/security/apache_directives.blacklist
chmod 0640 /usr/local/ispconfig/security/apache_directives.blacklist
printf '[ids]\napache_directives_scan_enabled=yes\n' > /usr/local/ispconfig/security/security_settings.ini
bash /opt/waf-release/waf-server/install.sh
ispconfig-waf status
ispconfig-waf refresh
[[ $(stat -c %a /var/log/ispconfig-waf) = 700 ]]
[[ $(stat -c %a /usr/local/lib/ispconfig-rest-waf/run.php) = 600 ]]
[[ -s /etc/cron.d/ispconfig-rest-waf && -s /etc/logrotate.d/ispconfig-rest-waf ]]
crs=/usr/local/share/ispconfig-waf/crs
version=$(php -r 'echo json_decode(file_get_contents("/app/waf-server/crs.json"), true)["version"];')
[[ $(readlink "$crs/current") = "$version" && $(stat -c '%U %a' "$crs/$version/rules") = 'root 755' ]]
[[ $(ispconfig-waf status | php -r 'echo json_decode(stream_get_contents(STDIN), true)["rules_version"];') = "$version" ]]
grep -q '^SecAction' /etc/ispconfig-waf/crs-setup.conf
if dpkg-query -W -f='${Status}' modsecurity-crs 2>/dev/null | grep -q ' installed$'; then echo 'Distribution CRS installed'; exit 1; fi
echo "PASS $engine installer, status, refresh, root permissions, cron and rotation, upstream CRS $version"

# Re-running keeps local CRS settings and the verified copy; a modified copy is replaced from the pinned release.
printf '# administrator setting\n' >> /etc/ispconfig-waf/crs-setup.local.conf
marker=$(stat -c %i "$crs/$version/.ispconfig-crs.json")
bash /opt/waf-release/waf-server/install.sh
grep -q '^# administrator setting$' /etc/ispconfig-waf/crs-setup.local.conf
[[ $(stat -c %i "$crs/$version/.ispconfig-crs.json") = "$marker" ]]
plugin="$crs/current/plugins/wordpress-rule-exclusions-before.conf"
pinned=$(php -r 'echo json_decode(file_get_contents("/app/waf-server/crs.json"), true)["plugins"]["wordpress"]["files"]["wordpress-rule-exclusions-before.conf"];')
printf 'SecRuleEngine Off\n' >> "$plugin"
mkdir "$crs/4.0.0"
bash /opt/waf-release/waf-server/install.sh
[[ $(sha256sum "$plugin" | cut -d' ' -f1) = "$pinned" && ! -e "$crs/4.0.0" ]]
echo "PASS $engine re-install keeps local settings, reuses the verified CRS and replaces a modified copy"

php /app/tests/Integration/waf/check-security.php
