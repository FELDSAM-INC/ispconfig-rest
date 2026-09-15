# Feature Specification: DKIM Key Generation for Mail Domains

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-15  
**Status**: Draft  
**Module**: mail  
**Input**: User description: "DKIM key generation: server-side key pair generation for scoped keys when the plan allows, return the public DNS record (never the private key to non-admin keys), allow disabling; datalog parity with the legacy mail_domain DKIM fields. Must fit the WHMCS module `specs/004-mail/contracts/ispconfig-rest-calls.md` (`POST /mail/domains/{id}/dkim`, `PUT /mail/domains/{id}` `dkim: false`, domain reads never expose `dkim_private`)."

## Context

In the ISPConfig panel a customer enables DKIM on the mail domain form by clicking "Generate DKIM key": the panel
creates an RSA key pair with the strength configured for the mail server, fills in the private key and shows the DNS
TXT record to publish. When the domain's DNS zone is hosted on the same ISPConfig, saving the form publishes the record
automatically.

The API can only enable DKIM when the caller sends a private key it generated itself. A panel for non-technical
customers cannot ask for a PEM key, and the private key a customer key could upload is also returned by every mail
domain read to client and reseller keys — it should never leave the server once generated.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Customer turns on DKIM with one click (Priority: P1)

A customer opens a mail domain, clicks "Enable DKIM" and sees DKIM as active together with the DNS record for their
domain. When the DNS zone is hosted on the platform, the record is published automatically.

**Why this priority**: DKIM is needed for deliverability; the WHMCS module blocks the DKIM switch until the API can
create keys.

**Independent Test**: mail servers with and without a DKIM key directory, a hosted DNS zone; call
`POST /mail/domains/{id}/dkim` with client, reseller and admin keys; compare the domain row, the datalog, the DNS
records and the response.

**Acceptance Scenarios**:

1. **Given** a mail domain on a server with a DKIM key directory and `dkim_strength = 2048`, **When** its client key
   calls `POST /mail/domains/{id}/dkim`, **Then** 200 with `enabled = true`, `selector = default`, `key_bits = 2048`, the
   PEM public key and `dns_record = {name: "default._domainkey.<domain>.", type: "TXT", value: "v=DKIM1; t=s; p=…"}`;
   the domain is updated through one datalog entry (`dkim = y`, new `dkim_private`, matching `dkim_public`,
   `dkim_selector`); the response never contains the private key.
2. **Given** a hosted, active DNS zone for the domain, **Then** the TXT record is published in that zone and the zone
   serial increases (existing DKIM DNS behavior), and the response reports `dns_managed = true`.
3. **Given** DKIM is already enabled, **When** the customer generates again (optionally with `selector`), **Then** the
   key is replaced, the old DKIM TXT record of the previous selector is removed from the hosted zone and the new one
   published.
4. **Given** the domain's mail server has no DKIM key directory, **Then** 409 and nothing is written.
5. **Given** an invalid `selector`, **Then** 422 and nothing is written.
6. **Given** an inactive domain, **Then** the key is stored but no DNS record is published (legacy only publishes for
   active domains).

---

### User Story 2 - Customer reads the DKIM status and DNS record (Priority: P1)

A customer opens the DKIM section of a mail domain and sees whether DKIM is on, which selector is used and the exact DNS
record to add at an external DNS provider.

**Why this priority**: customers with external DNS must copy the record; the panel must show it without the private
key.

**Independent Test**: domains without key, with key enabled and disabled, with and without a hosted zone; call
`GET /mail/domains/{id}/dkim` and compare.

**Acceptance Scenarios**:

1. **Given** a domain without a key, **Then** 200 with `enabled = false`, `selector` (stored or `default`),
   `public_key = null`, `key_bits = null`, `dns_record = null`, `available` telling whether its server can sign.
2. **Given** a domain with a key, **Then** the public key, key size and DNS record are returned (also while DKIM is
   switched off, as the legacy form shows the record whenever a public key exists).
3. **Given** another account's domain, **Then** 404.

---

