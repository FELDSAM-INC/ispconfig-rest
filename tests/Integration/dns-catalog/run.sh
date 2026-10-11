#!/bin/bash
# Host-side, disposable containers only: primary + secondary + Ansible controller on a private network.
set -euo pipefail
repo=$(cd -- "$(dirname -- "$0")/../../.." && pwd)
here="$repo/tests/Integration/dns-catalog"
net=ispcp-catalog-net
cleanup() { docker rm -f ispcp-catalog-primary ispcp-catalog-secondary >/dev/null 2>&1 || true; docker network rm "$net" >/dev/null 2>&1 || true; }
inventory=$(mktemp) log=$(mktemp) work=$(mktemp -d)
trap 'cleanup; rm -rf "$inventory" "$log" "$work"' EXIT
# The secondary playbook lives in ispconfig-ansible: a local checkout (ANSIBLE_DIR) or a fresh clone.
ansible_dir=${ANSIBLE_DIR:-$work/ispconfig-ansible}
[ -n "${ANSIBLE_DIR:-}" ] || git clone -q --depth 1 https://github.com/FELDSAM-INC/ispconfig-ansible "$ansible_dir"
cleanup
docker build -q -t ispcp-dns-catalog-test "$here" >/dev/null
docker build -q -t ispcp-dns-catalog-ansible -f "$here/controller.Dockerfile" "$here" >/dev/null
docker network create --subnet 10.231.78.0/24 "$net" >/dev/null
docker run -d --name ispcp-catalog-primary --hostname ns1.test --network "$net" --ip 10.231.78.10 -v "$repo:/app:ro" ispcp-dns-catalog-test sleep infinity >/dev/null
docker run -d --name ispcp-catalog-secondary --hostname ns2.test --network "$net" --ip 10.231.78.20 ispcp-dns-catalog-test sh -c 'named -u bind -c /etc/bind/named.conf; sleep infinity' >/dev/null
docker exec ispcp-catalog-primary bash /app/tests/Integration/dns-catalog/primary.sh
cat > "$inventory" <<'INV'
all:
  vars:
    ansible_connection: community.docker.docker
    ansible_python_interpreter: /usr/bin/python3
  children:
    ispconfig_dns_primaries:
      hosts:
        ispcp-catalog-primary: {}
    ispconfig_dns_secondaries:
      hosts:
        ispcp-catalog-secondary:
          ispcp_install_packages: false
          ispcp_manage_service: false
INV
for run in first second; do
    docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v "$(command -v docker):/usr/bin/docker:ro" -v "$ansible_dir/dns-secondary:/play:ro" \
        -v "$inventory:/inventory.yml:ro" -e ANSIBLE_HOST_KEY_CHECKING=False -e ANSIBLE_STDOUT_CALLBACK=default ispcp-dns-catalog-ansible \
        ansible-playbook -i /inventory.yml /play/secondary.yml | tee "$log" | grep -E '^(PLAY RECAP|ispcp-catalog-)' || { cat "$log"; exit 1; }
done
grep -q 'ispcp-catalog-secondary *: ok=[0-9]* *changed=0 ' "$log" && echo 'PASS second playbook run changes nothing'
answer() { docker exec ispcp-catalog-secondary dig +norec +short @127.0.0.1 "www.$1" A; }
wait_for() { for _ in $(seq 1 30); do [[ "$(answer "$1")" = "$2" ]] && return 0; sleep 1; done; echo "secondary: www.$1 is '$(answer "$1")', expected '$2'"; exit 1; }
wait_for a.test 192.0.2.80
[[ -z "$(answer b.test)" ]]
echo 'PASS secondary provisioned from the catalog; a zone with its own transfer list is left to its own secondary'
grep -q '"10.231.78.20"' <(docker exec ispcp-catalog-primary cat /etc/ispconfig-dns-catalog/config.json)
# ISPConfig adds a zone: it rewrites named.conf.local; the path unit runs sync (here by hand: no systemd)
docker exec ispcp-catalog-primary sh -c 'printf "zone \"c.test\" {\n        type master;\n        file \"/etc/bind/pri.c.test\";\n};\n" >> /etc/bind/named.conf.local && rndc reconfig && ispconfig-dns-catalog sync'
wait_for c.test 192.0.2.82
echo 'PASS a new ISPConfig zone reaches the secondary through NOTIFY of the catalog'
docker exec ispcp-catalog-primary sh -c 'ispconfig-dns-catalog sync | grep -q unchanged'
docker exec ispcp-catalog-primary sh -c 'sed -i "s/( 1 3600/( 2 3600/; s/192.0.2.82/192.0.2.92/" /etc/bind/pri.c.test && rndc reload c.test >/dev/null'
wait_for c.test 192.0.2.92
echo 'PASS record changes of member zones reach the secondary'
docker exec ispcp-catalog-primary sh -c 'sed -i "/a\.test/,/^};/d" /etc/bind/named.conf.local && rndc reconfig && ispconfig-dns-catalog sync'
served() { docker exec ispcp-catalog-secondary rndc zonestatus "$1" >/dev/null 2>&1; }
for _ in $(seq 1 30); do served a.test || break; sleep 1; done
if served a.test; then echo 'secondary still serves a.test'; exit 1; fi
served c.test
echo 'PASS a removed or disabled zone is removed from the secondary'
