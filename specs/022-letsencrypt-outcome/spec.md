# Feature Specification: Let's Encrypt Issuance Outcome

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-15  
**Status**: Draft  
**Module**: sites  
**Input**: User description: "Let's Encrypt issuance outcome: when a client enables `ssl_letsencrypt` and issuance fails, ISPConfig's server plugin silently switches `ssl`/`ssl_letsencrypt` back off — the API consumer cannot tell pending, issued or failed (and why). Specify a read endpoint for a client key on its own website, e.g. `GET /sites/web-domains/{id}/ssl/status` → state (none | requested | issued | failed), certificate expiry and issuer when issued, a safe human-readable reason when failed, timestamps; read-only."

## Context

A customer panel (the WHMCS ISPConfig module, spec 003 "Websites & Domains") turns on HTTPS with a free
Let's Encrypt certificate by updating a website with `ssl = true` and `ssl_letsencrypt = true`. ISPConfig's web
server plugin requests the certificate while it applies that change. When the request fails — most often because
the domain does not point to the server yet — the plugin writes `ssl_letsencrypt = n` (and `ssl = n` when HTTPS
was off before) straight into the master database, without a journal entry. The API consumer only sees the flags
flip back; it cannot tell "still being applied", "certificate issued" and "failed, and why". The module therefore
keeps its own request table and guesses.

This feature adds one read-only endpoint that derives the Let's Encrypt outcome of a website from what ISPConfig
already records on the master — the website row, the change journal, the server processing watermark and the
server's processing log — plus, where the certificate file is readable by the API, the certificate's validity.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Panel shows whether the free certificate was issued (Priority: P1)

The customer switches on HTTPS for `shop.example.com` in the panel. The panel saves the website and then polls
`GET /sites/web-domains/{id}/ssl/status` with the customer's key. It shows "Requesting certificate…" while the
change waits for the server, "HTTPS active" once the certificate is in place, and "Certificate could not be issued"
when ISPConfig switched the option back off — without keeping any state of its own.

**Why this priority**: this is the gap the module works around today; every panel that offers free HTTPS needs it.

**Independent Test**: seed a website and a journal entry that enables Let's Encrypt; move the server watermark and
the website flags through the three situations and call the endpoint after each step.

**Acceptance Scenarios**:

1. **Given** a website whose latest journal entry enables Let's Encrypt and the website's server has not processed
   it, **When** its client key reads the status, **Then** 200 with `state = "requested"`, `requested_at` set,
   `change_set_id` of that entry and `change_status = "pending"`.
2. **Given** the server processed that entry and the website still has `ssl_letsencrypt = y`, **When** the status is
   read, **Then** `state = "issued"` and `failure = null`.
3. **Given** the server processed that entry and the website now has `ssl_letsencrypt = n` with no later journal
   entry switching it off, **When** the status is read, **Then** `state = "failed"` with a `failure` object.
4. **Given** a later journal entry in which the customer switched Let's Encrypt off, **When** the status is read,
   **Then** `state = "none"`.
5. **Given** a website that never had Let's Encrypt enabled (or whose journal entries were already purged and whose
   flag is off), **When** the status is read, **Then** `state = "none"`; with the flag on and no journal entry,
   **Then** `state = "issued"` and `requested_at = null`.
6. **Given** the entry's responsible server is inactive, **When** the status is read, **Then** `state = "requested"`
   and `change_status = "stalled"`.

---

### User Story 2 - Panel explains why issuance failed (Priority: P2)

When issuance failed, the panel tells the customer what to do in plain language: "shop.example.com does not point
to this server yet" instead of a generic error. The endpoint returns a reason code and the customer's own affected
domain names, never commands, file paths or server internals.

**Why this priority**: most failures are DNS related and fixable by the customer; a reason avoids support tickets.
It depends on the server logging warnings, so it is secondary to the state itself.

**Independent Test**: seed `sys_log` rows the ISPConfig letsencrypt class writes for each failure path (tied to the
request's journal entry or naming the website's domain after the request) and check the reported reason.

**Acceptance Scenarios**:

1. **Given** a failed request and a warning "Could not verify domain shop.example.com, so excluding it from let's
   encrypt request.", **When** the status is read, **Then** `failure.reason = "domain_not_reachable"` and
   `failure.domains = ["shop.example.com"]`.
2. **Given** a failed request and a warning that the certificate "could not be issued. Used command: …", **When** the
   status is read, **Then** `failure.reason = "issuance_failed"` and the response contains no part of the command.
3. **Given** a failed request with "Unable to install acme.sh. Cannot proceed, no Let's Encrypt client found.",
   **Then** `failure.reason = "client_unavailable"`; with "could not find the issued certificate", **Then**
   `certificate_not_found`.
4. **Given** a failed request and no matching log rows (the server log level hides warnings), **Then**
   `failure.reason = "unknown"` with the generic detail.
