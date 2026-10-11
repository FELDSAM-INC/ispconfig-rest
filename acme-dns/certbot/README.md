# certbot-dns-ispconfig-rest

Certbot DNS authenticator for domains whose zones are hosted in ISPConfig: it publishes the DNS-01 challenge through
[ispconfig-rest](https://github.com/FELDSAM-INC/ispconfig-rest) 1.0.7 or newer and waits until ISPConfig has applied
it. See the [ACME DNS guide](../README.md) for keys, installation and the hook script for snap certbot.

```sh
certbot certonly --authenticator dns-ispconfig-rest \
  --dns-ispconfig-rest-credentials /etc/letsencrypt/ispconfig-rest.ini -d example.com -d '*.example.com'
```

`/etc/letsencrypt/ispconfig-rest.ini` (mode 600):

```ini
dns_ispconfig_rest_url = https://panel.example.com:8090
dns_ispconfig_rest_key = isp_...
# dns_ispconfig_rest_ca_bundle = /etc/ssl/certs/private-ca.pem
```
