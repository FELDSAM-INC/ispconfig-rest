#!/bin/bash
# The ACME DNS-01 clients of acme-dns/ end to end: the certbot plugin, the certbot hook script and the acme.sh plugin
# obtain certificates from Pebble (Let's Encrypt's test CA), whose DNS is pebble-challtestsrv, with stub_api.py in
# place of ispconfig-rest. The stub keeps records pending until their second status read, so clients that do not wait
# fail validation; afterwards no record may remain. Needs Docker and internet access for the images.
set -euo pipefail
cd "$(dirname "$0")/../../.."
NET=ispcp-acme-dns
KEY=isp_stubstubstubstubstubstubstubstubstubstub
PEBBLE=ghcr.io/letsencrypt/pebble:2.7.0
CHALLTESTSRV=ghcr.io/letsencrypt/pebble-challtestsrv:2.7.0
SERVER=https://pebble:14000/dir
LOGS=$(mktemp -d)

cleanup() {
    docker rm -f ispcp-acme-pebble ispcp-acme-challtestsrv ispcp-acme-stub ispcp-acme-client >/dev/null 2>&1 || true
    docker network rm "$NET" >/dev/null 2>&1 || true
}
fail() { echo "FAIL: $*" >&2; exit 1; }
cleanup
[ "${KEEP:-0}" = 1 ] || trap cleanup EXIT

docker build -q -t ispcp-acme-client -f tests/Integration/acme-dns/client.Dockerfile tests/Integration/acme-dns >/dev/null
docker network create --subnet 10.231.81.0/24 "$NET" >/dev/null
docker run -d --name ispcp-acme-challtestsrv --network "$NET" --ip 10.231.81.53 "$CHALLTESTSRV" \
    -defaultIPv4 10.231.81.53 -dns01 :8053 -management :8055 -http01 '' -https01 '' -tlsalpn01 '' -doh '' >/dev/null
docker run -d --name ispcp-acme-pebble --network "$NET" --network-alias pebble -e PEBBLE_VA_NOSLEEP=1 -e PEBBLE_WFE_NONCEREJECT=0 \
    "$PEBBLE" -config /test/config/pebble-config.json -dnsserver 10.231.81.53:8053 >/dev/null
docker run -d --name ispcp-acme-stub --network "$NET" --network-alias stub -e STUB_KEY="$KEY" -e STUB_ZONES=example.test \
    -e CHALLTESTSRV=http://10.231.81.53:8055 -v "$PWD/tests/Integration/acme-dns/stub_api.py:/stub_api.py:ro" \
    python:3.12-slim python3 /stub_api.py >/dev/null
docker run -d --name ispcp-acme-client --network "$NET" -v "$PWD:/repo:ro" -e REQUESTS_CA_BUNDLE=/tmp/pebble.pem \
    ispcp-acme-client sleep infinity >/dev/null
docker cp ispcp-acme-pebble:/test/certs/pebble.minica.pem - | docker exec -i ispcp-acme-client tar -x -C /tmp
docker exec ispcp-acme-client mv /tmp/pebble.minica.pem /tmp/pebble.pem
client() { docker exec ispcp-acme-client "$@"; }
client sh -c 'cp -r /repo/acme-dns/certbot /tmp/plugin && pip install --quiet --root-user-action=ignore --disable-pip-version-check --no-cache-dir --no-deps /tmp/plugin && cp /repo/acme-dns/acme.sh/dns_ispconfig_rest.sh /root/.acme.sh/dnsapi/'
client sh -c 'cd /tmp/plugin && python -m unittest discover -s tests 2>&1 | tail -n 1'
client sh -c "until curl -s --cacert /tmp/pebble.pem $SERVER >/dev/null; do sleep 1; done"
echo "certbot $(client certbot --version 2>&1 | awk '{print $2}'), acme.sh $(client /root/.acme.sh/acme.sh --version | tail -n 1)"
certbot=(certbot certonly -n --agree-tos -m test@example.test --server "$SERVER" --config-dir /tmp/le --work-dir /tmp/le-work --logs-dir /tmp/le-logs)

echo "== certbot plugin: example.test and *.example.test (one record name, two values)"
client sh -c "umask 077; printf 'dns_ispconfig_rest_url = http://stub:8080\ndns_ispconfig_rest_key = $KEY\n' > /tmp/ispconfig-rest.ini"
client "${certbot[@]}" --authenticator dns-ispconfig-rest --dns-ispconfig-rest-credentials /tmp/ispconfig-rest.ini \
    --dns-ispconfig-rest-propagation-seconds 0 --cert-name plugin -d example.test -d '*.example.test' >/dev/null 2>&1 \
    || { client tail -n 30 /tmp/le-logs/letsencrypt.log; fail "certbot plugin"; }
client openssl x509 -in /tmp/le/live/plugin/cert.pem -noout -ext subjectAltName | tail -n 1

echo "== certbot hook script: hook.example.test"
client env ISPC_REST_URL=http://stub:8080 ISPC_REST_KEY="$KEY" ISPC_REST_PROPAGATION=0 "${certbot[@]}" --manual \
    --preferred-challenges dns --manual-auth-hook '/repo/acme-dns/certbot-hook/ispconfig-rest-certbot-hook auth' \
    --manual-cleanup-hook '/repo/acme-dns/certbot-hook/ispconfig-rest-certbot-hook cleanup' --cert-name hook -d hook.example.test >/dev/null 2>&1 \
    || { client grep -iE 'hook|detail|error' /tmp/le-logs/letsencrypt.log | grep -v Traceback | tail -n 20; fail "certbot hook"; }
client openssl x509 -in /tmp/le/live/hook/cert.pem -noout -ext subjectAltName | tail -n 1

echo "== acme.sh plugin: acmesh.example.test and *.acmesh.example.test"
client env ISPC_REST_URL=http://stub:8080 ISPC_REST_KEY="$KEY" /root/.acme.sh/acme.sh --home /root/.acme.sh --issue \
    --dns dns_ispconfig_rest --dnssleep 1 --server "$SERVER" --ca-bundle /tmp/pebble.pem \
    -d acmesh.example.test -d '*.acmesh.example.test' >"$LOGS/acmesh.log" 2>&1 || { tail -n 30 "$LOGS/acmesh.log"; fail "acme.sh plugin"; }
client openssl x509 -in /root/.acme.sh/acmesh.example.test_ecc/acmesh.example.test.cer -noout -ext subjectAltName | tail -n 1
client grep -q "^SAVED_ISPC_REST_URL='http://stub:8080'" /root/.acme.sh/account.conf || fail "acme.sh did not keep the settings"

echo "== Refusals reach the user"
if client env ISPC_REST_URL=http://stub:8080 ISPC_REST_KEY=wrong ISPC_REST_PROPAGATION=0 CERTBOT_DOMAIN=example.test CERTBOT_VALIDATION=abc \
    /repo/acme-dns/certbot-hook/ispconfig-rest-certbot-hook auth 2>"$LOGS/hook.err"; then fail "wrong key accepted"; fi
grep -q 'HTTP 401): The provided API key is invalid' "$LOGS/hook.err" || { cat "$LOGS/hook.err"; fail "hook error message"; }
cat "$LOGS/hook.err"

echo "== Nothing remains"
state=$(client curl -s http://stub:8080/state)
echo "$state" | python3 -c 'import json,sys; s=json.load(sys.stdin); print("records left:", len(s["records"]), "requests:", len(s["seen"])); sys.exit(1 if s["records"] else 0)' \
    || fail "challenge records were left behind"
echo PASS
