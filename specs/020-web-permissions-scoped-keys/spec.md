# Feature Specification: Web Permission Enforcement for Scoped Keys

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-15  
**Status**: Draft  
**Module**: sites  
**Input**: User description: "Web permission enforcement for scoped keys: client and reseller keys can set website options their plan does not allow (SSL, Let's Encrypt, CGI/SSI/Perl/Ruby/Python, suEXEC, error documents, wildcard subdomains, directive snippets), any PHP mode, any PHP version and the admin-only advanced settings. Mirror what legacy ISPConfig lets a client do; admin keys unchanged."

## Context

Features 011/012/016/019 made client-scoped API keys safe for reading and for record counts, quotas, server
placement and suspension. The website endpoints still accept every field from a client or reseller key:
a customer key can switch on free certificates or CGI on a plan that does not include them, pick a PHP
mode the provider disabled, point a website at another customer's private PHP version or write Apache
directives and PHP-FPM pool settings that the ISPConfig interface only shows to administrators.

The first consumer is the WHMCS ISPConfig module (spec 003 "Websites & Domains"), which lets customers
manage their websites with their own client-scoped key. This feature applies the ISPConfig 3.3.1p1 client
rules server-side so the panel cannot exceed the plan even if its own checks are wrong.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Plan flags hold for customer keys (Priority: P1)

A hosting customer whose plan does not include SSL certificates uses the panel (client key) to edit a
website. Requests that switch on SSL, Let's Encrypt, CGI, SSI, Perl, Ruby, Python, custom error documents,
wildcard subdomains or directive snippets are refused with a field error naming the option. Any website
save by the customer also clears plan options that are no longer included (for example after a downgrade)
and keeps suEXEC switched on when the plan forces it — exactly as saving the website in the ISPConfig
interface does.

**Why this priority**: These options are what providers sell as plan features; a customer key that can
switch them on defeats the plan. It is a viable MVP on its own.

**Independent Test**: Client A with `limit_ssl = n`, `limit_ssl_letsencrypt = n`, `limit_cgi = n`,
`force_suexec = y`. With A's key: `PUT /sites/web-domains/{id}` `{"ssl": true}` → 422 `errors.ssl`, no
datalog row; `{"cgi": true}` → 422; `{"suexec": false}` → 422. A website seeded with `cgi = 'y'` updated
with `{"active": true}` → 200 and the datalog shows `cgi = 'n'`. With the admin key the same requests
succeed unchanged.

**Acceptance Scenarios**:

1. **Given** a client key whose plan does not include an option (SSL, Let's Encrypt, CGI, SSI, Perl, Ruby,
   Python, error documents, wildcard subdomains, directive snippets), **When** it creates a website with
   that option enabled or changes an existing website to enable it, **Then** 422 problem+json with an error
   on that field, and nothing is written.
2. **Given** a plan that forces suEXEC, **When** a client key sets `suexec` to false on create or changes it
   to false, **Then** 422 on `suexec`.
3. **Given** a website that has options enabled which the plan no longer includes, **When** a client key
   saves any change to it, **Then** the save succeeds and the stored website has those options switched off
   (and suEXEC on when forced), in the same datalog entry.
4. **Given** a request that repeats an option's current value (for example a full-object PUT), **When** the
   value is not a change, **Then** it is not refused because of that option.
5. **Given** an admin key, **When** it sends any of these options, **Then** behavior is unchanged from today.

---

### User Story 2 - PHP modes and versions within the plan (Priority: P1)

