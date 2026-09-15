# Feature Specification: Scoped Parent References (Mail Domain Ownership and Siblings)

**Feature Branch**: `024-mail-domain-ownership` (committed directly to `main`, no branch)  
**Created**: 2026-09-15  
**Status**: Draft  
**Module**: cross-cutting (mail / sites / dns)  
**Input**: User description: "Mail domain ownership for scoped keys — a cross-tenant security gap. For client/reseller keys, creating/updating mailboxes, forwarders/aliases/catch-alls and alias domains resolves the target mail domain from the email/source without checking that the key may read that mail domain, and does not check alias destinations against the key's own mailboxes where legacy does (`no_domain_perm` / `no_destination_perm`). Audit other modules for the same pattern and fix it where the same helper applies."

## Context

Feature 011 scoped every row a non-admin key reads or mutates by URL id, and 012 capped volume. Writes that
*reference another row by value* were not scoped: the referenced row is looked up with a plain existence check.
Because several write paths then copy the referenced row's server and owner onto the new row, a client key can
attach records to another tenant's mail domain, website, database user, protected folder or DNS zone.

Verified in code on `main` (before this feature):

| Endpoint | Referenced row | Lookup | Effect for client key A targeting tenant B |
|----------|----------------|--------|--------------------------------------------|
| `POST /mail/users` | `mail_domain` by the email's domain part | unscoped (`MailUserService::resolveMailDomain`) | mailbox `x@b-domain` created and owned by A (BaseModel forces A's group) → A receives B's mail |
| `PUT /mail/users/{id}` | same, from stored email | unscoped | re-derives from a foreign domain |
| `POST /mail/forwards` (`forward`, `alias`, `catchall`) | `mail_domain` by source domain | unscoped (`MailForwardingController::resolveSourceDomain`) | `postmaster@b-domain` → attacker, or `@b-domain` catch-all → attacker |
| `POST/PUT /mail/forwards` type `alias` | destination mailbox | not checked | alias onto B's mailbox |
| `POST/PUT /mail/alias-domains` | source and destination `mail_domain` | unscoped | route all of B's domain into A's domain (or the reverse) |
| `POST/PUT /mail/spamfilter/wblist` (limit-gated) | `spamfilter_users` by `rid` | unscoped existence (404 when missing) | allow/deny list on B's spam filter user |
| `POST /dns/records` (+`PUT` zone) | `dns_soa` by `zone` | `Rule::exists` | records in B's zone (DNS hijack) |
| `POST/PUT /sites/web-child-domains`, `POST/PUT /sites/web-domains` (vhostsubdomain/vhostalias) | parent `web_domain` | `Rule::exists` | subdomain/alias on B's website |
| `POST/PUT /sites/ftp-users`, `/shell-users`, `POST /webdav-users`, `POST/PUT /cron-jobs`, `POST /web-folders` | parent `web_domain` | `Rule::exists` | FTP/SSH access to B's web root; cron in B's site |
| `POST/PUT /sites/databases` | parent `web_domain`, `database_user_id`, `database_ro_user_id` | `Rule::exists` | database attached to B's site or granted to B's DB user |
| `POST /sites/web-folder-users` | `web_folder` | `Rule::exists` | login added to B's protected folder |

`POST/PUT /mail/fetchmail` destinations were already scoped by 016 (FR-014). Spam filter users, relay domains and
mail user filters are admin-only or bound through the URL, so they are not affected.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Mail writes only on the key's own mail domains (Priority: P1)

A hosting panel serves customers with client-scoped keys. Customer A must not be able to create mailboxes,
forwarders, aliases, catch-alls or alias domains on customer B's mail domain, nor alias onto B's mailbox, even if A
knows B's domain or address.

**Why this priority**: Mail interception across tenants is the most severe consequence and the WHMCS mail area
(module spec 004) depends on it for release.

**Independent Test**: Seed clients A and B, each with a mail domain and a mailbox. With A's key:
`POST /mail/users {email: "x@b.test"}` → 400 with the same body as for a nonexistent domain, no `sys_datalog` row;
`POST /mail/forwards` forward/catch-all with source on `b.test` → 400; alias with destination `b@b.test` → 422 on
`destination`; `POST /mail/alias-domains` with either side `@b.test` → 400. The same requests on A's own domain
succeed. The admin key keeps succeeding on both domains.

**Acceptance Scenarios**:

1. **Given** a client key and a mail domain the key cannot read, **When** it creates a mailbox with an address in
   that domain, **Then** 400 problem+json identical to "the domain is not an existing mail domain", and nothing is
   written (no row, no datalog, no spam filter user).
2. **Given** the same, **When** it creates a forward, alias or catch-all whose source is in that domain, **Then** 400
   identical to the nonexistent-domain response.
3. **Given** a client key, **When** it creates or updates an alias (type `alias`) whose destination list contains an
   address that is not a mailbox the key can read, **Then** 422 with an error on `destination` identical for
   nonexistent and foreign mailboxes. Forward and catch-all destinations stay free (external addresses allowed).
