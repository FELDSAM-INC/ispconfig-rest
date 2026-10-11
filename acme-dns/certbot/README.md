# certbot-dns-ispconfig-rest

Certbot DNS authenticator for domains whose zones are hosted in [ISPConfig](https://www.ispconfig.org/). It publishes
the DNS-01 challenge through [ispconfig-rest](https://github.com/FELDSAM-INC/ispconfig-rest) 1.0.7 or newer, waits
until ISPConfig's DNS server has applied the record (no fixed sleep), and removes it after validation. Wildcard
certificates work.

## Install

Into the Python environment of a pip-installed certbot, for example one in `/opt/certbot`:

```sh
/opt/certbot/bin/pip install certbot-dns-ispconfig-rest
```

Certbot from snap or a distribution package cannot load pip plugins; use the
[hook script](https://github.com/FELDSAM-INC/ispconfig-rest/tree/main/acme-dns/certbot-hook) with those.

## Use

Create an API key on the ISPConfig panel that can only publish challenges:

```sh
sudo ispconfig-rest key:create "vps1 certificates" --acme [--zone example.com]
```

Put it in `/etc/letsencrypt/ispconfig-rest.ini` (mode 600):

```ini
dns_ispconfig_rest_url = https://panel.example.com:8090
dns_ispconfig_rest_key = isp_...
# dns_ispconfig_rest_ca_bundle = /etc/ssl/certs/private-ca.pem
```

```sh
certbot certonly --authenticator dns-ispconfig-rest \
  --dns-ispconfig-rest-credentials /etc/letsencrypt/ispconfig-rest.ini -d example.com -d '*.example.com'
```

| Option | Default | Meaning |
| --- | --- | --- |
| `--dns-ispconfig-rest-credentials` | (required) | the credentials file above |
| `--dns-ispconfig-rest-timeout` | 300 | seconds to wait until ISPConfig has applied a record |
| `--dns-ispconfig-rest-propagation-seconds` | 30 | extra wait afterwards for secondary DNS servers; 0 when all name servers are ISPConfig servers |

Renewals keep these settings. ispconfig-rest's certificate must be valid for its name; ISPConfig's Let's Encrypt
certificate is, ISPConfig's self-signed fallback (no subjectAltName) is refused.

See the [full guide](https://github.com/FELDSAM-INC/ispconfig-rest/blob/main/acme-dns/README.md), which also covers
acme.sh.

## License

BSD-3-Clause
