# Feature Specification: Mailbox Access Switches and Password Policy

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-15  
**Status**: Draft  
**Module**: mail  
**Input**: User description: "Mailbox access switches and password policy: expose disableimap/disablepop3/disablesmtp/disabledeliver (as the legacy client forms allow); enforce the installation password policy (min_password_length/min_password_strength, legacy strength calculation) on mailbox create and password change for all keys where legacy enforces it (today 5-character passwords pass); list client/FTP and other password fields and fix only mail unless trivial; keep the spec 019 lock guard interaction on disablesmtp correct and tested. Must fit the WHMCS module `specs/004-mail/contracts/ispconfig-rest-calls.md` (`PUT /mail/users/{id}` `disableimap|disablepop3|disablesmtp` booleans, password policy parity)."

## Context

The ISPConfig mailbox form offers every user four switches — "Disable sending", "Disable (local) delivering",
"Disable IMAP", "Disable POP3" — and validates the password with the installation's password policy (minimum length
and strength, or ASCII-only characters). The API neither exposes the switches nor applies the policy: any password of
five or more characters is accepted, so a panel shows rules (feature 025) that the API does not enforce, and a
customer cannot turn off POP3 or sending for a single mailbox.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Mailbox passwords follow the installation's password policy (Priority: P1)

A customer creates a mailbox or changes its password in the panel. A password that is too short or too weak for the
installation's rules is refused with the same explanation ISPConfig gives; a compliant password is accepted.
Administrator keys follow the same rules, as in the ISPConfig panel.

**Why this priority**: weak mailbox passwords are the main cause of compromised accounts and spam from customer
mailboxes; feature 025 already shows the policy to customers.

**Independent Test**: set `min_password_length`, `min_password_strength` and `mail_password_onlyascii`; create
mailboxes, update passwords through `PUT /mail/users/{id}` and `PUT /mail/users/{id}/password` with client and admin
keys; compare status codes and messages with the legacy validator.

**Acceptance Scenarios**:

1. **Given** `min_password_length = 8`, `min_password_strength = 3`, **When** a client key creates a mailbox with
   password `abcdefgh` (strength 2), **Then** 422 on `password` with `The chosen password does not match the security
   guidelines. It has to be at least 8 chars in length and have a strength of "Good".` and nothing is written.
2. **Given** the same policy, **When** the password is `Abcdef12` (strength 3), **Then** 201.
3. **Given** `min_password_strength` is empty or 0 and `min_password_length = 10`, **When** the password has 9
   characters, **Then** 422 with `… It has to be at least 10 chars in length.`
4. **Given** the policy keys are missing, **Then** the ISPConfig defaults apply (at least 8 characters, no strength).
5. **Given** `mail_password_onlyascii = y`, **When** the password contains non-ASCII characters, **Then** 422 with
   `Please do not use special unicode characters for your password. This could lead to problems with your mail
   client.`; ASCII passwords are accepted without the length and strength checks (ISPConfig replaces the validator).
6. **Given** a password update with an empty password on `PUT /mail/users/{id}`, **Then** the password stays unchanged
   (no policy check).
7. **Given** an admin key, **Then** the same rules apply.

---

### User Story 2 - Customer switches mailbox access (Priority: P1)

A customer turns off POP3 for a mailbox, or temporarily stops a compromised mailbox from sending, or stops local
delivery while keeping "send copy to" forwarding.

**Why this priority**: the WHMCS module blocks the "Disable IMAP/POP3/sending" switches until the API exposes them.

**Independent Test**: create and update mailboxes with the four switches; read them back; compare the stored columns
including the Dovecot companion columns and the datalog.

**Acceptance Scenarios**:

1. **Given** a mailbox, **When** its client key reads it, **Then** `disableimap`, `disablepop3`, `disablesmtp` and
   `disabledeliver` are returned as booleans (list and show).
2. **When** the client key sends `PUT /mail/users/{id}` `{disableimap: true}`, **Then** 200 and one datalog update sets
   `disableimap`, `disablesieve` and `disablesieve-filter` to `y`.
3. **When** it sends `{disabledeliver: true}`, **Then** `disabledeliver`, `disablelda` and `disablelmtp` are `y`.
4. **When** a mailbox is created with switches, **Then** the stored switches and companion columns match.
5. **Given** an update that does not send `disableimap` or `disabledeliver`, **Then** the companion columns are not
   touched.

---

### User Story 3 - Locked accounts cannot re-enable sending (Priority: P2)

A locked customer account (feature 019) had sending switched off for its mailboxes. The customer's key cannot switch
sending back on; switching it off, and other switches, stay possible; administrators may do anything.

**Why this priority**: exposing `disablesmtp` must not bypass the lock guard.

**Independent Test**: lock a client whose mailbox has `disablesmtp = y`; send `disablesmtp: false` with client,
reseller and admin keys.

**Acceptance Scenarios**:

1. **Given** a locked client and a mailbox with `disablesmtp = y`, **When** the client or reseller key sends
   `disablesmtp: false`, **Then** 403 `account-locked`, nothing written.
2. **When** it sends `disablesmtp: true` on a mailbox that can send, or changes `disablepop3`, **Then** 200.
3. **When** the admin key sends `disablesmtp: false`, **Then** 200.

### Edge Cases

- `min_password_length` present but empty → no minimum; strength empty → no strength check (legacy comparisons).
- Length is counted in bytes (legacy `strlen`).
- Strength follows the legacy table: under 5 characters strength 1; character classes lowercase, uppercase (1 point),
  digits (1 point), special characters (1 point); fewer than 3 classes or 0 points → 1/2/3 by length (5–6, 7–8, 9+);
  2 points → 3/4/5 (5–8, 9–10, 11+); 3 points → 3/4/5 (5–6, 7–8, 9+).