5. **Given** an issued certificate for which the log names excluded domains, **Then** `state = "issued"` and
   `excluded_domains` lists them.

---

### User Story 3 - Panel shows certificate validity (Priority: P3)

For an issued certificate the panel shows "valid until 14 Dec 2026, issued by Let's Encrypt". The endpoint reads the
public certificate file at ISPConfig's expected location when the API can read it (single-server installations or a
shared web root) and returns validity, issuer and covered domain names; otherwise it returns `certificate = null`.

**Why this priority**: helpful but not needed to decide whether HTTPS works; unavailable on multi-server setups.

**Independent Test**: place a self-signed certificate at `<document_root>/ssl/<domain>-le.crt` in a temporary
directory seeded as the website's document root and read the status.

**Acceptance Scenarios**:

1. **Given** an issued website and a readable certificate file, **Then** `certificate` contains `valid_from`,
   `expires_at`, `issuer` and `domains`.
2. **Given** an issued website without a readable file, **Then** `certificate = null` and the response is otherwise
   unchanged.
3. **Given** a `requested` or `failed` state, **Then** `certificate = null` even if an old file exists.

### Edge Cases

- Missing or invalid `X-API-Key` → 401; a website the key cannot read (other tenant) → 404 (spec 011 binding).
- Child domain types without their own web server configuration (`subdomain`, `alias`) are not web-domain resources →
  404; their names are covered by the parent website's certificate.
- A client key whose plan does not include Let's Encrypt may still read the status (read-only; usually `none`).
- `ssl = y` with an uploaded certificate and `ssl_letsencrypt = n` → `state = "none"`, `https_enabled = true`.
- Journal entries are purged by ISPConfig's log cleanup after processing; the endpoint falls back to the current flags.
- A later failure caused by another change (for example an alias added to an issued website makes the plugin request
  a new certificate) leaves the flag off after the last Let's Encrypt entry → reported as `failed`; the reason comes
  from log rows naming the domain after that entry.
- Wildcard domains (`*.example.com`): ISPConfig requests the certificate for the bare domain; the file name uses it.
- Corrupt journal payloads are skipped; unreadable or non-certificate files give `certificate = null`.
- Mirror servers never request certificates; processing is judged by the website's journal entry status (spec 015).
- The endpoint never writes: no journal entry, no `X-Change-Set-Id` header.

## API Contract *(mandatory)*

- **Spec file(s)**: `api/modules/sites/web-domains.yaml` (existing — add the `/sites/web-domains/{id}/ssl/status` path
  item); path registered in `api/openapi.yaml`.
- **Shared schemas**: `api/components/schemas/WebDomainSslStatus.yaml` (new); shared `Unauthorized`, `NotFound`
  responses reused.
- **Endpoints**:

| Method | Path | Purpose | Success code |
|--------|------|---------|--------------|
| GET | `/api/v1/sites/web-domains/{id}/ssl/status` | Let's Encrypt outcome of one website | 200 |

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference** (ISPConfig 3.3.1p1, read on isp-test):
  - `server/plugins-available/apache2_plugin.inc.php` 1305–1330 and `nginx_plugin.inc.php` 1374–1399 — request
    condition (`ssl = y`, `ssl_letsencrypt = y`, not a mirror, and HTTPS/Let's Encrypt newly enabled, domain or
    subdomain changed, or `update_letsencrypt`); on failure `ssl_letsencrypt = n` (and `ssl = n` when it was off
    before) written directly to the local and master database — no journal entry; on success `ssl_request`,
    `ssl_cert`, `ssl_key`, `ssl_action` are cleared.
  - `server/lib/classes/letsencrypt.inc.php` — `get_ssl_domain()` 294–312 (wildcard stripped),
    `get_website_certificate_paths()` 314–340 (`<document_root>/ssl/<domain>-le.crt`),
    `assemble_domains_to_request()` 342–404 (unreachable domains excluded with a warning; empty list → failure),
    `request_certificates()` 428–511 (warnings "could not be issued. Used command: …", "could not find the issued
    certificate"), `use_acme()` 216–238 ("Unable to install acme.sh…").
  - `server/lib/app.inc.php` 257–345 — `log()` writes a `sys_log` row (with the datalog id being processed) only when
    the priority reaches the server's log level (`server.php` 87–91 sets it from server config `[server] loglevel`;
    default 2 = errors only, so warnings are often absent).
  - `server/lib/classes/cron.d/200-logfiles.inc.php` 241–300 — `sys_log` kept 7 days, processed journal entries purged.
- **Legacy behaviors to mirror**: request condition and certificate path derivation (read-only interpretation).
- **Tables written (via datalog only)**: none — read-only feature.
- **Tables read**: `web_domain`, `sys_datalog`, `server` (processing watermark, spec 015 resolver), `sys_log`.
- **System fields handling**: not applicable.
- **Intentional deviations from legacy**: legacy exposes no outcome at all; this endpoint is an addition.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST expose `GET /sites/web-domains/{id}/ssl/status` to every key that can read the website
  (admin, reseller, client; spec 011 read scoping through the existing route binding).
