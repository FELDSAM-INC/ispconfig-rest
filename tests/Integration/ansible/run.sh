#!/bin/bash
# The Ansible ISPConfig roles end to end: a panel, a web, a database and a DNS server in systemd containers, the real
# ISPConfig autoinstaller, ispconfig-rest from GitHub and its server tools. Then websites on PHP 5.3 (built) and 5.6
# (sury) through ISPConfig and PHP-FPM, and a second run that must change nothing. Needs Docker (cgroup v2) and
# internet access; takes 30-90 minutes. KEEP=1 keeps the containers, REUSE=1 runs again on kept ones; BASE=debian:12
# tests another system.
set -euo pipefail
cd "$(dirname "$0")/../../.."
BASE=${BASE:-ubuntu:24.04}
NET=ispcp-ansible
NODES=(panel web1 db1 ns1)
declare -A ADDRESS=([panel]=10.231.80.10 [web1]=10.231.80.11 [db1]=10.231.80.21 [ns1]=10.231.80.31)
LOGS=$(mktemp -d)

cleanup() {
    for node in "${NODES[@]}" pebble acme; do docker rm -f "ispcp-ansible-$node" >/dev/null 2>&1 || true; done
    docker network rm "$NET" >/dev/null 2>&1 || true
}
fail() { echo "FAIL: $*" >&2; exit 1; }
on() { local node=$1; shift; docker exec "ispcp-ansible-$node" "$@"; }
sql() { on panel mysql --defaults-file=/etc/mysql/debian.cnf --batch --skip-column-names dbispconfig -e "$1"; }

docker build -q -t ispcp-dns-catalog-ansible -f tests/Integration/dns-catalog/controller.Dockerfile tests/Integration/dns-catalog >/dev/null
[ "${KEEP:-0}" = 1 ] || [ "${REUSE:-0}" = 1 ] || trap cleanup EXIT
if [ "${REUSE:-0}" != 1 ]; then
    docker build -q -t ispcp-ansible-node --build-arg BASE="$BASE" tests/Integration/ansible >/dev/null
    cleanup
    docker network create --subnet 10.231.80.0/24 "$NET" >/dev/null
    for node in "${NODES[@]}"; do
        docker run -d --name "ispcp-ansible-$node" --hostname "$node" --domainname ispcp.test --network "$NET" \
            --ip "${ADDRESS[$node]}" --network-alias "$node.ispcp.test" --privileged --cgroupns=private \
            --tmpfs /run --tmpfs /run/lock ispcp-ansible-node >/dev/null
    done
fi
for node in "${NODES[@]}"; do
    on "$node" timeout 60 systemctl is-system-running --wait >/dev/null 2>&1 || true
    [ "$(on "$node" hostname -f)" = "$node.ispcp.test" ] || fail "$node has no FQDN"
done

playbook() {
    docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v "$(command -v docker):/usr/bin/docker:ro" \
        -v "$PWD:/repo:ro" -w /repo/ansible \
        -e ANSIBLE_CONFIG=/repo/ansible/ansible.cfg -e ANSIBLE_FORCE_COLOR=0 ispcp-dns-catalog-ansible \
        ansible-playbook -i /repo/tests/Integration/ansible/hosts -e @/repo/tests/Integration/ansible/vars.yml site.yml
}

echo "== First run (autoinstaller on four servers; logs in $LOGS)"
playbook >"$LOGS/first.log" 2>&1 || { tail -n 60 "$LOGS/first.log"; fail "first run"; }
tail -n 8 "$LOGS/first.log"

echo "== ISPConfig servers and their services"
servers=$(sql "SELECT server_name, web_server, file_server, db_server, dns_server FROM server ORDER BY server_name")
echo "$servers"
expected=$(printf '%s\n' "db1.ispcp.test	0	0	1	0" "ns1.ispcp.test	0	0	0	1" "panel.ispcp.test	0	0	0	0" \
    "web1.ispcp.test	1	1	0	0")
