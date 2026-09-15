# Feature Specification: Hosting Capabilities for Scoped Keys

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-16  
**Status**: Draft  
**Module**: me (plus sites limits: databases, database users, FTP, shell, cron)  
**Input**: User description: "Hosting capabilities for scoped keys (most important; module release gate): extend `GET /me/capabilities` beyond `web`/`mail` with a hosting block for databases, database users, FTP, shell and cron: the client's prefixes (`dbname_prefix`, `dbuser_prefix`, `ftpuser_prefix`, `shelluser_prefix` — resolved like legacy with [CLIENTID]/[CLIENTNAME]/[DOMAIN] placeholders where applicable), remote database access permission, `limit_cron_type` and `limit_cron_frequency`, shell/jailkit availability, and any missing counts (`limit_database_user` is absent from `USAGE_COUNT_COLUMNS` — add the count to `/usage/summary` too if legacy limits it)."

## Context

The WHMCS panel (module spec `006-databases-ftp-cron`) manages databases, database users, FTP accounts, SSH access and
scheduled tasks for a customer. Three facts it needs are not readable with a customer key today:

1. **The name prefixes.** ISPConfig prepends a prefix to every database, database user, FTP user and shell user name
   (`c42_`, `webname_`), configured per installation with `[CLIENTID]` / `[CLIENTNAME]` placeholders. The API applies
   the prefix on write and returns it on existing rows, so a panel can only learn it by reading an entry that already
   exists. On a new account there is none, and the customer cannot be told what the full name will be.
2. **The plan's task rules.** `limit_cron_type` (url / chrooted / full) and `limit_cron_frequency` (shortest interval
   in minutes) decide which scheduled tasks a customer may create. Neither is readable, so the panel can only offer
   everything and let the backend refuse.
3. **The plan's database and shell options.** The database quota cap (`limit_database_quota`) and the chroot options
   of the account (`client.ssh_chroot`) shape the forms.

One plan limit is also **not enforced** by the API for client and reseller keys, although legacy ISPConfig enforces
it on every save: `limit_cron_frequency` and the "url only" case of `limit_cron_type`. `cron_edit.php:170-220`
re-checks both in `onInsertSave()` and `onUpdateSave()` for every non-admin user; the API derives the job's `type`
from the command and the owner's limit (parity, `SitesService::deriveCronType()`) but never refuses a too-frequent
schedule, and never refuses a shell command for an account limited to URL tasks — it silently stores it as
`chrooted`. This is the same class of gap spec 030 closed for DNS records.

A second limit is enforced but invisible. `limit_database_user` **is** checked on create —
`ClientLimitService::countSpecsFor()` maps `web_database_user` to it, matching `database_user_edit.php:58-63` — yet
the column is missing from `USAGE_COUNT_COLUMNS`, so `GET /usage/summary` reports no `database_users` count. A panel
can neither show "2 of 3 used" nor warn before the refusal.

This feature adds the account's hosting capabilities to the existing read-only account endpoint, adds the missing
usage count, and closes the scheduled-task enforcement gap so a panel's pre-checks and the backend's refusals agree.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Panel shows the full name before the first entry exists (Priority: P1)

A customer with a brand-new hosting account opens "Databases" and types `shop` as the database name. The panel reads
the account's capabilities, learns the database prefix `c42_`, and shows the real name `c42_shop` before saving.

**Why this priority**: without it every name field on a new account is a guess; the customer only learns the real name
after creating the entry.

**Independent Test**: call `GET /api/v1/me/capabilities` with a client key on an account that owns nothing and verify
the `sites.prefixes` values match what `POST /sites/databases` would apply.

**Acceptance Scenarios**:

1. **Given** a client key for client 42 and the installation prefix `c[CLIENTID]_`, **When** `GET /me/capabilities`,
   **Then** `sites.prefixes.database` is `c42_` and equals the prefix a created database receives.
2. **Given** an installation prefix `[CLIENTNAME]_` and the client's group name `webhostingcz`, **When** the same
   call, **Then** `sites.prefixes.ftp_user` is `webhostingcz_` with the legacy name normalization applied.
3. **Given** an installation with empty prefix settings, **When** the same call, **Then** every prefix is `""`.
4. **Given** a reseller key reading one of its clients with `client_id`, **When** the same call, **Then** the prefixes
   are that client's, not the reseller's.

---

### User Story 2 - The database user limit is visible and stays enforced (Priority: P1)

A hosting plan includes three database users. The panel shows "2 of 3 used" and the backend refuses a fourth.

**Why this priority**: the cap is already enforced on create, but invisible — without the count a panel can neither
show usage next to the other resources nor warn before submitting. The scenarios also pin the existing enforcement
against regressions.