The customer changes a website's PHP. The API only accepts PHP modes the provider allows for the account
(system-wide list intersected with the client's list) and PHP versions that exist, are active, belong to the
website's web server, are public or belong to the customer, and support the chosen mode. When the server
hides the default PHP version, a website with PHP enabled always gets a real version: omitted means the first
available version, an explicit "default" is refused.

**Why this priority**: PHP choice is the most common website setting in the panel; an invalid or foreign
version breaks the website or leaks another customer's configuration.

**Independent Test**: System `web_php_options = no,fast-cgi,php-fpm`, client A `web_php_options =
no,php-fpm`, server 1 with versions 1 (public) and 7 (private to client B) and `php_default_hide = y`.
A's key: create with `php = fast-cgi` → 422 `errors.php`; create with `php = php-fpm` and no version →
201 with `server_php_id = 1`; update `server_php_id = 7` → 422; update `server_php_id = 999` → 422;
update `{"php": "php-fpm", "server_php_id": 0}` on a site that had version 1 → 422.

**Acceptance Scenarios**:

1. **Given** a client key, **When** it sets `php` to a mode outside the account's allowed modes and the value
   is a change, **Then** 422 on `php`.
2. **Given** a client key, **When** it sets a non-zero `server_php_id` that does not exist, is inactive, is on
   another server, belongs to another client, or does not support the resulting PHP mode, **Then** 422 on
   `server_php_id`.
3. **Given** a PHP mode without selectable versions (`no`, `mod`, `cgi`, `suphp`, `hhvm`), **When** a website
   is saved, **Then** `server_php_id` is stored as 0 (existing legacy behavior, all keys).
4. **Given** a web server with the default PHP version hidden, **When** a client key saves a website with PHP
   in `fast-cgi` or `php-fpm` mode and no version results, **Then** the first available version for that mode
   (lowest sort priority, then lowest id) is stored; **When** it sends `server_php_id = 0` as a change,
   **Then** 422; **When** no version exists for the mode, **Then** 422 on `server_php_id`.
5. **Given** an admin key, **When** it sets any mode or version, **Then** behavior is unchanged.

---

### User Story 3 - Administrator-only settings stay with the administrator (Priority: P2)

A customer key cannot change the settings ISPConfig shows only to administrators on the website "Options"
tab (PHP-FPM pool, custom php.ini, open_basedir, Apache/nginx/proxy directives, AllowOverride, HTTP/HTTPS
ports, log retention, jailkit, symlink protection), nor the certificate fields of the "SSL" tab when the
plan has no SSL. Resellers may change the Options-tab settings only when the system setting "Resellers can
use the option tab" is enabled. Plain clients also cannot rename a website or change its IP addresses or
vhost type, which the ISPConfig interface shows them read-only.

**Why this priority**: These settings can break the server or bypass isolation, but a well-behaved panel
never sends them; enforcement is defence in depth.

**Independent Test**: Client A's key: `PUT {"nginx_directives": "return 301 /;"}` → 422
`errors.nginx_directives`; `PUT {"domain": "renamed.test"}` on a vhost → 422; `POST
/sites/web-domains/{id}/ssl` on a plan without SSL → 403. Reseller key with `reseller_can_use_options = y`:
`PUT {"pm": "static"}` → 200; with `n` → 422. Admin key: all succeed.

**Acceptance Scenarios**:

1. **Given** a client key (or a reseller key while the reseller option is off), **When** it sets an
   Options-tab setting to a value different from the current (update) or default (create) value, **Then**
   422 on that field.
2. **Given** a plan without SSL, **When** a client or reseller key changes an SSL certificate field on the
   website or calls the certificate upload, delete or renew operation, **Then** 422 on the field for website
   writes and 403 for the certificate operations; reading the certificate stays allowed. Renewal additionally
   requires Let's Encrypt in the plan.
3. **Given** a plain client key (not a reseller), **When** it changes `domain`, `ip_address`, `ipv6_address`
   or `vhost_type` of an existing website of type `vhost`, **Then** 422 on that field.
4. **Given** a client key, **When** it sets `subdomain = "*"` on a subdomain or alias website
   (`vhostsubdomain`, `vhostalias`), **Then** 422 on `subdomain`.
5. **Given** an admin key, **When** it changes any of these settings, **Then** behavior is unchanged.

### Edge Cases

- Missing/invalid `X-API-Key` → 401; unreadable website → 404 before any permission check (existing order).
- A request with several forbidden fields gets one error per field in the same 422 response.
- The acting account has no client row (orphan key identity): every plan flag counts as not included, no PHP
  mode is allowed, suEXEC is not forced (legacy query returns no row).
- A reseller key acting on one of its clients' websites is checked against the reseller's own plan (legacy
  uses the logged-in user's limits), not the owning client's.
- The system-wide `web_php_options` list is empty or missing: only the client list applies (legacy would show
  only the current value; owner-delegated decision to treat "not configured" as "no system restriction").
- `server_php_id` that equals the stored value while only another field changes is not re-validated, so an
  existing website keeps working after a version is deactivated until the customer touches PHP.
- Legacy writes the invalid subdomain value `n` when wildcard is not allowed; the API refuses the request
  instead (422), so no invalid value is stored.
- Child domains (`/sites/web-child-domains`) have no client-restricted fields in the API contract; unchanged.
- The ISPConfig interface maps `fast-cgi` to `php-fpm` for display on nginx servers; the API stores what was
  sent (unchanged).
- Quota and record-count limits stay with feature 012; server placement with 016; locked accounts with 019.

## API Contract *(mandatory)*

- **Spec file(s)**: `api/modules/sites/web-domains.yaml` (existing — POST/PUT descriptions and 422 notes
  updated; SSL subresource POST/DELETE and renew gain documented 403 cases).
- **Shared schemas**: `api/components/schemas/WebDomain.yaml` (existing — field descriptions state which
  fields are restricted for client and reseller keys).
- **Endpoints** (no new paths; behavior for client and reseller keys only):

| Method | Path | Change | Success code |
|--------|------|--------|--------------|
| POST | `/api/v1/sites/web-domains` | plan flags, PHP mode/version, Options/SSL-tab fields validated (422); forced flags and default PHP version applied | 201 |
| PUT | `/api/v1/sites/web-domains/{id}` | same, plus read-only identity fields for plain clients; forced flags applied on every save | 200 |
| POST | `/api/v1/sites/web-domains/{id}/ssl` | 403 when the plan has no SSL | 200 |
| DELETE | `/api/v1/sites/web-domains/{id}/ssl` | 403 when the plan has no SSL | 204 |
| POST | `/api/v1/sites/web-domains/{id}/ssl/renew` | 403 when the plan has no SSL or no Let's Encrypt | 200 |

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference** (ISPConfig 3.3.1p1, read on isp-test.feldhost.cz):
  - `interface/web/sites/web_vhost_domain_edit.php` `onSubmit()` lines 979–996 (plan flags forced for every
    non-admin save), 1284–1304 (server_php_id reset by mode/active/binary), 1507–1546
    `validateDefaultFastcgiPhpVersion()` (hidden default version), 236–270 (client PHP version list:
    `server_id`, `client_id = 0 OR own`, `active = 'y'`, mode binaries).
  - `interface/web/sites/form/web_vhost_domain.tform.php` lines 78–100 (domain tab read-only for plain
    clients on vhosts; wildcard hidden for child types and when `limit_wildcard != y`; SSL tab only when
    `limit_ssl = y`), 254 (`php` valuelimit `system:sites:web_php_options;client:web_php_options`), 791–795
    (Options tab only for admins, or resellers when `sites.reseller_can_use_options = y`).
  - `interface/lib/classes/tform_base.inc.php` `applyValueLimit()` 339–430 (system ∩ client intersection).
  - `interface/web/sites/ajax_get_json.php` 66–125 (`getserverphp`: ordering by `sortprio`).
- **Legacy behaviors to mirror**: forced plan flags on every non-admin save; mode-dependent `server_php_id`
  reset; client PHP version visibility; Options/SSL tab visibility; domain-tab read-only fields for plain
  clients; first-listed PHP version when the default is hidden.
- **Tables written (via datalog only)**: `web_domain` — actions i/u, unchanged mechanism; forced values are
  part of the same entry. No new tables, no direct writes.
- **System fields handling**: unchanged.
- **Intentional deviations from legacy** (owner-delegated decisions 2026-09-15):
  - UI-only legacy restrictions (PHP mode list, PHP version list, Options/SSL tab visibility, domain-tab
    read-only fields, wildcard option) are enforced server-side with 422/403, because the API has no form.
  - Explicitly enabling a forbidden plan flag is refused (422) instead of silently cleared; values that are not
    a change are accepted and then forced like legacy.
  - Wildcard refusal instead of legacy's invalid stored value `n`.
  - An empty or missing system `web_php_options` does not restrict PHP modes.
  - Legacy's confirm-once warning for websites still on the hidden default version is not reproduced: an
    unchanged `server_php_id = 0` is accepted.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: For client and reseller keys, the system MUST refuse (422, field error) a website create or
  update that enables an option the acting account's plan does not include: `ssl` (`limit_ssl`),
  `ssl_letsencrypt` (`limit_ssl_letsencrypt`), `cgi`, `ssi`, `perl`, `ruby`, `python` (`limit_cgi`,
  `limit_ssi`, `limit_perl`, `limit_ruby`, `limit_python`), `errordocs = 1` (`limit_hterror`),
  `subdomain = "*"` (`limit_wildcard`), non-zero `directive_snippets_id` (`limit_directive_snippets`), and
  `suexec = false` when `force_suexec = y`. A value equal to the current (update) or default (create) value
  is not a violation.
- **FR-002**: For client and reseller keys, every website create and update MUST store the plan-forced values
  of FR-001 (options not included switched off, suEXEC on when forced, `errordocs` 0, `directive_snippets_id`
  0) in the same datalog entry, mirroring legacy `onSubmit`.
- **FR-003**: For client and reseller keys, `php` MUST be one of the account's allowed modes (system
  `sites.web_php_options` intersected with `client.web_php_options`; an empty system list does not restrict)
  when it is a change; otherwise 422.
- **FR-004**: For client and reseller keys, a non-zero `server_php_id` that is a change (or whose PHP mode
  changes) MUST reference an active PHP version on the website's web server (the parent's server for
  `vhostsubdomain`/`vhostalias`), with `client_id` 0 or the acting account's client or the website owner's
  client, that supports the resulting mode (`php-fpm`: FPM init script, ini and pool directories; `fast-cgi`:
  FastCGI binary and ini directory); otherwise 422.