[ "$servers" = "$expected" ] || fail "server services"
web=$(sql "SELECT server_id FROM server WHERE server_name = 'web1.ispcp.test'")
for node in db1 ns1 web1; do
    on "$node" grep -q "dbmaster_host'\]\s*= '10.231.80.10'" /usr/local/ispconfig/server/lib/config.inc.php || fail "$node did not join"
done

echo "== PHP versions of the web server"
php=$(sql "SELECT name, server_php_id FROM server_php WHERE server_id = $web ORDER BY name")
echo "$php"
for version in 5.3 5.6 8.3; do grep -q "^PHP $version	" <<<"$php" || fail "PHP $version not registered"; done
local_php=$(on web1 mysql --defaults-file=/etc/mysql/debian.cnf --batch --skip-column-names dbispconfig \
    -e "SELECT name, server_php_id FROM server_php WHERE server_id = $web ORDER BY name")
[ "$local_php" = "$php" ] || fail "web server's own PHP versions differ: $local_php"
on web1 /usr/bin/php5.3 -r 'echo PHP_VERSION, " ", OPENSSL_VERSION_TEXT, "\n";'
on web1 systemctl is-active php5.3-fpm php5.6-fpm >/dev/null || fail "PHP-FPM services"
on web1 grep -q '^\[php5_3\]' /etc/jailkit/jk_init.ini || fail "jailkit section"
on web1 grep -qx '40110 40210' /etc/pure-ftpd/conf/PassivePortRange || fail "FTP passive ports"
[ "$(on db1 php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" = "8.3" ] || fail "PHP CLI on db1"

echo "== ispconfig-rest and the server tools"
on panel ispconfig-rest status
on panel ispconfig-rest server-tools status --components database,web-logs,dns-catalog
on ns1 ispconfig-dns-catalog status >/dev/null || fail "dns-catalog"

echo "== Websites on PHP 5.3 and PHP 5.6 through ISPConfig"
key=$(on panel ispconfig-rest key:create ansible-e2e | grep -oE 'isp_[A-Za-z0-9]+' | head -n 1)
[ -n "$key" ] || fail "API key"
api() { on panel curl -fsSk -H "X-API-Key: $key" -H 'Content-Type: application/json' -H 'Accept: application/json' "$@"; }
client=$(sql "SELECT client_id FROM client WHERE username = 'e2e'")
if [ -z "$client" ]; then
    client=$(api -X POST https://127.0.0.1:8090/api/v1/clients -d '{"company_name":"E2E","contact_name":"Ansible E2E","email":"e2e@ispcp.test","username":"e2e","password":"Ansible-E2E-2026!"}' \
        | python3 -c 'import json,sys; print(json.load(sys.stdin)["id"])')
fi
group=$(sql "SELECT groupid FROM sys_group WHERE client_id = $client")
for version in 5.3 5.6; do
    domain="php${version/./}.ispcp.test"
    [ -z "$(sql "SELECT domain_id FROM web_domain WHERE domain = '$domain'")" ] || continue
    php_id=$(sql "SELECT server_php_id FROM server_php WHERE server_id = $web AND name = 'PHP $version'")
    api -X POST https://127.0.0.1:8090/api/v1/sites/web-domains -d "{\"server_id\":$web,\"domain\":\"$domain\",\"sys_groupid\":$group,\"php\":\"php-fpm\",\"server_php_id\":$php_id}" >/dev/null
