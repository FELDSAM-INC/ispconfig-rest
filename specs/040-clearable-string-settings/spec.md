# Feature Specification: Clearable String Settings in the System Configuration

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-16  
**Status**: Draft  
**Module**: system (configuration)  
**Input**: Coordinator request after the spec 037 verification: "an exposed string setting cannot be cleared because an empty string becomes null before validation, so `webmail_url`, `dns_external_slave_fqdn`, `ssh_authentication`, `company_name` and others are settable once and never clearable. Specify and fix it (empty string must clear the value where legacy allows an empty value; keep NOT-NULL-style settings refused with a typed 422), with tests covering at least those four settings and a legacy check of which of them legacy lets an admin blank."

## Context

`PUT /system/config/{section}` updates one section of ISPConfig's system configuration. Every exposed string setting
is validated with a bare `string` rule built from the service's field map, and Laravel converts an empty request
string to `null` before validation. The result: sending `""` is refused with *"The … field must be a string."*

An administrator can therefore **set** a value but never **take it back** through the API. Found while verifying
spec 037: restoring `ssh_authentication` to its original empty value was impossible, and the live check had to
repair the row with SQL. The same applies to `webmail_url`, `dns_external_slave_fqdn`, `company_name`,
`custom_login_text`, `custom_login_link`, `default_remote_dbserver`, `mailmailinglist_url`, the dashboard URLs and
every other exposed string.

In ISPConfig itself these fields are ordinary text inputs or selects with an empty option: an administrator clears
them by submitting an empty form field. Only `web_php_options` carries a `NOTEMPTY` validator in the whole
system-configuration form, so it is the one setting that must keep refusing an empty value.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - An administrator can clear a setting (Priority: P1)

An administrator removes the webmail address, the external DNS servers, the company name or the SSH authentication
restriction, and the installation goes back to its default behaviour.

**Why this priority**: today the only way back is editing the database by hand; a configuration API that cannot undo
its own change is not usable for automation.

**Independent Test**: set each of the four settings, then send `""` and read the section back.

**Acceptance Scenarios**:

1. **Given** `webmail_url` is set, **When** `PUT /system/config/mail` sends `{"webmail_url": ""}`, **Then** 200 and a
   read returns an empty value.
2. **Given** `dns_external_slave_fqdn` is set, **When** the same is done on the `dns` section, **Then** 200 and the
   value is empty.
3. **Given** `ssh_authentication` is `key`, **When** the `sites` section is sent `{"ssh_authentication": ""}`,
   **Then** 200 and the installation accepts both credentials again.
4. **Given** `company_name` is set, **When** the `misc` section is sent `{"company_name": ""}`, **Then** 200 and the
   value is empty.
5. **Given** any of them, **When** `null` is sent instead of `""`, **Then** it is treated the same as `""`.

---

### User Story 2 - Settings that must not be empty stay refused (Priority: P1)

A setting ISPConfig marks as required keeps rejecting an empty value, with a message naming the field.

**Why this priority**: the fix must not turn a required setting into a way to break the installation.

**Independent Test**: send an empty value to `web_php_options` and to an integer setting.

**Acceptance Scenarios**:

1. **Given** the `sites` section, **When** `{"web_php_options": []}` or `{"web_php_options": ""}` is sent, **Then**
   422 naming that field (legacy `NOTEMPTY`).
2. **Given** an integer setting such as `default_webserver`, **When** `""` is sent, **Then** 422 — clearing applies to
   text settings, not to numbers.
3. **Given** a `y`/`n` setting, **When** `""` is sent, **Then** 422.

---

### User Story 3 - Clearing writes exactly what legacy writes (Priority: P2)

A cleared setting is stored as an empty value in the configuration blob, leaving every other key untouched.

**Why this priority**: the blob is shared with ISPConfig's own interface; a wrong shape would corrupt settings this
API does not expose.

**Independent Test**: clear one setting and compare the whole blob with the original, key by key.

**Acceptance Scenarios**:

1. **Given** a section with several settings, **When** one is cleared, **Then** only that key changes and unexposed
   keys are preserved.
2. **Given** a cleared setting, **When** ISPConfig's own interface reads it, **Then** it shows an empty field (the
   stored form is `key=`).

---

### Edge Cases

- A setting that is already empty accepts `""` again and reports 200 without a change.
- Whitespace only is trimmed to an empty value by the existing save filters and then clears the setting.
- A regex-validated setting (for example `webmail_url`) must accept an empty value because the legacy pattern allows
  zero length; a non-empty invalid value is still refused.
- Clearing is not the same as removing the key from the blob: the key stays with an empty value, exactly as legacy
  writes it.
- `PUT /system/config` (the whole document) behaves the same as the per-section route.

## Requirements *(mandatory)*

- **FR-001**: Every exposed **string** setting MUST accept an empty string and `null` as "clear this value", storing
  an empty value.
- **FR-002**: Settings ISPConfig marks as required (`NOTEMPTY` in the system-configuration form — `web_php_options`)
  MUST keep refusing an empty value with 422 naming the field.
- **FR-003**: Non-string settings (integers, `y`/`n` enums, arrays other than the above) MUST keep their current
  validation; clearing applies to text settings only.
- **FR-004**: Clearing MUST leave every other key of the blob byte-identical, including keys the contract does not
  expose.
- **FR-005**: The behaviour MUST be identical on `PUT /system/config` and `PUT /system/config/{section}`.
- **FR-006**: The contract MUST state that an empty string clears a text setting, and which setting refuses it.
- **FR-007**: Refusals MUST stay ordinary 422 validation problems; no new problem type is introduced.

### Key Entities

- **System configuration section**: the flat `[sites]`, `[mail]`, `[dns]`, `[domains]` and `[misc]` maps of the
  `sys_ini` blob, updated by read-merge-write (the documented Principle II exception).

## Success Criteria *(mandatory)*

- **SC-001**: Each of `webmail_url`, `dns_external_slave_fqdn`, `ssh_authentication` and `company_name` can be set
  and then cleared through the API, with the blob otherwise unchanged.
- **SC-002**: A required setting cannot be emptied.
- **SC-003**: No existing system-configuration expectation changes.
- **SC-004**: A verification run no longer needs SQL to restore a setting it changed.

## Assumptions

Owner-delegated decisions (2026-09-16), recorded per the owner's standing instruction to apply recommendations:

- **Empty and `null` mean the same thing** for a text setting. Laravel converts `""` to `null` before validation, so
  distinguishing them would depend on transport details the caller cannot control.
- **The legacy check settles the required list**: of the whole system-configuration form only `web_php_options`
  carries `NOTEMPTY`; `webmail_url` has a regex that allows zero length, `dns_external_slave_fqdn` and
  `company_name` carry only `STRIPTAGS`/`STRIPNL`, and `ssh_authentication` is a select whose first option is the
  empty string. All four named settings are therefore blankable in ISPConfig itself.
- **Clearing keeps the key** with an empty value rather than deleting it from the blob, which is what ISPConfig's own
  save does.
- **Out of scope**: per-server configuration (`server.config`), settings the contract deliberately does not expose,
  and any change to how values are read back.
