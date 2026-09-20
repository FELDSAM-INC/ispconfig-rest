# Website automatic alias

Expose nullable, read-only `auto_alias` on the existing web-domain list/detail/create/update responses. Resolve the server's `[web] website_autoalias` for the resource's server and owning group, never the requesting reseller/admin. No additional client permission or administrator credential is needed beyond access to the website. Shared alias/subdomain resources do not generate their own autoalias.

Parity reference: ISPConfig 3.3.1p1 official release archive, `server/plugins-available/apache2_plugin.inc.php:855–857,1412–1421` and `nginx_plugin.inc.php:699–701,1650–1660`, also verified against the installed sources on isp-test.feldhost.cz. Both use literal replacement of `[client_id]`, `[website_id]`, `[client_username]`, `[website_domain]`. Client ID comes directly from the vhost's `sys_groupid`; vhost children use their own domain ID/name even though their system user/docroot belongs to the parent.

The API intentionally returns null for a result that cannot be used as a single hostname (wildcards, unknown placeholders, URLs, whitespace, malformed labels), rather than inventing an address. It does not expose the configuration template or other configuration fields. It makes no readiness, DNS, or TLS claim and changes no website configuration, DNS or datalog rows.

Validation: Apache/nginx and all vhost types, each placeholder and resource owner, no configuration/missing server, malformed values, live configuration refresh, client/reseller ownership, unauthenticated requests, shared child exclusion and response-only create/update behavior. Deploy API before the consuming WHMCS panel.
