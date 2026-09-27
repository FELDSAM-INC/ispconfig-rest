#!/bin/bash
# Disposable container only. Verify the public installer from a root-owned release.
set -euo pipefail
engine=${1:?apache or nginx}
mkdir -p /usr/local/ispconfig/server/{lib,plugins-enabled} /opt/waf-release/app/Support
printf '<?php\n' > /usr/local/ispconfig/server/lib/config.inc.php
ln -s /tmp/plugin.php "/usr/local/ispconfig/server/plugins-enabled/${engine/apache/apache2}_plugin.inc.php"
a2dismod security2
cp -R /app/waf-server /opt/waf-release/
cp /app/app/Support/WebWaf{Policy,Audit,Profiles,IspconfigSecurity}.php /opt/waf-release/app/Support/
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
echo "PASS $engine installer, status, refresh, root permissions, cron and rotation"

php /app/tests/Integration/waf/check-security.php
