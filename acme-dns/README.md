# DNS challenges with certbot and acme.sh

Servers outside ISPConfig, such as VPS servers, can obtain Let's Encrypt certificates for domains whose DNS zones are
hosted in ISPConfig. They answer the DNS-01 challenge through ispconfig-rest, so wildcard certificates and servers
without a public web server work too. Needs ispconfig-rest 1.0.7 or newer.

| Client | What to install |
| --- | --- |
| acme.sh | [`acme.sh/dns_ispconfig_rest.sh`](acme.sh/dns_ispconfig_rest.sh), one file |
| certbot installed with pip | the [`certbot-dns-ispconfig-rest`](certbot/) plugin |
| certbot from snap or the distribution | the hook script [`certbot-hook/ispconfig-rest-certbot-hook`](certbot-hook/ispconfig-rest-certbot-hook) (needs curl) |

All three publish the TXT record `_acme-challenge.<domain>`, wait until ISPConfig's DNS server has applied it, and
remove it after validation. They do not wait a fixed time: ispconfig-rest reports when the record is live (usually
within a minute, ISPConfig's server cron). ISPConfig mirrors are included; for other secondary servers the certbot
clients wait 30 more seconds, and acme.sh checks public DNS itself.

## 1. Create a key on the ISPConfig panel

Use a key that can only publish challenges. Any other request with it returns `403`:

```sh
sudo ispconfig-rest key:create "vps1 certificates" --acme                      # every zone
sudo ispconfig-rest key:create "vps1 certificates" --acme --zone example.com   # only these zones (repeatable)
sudo ispconfig-rest key:create "client 42 certificates" --acme --client-id 42  # only that client's zones
```

The same works over HTTP with an admin key: `POST /api/v1/system/api-keys` with
`{"name": "vps1", "access": "acme", "zones": ["example.com"]}` (and `client_id`). `key:list` shows the access;
revoke a key with `key:revoke ID`.

The record goes into the zone that is authoritative for the name. That's the longest zone in ISPConfig whose origin
ends the name, so a delegated subzone wins over its parent. The key must be allowed to use that zone. A client's
challenge records count against its DNS record limit while they exist.

The server running certbot or acme.sh must reach ispconfig-rest (default port 8090). Its certificate should be valid.
The ISPConfig panel's Let's Encrypt certificate is, or point the clients to a CA file. ISPConfig's self-signed fallback
certificate has no subjectAltName, which the certbot plugin's TLS library refuses, even with that certificate as CA file.

## 2a. acme.sh

```sh
cp dns_ispconfig_rest.sh ~/.acme.sh/dnsapi/
export ISPC_REST_URL=https://panel.example.com:8090
export ISPC_REST_KEY=isp_...
acme.sh --issue --dns dns_ispconfig_rest -d example.com -d '*.example.com'
```

acme.sh saves `ISPC_REST_URL` and `ISPC_REST_KEY` in its `account.conf` for renewals. `ISPC_REST_TIMEOUT` (default
300) limits the wait for ISPConfig. acme.sh then checks the record through public DNS before validation;
`--dnssleep 30` replaces that check with a fixed wait, for example for zones not reachable from public resolvers.
For ispconfig-rest with a private CA, use acme.sh's `--ca-bundle`.

## 2b. certbot with the plugin

Install the plugin into certbot's Python environment, for example a certbot installed in `/opt/certbot` with pip:

```sh
/opt/certbot/bin/pip install ./acme-dns/certbot     # from a checkout of ispconfig-rest
```

`/etc/letsencrypt/ispconfig-rest.ini`, mode 600:

```ini
dns_ispconfig_rest_url = https://panel.example.com:8090
dns_ispconfig_rest_key = isp_...
# dns_ispconfig_rest_ca_bundle = /etc/ssl/certs/private-ca.pem
```

```sh
certbot certonly --authenticator dns-ispconfig-rest \
  --dns-ispconfig-rest-credentials /etc/letsencrypt/ispconfig-rest.ini -d example.com -d '*.example.com'
```

`--dns-ispconfig-rest-timeout` (default 300) limits the wait for ISPConfig. `--dns-ispconfig-rest-propagation-seconds`
(default 30) is the extra wait for secondary DNS servers afterwards; use 0 when all name servers are ISPConfig
servers. Renewals keep these settings.

## 2c. certbot from snap or the distribution, with the hook script

Snap and distribution packages of certbot cannot load pip plugins; manual hooks work with any certbot:

```sh
install -m 755 ispconfig-rest-certbot-hook /usr/local/bin/
install -m 600 /dev/null /etc/letsencrypt/ispconfig-rest.conf
cat > /etc/letsencrypt/ispconfig-rest.conf <<'EOF'
ISPC_REST_URL=https://panel.example.com:8090
ISPC_REST_KEY=isp_...
EOF
certbot certonly --manual --preferred-challenges dns \
  --manual-auth-hook 'ispconfig-rest-certbot-hook auth' \
  --manual-cleanup-hook 'ispconfig-rest-certbot-hook cleanup' -d example.com -d '*.example.com'
```

The script reads its settings from that file (`ISPC_REST_CONFIG` picks another one), and the environment overrides
them. Further settings: `ISPC_REST_CA_BUNDLE`, `ISPC_REST_TIMEOUT` (default 300) and `ISPC_REST_PROPAGATION`
(default 30). The key goes to curl on standard input, so it never appears in the process list. certbot keeps the hooks
for renewals.

## The API

The clients use three calls, available to every key with access to the zone:

```http
POST /api/v1/dns/acme-challenges              {"name": "_acme-challenge.www.example.com", "value": "<token>"}
GET  /api/v1/dns/acme-challenges/{id}         status: pending | applied | failed | stalled
DELETE /api/v1/dns/acme-challenges?name=_acme-challenge.www.example.com&value=<token>
```

The records get TTL 60. Publishing the same name and value again returns the existing record. A zone holds at most
100 challenge records at a time. `DELETE /api/v1/dns/acme-challenges/{id}` removes one record by id. Other DNS records
are invisible to these calls. See the OpenAPI description at `/api/documentation`.

## Tests

```sh
bash tests/Integration/acme-dns/run.sh   # the three clients against Pebble with a stand-in API (Docker)
```

The Ansible end-to-end test (`tests/Integration/ansible/run.sh`) also obtains certificates with all three clients
from a real ISPConfig DNS server through ispconfig-rest.
