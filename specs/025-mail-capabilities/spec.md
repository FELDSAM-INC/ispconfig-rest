# Feature Specification: Mail Capabilities and Email Program Settings for Scoped Keys

**Feature Branch**: `main` (owner workflow: committed directly to main)  
**Created**: 2026-09-15  
**Status**: Draft  
**Module**: me (plus mail and usage)  
**Input**: User description: "Mail capabilities and email program settings: extend `GET /me/capabilities` (spec 021) with a `mail` block (counts vs limits where not already in /usage/summary, whether vacation messages, spam filter level choice, DKIM and mail filters are allowed per client limits and legacy form permissions) and add a client-readable mail settings endpoint (IMAP/POP3/SMTP host names and ports, resolved webmail URL, password policy). Must fit the WHMCS module `specs/004-mail/contracts/ispconfig-rest-calls.md`."

## Context

The WHMCS ISPConfig module (spec 004 "Mail") builds a mail area on the customer's client-scoped key. It cannot
read what the customer's own mail forms would allow: the vacation message and mail filter tabs are system settings
(`sys_ini` [mail]), the password policy lives in `sys_ini` [misc], the webmail address and host names in `sys_ini`
and `server`, and the catch-all / alias-domain / filter / fetchmail counts are not part of `/usage/summary`. Customer
keys cannot read `/system/config` or `/servers` (admin only), so the module falls back to hard-coded ports, its own
password rules and no webmail link.

At the same time the mail sub-resources accept writes from customer keys that the legacy panel never offers them:
a custom sieve/maildrop script (`custom_mailfilter`, an administrator-only tab in ISPConfig) and vacation messages or
mail filters on installations that switched those tabs off.

This feature describes the mail side of an account for its own key, adds the missing counts to the usage summary
and makes the mail sub-resources enforce the same tab rules for client and reseller keys.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Panel reads the mail capabilities of the account (Priority: P1)

A customer opens the mail area of the panel. With the customer's own key the panel reads the account capabilities
once and shows the vacation message page only when the installation offers it, the spam filter level choice only
when the account can read at least one level, the DKIM switch only when a mail server of the account can sign mail,
mail filter options only when the mail filter tab is enabled, and the password rules the mailbox endpoints will
enforce. It never offers a custom filter script.

**Why this priority**: without it the panel offers options the API refuses or hides features customers pay for; it
removes three hard-coded fallbacks of the module.

**Independent Test**: set the [mail] and [misc] system settings, spam filter policies with different permissions and
mail servers with and without a DKIM path; call `GET /me/capabilities` with client, reseller and admin keys and
compare the `mail` block with the settings and with the outcome of the matching mail writes.

**Acceptance Scenarios**:

1. **Given** `mailbox_show_autoresponder_tab = y`, `mailbox_show_mail_filter_tab = n`, **When** a client key calls
   `GET /me/capabilities`, **Then** 200 with `mail.autoresponder = true`, `mail.mail_filters = false` and
   `mail.custom_rules = false`.
2. **Given** the seeded world-readable spam filter policies, **When** a client key reads capabilities, **Then**
   `mail.spamfilter_policy = true`; **Given** no policy readable by the account, **Then** `false`.
3. **Given** the account's mail server has `dkim_path = /var/lib/amavis/dkim`, **Then** `mail.dkim = true`;
   **Given** its only mail server has no DKIM path (empty or `/`), **Then** `false`.
4. **Given** `min_password_length = 8`, `min_password_strength = 3`, **Then**
   `mail.password_policy = {min_length: 8, min_strength: 3, ascii_only: false}`; **Given** the keys are absent,
   **Then** `{min_length: 8, min_strength: 0, ascii_only: false}` (ISPConfig runtime defaults).
5. **Given** `enable_custom_login = y`, **Then** `mail.custom_login = true`.
6. **Given** an admin key naming a client, **Then** the same `mail` block as that client's own key; the target rules
   of spec 021 are unchanged (admin without `client_id` 422, other client 404).

---

### User Story 2 - Panel shows email program settings (Priority: P1)

A customer who wants to set up Outlook or a phone opens "Email program settings". With the customer's key the panel
reads, for each mail server of the account, the server name to enter, the IMAP, POP3 and SMTP ports with their
connection security, and the webmail address, and shows the account's password rules.

**Why this priority**: the settings page is how customers start using a mailbox; wrong host names are the most
common support request.

**Independent Test**: seed accounts with assigned mail servers, mail domains on another server and a webmail URL with
`[SERVERNAME]`; call `GET /me/mail-settings` and compare host names, ports and URLs.

**Acceptance Scenarios**:

