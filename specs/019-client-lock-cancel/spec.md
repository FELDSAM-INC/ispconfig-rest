# Feature Specification: Client Lock and Cancel Side Effects

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-14  
**Status**: Draft  
**Module**: client  
**Input**: User description: "Client lock and cancel side effects with legacy parity: locking a client disables its services and unlocking restores their previous state; canceling a client disables its control-panel login; creating a client with canceled set starts with login disabled."

## Context

`client.locked` and `client.canceled` are writable today on `POST/PUT /clients` and `/resellers`, but
only the client row changes. `ClientService` documents the legacy side effects
(`func_client_lock` / `func_client_cancel`) as intentionally not ported, and new clients always get
`sys_user.active = 1`.

The first consumer, the WHMCS ISPConfig module (repo `feldhost/whmcs/ispconfig`, spec 001), suspends a
hosting service by locking its client and keeps customers out of the ISPConfig interface by canceling
the client (by default from creation). Without the side effects a suspended customer's websites and mail
keep running and the ISPConfig login stays open. This feature ports the legacy behavior.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Suspend and resume a client with lock (Priority: P1)

A billing system suspends an overdue customer: `PUT /clients/{id}` with `"locked": true`. Every service of
the client (websites, mail domains, mailboxes, forwards, fetchmail, databases, FTP/shell/WebDAV users,
protected folders, cron jobs) is disabled through the datalog, and the previous state of each record is
remembered. When the customer pays, `PUT /clients/{id}` with `"locked": false` re-enables exactly the
records that were active before the lock; records the customer had disabled themselves stay disabled.

**Why this priority**: Suspension that does not stop services is not a suspension. This is the billing
system's core lifecycle action and a viable MVP on its own.

**Independent Test**: Seed client A with an active website, a disabled website, an active mailbox with SMTP
enabled, a mailbox with SMTP already disabled, and an active cron job. `PUT locked=true` → 200; datalog
`u` entries set `web_domain.active = 'n'` for the active site, `mail_user.postfix = 'n'` and
`mail_user.disablesmtp = 'y'`, `cron.active = 'n'`; `client.tmp_data` holds the snapshot marking the
disabled website and the SMTP-disabled mailbox. `PUT locked=false` → 200; the active site, cron job and
first mailbox are re-enabled, the disabled website stays `n` and the second mailbox keeps
`disablesmtp = 'y'`; the snapshot's `prev_active` key is removed.

**Acceptance Scenarios**:

1. **Given** an unlocked client with records in the lock table list, **When** it is updated with
   `locked: true`, **Then** every record owned by the client's group is disabled through a datalog `u`
   entry and the prior inactive states are stored in the client's lock snapshot.
2. **Given** a locked client with a snapshot, **When** it is updated with `locked: false`, **Then** records
   recorded as previously inactive keep their inactive value, all other records are enabled, and the
   `prev_active` part of the snapshot is removed.
3. **Given** a client, **When** it is updated without changing `locked` (field omitted or same value),
   **Then** no lock or unlock side effect runs and no extra datalog entries are written.
4. **Given** a client locked through the legacy ISPConfig interface, **When** it is unlocked through the
   API, **Then** the result is identical to unlocking it in the legacy interface, and vice versa.
5. **Given** the lock or unlock request, **When** it succeeds, **Then** all record changes belong to the
   same change set as the client update (feature 015 `X-Change-Set-Id`), so a consumer can track when
   the suspension is applied.

---

### User Story 2 - Block and allow control-panel login with cancel (Priority: P2)

A billing system keeps customers inside its own panel: it creates the client with `"canceled": true`, so
the client's ISPConfig control-panel user starts inactive and cannot log in to the ISPConfig interface,
while websites and mail are provisioned and run normally. Setting `canceled: false` later re-enables the
login; setting it back to true disables it again.

**Why this priority**: Needed for the consumer's default "no ISPConfig panel login", but services work
without it; it follows suspension in importance.

