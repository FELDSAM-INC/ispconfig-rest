# Feature Specification: Account Capabilities for Scoped Keys

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-15  
**Status**: Draft  
**Module**: me  
**Input**: User description: "Account capabilities for scoped keys: `GET /me/capabilities` (plan web flags, allowed PHP modes, locked/canceled; admin keys pass `client_id` or get 422) and `GET /me/php-versions` (PHP versions on the key's web servers per mode). Must fit the WHMCS module's `specs/003-websites-domains/contracts/ispconfig-rest-calls.md`."

## Context

Feature 020 made the website endpoints enforce the plan of client and reseller keys: plan flags, allowed PHP
modes, usable PHP versions and administrator-only settings. A customer panel still cannot *see* those rules:
the plan columns live in `/clients/**` (reseller/admin only), PHP versions in `/servers/{id}/php-versions`
(admin only), and the lock state of the account is not readable with the customer's own key. The WHMCS
ISPConfig module (spec 003 "Websites & Domains") therefore hides HTTPS and PHP version choices and only learns
that an account is suspended after a refused write.

This feature adds two read-only endpoints to the `me` module that describe, for the calling key's account (or a
named client), exactly what feature 020 lets that account's own key do — so a panel can offer only choices the
API will accept.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Panel reads the plan's website capabilities (Priority: P1)

A customer opens the websites page of the panel, which uses the customer's client key. The panel reads the
account's capabilities once and shows the HTTPS / free certificate switch only when the plan includes SSL and
Let's Encrypt, offers the wildcard subdomain option only when included, lists only the PHP modes the plan
allows (preselecting the mode a new website gets when none is chosen), and shows a "suspended" notice before
the customer tries to change anything when the account is locked.

**Why this priority**: without it, the panel either offers options the API refuses (feature 020) or hides
features customers pay for; it is the blocker for the module's HTTPS and PHP tasks.

**Independent Test**: create clients with different plan flags, `web_php_options` and lock states; call
`GET /me/capabilities` with each client's key and compare every reported value with the client row and the
outcome of the matching website write from feature 020.

**Acceptance Scenarios**:

1. **Given** a client whose plan has `limit_ssl = y`, `limit_ssl_letsencrypt = n`, `limit_wildcard = n`,
   `force_suexec = y`, **When** its key calls `GET /me/capabilities`, **Then** 200 with `web.ssl = true`,
   `web.ssl_letsencrypt = false`, `web.wildcard = false`, `web.suexec_forced = true`.
2. **Given** system `web_php_options = no,fast-cgi,mod,php-fpm` and client `web_php_options = no,php-fpm,cgi`,
   **When** the client key reads capabilities, **Then** `web.php_modes = ["no", "php-fpm"]` and
   `web.php_default_mode = "php-fpm"`.
3. **Given** a locked, canceled client, **When** its key reads capabilities, **Then** `locked = true` and
   `canceled = true`.
4. **Given** a reseller key, **When** it calls without `client_id`, **Then** the reseller's own capabilities
   (`account_type = "reseller"`); **When** it names one of its clients, **Then** that client's capabilities;
   **When** it names another reseller's client, **Then** 404.
5. **Given** an admin key, **When** it calls without `client_id`, **Then** 422 on `client_id`; **When** it names an
   existing client, **Then** 200 with that client's capabilities; **When** the client does not exist, **Then** 404.
6. **Given** a client key, **When** it names another client, **Then** 404.

---

### User Story 2 - Panel lists the PHP versions a website may use (Priority: P1)

When a customer creates a website or changes its PHP settings, the panel asks for the PHP versions usable on
the website's server for the selected mode and shows exactly those (with the server's "Default" entry only
when the server does not hide it). Every version shown is accepted by the website endpoints for that
customer; versions of other customers, inactive versions and versions without the binaries for the mode never
appear.

**Why this priority**: PHP version choice is one of the two blocked module tasks; showing an unusable version
produces a refused save.