1. **Given** the account is assigned mail server 1 (`server_name = mail1.example.com`) and
   `webmail_url = https://[SERVERNAME]:8081/webmail`, **When** its key calls `GET /me/mail-settings`, **Then** 200 with
   `servers[0] = {server_id: 1, host: "mail1.example.com", webmail_url: "https://mail1.example.com:8081/webmail",
   imap: {port: 993, security: "ssl"}, pop3: {port: 995, security: "ssl"}, smtp: [{port: 587, security:
   "starttls"}, {port: 465, security: "ssl"}]}`.
2. **Given** an empty `webmail_url` and an Apache mail server, **Then** `webmail_url = "https://<host>/webmail"`;
   **Given** an nginx server, **Then** `https://<host>:8081/webmail` (legacy `webmailer.php`).
3. **Given** the account has mail domains on server 2 that is not in its assignment list, **Then** server 2 is listed
   after the assigned servers.
4. **Given** `mailboxlist_webmail_link = n`, **Then** `webmail_link = false` (the URL is still returned).
5. **Given** a reseller key naming one of its clients, **Then** that client's servers; admin keys must name a client
   (422 otherwise); another client → 404; unknown parameters → 400.
6. **Given** an account without assigned mail servers and without mail domains, **Then** `servers = []`.

---

### User Story 3 - Mail sub-resources enforce the panel's tab rules for customer keys (Priority: P2)

A customer's key tries to store a custom sieve script on a mailbox, or to set a vacation message on an installation
that switched the vacation tab off. The API refuses, exactly as the customer's ISPConfig panel would not offer the
field; administrator keys keep full access.

**Why this priority**: the custom script is an administrator-only feature in ISPConfig (it runs arbitrary filter
rules on the mail server); capabilities must describe rules the API actually enforces.

**Independent Test**: with client keys, write `custom_mailfilter`, the autoresponder and filters with the tabs on and
off; check status codes, problem types and that nothing is journaled on refusal; repeat with the admin key.

**Acceptance Scenarios**:

1. **Given** a client key, **When** it sends `custom_mailfilter` in `PUT /mail/users/{id}/spamfilter`, **Then** 422
   with an error on `custom_mailfilter` and `error_types.custom_mailfilter = feature-not-allowed`, nothing journaled;
   the admin key is accepted.
2. **Given** `mailbox_show_autoresponder_tab = n`, **When** a client key calls `PUT` or `DELETE
   /mail/users/{id}/autoresponder`, **Then** 403 `feature-not-allowed` with `feature =
   mailbox_show_autoresponder_tab`; `GET` stays 200; the admin key is accepted.
3. **Given** `mailbox_show_mail_filter_tab = n`, **When** a client key changes `move_junk` or the purge days, or
   creates, changes or deletes a filter rule, **Then** 403 `feature-not-allowed` with `feature =
   mailbox_show_mail_filter_tab`; reads stay allowed.
4. **Given** both tabs enabled, **When** a client key changes the vacation message, `move_junk` and filter rules of
   its own mailbox, **Then** the writes succeed as before.

---

### User Story 4 - Usage summary counts the remaining mail limits (Priority: P3)

The panel shows "catch-all addresses 1 of 1" and disables "Add catch-all" before the customer tries.

**Why this priority**: the limits are already enforced (spec 012); showing them avoids refused writes.

**Independent Test**: seed catch-alls, alias domains, filter rules and fetchmail jobs; compare `/usage/summary` counts
with the spec 012 counting rules and the client limits.

**Acceptance Scenarios**:

1. **Given** a client with `limit_mailcatchall = 1` and one catch-all, **Then** `counts.mail_catchalls = {used: 1,
   limit: 1}`.
2. **Given** `limit_mailaliasdomain = -1`, **Then** `counts.mail_alias_domains.limit = null`; `mail_filters` and
   `fetchmail_accounts` are counted with the same rules as the create checks of spec 012.

### Edge Cases

- Missing/invalid `X-API-Key` → 401.
- `sys_ini` without the [mail] keys → the legacy form defaults apply to the tab switches (`y`), as `/system/config`
  presents them; the password policy uses the ISPConfig runtime defaults (length 8, strength 0), not the form default.
- `mail_password_onlyascii = y` → `ascii_only = true`; ISPConfig then checks only that the password is ASCII
  (feature 028 enforces it).
- `min_password_length` or `min_password_strength` present but empty → 0 (no check).
- A mail server whose row is a mirror or lost the mail role → not listed; mail domains on it do not add it.
- Servers are listed once, assigned servers first in assignment order, then other servers hosting the account's mail
  domains by id.
- A reseller key without `client_id` → the reseller's own settings; naming another reseller's client → 404.
- A client key sending `custom_mailfilter` with its current value → still 422 (the field is not part of the customer
  form; ISPConfig ignores it for clients).
- A client key's `PUT /mail/users/{id}/spamfilter` carrying `move_junk` or a purge field while the mail filter tab is
  off → 403; fields of other tabs (feature 026 adds the spam filter level of the always-shown mailbox tab) are not
  refused by the tab rule.