- **FR-005**: For every key, `server_php_id` MUST be stored as 0 when the resulting PHP mode is not `php-fpm`
  or `fast-cgi` (existing behavior kept).
- **FR-006**: For client and reseller keys, when the web server's `php_default_hide = y` and the resulting mode
  is `php-fpm` or `fast-cgi`: an explicit `server_php_id = 0` that is a change MUST be refused (422); a
  resulting version of 0 otherwise MUST be replaced by the first available version for that mode and account
  (lowest `sortprio`, then lowest id); no available version MUST be refused (422 on `server_php_id`).
- **FR-007**: For client keys, and for reseller keys unless `sites.reseller_can_use_options = y`, the Options-tab
  fields (`allow_override`, `proxy_protocol`, `php_fpm_use_socket`, `php_fpm_chroot`, `pm`, `pm_max_children`,
  `pm_start_servers`, `pm_min_spare_servers`, `pm_max_spare_servers`, `pm_process_idle_timeout`,
  `pm_max_requests`, `disable_symlinknotowner`, `php_open_basedir`, `custom_php_ini`, `apache_directives`,
  `nginx_directives`, `proxy_directives`, `http_port`, `https_port`, `log_retention`,
  `jailkit_chroot_app_sections`, `jailkit_chroot_app_programs`, `delete_unused_jailkit`) MUST NOT change:
  a value different from the current (update) or default (create) value is refused with 422.
