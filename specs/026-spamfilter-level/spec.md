# Feature Specification: Spam Filter Level Selection for Scoped Keys

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-15  
**Status**: Draft  
**Module**: mail  
**Input**: User description: "Spam filter level selection: client and reseller keys choose, for their own mailbox or mail domain, a spam filter policy they may use (legacy spamfilter_users.policy_id; readable policies); list readable policies; respect spec 024 reference scoping and spec 012 limits. Must fit the WHMCS module `specs/004-mail/contracts/ispconfig-rest-calls.md` (`PUT /mail/users/{id}/spamfilter` `policy_id`, `PUT /mail/domains/{id}` `spamfilter_policy_id`)."

## Context

In the ISPConfig panel a customer picks a spam filter level ("Normal", "Permissive", "Trigger happy", …) on the
mailbox form and on the mail domain form. The choice is stored in `spamfilter_users`: one row per mailbox address
(`policy_id`, 0 = inherit the domain or server default) and one row per domain (`@domain`, 0 = no policy). The list
offers every policy the customer can read.

The API already lists the readable policies (`GET /mail/spamfilter/policies`), but the level itself can only be set
through `/mail/spamfilter/users`, which is administrator-only. Customer keys therefore cannot change the spam filter
level of their own mailboxes and domains, and the mail domain resource does not show it at all.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Customer chooses the spam filter level of a mailbox (Priority: P1)

A customer opens a mailbox in the panel, sees its current spam filter level ("inherit" when none is chosen) and picks
another level from the list the account may use.

**Why this priority**: spam handling per mailbox is the most requested mail setting after passwords; the WHMCS module
blocks the level choice until the API accepts it from customer keys.

**Independent Test**: seed world-readable and private policies, a mailbox with and without a `spamfilter_users` row;
call `GET`/`PUT /mail/users/{id}/spamfilter` with client, reseller and admin keys and compare responses, rows and
datalog entries.

**Acceptance Scenarios**:

1. **Given** a mailbox without a spam filter user row, **When** its client key calls `GET
   /mail/users/{id}/spamfilter`, **Then** 200 with `policy_id = 0`.