4. **Given** a client key, **When** it creates an alias domain or changes an alias domain's destination where the
   source or destination is a mail domain the key cannot read, **Then** 400 identical to the nonexistent-domain
   response.
5. **Given** a mailbox the key can read whose mail domain it cannot read (legacy data), **When** it updates the
   mailbox, **Then** 400 identical to the nonexistent-domain response (legacy re-checks the domain on every submit).
6. **Given** an admin key, **When** it performs any of the above, **Then** behaviour is unchanged.
7. **Given** a reseller key, **When** the referenced mail domain belongs to one of its clients, **Then** the write
   succeeds (reseller group scope); for a domain outside its scope it fails as for a client.

---

### User Story 2 - Sites and DNS writes only under the key's own parents (Priority: P2)

The same customer must not attach subdomains, FTP/SSH/WebDAV users, cron jobs, protected folders, folder users or
databases to another tenant's website, use another tenant's database user, or add records to another tenant's DNS
zone.

**Why this priority**: Same vulnerability class with severe impact (file access, DNS hijack); the fix reuses the
same scoping helper. Independent of the mail story.

**Independent Test**: Seed A and B each with a vhost, a web folder, a database user and a DNS zone. With A's key, every
listed create (and the update endpoints that accept a changed parent) referencing B's row returns 422 on the
reference field with the same message as for a nonexistent id, with no datalog row; referencing A's own rows
succeeds; admin unchanged.

**Acceptance Scenarios**:

1. **Given** a client key and a website it cannot read, **When** it creates a child domain, website
   (vhostsubdomain/vhostalias), FTP user, shell user, WebDAV user, cron job, web folder or database with that
   `parent_domain_id`, or moves an existing record there, **Then** 422 on `parent_domain_id` identical to a
   nonexistent id.
2. **Given** a database user the key cannot read, **When** a database create/update names it as `database_user_id` or
   `database_ro_user_id`, **Then** 422 on that field identical to a nonexistent id.
3. **Given** a web folder the key cannot read, **When** it creates a folder user for it, **Then** 422 on
   `web_folder_id`.
4. **Given** a DNS zone the key cannot read, **When** it creates a record in that zone or moves a record there,
   **Then** 422 on `zone` identical to a nonexistent id.
5. **Given** a spam filter user the key cannot read, **When** it creates an allow/deny list entry with that `rid`,
   **Then** 404 identical to a nonexistent spam filter user.

### Edge Cases

- A world-readable referenced row (`sys_perm_other` containing `r`) counts as readable, as in legacy `getAuthSQL('r')`.
- IDN input: the domain part is compared after the existing IDN/lowercase normalisation.
- Alias destination lists: every address must be a readable mailbox; one bad address rejects the whole request.
- Admin keys: no additional checks, including alias destinations (legacy admins also pick destinations from a list
  of existing mailboxes, but the current admin API accepts any address — unchanged).
- Rejected requests write no datalog entry and return no `X-Change-Set-Id` header.
- Existing cross-tenant rows created before this fix are not modified; updating them fails where the reference is
  re-validated.

## API Contract *(mandatory)*

- **Spec file(s)**: no new endpoints. Existing operations get descriptions of the scoped reference checks:
  `api/modules/mail/users.yaml`, `forwards.yaml`, `alias-domains.yaml`, `spamfilter-wblist.yaml`,
  `api/modules/dns/records.yaml`, `api/modules/sites/web-domains.yaml`, `web-child-domains.yaml`, `ftp-users.yaml`,
  `shell-users.yaml`, `webdav-users.yaml`, `cron-jobs.yaml`, `web-folders.yaml`, `web-folder-users.yaml`,
  `databases.yaml` (existing 400/404/422 responses; wording only).
- **Shared schemas**: unchanged.
- **Endpoints** (status codes unchanged; new rejection causes for non-admin keys):

