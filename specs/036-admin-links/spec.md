# Feature Specification: Administration and File-Transfer Links for Scoped Keys

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-16  
**Status**: Draft  
**Module**: me (plus the `[sites]` system configuration)  
**Input**: User description: "Administration and file-transfer links: expose the installation's `phpmyadmin_url` and `webftp_url` (legacy system config, placeholders resolved like the webmail URL in spec 025) to scoped keys, e.g. in `/me/mail-settings`'s sibling or a `/me/hosting-links` endpoint — decide and document; no admin-only data."

## Context

An ISPConfig installation usually runs a database administration tool (phpMyAdmin) and sometimes a web file manager
for FTP accounts. The interface shows a link to them next to each database and FTP account, built from two system
settings: `phpmyadmin_url` (with `[SERVERNAME]` and `[DATABASENAME]` placeholders) and `webftp_url`.

A customer panel wants the same two buttons, but cannot learn the addresses: both settings live in the `[sites]`
section of the `sys_ini` blob, `SystemSitesConfig.yaml` deliberately does not expose them, and the whole `system`
module is administrator-only for scoped keys. The WHMCS module (spec 006) therefore hides both buttons, which is a
visible gap next to ISPConfig's own interface.

Spec 025 solved the same problem for mail: `GET /me/mail-settings` returns the webmail address with `[SERVERNAME]`
resolved, without exposing anything else from the system configuration. This feature does the same for the database
administration and file-transfer links.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Panel offers database administration (Priority: P1)

A customer opens "Databases" and clicks "Open database administration" next to a database. The panel builds the
address from the account's links and the database's own name, and the customer lands in phpMyAdmin for the server
that hosts the database.

**Why this priority**: the main reason a customer visits the database area; without it they cannot manage tables and
data at all.

**Independent Test**: call `GET /api/v1/me/hosting-links` with a client key on an account with a database and verify
the returned address matches what the ISPConfig interface would open for that database.

**Acceptance Scenarios**:

1. **Given** the installation setting `https://[SERVERNAME]:8081/phpmyadmin` and a database server named
   `db1.example.com`, **When** `GET /me/hosting-links`, **Then** the entry for that server carries
   `https://db1.example.com:8081/phpmyadmin` and `available` is true.
2. **Given** a setting that also contains `[DATABASENAME]`, **When** the same call, **Then** that placeholder is left
   in the returned address for the consumer to substitute with the database's own name, and the response says so.
3. **Given** the installation hides the link (`dblist_phpmyadmin_link` is not `y`) or the setting is empty, **When**
   the same call, **Then** `available` is false and no address is returned.
4. **Given** an account whose databases live on two servers, **When** the same call, **Then** each server appears
   once with its own address.

---

### User Story 2 - Panel offers web file transfer (Priority: P2)

A customer opens "FTP accounts" and sees an "Open file manager" button when the installation runs one.

**Why this priority**: useful but optional; FTP accounts work without it.

**Independent Test**: set and clear `webftp_url` and verify the endpoint mirrors it.

**Acceptance Scenarios**:

1. **Given** `webftp_url` is `https://files.example.com`, **When** `GET /me/hosting-links`, **Then** the file
   transfer address is exactly that value and `available` is true.
2. **Given** `webftp_url` is empty, **When** the same call, **Then** `available` is false and the address is empty.

---

### User Story 3 - Nothing configured, nothing shown (Priority: P3)

An installation without either tool must not make a panel show a broken button.

**Why this priority**: prevents dead links; the other stories already carry the positive cases.

**Independent Test**: clear both settings and read the endpoint.

**Acceptance Scenarios**:

1. **Given** neither setting is configured, **When** `GET /me/hosting-links`, **Then** both parts report
   `available: false` and the call still succeeds with the account's servers listed as an empty list.
2. **Given** any key type, **When** the endpoint is read, **Then** nothing from the system configuration beyond these
   two links is returned.

---

### Edge Cases

- An account with no databases and no assigned database servers gets an empty server list, with `available` still
  describing the installation.
- A database server the account uses but is not assigned to appears as well, so the link works for every database the
  key can read (the spec 031 composition).
- A setting containing neither placeholder is returned verbatim.
- PostgreSQL's separate `phppgadmin_url` is out of scope: the panel does not offer PostgreSQL databases.
- The endpoint never redirects and never proxies; it only reports addresses.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: A read-only `GET /me/hosting-links` MUST return the database administration and file-transfer links for
  the resolved client, with the same target rules as the other `/me` reads (client key: own account; reseller: own or
  one of its clients by `client_id`; admin: `client_id` required).
- **FR-002**: The database administration part MUST report `available` (the installation shows the link:
  `dblist_phpmyadmin_link` is `y` and `phpmyadmin_url` is not empty) and one entry per database server of the
  account, each with `server_id`, `server_name` and the address with `[SERVERNAME]` resolved.
- **FR-003**: A `[DATABASENAME]` placeholder MUST be left in the returned address, and the contract MUST state that
  the consumer substitutes the database's own (prefixed) name — the API cannot know which database a link is for.
- **FR-004**: The file-transfer part MUST report `available` (`webftp_url` is not empty) and the address verbatim;
  legacy substitutes nothing there.
- **FR-005**: The account's database servers MUST be composed like spec 031: assigned database servers in assignment
  order, then other database servers hosting the account's databases, each server once.
- **FR-006**: The endpoint MUST NOT expose any other system configuration value, any server data beyond id and name,
  or another client's resources.
- **FR-007**: The endpoint MUST be read-only and MUST write nothing.
- **FR-008**: The contract (`api/modules/me/hosting-links.yaml`, its schemas and the `me` module index) MUST describe
  the response before the implementation, and the README MUST mention the endpoint.

### Key Entities

- **Hosting links**: the installation's two tool addresses as they apply to one account, derived per request from the
  `[sites]` section of `sys_ini` and the account's database servers. Read-only, never stored.

## Success Criteria *(mandatory)*

- **SC-001**: A panel can offer a working database administration link for every database a customer key can read,
  without any administrator access.
- **SC-002**: A panel can decide whether to show each button from one field, without parsing addresses.
- **SC-003**: An installation that configures neither tool causes no button and no error.
- **SC-004**: The endpoint exposes exactly two settings; no other system configuration becomes readable for scoped
  keys.

## Assumptions

Owner-delegated decisions (2026-09-16), recorded per the owner's standing instruction to apply recommendations:

- **A separate `GET /me/hosting-links` endpoint**, not a block of `/me/capabilities`. Capabilities describe what the
  plan allows; these are installation addresses, the same distinction spec 025 drew between `/me/capabilities` and
  `/me/mail-settings`. Panels read it only on the pages that show the buttons.
- **`[DATABASENAME]` stays unresolved**, like `[DOMAINID]` in the spec 035 prefixes: one address describes a server,
  not a database.
- **`available` for the database link follows the interface's own gate** (`dblist_phpmyadmin_link`), so a provider who
  switched the link off in ISPConfig does not see it reappear in the panel.
- **The file-transfer address is reported verbatim**, including any placeholder, because legacy substitutes nothing.
- **Out of scope**: `phppgadmin_url` (PostgreSQL), the mailing-list link (`mailmailinglist_url`), monitoring links
  (munin/monit), and any redirect or single-sign-on into those tools.
