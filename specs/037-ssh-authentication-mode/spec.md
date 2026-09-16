# Feature Specification: SSH Authentication Mode for Scoped Keys

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-16  
**Status**: Draft  
**Module**: sites (shell users) + me (capabilities)  
**Input**: User description: "SSH authentication mode: `misc.ssh_authentication` in legacy decides whether password, key, or both are kept for shell users; the API currently accepts both and silently clears one. Expose the mode (read) and enforce it on write per legacy (422 typed for the field that is not allowed)."

## Context

An ISPConfig installation decides how SSH accounts authenticate with the system setting `ssh_authentication`: empty
means a password **and** a key may be stored, `password` means only a password, `key` means only a key. The
administrator sets it on the **Sites** tab of the system configuration, and the value is stored in the `[sites]`
section of the `sys_ini` blob (verified on isp-test: the key sits between `[sites]` and `[domains]`).

Two problems stand in the way of a customer panel:

1. **The setting is unreadable for a customer key.** The whole system configuration is administrator-only, so a panel
   must offer both credential fields and hope (WHMCS module spec 006 R11 does exactly that).
2. **Nobody enforces it.** Legacy reads the setting from the wrong section when saving:
   `shell_user_edit.php:100` reads `$system_config['sites']['ssh_authentication']` to build the form, but
   `shell_user_edit.php:131-137`, which clears the other credential, reads `$system_config['misc']` — where the key
   never exists. The clearing is therefore dead code in ISPConfig 3.3.1p1. The API faithfully mirrored that read
   (`SitesConfigService::sshAuthenticationMode()` reads `[misc]`), so `ShellUserController::applySshAuthenticationMode()`
   never fires on a real installation either; it only appears to work in tests, whose fixtures happen to write the
   key into `[misc]`.

The result today: a customer can store a password on a key-only installation and a key on a password-only one, the
panel cannot tell which is accepted, and the administrator's choice has no effect at all.

This feature reads the setting where the administrator actually writes it, reports it to the account's own key, and
refuses a credential the installation does not accept instead of discarding it.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Panel offers only the accepted credential (Priority: P1)

A customer creates an SSH account. On a key-only installation the panel shows the key field and no password field; on
a password-only installation the opposite; when both are allowed it shows both.

**Why this priority**: without it the panel must guess, and one of the two fields it shows is a trap.

**Independent Test**: set each of the three values in the `[sites]` section and read `GET /api/v1/me/capabilities`
with a client key.

**Acceptance Scenarios**:

1. **Given** `ssh_authentication` is empty, **When** `GET /me/capabilities`, **Then** `sites.shell.authentication`
   is `password_or_key`.