**Independent Test**: seed PHP versions (public, own private, other client's private, inactive, FPM-only,
other server) and servers with `php_default_hide = y/n`; call `GET /me/php-versions` with and without
`server_id` / `mode`, then try each listed and unlisted version with `PUT /sites/web-domains/{id}`.

**Acceptance Scenarios**:

1. **Given** server 1 with public versions PHP 8.2 (sortprio 20, FPM + FastCGI) and PHP 8.3 (sortprio 10,
   FPM only), **When** a client allowed `php-fpm` and `fast-cgi` calls `GET /me/php-versions?server_id=1`,
   **Then** `data` lists PHP 8.3 (`modes: ["php-fpm"]`) before PHP 8.2 (`modes: ["php-fpm", "fast-cgi"]`).
2. **Given** the same server, **When** the client calls `?server_id=1&mode=fast-cgi`, **Then** only PHP 8.2 is
   listed.
3. **Given** a private version of client A and one of client B, **When** client A's key lists versions,
   **Then** only A's private version appears; inactive versions never appear.
4. **Given** server 1 does not hide the default version (`php_default_hide = n`, `php_default_name = Default`),
   **When** versions are listed, **Then** the server's list starts with `{id: 0, name: "Default", is_default: true}`;
   **Given** `php_default_hide = y`, **Then** no id 0 entry.
5. **Given** the client's plan allows only `no` and `mod`, **When** it lists versions, **Then** `data` is empty.
6. **Given** a server that is neither assigned to the account nor hosts one of its websites, **When** it is
   named in `server_id`, **Then** 422 on `server_id`; an unknown `mode` → 422 on `mode`.
7. **Given** no `server_id`, **When** versions are listed, **Then** entries for every web server of the account
   (assigned web servers, then other servers hosting its websites) with `server_id` on each entry.
8. **Given** an admin key, **Then** `client_id` is required (422) and the named client's view is returned; client
   and reseller keys follow the same target rules as User Story 1.

---

### Edge Cases

- Missing/invalid `X-API-Key` → 401 before anything else.
- The key's identity has no client row (orphan identity without `client_id`) → 404, like `/usage/summary`.
- A named client without a control-panel group still has capabilities (read from the client row); it has no
  websites, so only assigned web servers count for PHP versions.
- System `web_php_options` empty or missing → the client list applies (feature 020 decision).
- Account with no allowed version mode (`php-fpm`/`fast-cgi`) → capabilities report the modes; PHP version list
  is empty (versions only exist for those two modes).
- nginx web servers: legacy lists FPM versions for `fast-cgi` on nginx (`ajax_get_json.php:72`); the list uses
  the FPM binaries for both modes on nginx servers.
- Mirror servers and servers without the web role are never listed.
- A version deactivated after a website used it disappears from the list; the website keeps it until PHP is
  changed (feature 020 edge case).
- `limit` / `offset` follow the list conventions; entries are ordered by server (account order), then the
  default entry, then `sortprio`, then id.

## API Contract *(mandatory)*

- **Spec file(s)**: `api/modules/me/capabilities.yaml` (new — `GET /me/capabilities`),
  `api/modules/me/php-versions.yaml` (new — `GET /me/php-versions`), registered in `api/modules/me/_index.yaml`
  and `api/openapi.yaml`.
- **Shared schemas**: `api/components/schemas/AccountCapabilities.yaml` (new),
  `api/components/schemas/AccountPhpVersion.yaml` (new); existing `Meta.yaml`, parameters `limit.yaml`,
  `offset.yaml`, responses `Unauthorized`, `NotFound`, `ValidationError`, `InternalServerError`.
- **Endpoints**:

| Method | Path | Purpose | Success code |
|--------|------|---------|--------------|
| GET | `/api/v1/me/capabilities` | website plan flags, allowed PHP modes, default mode, lock/cancel state of the key's account or a named client | 200 |
| GET | `/api/v1/me/php-versions` | PHP versions usable per web server and mode (`server_id`, `mode`, `client_id`, `limit`, `offset`) | 200 |

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference** (ISPConfig 3.3.1p1, read on isp-test.feldhost.cz):
  - `interface/web/sites/form/web_vhost_domain.tform.php` lines 78–100 (SSL tab only with `limit_ssl = y`,
    wildcard option only with `limit_wildcard = y`), 254 (`php` valuelimit
    `system:sites:web_php_options;client:web_php_options`), 791–795 (Options tab for resellers only with
    `sites.reseller_can_use_options = y`).
  - `interface/web/sites/web_vhost_domain_edit.php` lines 979–996 (plan flags forced for non-admins), 240–272
    (client PHP version list for the website's server: nginx `fast-cgi` → `php-fpm`, `client_id = 0 OR own`,
    `active = 'y'`, mode binaries; option `0` = `php_default_name` first unless `php_default_hide = y`).
  - `interface/lib/classes/tform_base.inc.php` `applyValueLimit()` 339–430 (system ∩ client PHP modes).
  - `interface/web/sites/ajax_get_json.php` 66–125 (`getserverphp`: `server_id`, `active = 'y'`, `client_id = 0
    OR own`, mode binaries, nginx `fast-cgi` → `php-fpm`, `ORDER BY sortprio`, virtual `0` "Default" entry
    unless `php_default_hide = y`).
  - `client.locked`, `client.canceled` (lock/cancel semantics of feature 019).
- **Legacy behaviors to mirror**: the values the ISPConfig interface uses to build the website form for the
  logged-in account (visible tabs/options, PHP mode list, PHP version list) — through the same rules feature 020
  enforces.
- **Tables written (via datalog only)**: none — both endpoints are read-only.
- **System fields handling**: not applicable (no writes; responses contain no `sys_*` fields).
- **Intentional deviations from legacy** (owner-delegated decisions 2026-09-15):
  - New read endpoints without a legacy counterpart (legacy renders these values into forms only).
  - The "Default" entry is listed first whenever the server does not hide it, as in the initial website form
    (`web_vhost_domain_edit.php:263-265`); the mode-change list (`ajax_get_json.php`) inserts it before the first
    version with `sortprio > 0` and only when versions exist. Versions are ordered by `sortprio`, then id (the
    initial form has no order).
  - Without `server_id`, versions of all the account's web servers are listed in one response.
  - A reseller naming one of its clients sees that client's own capabilities (what the client's key may do);
    feature 020 checks a reseller key's writes against the reseller's own plan.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `GET /me/capabilities` and `GET /me/php-versions` MUST be available to every valid key (not
  admin-gated) and MUST NOT write anything.
- **FR-002**: Both endpoints MUST resolve the target account from the optional `client_id` query parameter:
  admin keys MUST send it (422 on `client_id` otherwise; unknown client → 404); client keys get their own
  account and may name only themselves; reseller keys get their own account or may name one of their clients;
  any other client → 404 (same rules as `/usage/summary`).