**Independent Test**: with a client key on a client whose `limit_database_user` is 1, create one database user (201)
and a second (403 `limit-reached`), and read `GET /usage/summary`.

**Acceptance Scenarios**:

1. **Given** `limit_database_user = 1` and one existing database user, **When** `POST /sites/database-users`,
   **Then** 403 `limit-reached` with `limit {name: limit_database_user, scope: client, max: 1, used: 1}` and no
   `sys_datalog` row is written.
2. **Given** the same account, **When** `GET /usage/summary`, **Then** `counts.database_users` is `{used: 1, limit: 1}`.
3. **Given** `limit_database_user = -1` (unlimited), **When** creating users, **Then** none is refused and
   `counts.database_users.limit` is `null`.
4. **Given** a reseller whose own `limit_database_user` cap is reached, **When** its client creates a database user,
   **Then** 403 `limit-reached` with `scope: reseller`.
5. **Given** an admin key, **When** creating database users past a client's cap, **Then** they are created (admin keys
   are not limited).

---

### User Story 3 - Scheduled task rules are enforced (Priority: P2)

A plan allows URL tasks only, at most once per hour. A customer key that sends a shell command, or a schedule running
every five minutes, is refused with a message the panel can map.

**Why this priority**: same unenforced-limit class as US2; without it the panel's own pre-check is the only guard and
a direct API call bypasses the plan.

**Independent Test**: with a client key on a client with `limit_cron_type = url`, `limit_cron_frequency = 60`, create
jobs that break each rule and verify the refusals and the empty journal.

**Acceptance Scenarios**:

1. **Given** `limit_cron_frequency = 60`, **When** `POST /sites/cron-jobs` with `run_min = */5`, **Then** 403
   `limit-reached` with `limit {name: limit_cron_frequency, scope: client, max: 60, used: 5}` and nothing journaled.
2. **Given** the same limit, **When** creating a job that runs hourly, **Then** 201.
3. **Given** `limit_cron_type = url`, **When** creating a job whose command is not an `http(s)://` URL, **Then** 403
   `feature-not-allowed` with `feature: limit_cron_type` and nothing journaled.
4. **Given** the same limits, **When** `PUT /sites/cron-jobs/{id}` changes an allowed job to a five-minute schedule,
   **Then** 403 and the stored row is unchanged.
5. **Given** an admin key, **When** it creates a five-minute shell job for that client, **Then** 201 (legacy checks
   only non-admin users).

---

### User Story 4 - Panel offers only the options the plan allows (Priority: P2)

The SSH form offers only the chroot options of the account, the database form knows the plan's database quota cap, and
the task form knows which kinds of tasks and which shortest interval are allowed.

**Why this priority**: prevents forms that offer choices the backend refuses; lower than US1 because the panel can
start with safe defaults.

**Independent Test**: `GET /me/capabilities` for clients with different `ssh_chroot`, `limit_shell_user`,
`limit_database_quota`, `limit_cron_type` and `limit_cron_frequency` values.

**Acceptance Scenarios**:

1. **Given** `client.ssh_chroot = "no,jailkit"`, **When** `GET /me/capabilities`, **Then**
   `sites.shell.chroot_options` is `["no", "jailkit"]`.
2. **Given** `limit_shell_user = 0`, **When** the same call, **Then** `sites.shell.available` is `false`.
3. **Given** `limit_cron_type = chrooted`, **When** the same call, **Then** `sites.cron.types` is
   `["url", "chrooted"]` and `sites.cron.min_interval_minutes` is the client's `limit_cron_frequency`.
4. **Given** `limit_database_quota = 2048`, **When** the same call, **Then** `sites.databases.quota_limit_mb` is
   `2048`; with `-1` it is `null`.

---

### Edge Cases

- A prefix setting containing `[DOMAINID]` cannot be resolved without a website: the placeholder stays in the returned
  prefix, exactly as legacy leaves it when no record is known.
- A client whose group name is empty resolves `[CLIENTNAME]` to `default`, like legacy.
- `limit_cron_frequency` of 0 or 1 disables the interval check (legacy checks only `> 1`).
- A cron field the API already rejects as invalid (spec 013 validation) is refused before any limit check.
- Counting database users counts the rows the key may read, exactly like the other counts.
- An account with no cron jobs, no databases and no shell access still returns a complete `sites` block.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `GET /me/capabilities` MUST return a `sites` object for the resolved client, with the same target rules
  as the existing `web` and `mail` blocks (client key: own account; reseller: own or one of its clients; admin: must
  pass `client_id`).
- **FR-002**: `sites.prefixes` MUST contain `database`, `database_user`, `ftp_user`, `shell_user` and `webdav_user`,
  each resolved for the described client exactly as the write endpoints resolve them (legacy
  `tools_sites::replacePrefix` with `[CLIENTID]`, `[CLIENTNAME]`, `[DOMAINID]`).