- **FR-002**: The response MUST contain `website_id`, `domain`, `https_enabled`, `letsencrypt_enabled` (current row),
  `state` (`none | requested | issued | failed`), `requested_at` (date-time or null), `change_set_id` and
  `change_status` (spec 015 values or null), `failure` (object or null), `excluded_domains` (array) and `certificate`
  (object or null).
- **FR-003**: The request entry MUST be the newest `sys_datalog` entry of the website (`dbtable = web_domain`,
  `dbidx = domain_id:{id}`) whose new values have `ssl = y` and `ssl_letsencrypt = y` and that is an insert or changes
  `ssl`, `ssl_letsencrypt`, `domain` or `subdomain` (legacy request condition). An *off entry* is a newer entry whose
  new values set `ssl_letsencrypt = n` or `ssl = n` after they were `y`.
- **FR-004**: State derivation: newest relevant entry is an off entry → `none`; request entry not yet processed
  (`pending` or `stalled`) → `requested`; processed and the row still has `ssl_letsencrypt = y` → `issued`; processed
  and the row has `ssl_letsencrypt = n` → `failed`; no relevant entry → `issued` when the row has
  `ssl = y` and `ssl_letsencrypt = y`, else `none`.
- **FR-005**: For `failed` the `failure` object MUST contain `reason` (`domain_not_reachable`, `issuance_failed`,
  `certificate_not_found`, `client_unavailable`, `unknown`), a fixed safe English `detail` per reason, and `domains`
  (customer domain names parsed from the log, may be empty). Reasons come from `sys_log` rows of the website's server
  with log level ≥ warning that belong to the request entry (`datalog_id`) or were written at or after it and name
  the website's domain; precedence `client_unavailable` > `domain_not_reachable` > `issuance_failed` >
  `certificate_not_found` > `unknown`.
- **FR-006**: Responses MUST NOT contain log text, commands, file paths, server names or configuration values.
- **FR-007**: For `issued`, `excluded_domains` MUST list domains the log reports as excluded from the request entry;
  otherwise it is empty.
- **FR-008**: For `issued`, `certificate` MUST be filled from `<document_root>/ssl/<ssl_domain>-le.crt` when that file
  is readable by the API and parses as an X.509 certificate: `valid_from`, `expires_at` (date-times in the API
  timezone), `issuer` (organisation or common name) and `domains` (subject alternative DNS names, else common name);
  else `null`. Only this public certificate file may be read; `document_root` must be absolute without `..` and the
  domain a hostname.
- **FR-009**: Timestamps MUST be ISO 8601 in the API's configured timezone (spec 017/026 convention).
- **FR-010**: The endpoint MUST NOT write anything and MUST NOT emit `X-Change-Set-Id`.
- **FR-011**: The endpoint MUST be defined in the OpenAPI contract first and covered by feature tests for every state,
  every reason, certificate details, scoping (two tenants), 401 and 404.

### Key Entities

- **Website SSL status**: derived view of one website — table `web_domain` (+ `sys_datalog`, `server`, `sys_log`),
  schema `api/components/schemas/WebDomainSslStatus.yaml`, no model (read in `app/Services/LetsEncryptStatusService.php`).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A panel can tell "requested", "issued" and "failed" apart for 100% of Let's Encrypt changes whose
  journal entry still exists, using only the customer's key and this endpoint.
- **SC-002**: For every failure path of the legacy letsencrypt class whose warning is logged, the endpoint reports the
  matching reason (verified by tests with the exact legacy messages).
- **SC-003**: No response contains a file path, command or log line (asserted in tests).
- **SC-004**: The endpoint answers within 300 ms with at most 5 database queries.
- **SC-005**: On isp-test, enabling Let's Encrypt for a domain that does not resolve to the server shows `requested`
  and then `failed`.

## Assumptions

- Owner-delegated decision 2026-09-15: the endpoint lives at `GET /sites/web-domains/{id}/ssl/status` next to the
  existing SSL subresource and is readable by every key that can read the website, independent of the plan's
  Let's Encrypt option.
- Owner-delegated decision 2026-09-15: the outcome is derived from existing master data only (no new table, no
  server-side component); failure reasons are best effort and depend on the server log level (Warning or Debug), with
  `unknown` otherwise.
- Owner-delegated decision 2026-09-15: certificate validity is reported only when the API can read the public
  certificate file locally; multi-server installations get `certificate = null`.
- Owner-delegated decision 2026-09-15: uploaded (non Let's Encrypt) certificates are out of scope; they report
  `state = "none"` with `https_enabled = true`.
- The journal entries used are the ones ISPConfig keeps until its log cleanup; older outcomes fall back to the flags.