| Method | Path | New rejection for foreign references | Code |
|--------|------|--------------------------------------|------|
| POST/PUT | `/api/v1/mail/users` | mail domain of the address | 400 |
| POST | `/api/v1/mail/forwards` | source mail domain | 400 |
| POST/PUT | `/api/v1/mail/forwards` | alias destination mailbox | 422 |
| POST/PUT | `/api/v1/mail/alias-domains` | source / destination mail domain | 400 |
| POST | `/api/v1/mail/spamfilter/wblist` | `rid` | 404 |
| POST/PUT | `/api/v1/dns/records` | `zone` | 422 |
| POST/PUT | `/api/v1/sites/web-domains`, `/web-child-domains`, `/ftp-users`, `/shell-users`, `/cron-jobs`; POST `/webdav-users`, `/web-folders` | `parent_domain_id` | 422 |
| POST/PUT | `/api/v1/sites/databases` | `parent_domain_id`, `database_user_id`, `database_ro_user_id` | 422 |
| POST | `/api/v1/sites/web-folder-users` | `web_folder_id` | 422 |

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference** (ISPConfig 3.3.1p1, read on isp-test):
  - `mail/mail_user_edit.php:181-185` — domain lookup `AND getAuthSQL('r')`, else `no_domain_perm`;
    `:330-334` owner = domain group.
  - `mail/mail_forward_edit.php:102-104`, `mail/mail_domain_catchall_edit.php:96-98`,
    `mail/mail_alias_edit.php:104-106` — source domain `getAuthSQL('r')`, else `no_domain_perm`.
  - `mail/mail_alias_edit.php:108-112` — destination mailbox `getAuthSQL('r')`, else `no_destination_perm`;
    `form/mail_alias.tform.php:93-114` destination datasource `mail_user WHERE {AUTHSQL}`.
  - `form/mail_domain_catchall.tform.php:98-122` — destination free text (datasource commented out);
    `mail_forward` destination free text.
  - `mail/mail_aliasdomain_edit.php:99-105` — source and destination `getAuthSQL('r')`, else `no_domain_perm`.
  - `mail/form/spamfilter_whitelist.tform.php:84-93` — `rid` datasource `spamfilter_users WHERE {AUTHSQL}`.
  - `dns/dns_edit_base.php:100-103` — zone `getAuthSQL('r')`, else `no_zone_perm`.
  - `sites/web_childdomain_edit.php:187-188`, `web_vhost_domain_edit.php:916-917`, `ftp_user_edit.php:98-100`,
    `shell_user_edit.php:110-111`, `webdav_user_edit.php:105-106`, `cron_edit.php:139-140`,
    `web_folder_edit.php:58-59`, `database_edit.php:179-180` — parent website `getAuthSQL('r')`, else `no_domain_perm`.
  - `sites/web_folder_user_edit.php:58-59` — folder `getAuthSQL('r')`, else `no_folder_perm`.
  - `sites/form/database.tform.php:158,169` — database user datasources `web_database_user WHERE {AUTHSQL}`.
- **Legacy behaviors to mirror**: the referenced row must be readable by the acting user; a missing and a foreign
  row produce the same error.
- **Tables written (via datalog only)**: none added; rejected requests write nothing.
- **System fields handling**: unchanged (owner/server still derived from the referenced row where legacy does).
- **Intentional deviations from legacy**:
  - Error shapes follow the API contract (400/404/422 problem+json) instead of form messages.
  - Database user and spam filter `rid` references are checked server-side although legacy only restricts the form
    datasource (owner-delegated decision 2026-09-15: the API has no form, so datasource restrictions become validation).
  - Admin alias destinations are not restricted to existing mailboxes (current API behaviour kept).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: For non-admin keys, every write that resolves a mail domain by name (mailbox address, forward/alias/
  catch-all source, alias domain source and destination) MUST only consider mail domains readable by the key; an
  unreadable domain MUST produce the same response as a nonexistent one.
- **FR-002**: For non-admin keys, alias (type `alias`) destinations MUST each be the address of a mailbox readable by
  the key; otherwise 422 on `destination` with one message for nonexistent and foreign mailboxes.
- **FR-003**: For non-admin keys, `parent_domain_id` on sites writes, `database_user_id`/`database_ro_user_id` on
  database writes, `web_folder_id` on folder user writes and `zone` on DNS record writes MUST reference readable rows;
  otherwise 422 identical to a nonexistent id.
- **FR-004**: For non-admin keys, a non-zero `rid` on allow/deny list writes MUST reference a readable spam filter
  user; otherwise 404 identical to a nonexistent one.
- **FR-005**: Rejected requests MUST NOT write any row or `sys_datalog` entry.
- **FR-006**: Admin keys MUST be unaffected; reseller keys follow their group scope.
- **FR-007**: Every change MUST be covered by two-tenant feature tests that fail before the fix.

### Key Entities

- **Referenced row**: the parent/target row named by value in a write — `mail_domain`, `mail_user`, `spamfilter_users`,
  `web_domain`, `web_database_user`, `web_folder`, `dns_soa`; readability per spec 011 `AuthScope::applyReadPredicate`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In two-tenant tests, 0 of the listed cross-tenant writes succeed for client or reseller keys, and each
  rejection body equals the nonexistent-reference body.
- **SC-002**: The same writes on the key's own rows succeed, and the admin key succeeds on both tenants' rows.
- **SC-003**: The full test suite passes with no change to admin-key tests.
- **SC-004**: A live check on isp-test with two temporary clients reproduces SC-001 and SC-002 for mail writes.

## Assumptions

- The WHMCS panel (module spec 004) is the first consumer; it only uses client-scoped keys.
- No existing data is migrated; cross-tenant rows created earlier (if any) remain and can be removed by an admin.
- 016 already covers fetchmail destinations; admin-only mail resources need no change.
