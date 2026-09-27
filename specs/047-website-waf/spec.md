# Website WAF

Provide a root-installed ModSecurity/OWASP CRS integration on each ISPConfig web
server, with website-scoped REST controls and a WHMCS WAF tile/tab. Support Apache
and nginx on Debian/Ubuntu package installations; reject unsupported installations
without replacing administrator configuration.

- Installation makes the engine available. A website is enabled explicitly, initially
  in detection mode, with enforcing mode selectable. No silent global blocking.
- Native website directives are written through ISPConfig's datalog. Never grant
  customers raw rule text, include paths, licensing secrets or server-wide settings.
- OWASP CRS is the base ruleset. Atomicorp is an optional additional ruleset with
  its license key stored on that server, never in customer API responses.
- Support rule exclusions, exact-path/argument exclusions, and website-only IP/CIDR
  allowlists. Creating an exclusion from an event defaults to its rule and path.
- Events distinguish a WAF intervention from a detection; an application 403 alone
  is not proof of a WAF block. Publish only bounded, normalized event metadata,
  without request bodies, credentials, cookies, query values or response bodies.
- Tenant authorization is repeated for reads and writes, including vhost children.
  Simple aliases share their primary vhost's WAF and are not separate WAF resources.

## Research

The installed ISPConfig Apache/nginx plugins accept native per-vhost directives
and validate/reload them via the usual datalog path. No native WAF resource exists.
Apache uses ModSecurity 2; nginx uses libmodsecurity 3 and its matching connector.
Debian/Ubuntu packages avoid compiling an ABI-mismatched nginx module.

https://github.com/owasp-modsecurity/ModSecurity-nginx
https://coreruleset.org/docs/
https://docs.atomicorp.com/gotrootModsec/remoterules.html
https://docs.atomicorp.com/gotrootModsec/index.html

Atomicorp documents SecRemoteRules with an API key for both engines (Apache >=
2.9.5, libmodsecurity >=3.0.6). Its licensing is per server. The paid feed cannot be
claimed verified without a real license; normal OWASP operation must not depend on it.

## Validation

Feature tests: tenant isolation, suspended/read-only accounts, injection rejection,
no-op datalog, exclusions and logging privacy. Disposable Apache/nginx acceptance:
benign requests, detection/enforcement, scoped exclusions, IP allowlist and events.
WHMCS: both themes, real vars/minified.css, desktop/mobile, CSRF and modal forms.

Validation completed: REST suite 1,510 tests (one existing skip); module suite
3,479 tests on PHP 7.4 and 8.3 (four existing skips). Native Apache/nginx checks
verify intervention evidence, cross-vhost isolation, narrow exceptions, log-file
safety, preserving original nginx logs, and rollback of invalid configuration.
Both public installers pass from a root-owned staged release. Desktop/mobile WAF
renders and existing website tab/anchor tests pass with real theme CSS.

Development deployment: installed the root tool on `isp-test` (Apache,
OWASP CRS 3.3.5-2), ran the API migration and verified the three customer sites
advertise WAF. A customer-key write enabled detection temporarily on website 19;
ISPConfig regenerated the real vhost, a localhost request to a nonexistent probe
path produced rule 942100, and the worker/customer API returned a sanitized
`detected` event. Original disabled settings were restored and the native vhost
and Apache configuration validated afterward. No licensed Atomicorp key was
provided or installed. WHMCS CI deployment matched all 18 changed module files,
including its expected licensing transformation.

## Application profiles (2026-09-27)

Owner requested a per-website application selector after reviewing public CRS exclusions. On development (`isp-test`, CRS 3.3.5-2) verified the six distribution `REQUEST-903.*-EXCLUSION-RULES.conf` files and their `tx.crs_exclusions_*` guards. Implement None plus available WordPress, Drupal, Nextcloud, DokuWiki, cPanel and XenForo profiles; discover on each root webserver worker, never on the API master. Do not offer unverified Joomla/PrestaShop options or assume CRS 4 plugins are loaded. Source and upgrade details are in `waf-server/README.md`.

API-owned worker capability column only; no native schema change. Strict profile enum, normal tenant authorization, datalog/CAS writes, backwards-compatible markers, preserved manual exceptions, safe disabling during worker/profile loss. Root configuration resets application flags after CRS setup so a selection does not affect another website. Native Apache/nginx tests cover actual Gutenberg exemptions and continued blocking on other paths, parameters and another vhost, including None after WordPress and global setup overrides.

Validation: REST suite 1,521 tests / 11,672 assertions (one existing skip); module suite 3,483 tests / 100,962 assertions (four existing skips) on PHP 7.4 and 8.3. Both native engines and public installers passed. Browser renders passed at 1440px and 390px in default and Lagom2 themes with `vars/minified.css` loaded.
