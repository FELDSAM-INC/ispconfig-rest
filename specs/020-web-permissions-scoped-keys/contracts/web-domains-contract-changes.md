# Contract Changes: Web Domains (spec 020)

No new paths, parameters or schemas. Descriptions only; 403/422 responses are already declared on the affected
operations of `api/modules/sites/web-domains.yaml`.

## `api/modules/sites/web-domains.yaml`

- `POST /sites/web-domains` and `PUT /sites/web-domains/{id}` descriptions gain a "Client and reseller keys"
  paragraph:
  - options the account's plan does not include (`ssl`, `ssl_letsencrypt`, `cgi`, `ssi`, `perl`, `ruby`, `python`,
    `errordocs`, wildcard `subdomain`, `directive_snippets_id`) and `suexec` when the plan forces it are refused with
    422 when requested, and switched off (suEXEC on) on every save;
  - `php` must be an allowed PHP mode; `server_php_id` must be a usable PHP version of the website's server; with a
    hidden default version the first usable version is used when none is given;
  - Options-tab fields cannot change unless the account is a reseller and resellers may use the Options tab;
  - SSL-tab fields cannot change without SSL in the plan;
  - plain clients cannot change `domain`, `ip_address`, `ipv6_address`, `vhost_type` of a website;
  - `subdomain = "*"` is refused for `vhostsubdomain` and `vhostalias`;
  - admin keys are not restricted.
- `POST /sites/web-domains/{id}/ssl`, `DELETE /sites/web-domains/{id}/ssl`: description adds "Client and reseller
  keys whose plan does not include SSL receive 403."
- `POST /sites/web-domains/{id}/ssl/renew`: adds "…whose plan does not include SSL and Let's Encrypt receive 403."

## `api/components/schemas/WebDomain.yaml`

Append to the description of each restricted property a short sentence, e.g.:

- `ssl`: "Client and reseller keys: requires SSL in the account's plan (`limit_ssl`)."
- `server_php_id`: "Client and reseller keys: must be an active PHP version of the website's server that is public
  or belongs to the account and supports the PHP mode; see `GET /me/php-versions`."
- Options-tab properties: "Administrator setting — client keys (and reseller keys unless resellers may use the
  Options tab) cannot change it."
