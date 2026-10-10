#!/bin/bash
# Inside the disposable primary: an ISPConfig DNS server layout, then the real installer from a root-owned release.
set -euo pipefail
mkdir -p /usr/local/ispconfig/server/{lib,plugins-available,plugins-enabled} /opt/release/app/Support
printf '<?php\n' > /usr/local/ispconfig/server/lib/config.inc.php
touch /usr/local/ispconfig/server/plugins-available/bind_plugin.inc.php
ln -s /usr/local/ispconfig/server/plugins-available/bind_plugin.inc.php /usr/local/ispconfig/server/plugins-enabled/
printf '#!/bin/sh\nexit 0\n' > /usr/bin/systemctl && chmod 755 /usr/bin/systemctl
# ISPConfig's named.conf.options template and named.conf.local format
cat > /etc/bind/named.conf.options <<'CONF'
options {
	directory "/var/cache/bind";
	version "unknown";
	allow-transfer {none;};
	auth-nxdomain no;    # conform to RFC1035
	listen-on-v6 { none; };
	recursion no;
	dnssec-validation no;
};
CONF
zone() { printf '$TTL 300\n@ IN SOA ns1.%s. hostmaster.%s. ( %s 3600 600 86400 300 )\n@ IN NS ns1.%s.\nns1 IN A 192.0.2.53\nwww IN A %s\n' "$1" "$1" "$2" "$1" "$3" > "/etc/bind/pri.$1"; }
zone a.test 1 192.0.2.80; zone b.test 1 192.0.2.81; zone c.test 1 192.0.2.82
printf 'zone "a.test" {\n        type master;\n        file "/etc/bind/pri.a.test";\n};\nzone "b.test" {\n        type master;\n        allow-transfer {192.0.2.1;};\n        file "/etc/bind/pri.b.test";\n};\n' > /etc/bind/named.conf.local
named -u bind -c /etc/bind/named.conf
cp -R /app/dns-catalog /opt/release/ && cp /app/app/Support/DnsCatalog.php /opt/release/app/Support/
chown -R root:root /opt/release && chmod -R go-w /opt/release
bash /opt/release/dns-catalog/install.sh --catalog catalog.ns1.test.invalid
ispconfig-dns-catalog status
grep -q 'allow-transfer { key "ispcp-catalog.' /etc/bind/named.conf.options
grep -q 'allow-transfer { key "ispcp-catalog.' /usr/local/ispconfig/server/conf-custom/install/named.conf.options.master
[[ $(stat -c '%U:%G %a' /etc/bind/ispcp-catalog.key) = 'root:bind 640' ]]
# A second run keeps the key and the catalog name
key=$(sha256sum /etc/bind/ispcp-catalog.key)
bash /opt/release/dns-catalog/install.sh >/dev/null
[[ $(sha256sum /etc/bind/ispcp-catalog.key) = "$key" ]]
if bash /opt/release/dns-catalog/install.sh --catalog other.invalid 2>/dev/null; then echo 'renamed the catalog'; exit 1; fi
echo "PASS primary installer: catalog, transfer key kept by ISPConfig updates, idempotent, no rename"
