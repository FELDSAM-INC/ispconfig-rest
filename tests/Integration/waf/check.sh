#!/bin/bash
set -euo pipefail
engine=${1:?Select apache or nginx}
mkdir -p /etc/ispconfig-waf /var/log/ispconfig-waf /var/lib/ispconfig-rest-waf-tmp /var/lib/ispconfig-rest-waf-data /tmp/waf-www
chmod 700 /var/log/ispconfig-waf
chown www-data:www-data /var/lib/ispconfig-rest-waf-{tmp,data}
printf 'fixture\n' > /tmp/waf-www/index.html
cp /tmp/waf-www/index.html /tmp/waf-www/allowed
cp /tmp/waf-www/index.html /tmp/waf-www/other
# Disposable container only: systemd is absent. Actual daemons are started below.
printf '#!/bin/sh\nexit 0\n' > /usr/bin/systemctl
chmod 755 /usr/bin/systemctl
php /app/waf-server/configure.php install "$engine" /etc/modsecurity
php /app/tests/Integration/waf/check-profiles.php
# Even with no opted-in websites, a malformed new base must fail and restore the prior files.
cp /etc/ispconfig-waf/base.conf /tmp/waf-base-before
mkdir /tmp/waf-invalid-source
cp /etc/modsecurity/{modsecurity.conf-recommended,unicode.mapping} /tmp/waf-invalid-source/
printf '\nInvalidWafDirective test\n' >> /tmp/waf-invalid-source/modsecurity.conf-recommended
if php /app/waf-server/configure.php install "$engine" /tmp/waf-invalid-source; then echo 'Invalid rules accepted'; exit 1; fi
cmp /tmp/waf-base-before /etc/ispconfig-waf/base.conf
echo "PASS $engine invalid configuration rejected and restored"
printf '\nListen 127.0.0.1:8080\n' >> /etc/apache2/ports.conf
rm -f /etc/nginx/sites-enabled/default
php /app/tests/Integration/waf/probe.php detection
if [[ "$engine" = apache ]]; then apache2ctl start; port=8080; else nginx; port=8081; fi
request() { curl -s -o /dev/null -w '%{http_code}' -H 'Host: waf.test' "http://127.0.0.1:$port$1"; }
for mode in detection enforcing exclude allowip off; do
    php /app/tests/Integration/waf/probe.php "$mode"
    if [[ "$engine" = apache ]]; then apache2ctl -t; apache2ctl graceful; else nginx -t; nginx -s reload; fi
    sleep .5
    [[ $(request /index.html) = 200 ]]
    expected=200
    [[ "$mode" != enforcing ]] || expected=403
    result=$(request '/allowed?q=1%27%20OR%201%3D1--&password=SECRET_VALUE')
    [[ "$result" = "$expected" ]] || { echo "$engine $mode expected $expected got $result"; exit 1; }
    if [[ "$mode" = exclude ]]; then
        [[ $(request '/other?q=1%27%20OR%201%3D1--') = 403 ]]
        [[ $(request '/allowed?different=1%27%20OR%201%3D1--') = 403 ]]
    fi
    [[ $(curl -s -o /dev/null -w '%{http_code}' -H 'Host: other.test' "http://127.0.0.1:$port/allowed?q=1%27%20OR%201%3D1--") = 403 ]]
    echo "PASS $engine $mode, benign traffic and scoped exceptions"
done
sleep .2
php /app/tests/Integration/waf/check-audit.php "$engine"

if [[ "$engine" = nginx ]]; then [[ -s /tmp/waf-original-error.log ]]; echo 'PASS nginx preserves original website error log'; fi

# Official WordPress Gutenberg exemption must affect only content at its known path.
mkdir -p /tmp/waf-www/wp-json/wp/v2
cp /tmp/waf-www/index.html /tmp/waf-www/wp-json/wp/v2/posts
# Even an administrator's global WordPress default must not override a site's None.
printf '\nSecAction "id:900130,phase:1,pass,nolog,setvar:tx.crs_exclusions_wordpress=1"\n' >> /etc/modsecurity/crs/crs-setup.conf
for profile in wordpress drupal nextcloud dokuwiki cpanel xenforo none; do
    php /app/tests/Integration/waf/probe.php "$profile"
    if [[ "$engine" = apache ]]; then apache2ctl -t; apache2ctl graceful; else nginx -t; nginx -s reload; fi
    sleep .5
    payload='content=%3Cscript%3Ealert(1)%3C%2Fscript%3E'
    expected=403
    [[ "$profile" != wordpress ]] || expected=200
    actual=$(request "/wp-json/wp/v2/posts?$payload")
    [[ "$actual" = "$expected" ]] || { echo "$engine $profile profile expected $expected got $actual"; exit 1; }
    [[ $(request "/other?$payload") = 403 ]]
    [[ $(request '/wp-json/wp/v2/posts?other=%3Cscript%3Ealert(1)%3C%2Fscript%3E') = 403 ]]
    [[ $(curl -s -o /dev/null -w '%{http_code}' -H 'Host: other.test' "http://127.0.0.1:$port/wp-json/wp/v2/posts?$payload") = 403 ]]
    echo "PASS $engine application profile $profile: scoped path/argument, other website protected"
done