### User Story 3 - The private key stays on the server (Priority: P1)

The private key is never returned to client or reseller keys: not by the DKIM endpoints and not by the mail domain
resource. Administrators keep access for migrations.

**Why this priority**: a leaked DKIM private key lets anyone sign mail as the customer's domain.

**Independent Test**: domains with stored private keys; read show, list, create and update responses with client,
reseller and admin keys.

**Acceptance Scenarios**:

1. **Given** a client or reseller key, **When** it reads `GET /mail/domains`, `GET /mail/domains/{id}` or receives the
   `POST`/`PUT /mail/domains` response, **Then** no `dkim_private` field is present; `dkim`, `dkim_selector` and
   `dkim_public` are.
2. **Given** a client key that sends its own `dkim_private` on create or update, **Then** it is stored (legacy form
   accepts a pasted key) but not echoed back.
3. **Given** an admin key, **Then** `dkim_private` is still returned.

---

### User Story 4 - Customer turns DKIM off (Priority: P2)

A customer disables DKIM for a domain.

**Why this priority**: needed to recover from DNS mistakes; the mechanism already exists.

**Independent Test**: after generating a key, send `PUT /mail/domains/{id}` `{dkim: false}` and read the DKIM status.

**Acceptance Scenarios**:

1. **When** the client key sends `PUT /mail/domains/{id}` `{dkim: false}`, **Then** 200, `dkim = n` through the datalog
   (the server removes the signing configuration), an existing DMARC record of a hosted zone is downgraded to `p=none`
   (existing behavior), and `GET …/dkim` reports `enabled = false` with the stored public key.
2. **When** the customer generates again later, **Then** DKIM is on with a new key.

### Edge Cases

- Missing/invalid `X-API-Key` → 401; unknown domain or another account's domain → 404.
- A domain the key can read but not update → 403, nothing written.
- `dkim_strength` missing, empty, 0 or not one of 1024/2048/4096 → 2048 (legacy default).
- `selector` omitted → the stored selector, `default` when empty; `selector` must match the existing selector rule
  (`^[a-z0-9]{1,63}(?:\.[a-z0-9]{1,63})?$`).
- Locked accounts (feature 019) may still generate keys: DKIM is not a lock-managed service switch.
- The key generation failing on the API host → 500, nothing written.
- `dns_managed` = a hosted, active DNS zone encloses the domain (the record is maintained automatically while the domain
  is active).
- Legacy selector rotation code in `ajax_get_json.php` never takes effect (its result is overwritten); the API keeps the
  selector unless one is sent.

## API Contract *(mandatory)*

- **Spec file(s)**: `api/modules/mail/domain-dkim.yaml` (new), `api/modules/mail/domains.yaml` (private key
  visibility).
- **Shared schemas**: `api/components/schemas/MailDomainDkim.yaml` (new), `MailDomainDkimGenerate.yaml` (new),
  `MailDomain.yaml` (`dkim_private` description).
- **Endpoints**:

| Method | Path | Purpose | Success code |
|--------|------|---------|--------------|
| GET | `/api/v1/mail/domains/{id}/dkim` | DKIM status, public key and DNS record | 200 |
| POST | `/api/v1/mail/domains/{id}/dkim` | Generate a key pair and enable DKIM (optional `selector`) | 200 |
| PUT | `/api/v1/mail/domains/{id}` | `dkim: false` disables (existing) | 200 |
| GET/POST/PUT | `/api/v1/mail/domains[/{id}]` | `dkim_private` omitted for client and reseller keys | 200/201 |

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference** (ISPConfig 3.3.1p1): `mail/ajax_get_json.php` 42–113 (`create_dkim`: server `dkim_strength`,
  default 2048, `openssl genrsa`, `openssl rsa -pubout`, DNS value without PEM armor), `mail/mail_domain_edit.php`
  243–266 (DNS record display `selector._domainkey.domain. 3600 IN TXT "v=DKIM1; t=s; p=…"`, shown whenever a public key
  exists; auto-DNS hint when a zone is found), 348–351 (public key derived from the private key), 392–396 and 704–735
  (publish/refresh TXT record in a hosted zone for active domains, DMARC downgrade when disabled), 749–775 (`update_dns`:
  purge old selector record, insert new, bump serial); `mail/form/mail_domain.tform.php` 105–141 (DKIM fields for every
  user type); `server/plugins-available/mail_plugin_dkim.inc.php` (`check_system()` needs `dkim_path`; key files
  `<dkim_path>/<domain>.private|.public`; rspamd `dkim_domains.map`/`dkim_selectors.map` or amavis `dkim_key()`),
  `admin/form/server_config.tform.php` 557–580 (`dkim_path`, `dkim_strength`).
