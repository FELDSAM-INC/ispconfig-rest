# Feature Specification: Password Policy for Non-Mail Users

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-16  
**Status**: Draft  
**Module**: sites (database, FTP, shell, WebDAV and web folder users, website statistics) + client  
**Input**: User description: "Password policy for non-mail users: spec 028 enforced the installation policy for mailboxes only. Apply the same policy to the other user types legacy checks: client/reseller passwords, FTP users, shell users, WebDAV users, web folder users and database users (check each legacy form for which validator applies — only enforce where legacy does). Typed 422 `errors.password`. NOTE: this changes what the WHMCS module may send when provisioning clients (it generates passwords) — keep the policy identical to legacy so a compliant generator passes."

## Context

An ISPConfig installation defines a password policy: a minimum length (`min_password_length`, default 8) and a
minimum strength on a 1–5 scale (`min_password_strength`, default "no requirement"), both in the `[misc]` section of
the system configuration. ISPConfig's own forms attach the validator `validate_password::password_check` to every
password a user can set — database users, FTP users, shell users, WebDAV users, web folder users, the statistics
password of a website, and client and reseller passwords.

Spec 028 ported that validator for mailboxes, where it is enforced for every key type. Everywhere else the API still
accepts anything: the client password only has to be 8 characters (`StoreClientRequest`), and the FTP, shell, WebDAV,
web folder and database user endpoints have no rule at all beyond a maximum length. A customer panel can therefore
create an SSH account with the password `x1234567` through the API, while the same password is refused in
ISPConfig's interface — and the provider's configured policy is not applied to the credentials that reach real
services.

This feature applies the installation's policy wherever legacy applies it, with the same computation and the same
messages.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Hosting credentials meet the provider's policy (Priority: P1)

A customer creates a database user, an FTP account or an SSH account. A password that is too short or too simple is
refused with the same explanation the ISPConfig interface gives.

**Why this priority**: these credentials reach services exposed to the internet; the provider's policy exists exactly
for them, and today the API ignores it.

**Independent Test**: with the installation policy set to length 8 and strength 3, create each of those users with a
weak password and with a compliant one.

**Acceptance Scenarios**:

1. **Given** `min_password_length` 8 and `min_password_strength` 3, **When** `POST /sites/database-users` sends
   `abcdefgh`, **Then** 422 with `errors.database_password` and nothing is written.
2. **Given** the same policy, **When** the same call sends `Str0ng-Pass!x`, **Then** 201.
3. **Given** the same policy, **When** `POST /sites/ftp-users`, `POST /sites/shell-users`,
   `POST /sites/webdav-users` or `POST /sites/web-folder-users` sends a weak password, **Then** 422 with
   `errors.password`.
4. **Given** an update that changes a password (`PUT` on any of those), **When** the new password is weak, **Then**
   422 and the stored hash is unchanged.
5. **Given** a request that omits the password or sends it empty, **When** it is otherwise valid, **Then** the policy
   does not apply (there is no new password to judge).

---

### User Story 2 - Client and reseller passwords follow the same policy (Priority: P1)

Provisioning creates ISPConfig clients with a generated password. That password must satisfy the installation policy,
as it does when an administrator creates the client in the interface.

**Why this priority**: it is the control-panel login of the customer; today only a length of 8 is required and the
strength setting is ignored.

**Independent Test**: create and update clients and resellers with weak and compliant passwords.

**Acceptance Scenarios**:

1. **Given** strength 3 is required, **When** `POST /clients` sends `password123`, **Then** 422 with
   `errors.password`.
2. **Given** the same policy, **When** it sends a generated 16-character mixed password, **Then** 201.
3. **Given** a reseller create or an update that changes the password, **When** the value is weak, **Then** 422.

---

### User Story 3 - The message explains what is required (Priority: P2)

The refusal states the required length and, when a strength is configured, its name — the wording ISPConfig uses.

**Why this priority**: a panel shows the message directly to the customer; inventing new wording would diverge from
the interface.

**Independent Test**: compare the message with the legacy text for both configurations.

**Acceptance Scenarios**:

1. **Given** strength 3 and length 8, **When** a weak password is refused, **Then** the message reads "The chosen
   password does not match the security guidelines. It has to be at least 8 chars in length and have a strength of
   "Good"."
2. **Given** strength 0 (no requirement) and length 10, **When** a 6-character password is sent, **Then** the message
   names the length only.

---

### User Story 5 - A panel can show the rules before submitting (Priority: P2)

A customer panel renders "at least 8 characters, strength Good" next to the password field and checks the input
before sending it, so the customer is not refused after the fact.

**Why this priority**: without readable values every panel must hard-code the provider's policy or guess; the mail
block already exposes it for mailboxes.

**Independent Test**: read `GET /api/v1/me/capabilities` with a client key under different policy settings.

**Acceptance Scenarios**:

1. **Given** `min_password_length` 8 and `min_password_strength` 3, **When** `GET /me/capabilities`, **Then**
   `sites.password_policy` is `{min_length: 8, min_strength: 3}`.
2. **Given** no strength configured, **When** the same call, **Then** `min_strength` is 0.
3. **Given** the same call, **Then** `sites.password_policy` carries no ASCII-only flag — that option applies to
   mailboxes only and stays in the `mail` block.

---

### User Story 4 - Mailboxes and unrelated credentials are untouched (Priority: P3)

Mailbox behaviour (spec 028) stays exactly as it is, including its ASCII-only option, and credentials legacy does not
validate keep their current rules.

**Why this priority**: prevents this change from leaking into areas legacy treats differently.