2. **Given** a world-readable policy 5, **When** the client key sends `PUT /mail/users/{id}/spamfilter` `{policy_id:
   5}`, **Then** 200 with `policy_id = 5`, and the mailbox's `spamfilter_users` row has `policy_id = 5` through one
   datalog entry (update when the row exists, insert with priority 7, `local = Y`, the address as `fullname`, the
   domain's server and owner group when it does not).
3. **Given** the row already has policy 5, **When** the same value is sent, **Then** 200 and nothing is journaled.
4. **Given** `policy_id = 0`, **Then** the mailbox inherits again (row updated to 0).
5. **Given** a policy the key cannot read, or a nonexistent policy, **Then** 422 on `policy_id` with the same message,
   nothing journaled.
6. **Given** the installation hides the mail filter tab (feature 025), **Then** `policy_id` is still accepted (the
   level is part of the always-shown mailbox tab).

---

### User Story 2 - Customer chooses the spam filter level of a mail domain (Priority: P1)

A customer opens a mail domain and sets the level used for addresses of the domain that have no own level.

**Why this priority**: domain-wide spam handling is how most customers configure spam filtering; the module shows it on
the domain page.

**Independent Test**: create and update mail domains with `spamfilter_policy_id` using client, reseller and admin
keys; read the domain and the list; compare the `@domain` spam filter user row and the datalog.

**Acceptance Scenarios**:

1. **Given** a mail domain without an `@domain` row, **When** the client key reads it (`GET /mail/domains/{id}` or the
   list), **Then** `spamfilter_policy_id = 0`.
2. **When** the client key sends `PUT /mail/domains/{id}` `{spamfilter_policy_id: 5}`, **Then** 200 with
   `spamfilter_policy_id = 5` and an `@domain` row (priority 5, `email` and `fullname` `@domain`, `local = Y`, the
   domain's server and owner group) inserted or updated through the datalog in the same change set.
3. **When** `POST /mail/domains` carries `spamfilter_policy_id`, **Then** the domain and its `@domain` row are created
   together; an invalid policy creates neither.
4. **Given** a domain update without `spamfilter_policy_id`, **Then** the `@domain` row is untouched.
5. **Given** an unreadable or nonexistent policy, **Then** 422 on `spamfilter_policy_id`, nothing journaled.

---

### User Story 3 - Only usable levels, only own mailboxes and domains (Priority: P2)

A customer's key can only pick levels it can read and only for mailboxes and domains it may change; administrators
can pick any level.

**Why this priority**: private policies of other accounts must not be referenced, and the level of another account's
mailbox must not be changeable.

**Independent Test**: tenant matrix (client A, client B, reseller of A, admin) over mailboxes and domains of A and B
and policies owned by A, by the administrator (world-readable and private).

**Acceptance Scenarios**:

1. **Given** client B's key, **When** it targets client A's mailbox or domain, **Then** 404 (unchanged scoping).
2. **Given** a policy owned by client A (not world-readable), **Then** client A may choose it and client B gets 422.
3. **Given** the reseller of A, **Then** it may choose levels for A's mailboxes and domains.
4. **Given** a mailbox the key can read but not update, **Then** 403 and nothing journaled.
5. **Given** the admin key, **Then** any existing policy is accepted.
6. **Given** a client key, **When** it calls `GET /mail/spamfilter/policies`, **Then** only the policies it can read
   are listed (existing behavior, documented for the panel).

### Edge Cases

- `policy_id` / `spamfilter_policy_id` not an integer or negative → 422.
- An existing spam filter user row owned by another group (e.g. created by an administrator) is still updated: the
  permission comes from the mailbox or domain, as in legacy (direct datalog update of the companion row).
- Locked accounts (feature 019) may still change the level: it does not enable or add a service.
- No client limit applies (`limit_spamfilter_user` governs the administrator spam filter users module; legacy creates
  these companion rows without a limit check).
- The existing mailbox create keeps inserting the mailbox row with policy 0; renames do not exist in the API.
- Deleting a mailbox or domain keeps removing its rows (existing cascade).
- `PUT /mail/users/{id}/spamfilter` with `policy_id` and tab fields: the tab rules of feature 025 apply to the tab
  fields only.

## API Contract *(mandatory)*

- **Spec file(s)**: `api/modules/mail/user-spamfilter.yaml`, `api/modules/mail/domains.yaml`,
  `api/modules/mail/spamfilter-policies.yaml` (description only).
- **Shared schemas**: `api/components/schemas/MailUserSpamFilter.yaml` (+ `policy_id`),
  `api/components/schemas/MailDomain.yaml` (+ `spamfilter_policy_id`).
- **Endpoints**:

| Method | Path | Purpose | Success code |
|--------|------|---------|--------------|
| GET | `/api/v1/mail/users/{id}/spamfilter` | + `policy_id` | 200 |
| PUT | `/api/v1/mail/users/{id}/spamfilter` | set `policy_id` (all keys) | 200 |
| GET | `/api/v1/mail/domains`, `/api/v1/mail/domains/{id}` | + `spamfilter_policy_id` | 200 |
| POST | `/api/v1/mail/domains` | optional `spamfilter_policy_id` | 201 |
| PUT | `/api/v1/mail/domains/{id}` | optional `spamfilter_policy_id` | 200 |
| GET | `/api/v1/mail/spamfilter/policies` | readable levels (unchanged) | 200 |

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference** (ISPConfig 3.3.1p1): `mail/mail_user_edit.php` 99–110 (policy select, `getAuthSQL('r')`,
  option 0 "inherit"), 336–360 (onAfterInsert upsert), 395–480 (onAfterUpdate upsert); `mail/mail_domain_edit.php`
  194–206 (select, option 0 "no policy"), 364–390 (onAfterInsert `@domain` upsert, priority 5), 468–492 (onAfterUpdate);
  `mail/form/spamfilter_users.tform.php` 78–86 (policy datasource `{AUTHSQL}`); `mail_user_del.php` 57–66 and
  `mail_domain_del.php` 80–87 (cascades, already implemented).
- **Legacy behaviors to mirror**: row per address and per `@domain`; insert defaults (mailbox priority 7, domain
  priority 5, `local = Y`, `fullname`, `server_id` and `sys_groupid` from the mail domain, `sys_userid` of the acting
  user, permissions `riud`/`riud`/''); update only `policy_id` and only when it changed; companion rows written with
  direct datalog calls.
- **Tables written (via datalog only)**: `spamfilter_users` (`i`/`u`), in the same change set as the mailbox or domain
  write.
- **System fields handling**: inserted rows get `sys_userid` = acting user, `sys_groupid` = mail domain group,
  `sys_perm_user`/`sys_perm_group` = `riud`, `sys_perm_other` = ''.
- **Intentional deviations from legacy** (owner-delegated decisions 2026-09-15):
  - The mailbox level lives on the spam filter sub-resource (`policy_id`) and the domain level on the domain resource
    (`spamfilter_policy_id`), the names the WHMCS module contract uses.
  - A policy the key cannot read is refused (422); legacy only hides it in the select but stores any posted id.
  - The `@domain` row is written only when `spamfilter_policy_id` is sent; legacy inserts a policy-0 row on every
    domain save, which has no filtering effect.
  - A mailbox or domain the key can read but not update is refused (403) even when only the level changes; legacy
    forms are not reachable without update permission.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `GET /mail/users/{id}/spamfilter` MUST include `policy_id`: the `policy_id` of the `spamfilter_users` row
  whose `email` is the mailbox address, 0 when there is none.
- **FR-002**: `PUT /mail/users/{id}/spamfilter` MUST accept `policy_id` (integer ≥ 0) from every key and store it in
  the mailbox's `spamfilter_users` row: datalog update when the row exists and the value differs; datalog insert
  (priority 7, `local = Y`, `fullname` = IDN-decoded address, `server_id`/`sys_groupid` of the mail domain) when it
  does not.
- **FR-003**: `GET /mail/domains` and `GET /mail/domains/{id}` (and the POST/PUT responses) MUST include
  `spamfilter_policy_id`: the `policy_id` of the `@domain` row, 0 when there is none; the list MUST read the values of
  a page with one query.
- **FR-004**: `POST /mail/domains` and `PUT /mail/domains/{id}` MUST accept `spamfilter_policy_id` (integer ≥ 0) and
  upsert the `@domain` row (priority 5, `email` = `fullname` = `@domain`, `local = Y`, `server_id`/`sys_groupid` of the
  domain) in the same transaction and change set; without the field the row MUST NOT be written.
- **FR-005**: A non-zero policy id MUST reference a `spamfilter_policy` row readable by the acting key; otherwise 422
  on the field with the message used for a nonexistent policy. Admin keys may use any existing policy.
- **FR-006**: Writing a level MUST require update permission on the mailbox or domain (403 otherwise, nothing
  journaled), and MUST NOT be restricted by client limits, the lock guard or the feature 025 tab switches.
- **FR-007**: Unchanged levels MUST produce no datalog entry; refused requests MUST write nothing.
- **FR-008**: Contract first; feature tests for success, 403/404/422, tenant matrix, datalog payloads and list
  batching.

### Key Entities

- **Spam filter user** (`spamfilter_users`): `email` (address or `@domain`), `policy_id`, `priority`, `local`,
  `fullname`, `server_id`, sys fields.
- **Spam filter policy** (`spamfilter_policy`): the selectable levels, readable per `sys_perm_*`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A customer key can read and change the spam filter level of each of its mailboxes and mail domains
  with one request each.
- **SC-002**: 0 accepted references to policies the key cannot read, and 0 changes to other accounts' mailboxes or
  domains, across the tenant matrix tests.
- **SC-003**: Legacy ISPConfig shows the level chosen through the API in the mailbox and domain forms (same rows and
  values), verified on isp-test.

## Assumptions

- ISPConfig's server plugins read `spamfilter_users` exactly as the panel writes them (Amavis and Rspamd).
- The WHMCS module shows the levels from `GET /mail/spamfilter/policies` and sends the chosen id.
- Feature 025 reports `mail.spamfilter_policy` so the panel knows whether any level is available.