- Tabs off do not affect `GET` sub-resources or the main mailbox resource.

## API Contract *(mandatory)*

- **Spec file(s)**: `api/modules/me/capabilities.yaml` (existing — response gains `mail`),
  `api/modules/me/mail-settings.yaml` (new — `GET /me/mail-settings`), `api/modules/usage/summary.yaml` (existing —
  counts gain four keys), `api/modules/mail/user-autoresponder.yaml`, `user-spamfilter.yaml`, `user-filters.yaml`
  (existing — 403/422 documented).
- **Shared schemas**: `api/components/schemas/AccountCapabilities.yaml` (`mail` object),
  `api/components/schemas/AccountMailSettings.yaml` (new), `api/components/schemas/UsageSummary.yaml` (counts).
- **Endpoints**:

| Method | Path | Purpose | Success code |
|--------|------|---------|--------------|
| GET | `/api/v1/me/capabilities` | + `mail` block | 200 |
| GET | `/api/v1/me/mail-settings` | Email program settings of the account (`client_id` for admin/reseller) | 200 |
| GET | `/api/v1/usage/summary` | + `mail_catchalls`, `mail_alias_domains`, `mail_filters`, `fetchmail_accounts` | 200 |
| PUT/DELETE | `/api/v1/mail/users/{id}/autoresponder` | 403 for customer keys when the vacation tab is off | 200/204 |
| PUT | `/api/v1/mail/users/{id}/spamfilter` | 403 for filter-tab fields when the tab is off; 422 on `custom_mailfilter` for customer keys | 200 |
| POST/PUT/DELETE | `/api/v1/mail/users/{id}/filters[/{filter_id}]` | 403 for customer keys when the filter tab is off | 201/200/204 |

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference** (ISPConfig 3.3.1p1): `interface/web/mail/form/mail_user.tform.php` 350–360 (ASCII-only password),
  356 (autoresponder tab: `autoresponder*` fields, only when `mailbox_show_autoresponder_tab === 'y'`), 427 (mail
  filter tab: `move_junk`, `purge_trash_days`, `purge_junk_days` and the filter rule list, only when
  `mailbox_show_mail_filter_tab === 'y'`), 474 (custom rules tab: `custom_mailfilter`, only for `typ == 'admin'` and
  `mailbox_show_custom_rules_tab === 'y'`); `mail_user_edit.php` onShowEnd 99–110 (policies readable with
  `getAuthSQL('r')`), 133 (`enable_custom_login`); `lib/classes/validate_password.inc.php` and
  `lib/classes/auth.inc.php` 211–228 (`get_min_password_length()` default 8, `get_min_password_strength()` default 0);
  `mail/webmailer.php` 55–76 (`[SERVERNAME]` placeholder, fallback `/webmail` or `:8081/webmail` on nginx); `server/plugins-available/
  mail_plugin_dkim.inc.php::check_system()` (DKIM disabled without a usable `dkim_path`);
  `admin/form/system_config.tform.php` [mail] defaults.
- **Legacy behaviors to mirror**: tab switches decide which mailbox fields a user can change; custom rules are
  administrator-only; password policy values and defaults; webmail URL resolution; policies readable by permission.
- **Tables written (via datalog only)**: none (read endpoints); refused writes produce no datalog entry.
- **System fields handling**: not applicable.
- **Intentional deviations from legacy** (owner-delegated decisions 2026-09-15):
  - New read endpoints `GET /me/mail-settings` and the `mail` block; ISPConfig has no port settings, so the standard
    ports of an ISPConfig mail server (Dovecot/Postfix setup)
    are reported and documented as such: IMAPS 993 and POP3S 995 (implicit TLS, RFC 8314), submission 587 with
    STARTTLS first and SMTPS 465; the shape follows the WHMCS module contract (`imap`/`pop3` objects, `smtp` list).
  - The webmail fallback uses `https`; legacy copies the scheme of the panel request, which the API does not have.
    Legacy `webmailer.php` tests `$web_config['server_type']` without loading `$web_config`, so its nginx branch
    (`:8081/webmail`) never runs; the API implements that evident intent from the server's [web] `server_type`.
  - Tab switches missing from `sys_ini` count as enabled (form default `y`, like `/system/config`); legacy treats a
    missing key as disabled.
  - Refused tab-bound writes return 403/422 instead of silently ignoring the fields (API has no form).
  - The usage summary gains four counts instead of duplicating counts in the capabilities.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: `GET /me/capabilities` MUST include `mail` with `autoresponder`, `mail_filters`, `custom_rules`,
  `spamfilter_policy`, `dkim`, `custom_login` and `password_policy` (`min_length`, `min_strength`, `ascii_only`)
  for the target account, using the target rules of spec 021.