**Independent Test**: `POST /clients` with `canceled: true` → 201 and the created `sys_user` row has
`active = 0`. `PUT canceled=false` → `active = 1`; `PUT canceled=true` → `active = 0`. The client's
client-scoped API key keeps authenticating in every state, and no datalog entry is written for `sys_user`.

**Acceptance Scenarios**:

1. **Given** a create request with `canceled: true`, **When** the client is created, **Then** its
   control-panel user is created inactive.
2. **Given** an existing client, **When** `canceled` changes, **Then** the control-panel user's active flag
   follows it (true → inactive, false → active); unchanged values do nothing.
3. **Given** a canceled and/or locked client, **When** a key bound to that client calls the API, **Then**
   authentication and scoping are unaffected (the flags only control the ISPConfig interface login and
   services).
4. **Given** a locked client, **When** `canceled` changes, **Then** only the login flag changes; lock and
   cancel are independent.

---

### User Story 3 - Reseller parity (Priority: P3)

An admin locks or cancels a reseller through `PUT /resellers/{id}` and gets the same behavior as the legacy
reseller form: the reseller's own records and control-panel login are affected, its clients are not.

**Why this priority**: Resellers are not used by the first consumer, but the endpoints already accept the
flags and must not silently diverge from legacy.

**Independent Test**: Seed reseller R with one own website and client C (under R) with one website.
`PUT /resellers/{R} locked=true` → R's website disabled, C's website untouched; datalog entries carry the
acting key's `sys_userid`. `canceled=true` → R's control-panel user inactive, C's user untouched.

**Acceptance Scenarios**:

1. **Given** a reseller with own records and clients, **When** it is locked, **Then** only records owned by
   the reseller's group are disabled and snapshotted; its clients' records are unchanged.
2. **Given** a reseller, **When** `canceled` changes, **Then** only the reseller's control-panel user changes.

### Edge Cases

- `locked: true` on create: the flag is stored; the new client has no records yet, so nothing is disabled.
  Records created later while the client is locked are not disabled automatically (legacy parity).
- A write that sets `active: true` on a record of a locked client re-enables that record (legacy does not
  block it); consumers are expected not to modify a suspended client's records.
- Client without `sys_group` or `sys_user` rows (inconsistent data): the flags are stored, lock finds no
  records, cancel updates no login; the request still succeeds.
- Empty, missing or non-array snapshot: treated as empty (legacy `unserialize` guard); unlock then enables
  every record.
- Unlock of a client locked outside the API without a snapshot (flag set directly in the database): every
  record is enabled.
- Mailboxes are handled on two columns: receiving (`postfix`, disabled with `n`) and sending
  (`disablesmtp`, disabled with `y` — inverted); each is snapshotted separately.
- Records already in the target state still get a datalog `u` entry only when a column value actually
  changes (the owner `sys_userid` rewrite may cause a change even when `active` does not).
- `openvz_vm` records (not exposed by this API) are included when the table exists, as in legacy.
- Concurrent requests changing `locked` for the same client must not double-snapshot (the second request
  sees the already-changed flag and does nothing).
- Non-admin keys: client keys cannot reach `/clients` (feature 011, 403); reseller keys may lock/cancel their
  own clients through `/clients/{id}` with the same side effects.
- Missing or invalid `X-API-Key` → 401 (existing behavior).

## API Contract *(mandatory)*

- **Spec file(s)**: `api/modules/client/clients.yaml`, `api/modules/client/resellers.yaml` (existing —
  descriptions of create/update operations document the side effects; no new paths or status codes).
- **Shared schemas**: `api/components/schemas/Client.yaml` (existing — `locked` and `canceled` descriptions
  document the side effects; `tmp_data` stays intentionally not exposed).
- **Endpoints**:

| Method | Path | Purpose | Success code |
|--------|------|---------|--------------|
| POST | `/api/v1/clients` | Create; `canceled: true` creates the control-panel user inactive | 201 |
| PUT | `/api/v1/clients/{id}` | Update; a change of `locked` runs lock/unlock, a change of `canceled` toggles login | 200 |
| POST | `/api/v1/resellers` | Create reseller; same `canceled` handling | 201 |
| PUT | `/api/v1/resellers/{id}` | Update reseller; lock/unlock of the reseller's own records, login toggle | 200 |

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference** (ISPConfig 3.3.1p1, read on isp-test):
  - `interface/lib/classes/functions.inc.php:567` `func_client_lock()` and `:663` `func_client_cancel()`;
  - `interface/web/client/client_edit.php:488-497` (`onAfterUpdate`: lock and cancel run only when the value
    changed); `client_edit.php:304-335` (`onAfterInsert`: `sys_user.active` always 1);
  - `interface/web/client/reseller_edit.php:434-548` (inline lock and cancel for resellers, only on change);
  - `interface/lib/classes/remote.d/client.inc.php:248-255` (remote `client_update` calls both on every
    update; `client_add` calls neither);
  - `interface/web/login/index.php:73` (login refused when `sys_user.active != 1`).
- **Legacy behaviors to mirror**:
  - **Lock table list** (table → primary key → column): `cron.id.active`, `ftp_user.ftp_user_id.active`,
    `mail_domain.domain_id.active`, `mail_user.mailuser_id.postfix`, `mail_user.mailuser_id.disablesmtp`
    (inverted), `mail_forwarding.forwarding_id.active`, `mail_get.mailget_id.active`,
    `openvz_vm.vm_id.active`, `shell_user.shell_user_id.active`, `webdav_user.webdav_user_id.active`,
    `web_database.database_id.active`, `web_domain.domain_id.active`, `web_folder.web_folder_id.active`,
    `web_folder_user.web_folder_user_id.active`. Records are selected by `sys_groupid` = the client's
    `sys_group.groupid`.
  - **Lock**: for each record, remember `n` when the column is not `y` (or `y` when the inverted
    `disablesmtp` is `y`), remember the record's `sys_userid` when it differs from the client's
    control-panel user, then datalog-update the column to `n` (`disablesmtp` to `y`) together with
    `sys_userid` = the client's control-panel user id.
  - **Unlock**: for each record, datalog-update the column to `y` (`disablesmtp` to `n`) unless the snapshot
    recorded the inactive value, together with `sys_userid` = the client's control-panel user id; then
    remove `prev_active` from the snapshot and keep any other keys.
  - **Snapshot format** (`client.tmp_data`, PHP `serialize()`): `prev_active` →
    `{table → {record id → {column → 'n' | 'y'}}}` and `prev_sys_userid` → `{table → {record id → sys_userid}}`.
    Legacy unlock reads a `prev_sysuser` key that lock never writes, so previous owners are never restored
    and every affected record ends owned by the client's control-panel user; the API mirrors this so
    snapshots written by either side unlock identically.
  - **Cancel**: `UPDATE sys_user SET active = 0` (canceled) or `1` (not canceled) `WHERE client_id = {id}`.
  - **Reseller form**: same lock algorithm on the reseller's own group; datalog `sys_userid` is the acting
    user (legacy session user → the API key's `sys_userid`); cancel identical.
- **Tables written (via datalog only)**: every table in the lock list — action `u` on the listed column and
  `sys_userid`; `client` — action `u` for `locked`/`canceled` (existing).
- **Direct writes (documented Principle II exceptions, matching legacy plain queries)**: `client.tmp_data`
  (snapshot) and `sys_user.active` (login flag); `sys_user` is already written without datalog by
  `ClientService` for the same legacy reason.
- **System fields handling**: affected records' `sys_userid` becomes the client's control-panel user id
  (`/clients`) or the acting key's user id (`/resellers`); `sys_groupid` and `sys_perm_*` are unchanged.
- **Intentional deviations from legacy**:
  1. `canceled: true` on create applies to the new control-panel user (legacy panel and remote API ignore
     both flags on insert). Needed so a consumer can create clients without ISPConfig login.
  2. Side effects run only when a flag value changes (legacy panel behavior); the legacy remote API runs
     them on every update, which re-snapshots already-locked clients and re-enables manually disabled
     records on unrelated updates.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: When `locked` changes from false to true on `PUT /clients/{id}` or `PUT /resellers/{id}`, the
  system MUST disable every record of the lock table list owned by the client's group through datalog `u`
  entries and store the lock snapshot in the legacy format.
- **FR-002**: When `locked` changes from true to false, the system MUST restore records from the snapshot
  exactly as legacy unlock does and remove the snapshot's `prev_active` key while keeping other keys.
- **FR-003**: Snapshots MUST be readable and writable interchangeably with the legacy ISPConfig interface
  (PHP serialized array, keys `prev_active` and `prev_sys_userid`); an unreadable snapshot is treated as
  empty.
- **FR-004**: When `canceled` changes, the system MUST set the client's control-panel user inactive (true) or
  active (false).
- **FR-005**: On `POST /clients` and `POST /resellers`, `canceled: true` MUST create the control-panel user
  inactive; `locked: true` MUST be stored without side effects.
- **FR-006**: Updates that do not change `locked` or `canceled` MUST NOT run lock, unlock or cancel side
  effects.
- **FR-007**: The client row update and all side effects of one request MUST be committed atomically and
  share one change set id (feature 015).
- **FR-008**: Mailbox records MUST be handled on both `postfix` and the inverted `disablesmtp` column, each
  with its own snapshot entry.
- **FR-009**: Reseller locks MUST only affect records of the reseller's own group and record the acting
  key's user as `sys_userid`.
- **FR-010**: `locked` and `canceled` MUST NOT affect API key authentication or scoping.
- **FR-011**: The `ClientService` note listing lock/cancel as "not ported" and any README deviation text MUST
  be updated to describe the implemented behavior.
- **FR-012**: Feature tests MUST cover lock and unlock with the exact datalog rows and snapshot content, the
  restore matrix (previously active, previously inactive, SMTP already disabled), unlock of a legacy-written
  snapshot, unchanged-flag no-ops, cancel toggle and cancel on create, reseller scope, and API keys of
  canceled/locked clients.

### Key Entities

- **Client**: customer account with `locked`, `canceled` and the internal lock snapshot — table `client`,
  schema `api/components/schemas/Client.yaml`, model `app/Models/Client.php`.
- **Lock Snapshot**: previous inactive states and owners of a locked client's records — column
  `client.tmp_data` (not exposed).
- **Control-Panel User**: the client's ISPConfig interface login — table `sys_user` (`active`), not exposed.
- **Client Services**: the records toggled by lock — tables in the lock table list, existing models
  (`WebDomain`, `MailDomain`, `MailUser`, `MailForwarding`, `MailGet`, `WebDatabase`, `FtpUser`, `ShellUser`,
  `WebdavUser`, `WebFolder`, `WebFolderUser`, `CronJob`).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: After a lock is applied by an unmodified ISPConfig 3.3 server, 100% of the client's websites,
  mail domains, mailboxes (receive and send), forwards, fetchmail entries, databases, FTP/shell/WebDAV users,
  protected folders and cron jobs are disabled.
- **SC-002**: After lock followed by unlock, every record has the same active state as before the lock
  (0 mismatches across the tested matrix).
- **SC-003**: A lock performed in the legacy interface and undone through the API, and a lock performed
  through the API and undone in the legacy interface, both end in the same state as a legacy-only round
  trip.
- **SC-004**: A canceled client cannot log in to the ISPConfig interface while its websites and mail keep
  serving, and its API keys keep working.
- **SC-005**: Client updates that do not change `locked` or `canceled` produce no additional datalog entries
  compared with today.

## Assumptions

- No new endpoints or response fields; consumers read `locked` and `canceled` from the existing client
  representation and track application through feature 015.
- The legacy owner rewrite on lock/unlock (every affected record ends owned by the client's control-panel
  user) is mirrored for parity; changing it would need an explicit owner decision.
- Consumers do not modify a locked client's records; the API does not block such writes (legacy parity).
- Welcome e-mails, customer number templates and SSH key generation remain out of scope, as today.
- Legacy `demo_mode` checks are not applicable to the API.
- The `openvz_vm` table exists in ISPConfig 3.3 installations; implementations skip it only if the table is
  absent.