**Independent Test**: the existing mailbox tests keep passing unchanged; an API key create is unaffected.

**Acceptance Scenarios**:

1. **Given** `mail_password_onlyascii` is enabled, **When** a mailbox password with unicode is sent, **Then** the
   mailbox rule applies as before and the non-mail rule is not involved.
2. **Given** any policy, **When** an API key is created, **Then** nothing changes (API keys are not an ISPConfig
   credential).

---

### Edge Cases

- An installation that sets no strength (the default) enforces the length only — exactly as legacy does.
- A policy length of 0 accepts any non-empty password.
- The strength is computed with the legacy table (character classes and length), so a long lowercase-only password
  can still fail a high strength requirement, as in the interface.
- Passwords are judged before hashing; the stored format does not change.
- An update that leaves the password field out never triggers the policy.
- The statistics password of a website (`stats_password`) is included, because legacy validates it.

## Requirements *(mandatory)*

- **FR-001**: The installation policy MUST be read from the `[misc]` section — `min_password_length` (default 8, as
  `auth::get_min_password_length()`) and `min_password_strength` (default 0) — and the strength MUST be computed with
  the legacy table already ported for mailboxes.
- **FR-002**: The policy MUST be enforced on every password field legacy validates with `validate_password`:
  database users, FTP users, shell users, WebDAV users, web folder users, the website statistics password, and client
  and reseller passwords, on create and on password change.
- **FR-003**: Enforcement MUST apply to every key type, as legacy validates in the form regardless of who is logged
  in.
- **FR-004**: An absent or empty password MUST NOT be judged; the existing required/optional rules stay as they are.
- **FR-005**: The refusal MUST be 422 with the field in `errors` and the legacy message text (length only when no
  strength is configured).
- **FR-006**: Mailbox passwords MUST keep the spec 028 behaviour, including the mail-only ASCII option, which MUST
  NOT be applied to the user types of FR-002.
- **FR-007**: Nothing MUST be written when a password is refused.
- **FR-008**: The contract of every affected endpoint and the README MUST state that the installation policy applies
  before the implementation.
- **FR-009**: `GET /me/capabilities` MUST expose the installation's length and strength requirements in the `sites`
  block as `password_policy` (`min_length`, `min_strength`), so a consumer of these user types can comply without
  reading the mail block. The mail-only ASCII option MUST NOT appear there.
- **FR-010**: The README MUST carry an upgrade note listing the exact endpoints and fields that begin to refuse weak
  passwords, stating that provisioning integrations generating weak passwords will start failing and that rolling
  this out to a production installation is the operator's decision.

### Key Entities

- **Installation password policy**: minimum length and minimum strength of the installation, read per request from
  the system configuration; never written by this feature.

## Success Criteria *(mandatory)*

- **SC-001**: A password ISPConfig's own form would refuse can no longer be set through the API for any user type
  legacy validates.
- **SC-002**: The refusal text matches the interface, so a panel can show it unchanged.
- **SC-003**: Mailbox behaviour and every test covering it are unchanged.
- **SC-004**: A provisioning integration that generates strong passwords (16 characters, mixed classes) passes
  unchanged.

## Upgrade note (consumer-visible change)

After this version the following stop accepting a password that is weaker than the installation's policy
(`[misc] min_password_length`, default 8, and `min_password_strength`, default 0 = no strength requirement):

| Endpoint | Field |
|---|---|
| `POST /clients`, `PUT /clients/{id}` | `password` |
| `POST /resellers`, `PUT /resellers/{id}` | `password` (same rule as clients; own admin-only prefix) |
| `POST /sites/ftp-users`, `PUT /sites/ftp-users/{id}` | `password` |
| `POST /sites/shell-users`, `PUT /sites/shell-users/{id}` | `password` |
| `POST /sites/webdav-users`, `PUT /sites/webdav-users/{id}` | `password` |
| `POST /sites/web-folder-users`, `PUT /sites/web-folder-users/{id}` | `password` |
| `POST /sites/database-users`, `PUT /sites/database-users/{id}` | `database_password` |
| `POST /sites/web-domains`, `PUT /sites/web-domains/{id}` | `stats_password` |

Mailboxes are unchanged (spec 028 already enforces the policy there, plus the mail-only ASCII option).

**What a compliant generator must satisfy**: at least `min_length` bytes, and a strength of at least `min_strength`
on ISPConfig's 1–5 scale — computed from character classes (lowercase, uppercase, digits, symbols) and length, so a
password of 16 characters mixing at least three classes reaches 5. There is no ASCII restriction for these user
types.

**Rollout**: enabling this against a production installation is the operator's decision — integrations that generate
weak passwords begin to fail immediately after the upgrade.

## Assumptions

Owner-delegated decisions (2026-09-16), recorded per the owner's standing instruction to apply recommendations:

- **The ported policy becomes neutral.** The strength table and message building move to a shared support class with
  the ASCII option kept as a mail-only flag, so mailboxes keep spec 028's behaviour exactly and the other user types
  get the same computation without it.
- **`stats_password` is included** because legacy attaches the validator to it (`web_vhost_domain.tform.php:620`),
  even though it protects only the statistics page.
- **Administrator keys are enforced too**, matching legacy, which validates the form for every user type. This can
  refuse requests that an administrator integration sends today; the policy is the installation's own choice and the
  message says what is required.
- **Out of scope**: API keys (not an ISPConfig credential), the mailbox rule itself (spec 028), password *storage*
  formats, a "generate a compliant password" endpoint, and `force_password_change_days`.