2. **Given** it is `password`, **When** the same call, **Then** the value is `password`.
3. **Given** it is `key`, **When** the same call, **Then** the value is `key`.
4. **Given** an unknown value, **When** the same call, **Then** the value is `password_or_key` (legacy's `else`).
5. **Given** the account has no SSH access (`limit_shell_user = 0`), **When** the same call, **Then**
   `sites.shell.available` is false and `authentication` still reports the installation's mode.

---

### User Story 2 - A credential the installation does not accept is refused (Priority: P1)

A customer key that sends a password on a key-only installation, or a key on a password-only one, gets a validation
error naming the field instead of a success that quietly drops the credential.

**Why this priority**: silent credential loss is the defect customers actually hit; it looks like the panel or the
server is broken.

**Independent Test**: with a client key, create and update shell users sending the not-allowed credential and check
the 422, its field, its problem type, and that nothing was written.

**Acceptance Scenarios**:

1. **Given** `ssh_authentication` is `key` and a client key, **When** `POST /sites/shell-users` sends a non-empty
   `password`, **Then** 422 with `errors.password` and `error_types.password` = `feature-not-allowed`, and no
   `sys_datalog` row is written.
2. **Given** it is `password` and a client key, **When** the create sends a non-empty `ssh_rsa`, **Then** 422 with
   `errors.ssh_rsa` and the same problem type.
3. **Given** either mode, **When** the request sends the not-allowed field as `null` or `""`, **Then** the request is
   accepted — there is nothing to discard — and that credential stays empty.
4. **Given** an existing SSH account, **When** `PUT` re-sends the stored value of the not-allowed field unchanged,
   **Then** the request is accepted, as specs 016 and 033 accept unchanged values.
5. **Given** the allowed credential, **When** create or update sends it, **Then** the request succeeds as before.

---

### User Story 3 - The administrator's choice finally takes effect (Priority: P2)

With the setting read from the section the administrator writes, an administrator key that posts both credentials
stores only the accepted one, as the ISPConfig form promises.

**Why this priority**: completes the correction; administrator keys keep their permissive behaviour (no refusal), so
existing automation keeps working.

**Independent Test**: with an administrator key, create shell users in each mode with both credentials and inspect
the stored row.

**Acceptance Scenarios**:

1. **Given** `ssh_authentication` is `password` in `[sites]` and an administrator key, **When** a create sends both
   credentials, **Then** 201 and the stored `ssh_rsa` is empty.
2. **Given** it is `key` and an administrator key, **When** a create sends both, **Then** 201 and no password is
   stored.
3. **Given** it is empty, **When** a create sends both, **Then** 201 and both are stored.

---

### Edge Cases

- An installation that never set the value behaves exactly as today (both credentials allowed).
- An installation that set `key` while existing accounts still carry passwords keeps those accounts untouched; only
  later writes are judged.
- The refusal message names the credential the hosting accepts, never the internal setting name.
- A request that sends neither credential is unaffected in every mode.
- FTP, WebDAV and web folder users are unaffected: the setting is specific to shell users.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The SSH authentication mode MUST be read from the `[sites]` section of the system configuration — the
  section the administrator's Sites tab writes and the legacy form display reads.
- **FR-002**: `GET /me/capabilities` `sites.shell` MUST report `authentication` with exactly one of
  `password_or_key`, `password`, `key`; an unknown or missing value MUST be reported as `password_or_key`.
- **FR-003**: For client and reseller keys, `POST` and `PUT /sites/shell-users` MUST refuse a non-empty credential
  the installation does not accept with 422, `errors.<field>` naming `password` or `ssh_rsa` and
  `error_types.<field>` = `feature-not-allowed`, writing nothing.
- **FR-004**: An empty string or `null` for the not-allowed field MUST be accepted and MUST leave that credential
  empty.
- **FR-005**: On update, a not-allowed field whose submitted value equals the stored one MUST be accepted.
- **FR-006**: Administrator keys MUST NOT be refused; for them the not-allowed credential is cleared server-side, as
  the legacy form intends.
- **FR-007**: In every accepted case the stored row MUST carry the allowed credential and an empty other credential.
- **FR-008**: The contract (`AccountSitesCapabilities.yaml`, `api/modules/me/capabilities.yaml`, `ShellUser.yaml`,
  `api/modules/sites/shell-users.yaml`) and the README MUST describe the reported mode, the refusal and the corrected
  section before the implementation.

### Key Entities

- **SSH authentication mode**: an installation-wide setting describing which credential an SSH account may carry,
  read per request from the `[sites]` section of `sys_ini`. This feature never writes it.

## Success Criteria *(mandatory)*

- **SC-001**: A panel decides from one field which credential input to show.
- **SC-002**: A customer key can no longer lose a credential silently: every credential that would be discarded
  becomes a refusal naming the field.
- **SC-003**: An administrator's configured mode has a visible effect for the first time, and administrator keys are
  never refused.
- **SC-004**: The refusal is machine-readable by problem type, so a panel maps it without reading English text.

## Assumptions

Owner-delegated decisions (2026-09-16), recorded per the owner's standing instruction to apply recommendations:

- **Read the setting from `[sites]`, correcting a legacy bug.** Legacy's save path reads `[misc]`, where the key does
  not exist, so its clearing never runs; its form display reads `[sites]`. Mirroring the bug would make this feature
  pointless, so the API follows the administrator's visible intent. This is a behaviour change for installations that
  set the mode: their administrator writes start clearing the other credential, and customer writes start being
  refused.
- **Values are named `password_or_key`, `password`, `key`** so a consumer never has to interpret an empty string.
- **Refusing instead of clearing applies to client and reseller keys only**, following spec 033 (`update_acl`, which
  legacy silently ignores and the API refuses) and spec 025 (`custom_mailfilter`). Administrator keys keep the
  permissive, clearing behaviour.
- **The existing regression test moves to the real section.** `ShellUserApiTest` currently sets the key in `[misc]`,
  which no longer exercises anything; it must set it in `[sites]`.
- **Out of scope**: writing the setting through the API (it stays administrator-only system configuration), the
  `chroot` and `shell` fields, other user types' credentials, and any migration of existing accounts.