- **FR-002**: `mail.autoresponder` and `mail.mail_filters` MUST be the `sys_ini` [mail] tab switches (missing → `y`);
  `mail.custom_rules` MUST be `false` for every described account (administrator-only in ISPConfig).
- **FR-003**: `mail.spamfilter_policy` MUST be true exactly when at least one spam filter policy is readable with the
  account's own permissions.
- **FR-004**: `mail.dkim` MUST be true exactly when at least one mail server of the account (FR-008) has a
  `dkim_path` that is set and not `/` in its [mail] configuration.
- **FR-005**: `password_policy.min_length` MUST be `sys_ini` [misc] `min_password_length` (missing → 8, empty → 0),
  `min_strength` `min_password_strength` (missing or empty → 0), `ascii_only` `mail_password_onlyascii = y`.
- **FR-006**: `GET /me/mail-settings` MUST return `client_id`, `custom_login`, `webmail_link`
  (`mailboxlist_webmail_link = y`), `password_policy` (FR-005) and `servers[]` with `server_id`, `host`
  (`server.server_name`), `webmail_url`, `imap` and `pop3` (the recommended encrypted connection: `port`, `security`)
  and `smtp[]` (preferred first); `security` ∈ `ssl`, `starttls`.
- **FR-007**: `webmail_url` MUST replace `[SERVERNAME]` in `sys_ini` [mail] `webmail_url` with the host; when the
  setting is empty it MUST be `https://<host>/webmail`, or `https://<host>:8081/webmail` when the server's [web]
  `server_type` is `nginx`.
- **FR-008**: The mail servers of an account MUST be its valid assigned mail servers (`mail_servers`, spec 016) in
  order, then non-mirror mail servers hosting its mail domains ordered by id, without duplicates.
- **FR-009**: `GET /me/mail-settings` MUST follow the query rules of `/me/capabilities` (unknown parameter 400,
  `client_id` 422/404 rules).
- **FR-010**: For client and reseller keys, `PUT /mail/users/{id}/spamfilter` with `custom_mailfilter` present MUST
  return 422 with `error_types.custom_mailfilter = feature-not-allowed` and write nothing.
- **FR-011**: For client and reseller keys, `PUT`/`DELETE /mail/users/{id}/autoresponder` MUST return 403
  `feature-not-allowed` (`feature = mailbox_show_autoresponder_tab`) when the vacation tab is off.
- **FR-012**: For client and reseller keys, `PUT /mail/users/{id}/spamfilter` carrying `move_junk`,
  `purge_trash_days` or `purge_junk_days` and `POST`/`PUT`/`DELETE`
  `/mail/users/{id}/filters…` MUST return 403 `feature-not-allowed` (`feature = mailbox_show_mail_filter_tab`) when
  the mail filter tab is off. Admin keys MUST be unaffected by FR-010…FR-012.
- **FR-013**: `/usage/summary` `counts` MUST include `mail_catchalls` (`limit_mailcatchall`), `mail_alias_domains`
  (`limit_mailaliasdomain`), `mail_filters` (`limit_mailfilter`) and `fetchmail_accounts` (`limit_fetchmail`), counted
  with the spec 012 create-check rules.
- **FR-014**: Every endpoint change MUST be in the OpenAPI contract first and covered by feature tests (success,
  400/401/403/404/422, admin vs customer keys, no datalog on reads and refusals).

### Key Entities

- **Account Mail Capabilities**: mail options of the account — `sys_ini` [mail]/[misc], `spamfilter_policy`,
  `server.config` [mail]; schema `AccountCapabilities.yaml` (`mail`).
- **Account Mail Settings**: what a mail client needs — `server`, `sys_ini` [mail], `mail_domain`; schema
  `AccountMailSettings.yaml`.
- **Usage Count**: existing `UsageCount.yaml`, four new keys.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A panel can decide every mail option of spec 004 (vacation message, filters, spam level, DKIM, password
  rules, webmail link, host and ports) from two reads with the customer's own key.
- **SC-002**: For 100% of tested combinations, a capability reported as not available matches a refused write, and
  one reported as available matches an accepted write.
- **SC-003**: Customer keys can no longer store a custom mail filter script (0 accepted attempts in tests); admin keys
  keep full access.
- **SC-004**: All new and changed endpoints render in Swagger UI and behave as documented.

## Assumptions

- The WHMCS module is the first consumer; it calls `/me/capabilities`, `/me/mail-settings` and `/usage/summary` with
  the service's customer key.
- Port numbers and security are not configurable in ISPConfig 3.3; the standard values of an ISPConfig mail server
  are adequate. Installations with other ports can be served by a later configuration feature.
- Feature 026 (spam filter level choice), 027 (DKIM generation) and 028 (password policy enforcement, access
  switches) build on the values described here.
- `cc`, forwarding copies and the main mailbox fields are part of the always-shown mailbox tab and need no capability.