- **Legacy behaviors to mirror**: key strength from the domain's mail server; one `mail_domain` datalog update carrying
  `dkim`, `dkim_private`, `dkim_public`, `dkim_selector`; DNS publication and cleanup through the existing DKIM DNS
  logic; disabling keeps the stored keys.
- **Tables written (via datalog only)**: `mail_domain` (`u`); when a hosted zone exists `dns_rr` (`d` old record, `i` new
  record) and `dns_soa` (`u` serial).
- **System fields handling**: unchanged (update of an existing row).
- **Intentional deviations from legacy** (owner-delegated decisions 2026-09-15):
  - The key is generated and stored in one request (legacy generates in the browser form, then saves); the private key
    is never shown to customer keys (legacy shows it in the form textarea).
  - Generation is refused with 409 when the server cannot sign (legacy lets the form save and the server plugin logs
    an error).
  - Keys are generated with PHP's OpenSSL extension instead of the `openssl` binary (same RSA/PEM formats).
  - Mail domain responses omit `dkim_private` for client and reseller keys.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `GET /mail/domains/{id}/dkim` MUST return `id`, `domain`, `enabled`, `selector`, `public_key`, `key_bits`,
  `dns_record` (`name`, `type`, `value`) or null, `dns_managed` and `available` for any readable domain.
- **FR-002**: `POST /mail/domains/{id}/dkim` MUST generate an RSA key pair with the domain's mail server
  `dkim_strength` (1024, 2048 or 4096; otherwise 2048), store `dkim = y`, `dkim_private`, `dkim_public` and
  `dkim_selector` (body `selector`, else stored, else `default`) with one datalog update, run the existing DKIM DNS
  update, and return the FR-001 view with 200.
- **FR-003**: Generation MUST be refused with 409 (nothing written) when the domain's mail server has no usable
  `dkim_path`; with 422 for an invalid `selector`; with 403 without update permission on the domain.
- **FR-004**: No DKIM endpoint response MAY contain the private key for any key type.
- **FR-005**: Mail domain responses (`GET` list and show, `POST`, `PUT`) MUST omit `dkim_private` for client and reseller
  keys and keep it for admin keys; `dkim_private` input stays accepted.
- **FR-006**: Disabling through `PUT /mail/domains/{id}` `dkim: false` MUST keep working for every key type and keep the
  stored keys.
- **FR-007**: Contract first (new operations reference `X-Change-Set-Id`); feature tests for success, 401/403/404/409/422,
  DNS publication and rotation, private key visibility and the tenant matrix.

### Key Entities

- **Mail domain DKIM** (`mail_domain`): `dkim`, `dkim_selector`, `dkim_private`, `dkim_public`; server [mail]
  `dkim_path`, `dkim_strength`; hosted zone `dns_soa`/`dns_rr`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A customer enables DKIM for a domain with one request and receives the DNS record to publish.
- **SC-002**: 0 responses to client or reseller keys contain a private key, across all mail domain and DKIM endpoints in
  the tests.
- **SC-003**: On isp-test the server writes the key files and signing maps for a generated key and removes them after
  disabling.

## Assumptions

- The API host's PHP has the OpenSSL extension (Laravel requirement) and can generate RSA keys.
- The WHMCS module shows `available` and `dns_record` and calls `POST …/dkim` / `PUT … dkim: false`.
- Feature 025 reports `mail.dkim` per account; this feature reports availability per domain.