- The maximum length stays 255.
- The switches accept booleans and legacy `y`/`n` strings (like the existing mailbox flags).
- Other password fields (clients, resellers, FTP, shell, WebDAV, database users, web folder users, mailbox
  self-service) keep their current rules in this feature; they are listed as follow-up work.

## API Contract *(mandatory)*

- **Spec file(s)**: `api/modules/mail/users.yaml`, `api/modules/mail/user-password.yaml` (descriptions and 422).
- **Shared schemas**: `api/components/schemas/MailUser.yaml` (four switches; password policy description, no fixed
  `minLength`), `api/components/schemas/MailUserPassword.yaml` (policy description, no fixed `minLength`).
- **Endpoints**:

| Method | Path | Purpose | Success code |
|--------|------|---------|--------------|
| GET | `/api/v1/mail/users`, `/api/v1/mail/users/{id}` | + four switches | 200 |
| POST | `/api/v1/mail/users` | switches; password policy | 201 |
| PUT | `/api/v1/mail/users/{id}` | switches; password policy for non-empty passwords | 200 |
| PUT | `/api/v1/mail/users/{id}/password` | password policy | 200 |

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference** (ISPConfig 3.3.1p1): `mail/form/mail_user.tform.php` 129–145 (password validator
  `validate_password::password_check` for every user type), 321–344 (the four CHECKBOX switches, default `n`), 350–354
  (`mail_password_onlyascii` replaces the validators with `ISASCII`); `mail/templates/mail_user_mailbox_edit.htm`
  104–130 (switches shown to every user type); `mail/mail_user_edit.php` 363–371 (onAfterInsert) and 384–392
  (onAfterUpdate: `disablesieve`, `disablesieve-filter` from `disableimap`; `disablelda`, `disablelmtp` from
  `disabledeliver`); `lib/classes/validate_password.inc.php` (strength table, `password_check`),
  `lib/classes/auth.inc.php` 211–228 (defaults 8 and 0), `lib/classes/tform_base.inc.php` 1057–1064 (`ISASCII`:
  `/[^\x20-\x7F]/`), 1367 (empty password fields skip validation); `lib/lang/en.lng` 168–174 and
  `mail/lib/lang/en_mail_user.lng` 70 (messages); spec 019 `ClientLockService::LOCK_ENTRIES` (`mail_user.disablesmtp`
  reversed).
- **Legacy behaviors to mirror**: validator for all user types; messages and strength names; defaults; ASCII-only mode;
  switch defaults; companion columns.
- **Tables written (via datalog only)**: `mail_user` (`i`/`u`) — switches and companion columns in the same entry.
- **System fields handling**: unchanged.
- **Intentional deviations from legacy** (owner-delegated decisions 2026-09-15):
  - Companion columns are written in the datalog'd save; legacy sets them with a direct SQL update after the save.
  - Companion columns are only recalculated when their source switch is sent (legacy recalculates on every form save).
  - `disablesieve-filter` is also set on create (legacy sets it only on update).
  - The fixed API minimum of 5 characters is replaced by the installation policy (which may allow shorter passwords
    when an administrator sets the minimum to 0 or empty, as in ISPConfig).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `POST /mail/users`, `PUT /mail/users/{id}` (non-empty password) and `PUT /mail/users/{id}/password` MUST
  validate the password with the installation policy for every key type: ASCII-only mode → only the ASCII check;
  otherwise length (`min_password_length`, default 8) and strength (`min_password_strength`, default 0) with the legacy
  algorithm and messages.
- **FR-002**: The fixed `min:5` rule MUST be removed; `max:255` stays; passwords stay required on create and on the
  password endpoint.
- **FR-003**: `GET /mail/users` and `GET /mail/users/{id}` MUST return `disableimap`, `disablepop3`, `disablesmtp`,
  `disabledeliver` as booleans; `POST` and `PUT` MUST accept them (booleans or `y`/`n`).
- **FR-004**: On create, and on update when the source switch is sent, `disablesieve` and `disablesieve-filter` MUST
  equal `disableimap`, and `disablelda` and `disablelmtp` MUST equal `disabledeliver`, in the same `mail_user` datalog
  entry.
- **FR-005**: For client and reseller keys, switching `disablesmtp` from `y` to `n` on a mailbox of a locked client
  MUST return 403 `account-locked` and write nothing; admin keys MUST be unaffected.
- **FR-006**: Contract first; unit tests for the strength table; feature tests for the policy matrix, switches,
  companion columns, datalog and lock interaction.

### Key Entities

- **Mailbox** (`mail_user`): `password` (CRYPTMAIL hash), `disableimap`, `disablepop3`, `disablesmtp`, `disabledeliver`,
  companion `disablesieve`, `disablesieve-filter`, `disablelda`, `disablelmtp`.
- **Password policy** (`sys_ini` [misc] `min_password_length`, `min_password_strength`; [mail] `mail_password_onlyascii`).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 0 mailbox passwords accepted by the API that the ISPConfig panel would refuse, across the policy matrix
  tests.
- **SC-002**: A customer can switch IMAP, POP3, sending and delivery of a mailbox with one request, and Dovecot sees
  the companion columns immediately after the server processed the change.
- **SC-003**: Locked accounts cannot re-enable sending through the new field (0 accepted attempts in tests).

## Assumptions

- The WHMCS module shows the policy from `GET /me/capabilities` (feature 025) and maps 422 messages to its own texts.
- Other password endpoints are handled in a later feature; the list is recorded in research.md.