- **FR-008**: For client and reseller keys whose plan has `limit_ssl != y`, the SSL-tab website fields
  (`ssl_state`, `ssl_locality`, `ssl_organisation`, `ssl_organisation_unit`, `ssl_country`, `ssl_domain`) MUST
  NOT change (422), and `POST`/`DELETE /sites/web-domains/{id}/ssl` MUST return 403. `POST
  /sites/web-domains/{id}/ssl/renew` MUST return 403 unless both `limit_ssl` and `limit_ssl_letsencrypt` are
  `y`. `GET /sites/web-domains/{id}/ssl` is unchanged.
- **FR-009**: For plain client keys (not resellers), `domain`, `ip_address`, `ipv6_address` and `vhost_type` of
  an existing website of type `vhost` MUST NOT change (422).
- **FR-010**: For client and reseller keys, `subdomain = "*"` MUST be refused (422) on `vhostsubdomain` and
  `vhostalias` websites.
- **FR-011**: All refusals MUST happen before any database write; a 422 response lists every violated field.
- **FR-012**: Admin keys MUST keep today's behavior for all website and certificate operations.
- **FR-013**: The contract MUST document the restrictions per field and the 403 cases, and every rule MUST be
  covered by feature tests for client, reseller and admin keys.

### Key Entities

- **Account web permissions**: the acting account's plan flags and PHP modes — table `client` (`limit_ssl`,
  `limit_ssl_letsencrypt`, `limit_cgi`, `limit_ssi`, `limit_perl`, `limit_ruby`, `limit_python`,
  `force_suexec`, `limit_hterror`, `limit_wildcard`, `limit_directive_snippets`, `web_php_options`) and
  `sys_ini` `[sites]` (`web_php_options`, `reseller_can_use_options`); no schema, derived per request.
- **PHP version**: table `server_php`, schema `api/components/schemas/ServerPhp.yaml`, model
  `app/Models/ServerPhp.php`; the website web server's `[web]` config (`php_default_hide`, `server_type`).
- **Website**: table `web_domain`, schema `api/components/schemas/WebDomain.yaml`, model
  `app/Models/WebDomain.php`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: With a customer key, 0 website option changes beyond the plan are accepted across all fields
  listed in FR-001, FR-003, FR-004, FR-007–FR-010 (verified by one test per field).
- **SC-002**: A customer saving any website after a plan downgrade leaves 0 forbidden plan options enabled on
  that website.
- **SC-003**: Every website saved by a customer key on a server that hides the default PHP version has a real
  PHP version when PHP is enabled.
- **SC-004**: All existing admin-key website and certificate tests pass without modification.
- **SC-005**: A refused request writes 0 datalog rows and lists every violated field in one response.

## Assumptions

- The consumer (WHMCS module spec 003) reads the allowed options and PHP versions through feature 021
  (`GET /me/capabilities`, `GET /me/php-versions`); this feature enforces, 021 describes.
- Record counts and quota sums (feature 012), server placement (016), locked accounts (019) and backups (018)
  are unchanged and run in their existing order.
- IP address restrictions (`client.limit_web_ip`) are out of scope; plain clients cannot change IPs of vhosts
  (FR-009) and new websites keep the existing default (`*`).
- `limit_web_quota`/`limit_traffic_quota` sums stay with feature 012.
- The legacy "default PHP version hidden" confirmation dialog has no API equivalent.