- **FR-003**: `GET /me/capabilities` MUST return `client_id`, `account_type` (`client` or `reseller`, reseller
  when the account's `limit_client != 0`), `locked`, `canceled` and a `web` object with booleans `ssl`,
  `ssl_letsencrypt`, `wildcard`, `cgi`, `ssi`, `perl`, `ruby`, `python`, `error_documents`,
  `directive_snippets` (client `limit_*` = `y`), `suexec_forced` (`force_suexec = y`), `backup`
  (`limit_backup = y`), `advanced_options` (reseller and `sites.reseller_can_use_options = y`), `php_modes`
  (system ∩ client `web_php_options`, client order; empty system list → client list) and `php_default_mode`
  (the mode a website created without `php` gets: `fast-cgi` if allowed, else the first allowed mode other than
  `no`, else `no`).
- **FR-004**: The capability values MUST come from the same rules feature 020 enforces, so that for the
  account's own key every reported `true` flag and every listed mode is accepted by the website endpoints and
  every `false` flag or unlisted mode is refused.
- **FR-005**: `GET /me/php-versions` MUST consider the account's web servers: the valid assigned web servers
  (`client.web_servers` order, non-mirror servers with the web role), then the web servers hosting websites of
  the account ordered by id. A `server_id` outside that set MUST be refused (422 on `server_id`).
- **FR-006**: For each considered server, the list MUST contain the active PHP versions with `client_id` 0 or the
  account's client that support at least one of the account's allowed modes among `php-fpm` and `fast-cgi`
  (`php-fpm`: FPM init script, ini and pool directories; `fast-cgi`: FastCGI binary and ini directory, or the
  FPM columns on nginx servers); `mode` (optional, `php-fpm` or `fast-cgi`, otherwise 422) narrows the list to
  that mode. Each entry carries `id`, `name`, `server_id`, `modes` (the allowed modes it supports) and
  `is_default: false`; no paths or binaries.
- **FR-007**: When the server's `php_default_hide` is not `y` and at least one allowed mode is considered, the
  list MUST contain an entry `id = 0`, `name` = the server's `php_default_name` (`Default` when empty),
  `is_default: true`, `modes` = the considered modes, as the first entry of that server.
- **FR-008**: Versions MUST be ordered by server (FR-005 order), then the default entry (FR-007), then `sortprio`,
  then id; the response MUST use `{data, meta}` with `limit` / `offset`.
- **FR-009**: Every version listed for a server and mode MUST be accepted as `server_php_id` for a website of the
  account on that server with that mode by the account's own key (feature 020 FR-004/FR-006), and every
  non-default version not listed MUST be refused.
- **FR-010**: The contract MUST document both endpoints, parameters, schemas and error cases, and every rule MUST
  be covered by feature tests for client, reseller and admin keys.

### Key Entities

- **Account capabilities**: derived per request — table `client` (`limit_ssl`, `limit_ssl_letsencrypt`,
  `limit_cgi`, `limit_ssi`, `limit_perl`, `limit_ruby`, `limit_python`, `force_suexec`, `limit_hterror`,
  `limit_wildcard`, `limit_directive_snippets`, `limit_backup`, `web_php_options`, `limit_client`, `locked`,
  `canceled`), `sys_ini` `[sites]` (`web_php_options`, `reseller_can_use_options`); schema
  `api/components/schemas/AccountCapabilities.yaml`.
- **Account PHP version**: table `server_php` (read-only), the server's `[web]` config (`php_default_hide`,
  `php_default_name`, `server_type`); schema `api/components/schemas/AccountPhpVersion.yaml`.
- **Account web servers**: `client.web_servers` (feature 016) and `web_domain.server_id` of the account's
  websites.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For every plan flag and PHP mode, the value reported for a client key matches the outcome of the
  corresponding website write by that key in 100% of tested combinations.
- **SC-002**: 0 PHP versions listed for an account are refused by the website endpoints for that account's key,
  and 0 versions of other customers, inactive versions or versions without the mode's binaries are listed.
- **SC-003**: A panel renders HTTPS, wildcard, PHP mode and PHP version choices plus the suspended notice for a
  website with at most 2 requests (capabilities + versions for the website's server).
- **SC-004**: Both endpoints write 0 datalog rows and expose no server paths or binaries.

## Assumptions

- The consumer is the WHMCS module spec 003 (`contracts/ispconfig-rest-calls.md`): it reads `web.ssl`,
  `web.ssl_letsencrypt`, `web.wildcard`, `web.php_modes`, `locked` and `data[].id`, `data[].name` with
  `?server_id={id}`; extra fields are additive.
- Record-count and quota capacity stay in `/usage/summary` (feature 017); server lists in `/me/servers` (016).
- Enforcement stays in feature 020; this feature only describes it.
- No caching beyond the request: values reflect the database at request time.