- **FR-003**: `sites.databases` MUST report `quota_limit_mb` (`limit_database_quota`, `null` when unlimited) and
  `remote_access` (whether a customer key may switch remote access on).
- **FR-004**: `sites.shell` MUST report `available` (`limit_shell_user` is not 0) and `chroot_options` (the account's
  `client.ssh_chroot` list intersected with the options ISPConfig offers).
- **FR-005**: `sites.cron` MUST report `types` (the kinds of task the account may create, derived from
  `limit_cron_type`) and `min_interval_minutes` (`limit_cron_frequency`, `null` when it does not constrain).
- **FR-006**: `GET /usage/summary` MUST include `counts.database_users` with `used` and `limit` like every other count.
- **FR-007**: `POST /sites/database-users` MUST keep refusing client and reseller keys past `limit_database_user`
  with 403 `limit-reached` (existing behaviour, pinned by tests), and the count of FR-006 MUST be produced by the
  same predicate that enforcement counts with.
- **FR-008**: `POST` and `PUT /sites/cron-jobs` MUST refuse client and reseller keys whose schedule runs more often
  than `limit_cron_frequency` with 403 `limit-reached` (`name: limit_cron_frequency`), writing nothing. The shortest
  interval MUST be computed exactly like legacy `validate_cron` (`cron_min_freq`).
- **FR-009**: `POST` and `PUT /sites/cron-jobs` MUST refuse client and reseller keys whose resulting task kind is not
  allowed by `limit_cron_type` with 403 `feature-not-allowed` (`feature: limit_cron_type`), writing nothing.
- **FR-010**: Admin keys MUST keep their current behaviour for FR-007 to FR-009 (no limit checks), as in legacy.
- **FR-011**: The `sites` block MUST NOT expose installation-wide or other clients' data beyond the described
  account's own limits and the installation's prefix patterns.
- **FR-012**: The contract (`api/modules/me/capabilities.yaml`, `AccountCapabilities.yaml`, `UsageSummary.yaml`,
  `database-users.yaml`, `cron-jobs.yaml`, `docs/problems.md`) MUST describe the new fields and refusals before the
  implementation.

### Key Entities

- **Account hosting capabilities**: prefixes, database options, shell options and task rules of one client, derived
  per request from `client`, `sys_group` and the `[sites]` section of `sys_ini`. Read-only, never stored.
- **Database user count**: rows of `web_database_user` the key may read, against `client.limit_database_user`.
- **Task schedule**: the five cron fields of a job, whose shortest interval in minutes is compared with
  `client.limit_cron_frequency`.

## Success Criteria *(mandatory)*

- **SC-001**: A panel can show the full prefixed name of a database, database user, FTP account and SSH account on an
  account that owns none, without creating anything.
- **SC-002**: A panel can show how many of the plan's database users are used, and that number matches what the
  backend enforces when a further user is created.
- **SC-003**: A client key cannot create or update a scheduled task that runs more often than the plan allows, or of a
  kind the plan excludes.
- **SC-004**: Every refusal added here carries a stable problem type with the limit name, so a panel maps it without
  reading English text.
- **SC-005**: Admin keys and existing integrations see no behaviour change beyond the new read-only fields.
- **SC-006**: One `GET /me/capabilities` call answers every question the panel's database, FTP, SSH and task forms ask
  about the plan.

## Assumptions

Owner-delegated decisions (2026-09-16), recorded per the owner's standing instruction to apply recommendations:

- **The block is named `sites`**, matching ISPConfig's module name and the WHMCS module's contract.
- **`remote_access` is reported as always allowed.** Legacy has no permission for it: `remote_access` is a plain
  checkbox in `database.tform.php` with no `valuelimit`, so every client may use it. The field exists so a panel need
  not encode that assumption.
- **`min_interval_minutes` is `null` when `limit_cron_frequency` is 0 or 1**, because legacy only checks `> 1`.
- **`chroot_options` follows legacy `applyValueLimit('client:ssh_chroot')`**: the client column's comma list
  intersected with ISPConfig's own option list (`no`, `jailkit`, and `ssh-chroot` where the installation offers it).
  Admin keys reading a client see that client's list, not the unrestricted admin list.
- **Cron limits are enforced for client and reseller keys only** (legacy checks `$_SESSION['s']['user']['typ'] !=
  'admin'`), and the reseller cap of `limit_database_user` follows the existing count-limit implementation.
- **`[DOMAINID]` is not resolved** in the capabilities response; there is no website context. Legacy leaves the
  placeholder in the same situation.
- **Out of scope**: the SSH authentication mode (spec 037), administration links (036), password policy for these user
  types (038) and database-user usage (039); PostgreSQL limits; per-website quota fields.