done
for version in 5.3 5.6; do
    domain="php${version/./}.ispcp.test"
    root=$(sql "SELECT document_root FROM web_domain WHERE domain = '$domain'")
    user=$(sql "SELECT system_user FROM web_domain WHERE domain = '$domain'")
    for _ in $(seq 1 30); do on web1 test -d "$root/web" && break; sleep 10; done
    on web1 test -d "$root/web" || fail "ISPConfig did not create $domain"
    on web1 sh -c "printf '%s' '<?php echo PHP_VERSION;' > $root/web/version.php && chown $user: $root/web/version.php"
    served=""
    for _ in $(seq 1 30); do
        served=$(on web1 curl -fs -H "Host: $domain" http://127.0.0.1/version.php || true)
        [[ $served == "$version".* ]] && break
        sleep 5
    done
    echo "$domain: PHP $served"
    [[ $served == "$version".* ]] || fail "$domain serves PHP '$served'"
done
on web1 sh -c 'ls /etc/php/5.3/fpm/pool.d/ | grep -q "^web[0-9]*\.conf$"' || fail "no ISPConfig pool for PHP 5.3"

echo "== MariaDB listens on the private address only where needed"
listening() { on "$1" ss -ltnH 'sport = :3306' | awk '{print $4}' | sort | tr '\n' ' '; }
for node in panel db1 web1 ns1; do echo "$node: $(listening "$node")"; done
[ "$(listening panel)" = "10.231.80.10:3306 127.0.0.1:3306 " ] || fail "panel MariaDB listens beyond loopback and its private address"
[[ "$(listening db1)" =~ ^(0\.0\.0\.0|\*):3306\ $ ]] || fail "database server MariaDB does not listen on all addresses"
for node in web1 ns1; do [ "$(listening "$node")" = "127.0.0.1:3306 " ] || fail "$node MariaDB listens beyond loopback"; done

echo "== Database on the database server, used by the PHP 5.3 website on the web server"
db_server=$(sql "SELECT server_id FROM server WHERE server_name = 'db1.ispcp.test'")
site=$(sql "SELECT domain_id FROM web_domain WHERE domain = 'php53.ispcp.test'")
db_id=$(sql "SELECT database_id FROM web_database WHERE parent_domain_id = $site")
if [ -z "$db_id" ]; then
    db_user_id=$(api -X POST https://127.0.0.1:8090/api/v1/sites/database-users -d "{\"database_user\":\"e2e\",\"database_password\":\"Ansible-E2E-db-2026!\",\"client_id\":$client}" \
        | python3 -c 'import json,sys; print(json.load(sys.stdin)["id"])')
    db_id=$(api -X POST https://127.0.0.1:8090/api/v1/sites/databases -d "{\"server_id\":$db_server,\"parent_domain_id\":$site,\"database_name\":\"e2e\",\"database_user_id\":$db_user_id}" \
        | python3 -c 'import json,sys; print(json.load(sys.stdin)["id"])')
fi
read -r db_name db_user remote_access remote_ips <<<"$(sql "SELECT d.database_name, u.database_user, d.remote_access, d.remote_ips FROM web_database d JOIN web_database_user u ON u.database_user_id = d.database_user_id WHERE d.database_id = $db_id")"
echo "$db_name ($db_user): remote access $remote_access from $remote_ips"
[ "$remote_access" = y ] && [[ ",$remote_ips," == *,10.231.80.11,* ]] || fail "the web server is not allowed to the database"
for _ in $(seq 1 30); do on db1 mysql --defaults-file=/etc/mysql/debian.cnf -N -e "SHOW DATABASES LIKE '$db_name'" | grep -q . && break; sleep 10; done
grants=$(on db1 mysql --defaults-file=/etc/mysql/debian.cnf -N -e "SELECT Host FROM mysql.user WHERE User = '$db_user' ORDER BY Host" | tr '\n' ' ')
echo "MariaDB on db1 grants $db_user to: $grants"
[[ " $grants" == *" 10.231.80.11 "* ]] || fail "db1 has no grant for the web server"
root=$(sql "SELECT document_root FROM web_domain WHERE domain_id = $site")
user=$(sql "SELECT system_user FROM web_domain WHERE domain_id = $site")
on web1 sh -c "cat > $root/web/db.php <<'PHP'
<?php
\$link = mysql_connect('db1.ispcp.test', '$db_user', 'Ansible-E2E-db-2026!');
echo \$link && mysql_select_db('$db_name', \$link) ? 'connected' : 'failed: ' . mysql_error();
PHP
chown $user: $root/web/db.php"
connected=$(on web1 curl -fs -H 'Host: php53.ispcp.test' http://127.0.0.1/db.php || true)
echo "PHP 5.3 website to db1.ispcp.test: $connected"
[ "$connected" = connected ] || fail "the website cannot reach its database"

echo "== Certificates with DNS challenges through ispconfig-rest: certbot plugin, certbot hook, acme.sh"
dns_server=$(sql "SELECT server_id FROM server WHERE server_name = 'ns1.ispcp.test'")
zone=$(sql "SELECT id FROM dns_soa WHERE origin = 'acme.test.'")
if [ -z "$zone" ]; then
    zone=$(api -X POST https://127.0.0.1:8090/api/v1/dns/soa -d "{\"server_id\":$dns_server,\"origin\":\"acme.test.\",\"ns\":\"ns1.ispcp.test.\",\"mbox\":\"hostmaster.acme.test.\"}" \
        | python3 -c 'import json,sys; print(json.load(sys.stdin)["id"])')
    api -X POST https://127.0.0.1:8090/api/v1/dns/records -d "{\"zone\":$zone,\"type\":\"NS\",\"name\":\"acme.test.\",\"data\":\"ns1.ispcp.test.\"}" >/dev/null
fi
acme_key=$(on panel ispconfig-rest key:create e2e-acme --acme --zone acme.test | grep -oE 'isp_[A-Za-z0-9]+' | head -n 1)
[ "$(on panel curl -sk -o /dev/null -w '%{http_code}' -H "X-API-Key: $acme_key" https://127.0.0.1:8090/api/v1/dns/soa)" = 403 ] || fail "the ACME key reaches other endpoints"
docker build -q -t ispcp-acme-client -f tests/Integration/acme-dns/client.Dockerfile tests/Integration/acme-dns >/dev/null
docker run -d --name ispcp-ansible-pebble --network "$NET" --network-alias pebble -e PEBBLE_VA_NOSLEEP=1 -e PEBBLE_WFE_NONCEREJECT=0 \
    ghcr.io/letsencrypt/pebble:2.7.0 -config /test/config/pebble-config.json -dnsserver 10.231.80.31:53 >/dev/null
docker run -d --name ispcp-ansible-acme --network "$NET" -v "$PWD:/repo:ro" -e REQUESTS_CA_BUNDLE=/tmp/pebble.pem ispcp-acme-client sleep infinity >/dev/null
acme() { docker exec ispcp-ansible-acme "$@"; }
docker cp ispcp-ansible-pebble:/test/certs/pebble.minica.pem - | docker exec -i ispcp-ansible-acme tar -x -C /tmp
# Clients verify ispconfig-rest's certificate. ISPConfig's self-signed fallback (no Let's Encrypt in containers) has no
# subjectAltName, which Python's TLS stack rejects; give the panel one with it, as Let's Encrypt would.
on panel sh -c 'cd /usr/local/ispconfig/interface/ssl && openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj /CN=panel.ispcp.test \
    -addext subjectAltName=DNS:panel.ispcp.test -keyout ispserver.key -out ispserver.crt 2>/dev/null && cat ispserver.key ispserver.crt > ispserver.pem \
    && systemctl reload nginx'
on panel cat /usr/local/ispconfig/interface/ssl/ispserver.crt | docker exec -i ispcp-ansible-acme sh -c 'cat > /tmp/panel.pem'
acme sh -c 'mv /tmp/pebble.minica.pem /tmp/pebble.pem && cat /tmp/pebble.pem /tmp/panel.pem > /tmp/both.pem
    cp -r /repo/acme-dns/certbot /tmp/plugin && pip install --quiet --root-user-action=ignore --disable-pip-version-check --no-deps /tmp/plugin
    cp /repo/acme-dns/acme.sh/dns_ispconfig_rest.sh /root/.acme.sh/dnsapi/'
for _ in $(seq 1 30); do acme dig +short @10.231.80.31 acme.test SOA | grep -q . && break; sleep 10; done
acme dig +short @10.231.80.31 acme.test SOA | grep -q . || fail "ns1 does not serve acme.test"
rest=https://panel.ispcp.test:8090
certbot=(certbot certonly -n --agree-tos -m test@acme.test --server https://pebble:14000/dir --config-dir /tmp/le --work-dir /tmp/le-work --logs-dir /tmp/le-logs)
acme sh -c "umask 077; printf 'dns_ispconfig_rest_url = $rest\ndns_ispconfig_rest_key = $acme_key\ndns_ispconfig_rest_ca_bundle = /tmp/panel.pem\n' > /tmp/ispconfig-rest.ini"
started=$SECONDS
acme "${certbot[@]}" --authenticator dns-ispconfig-rest --dns-ispconfig-rest-credentials /tmp/ispconfig-rest.ini \
    --dns-ispconfig-rest-propagation-seconds 0 --cert-name plugin -d acme.test -d '*.acme.test' >/dev/null 2>&1 \
    || { acme grep -iE 'error|detail' /tmp/le-logs/letsencrypt.log | tail -n 15; fail "certbot plugin with ISPConfig"; }
echo "certbot plugin: $(acme openssl x509 -in /tmp/le/live/plugin/cert.pem -noout -ext subjectAltName | tail -n 1 | xargs) in $((SECONDS - started)) s"
started=$SECONDS
acme env ISPC_REST_URL=$rest ISPC_REST_KEY="$acme_key" ISPC_REST_CA_BUNDLE=/tmp/panel.pem ISPC_REST_PROPAGATION=0 "${certbot[@]}" --manual \
    --preferred-challenges dns --manual-auth-hook '/repo/acme-dns/certbot-hook/ispconfig-rest-certbot-hook auth' \
    --manual-cleanup-hook '/repo/acme-dns/certbot-hook/ispconfig-rest-certbot-hook cleanup' --cert-name hook -d hook.acme.test >/dev/null 2>&1 \
    || { acme grep -iE 'hook|error|detail' /tmp/le-logs/letsencrypt.log | tail -n 15; fail "certbot hook with ISPConfig"; }
echo "certbot hook: $(acme openssl x509 -in /tmp/le/live/hook/cert.pem -noout -ext subjectAltName | tail -n 1 | xargs) in $((SECONDS - started)) s"
started=$SECONDS
acme env ISPC_REST_URL=$rest ISPC_REST_KEY="$acme_key" /root/.acme.sh/acme.sh --home /root/.acme.sh --issue --dns dns_ispconfig_rest \
    --dnssleep 1 --server https://pebble:14000/dir --ca-bundle /tmp/both.pem -d sh.acme.test -d '*.sh.acme.test' >"$LOGS/acmesh.log" 2>&1 \
    || { tail -n 25 "$LOGS/acmesh.log"; fail "acme.sh with ISPConfig"; }
echo "acme.sh: $(acme openssl x509 -in /root/.acme.sh/sh.acme.test_ecc/sh.acme.test.cer -noout -ext subjectAltName | tail -n 1 | xargs) in $((SECONDS - started)) s"
left=$(sql "SELECT COUNT(*) FROM dns_rr WHERE zone = $zone AND SUBSTR(name, 1, 15) = '_acme-challenge'")
[ "$left" = 0 ] || fail "$left challenge records were left in acme.test"
docker rm -f ispcp-ansible-pebble ispcp-ansible-acme >/dev/null

echo "== Second run changes nothing"
playbook >"$LOGS/second.log" 2>&1 || { tail -n 60 "$LOGS/second.log"; fail "second run"; }
sed -n '/PLAY RECAP/,$p' "$LOGS/second.log"
if sed -n '/PLAY RECAP/,$p' "$LOGS/second.log" | grep -Eq 'changed=[1-9]'; then
    grep -B 3 '^changed:' "$LOGS/second.log" | head -n 40
    fail "second run changed something"
fi
echo "PASS"
