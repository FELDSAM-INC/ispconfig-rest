# Feature Specification: Mail Module Completion

**Feature Branch**: `005-mail-module-completion`  
**Created**: 2026-07-04  
**Status**: Draft (reverse-engineered from contract + legacy source; not yet implemented)  
**Module**: mail  
**Input**: Complete the mail module by implementing the 18 specced-but-unbuilt mail resources under `api/modules/mail/`. The 19th resource, mail domains (`domains.yaml`), is already implemented (`app/Http/Controllers/Api/V1/MailDomainController.php` + `app/Models/MailDomain.php`, covered by specs/003) and serves as the pattern reference.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Provision and manage mailboxes (Priority: P1)

A hosting-automation script calls `POST /api/v1/mail/users` with `X-API-Key` to create a mailbox (email, password, name, quota) under an existing mail domain, later updates it (`PUT /mail/users/{id}`), rotates its password (`PUT /mail/users/{id}/password`), lists/filters mailboxes, and deletes them. Every write lands in `sys_datalog` so the ISPConfig mail server provisions the account (maildir, dovecot auth, quota) asynchronously.

**Why this priority**: Mailboxes are the core deliverable of a mail panel; nothing else in the module (forwards, spam settings, filters) is useful without them. `mail/domains` already exists, so this is the first missing link in the provisioning chain.

**Independent Test**: With an existing `mail_domain` row, `POST /api/v1/mail/users` with a valid body → `201`, response echoes the `MailUser` schema (no password), a `sys_datalog` row (`dbtable=mail_user`, `action=i`) exists whose `data.new.password` is a `$6$rounds=5000$…` crypt hash and whose `maildir` matches the server's `maildir_path` pattern, plus a companion `spamfilter_users` datalog insert.

**Acceptance Scenarios**:

1. **Given** mail domain `example.com` exists, **When** `POST /mail/users` with `email=user@example.com`, `password`, `name`, `quota`, **Then** `201` with the created record; `server_id` equals the domain's server, `sys_groupid` equals the domain's `sys_groupid`, `login` defaults to the email, `maildir` is derived from the server mail config.
2. **Given** an existing mailbox or an active forward with source `user@example.com`, **When** the same email is POSTed, **Then** `422`/`400` per contract (unique email; duplicate-alias check).
3. **Given** mailbox id 5 exists, **When** `PUT /mail/users/5/password` with `{"password":"newSecret!"}`, **Then** `200 {success:true,message:…}` and the datalog update contains only the newly hashed password.
4. **Given** mailbox id 5, **When** `DELETE /mail/users/5`, **Then** `204` and a `mail_user` datalog `d` entry.
5. **Given** a missing/invalid `X-API-Key`, **When** any endpoint is called, **Then** `401`.

---

### User Story 2 - Route addresses: forwards, aliases, catchalls, alias domains (Priority: P2)

An integrator manages address routing for a domain: `POST /api/v1/mail/forwards` for a per-address forward (`type=forward`), an alias to an existing mailbox (`type=alias`), or a domain catchall (`type=catchall`, source `@example.com`), and `POST /api/v1/mail/alias-domains` to deliver a whole secondary domain into a primary one. All CRUD + filtered lists.

**Why this priority**: Forwarding/aliasing is the second most used mail workflow and shares its table (`mail_forwarding`) across four legacy forms. Depends only on mail domains (already implemented) and optionally mailboxes (US1).

**Independent Test**: Create a forward, a catchall and an alias domain via the API; verify `sys_datalog` rows on `mail_forwarding` with correct `type` (`forward`/`catchall`/`aliasdomain`), normalized `destination`, and `server_id`/`sys_groupid` inherited from the relevant `mail_domain`.

**Acceptance Scenarios**:

1. **Given** domain `example.com`, **When** `POST /mail/forwards` with `source=info@example.com`, `destination="a@x.com; b@y.com"`, **Then** `201` and the stored destination is normalized to `a@x.com, b@y.com`.
2. **Given** an active mailbox `info@example.com`, **When** a forward with the same source is created, **Then** `400`/`422` (legacy `duplicate_mailbox_txt` check).
3. **Given** `type=catchall`, **When** source is not in `@domain.tld` form, **Then** `422`; when it is, **Then** `201`.
4. **Given** alias domain source `@alias.com` and destination `@example.com` where only `example.com` is a mail domain, **When** POSTed to `/mail/alias-domains`, **Then** `400` (destination must exist) — and with both domains existing, `201` with `server_id` from the destination domain.
5. **Given** an existing forward, **When** `GET /mail/forwards?type[eq]=catchall` style filters (`type`, `source`, `destination`, `active` query params) are used, **Then** only matching rows return, paginated.

---

### User Story 3 - Mailbox self-service settings: autoresponder, CC, filters, per-mailbox spam handling (Priority: P3)

A webmail or customer portal manages a single mailbox's convenience settings through nested endpoints: `GET/PUT/DELETE /mail/users/{id}/autoresponder`, `GET/PUT /mail/users/{id}/cc`, `GET/PUT /mail/users/{id}/spamfilter` (move-to-junk / purge days / custom sieve), and full CRUD on `/mail/users/{id}/filters` (server-side mail sorting rules).

**Why this priority**: High-value end-user features, but all operate on mailboxes created in US1 — they cannot ship first.

**Independent Test**: For an existing mailbox, PUT an autoresponder with a start/end window and verify the `mail_user` datalog update; create a filter rule and verify BOTH a `mail_user_filter` insert AND a `mail_user` update whose `custom_mailfilter` gained a `### BEGIN FILTER_ID:<id> … ### END FILTER_ID:<id>` block.

**Acceptance Scenarios**:

1. **Given** mailbox 5, **When** `PUT /mail/users/5/autoresponder` with `autoresponder=y`, subject, text, start/end dates, **Then** `200` with the data wrapper; **When** `end_date` < `start_date`, **Then** `422` (legacy `validate_autoresponder::end_date`).
2. **Given** an active autoresponder, **When** `DELETE /mail/users/5/autoresponder`, **Then** `204` and the datalog update sets `autoresponder=n` and clears both dates (legacy clears dates when the box is unchecked).
3. **Given** mailbox 5, **When** `PUT /mail/users/5/cc` with `cc="a@x.com, b@y.com"`, **Then** `200`; an invalid address list → `422` (legacy comma-separated-email regex).
4. **Given** mailbox 5, **When** `POST /mail/users/5/filters` with rulename/source/op/searchterm/action, **Then** `201`, and `mail_user.custom_mailfilter` is regenerated; **When** the filter is deleted, **Then** `204` and its block is removed from `custom_mailfilter`.
5. **Given** filter 9 belongs to mailbox 6, **When** `GET /mail/users/5/filters/9`, **Then** `404`.

---

### User Story 4 - Spam filtering administration (Priority: P4)

An admin tool manages the Amavis/Rspamd layer: CRUD spamfilter policies (`/mail/spamfilter/policies`), map addresses to policies (`/mail/spamfilter/users`), maintain white/blacklists (`/mail/spamfilter/wblist`), and read/update per-server spamfilter configuration (`/mail/spamfilter/config`, no delete endpoint).

**Why this priority**: Spam administration builds on mailboxes (US1 auto-creates `spamfilter_users` rows) and is less frequently exercised than mailbox/forward CRUD.

**Independent Test**: Create a policy, map `user@example.com` to it via spamfilter users, add a `wb=B` wblist entry with `rid` = that mapping's id; verify datalog rows on `spamfilter_policy`, `spamfilter_users`, `spamfilter_wblist` respectively.

**Acceptance Scenarios**:

1. **Given** no policy named "Strict", **When** `POST /mail/spamfilter/policies`, **Then** `201`; contract-exposed fields are persisted, all other legacy policy columns keep their DB defaults.
2. **Given** a policy referenced by a `spamfilter_users` row, **When** `DELETE /mail/spamfilter/policies/{id}`, **Then** `400` ("policy is in use") per contract.
3. **Given** a spamfilter user mapping, **When** `POST /mail/spamfilter/wblist` with `wb=W`, `email`, `rid`, `priority`, **Then** `201` with defaults `active=y`, `priority=5`.
4. **Given** server 1 is a mail server, **When** `GET /mail/spamfilter/config/1`, **Then** `200` with the server/mail sections (ip, hostname, maildir_path, mailuser_uid…) read from `server.config`; `PUT` writes them back (see FR-041 and Clarifications).

---

### User Story 5 - Mail routing & server-level filtering (admin) (Priority: P5)

A mail platform admin manages Postfix-level plumbing: transports (`/mail/transports`), relay domains (`/mail/relay-domains`), relay recipients (`/mail/relay-recipients`), sender/recipient/client access rules i.e. black/whitelists (`/mail/access-rules`), header/body content filters (`/mail/content-filters`), and remote-mailbox fetching (`/mail/get`).

**Why this priority**: Pure admin/edge functionality with no dependency on the other stories beyond shared infrastructure; lowest call volume.

**Independent Test**: Create one record of each resource and verify the corresponding `sys_datalog` insert on `mail_transport`, `mail_relay_domain`, `mail_relay_recipient`, `mail_access`, `mail_content_filter`, `mail_get` with legacy defaults applied (e.g. `access=REJECT` for a blacklist rule, `access=OK` for relay recipients).

**Acceptance Scenarios**:

1. **Given** a mail server id 1, **When** `POST /mail/transports` with `domain`, `transport="smtp:[mail.x.com]:587"`, **Then** `201`; **When** the same domain is posted again for the same server, **Then** `422` (legacy `validate_mail_transport::validate_domain`); a domain that is already a local `mail_domain` → `422` (`validate_isnot_maildomain`).
2. **Given** transport id 3 on server 1, **When** `PUT` attempts `server_id=2`, **Then** the server_id change is rejected/ignored (legacy reverts it).
3. **Given** no rule for `spammer@bad.tld`, **When** `POST /mail/access-rules` with `type=sender`, **Then** `201` with `access` defaulting to `REJECT`.
4. **When** `POST /mail/get` with `type=pop3`, `source_server`, credentials and a destination that is not an existing mailbox email, **Then** `400`/`422` (legacy requires an existing `mail_user` email); with a valid mailbox, **Then** `201` .

---

### Edge Cases

- Missing/invalid `X-API-Key` → `401` on every endpoint (shared `Unauthorized` response).
- Sub-resource under a nonexistent mailbox (`/mail/users/999/cc`) → `404` before any validation.
- ISPConfig `y`/`n` flags (`active`, `greylisting`, `forward_in_lda`, `source_delete`, …) vs the uppercase `Y`/`N` fields of `spamfilter_policy.*_lover` / `spamfilter_users.local` and `W`/`B` of `spamfilter_wblist.wb` — casing must be preserved exactly as legacy stores it.
- IDN domains/emails: legacy applies `IDNTOASCII` + `TOLOWER` on save for email, login, cc, source, destination, domain and hostname fields — API input must be punycode-encoded and lowercased before persisting.
- Deleting a mailbox does not cascade in the interface layer (server plugins handle maildir removal); forwards pointing at the deleted address remain — document, don't "fix".
- Quota interplay: `quota=0` means unlimited; ISPConfig client limits (`limit_mailbox`, `limit_mailquota`, `limit_mailforward`, `limit_fetchmail`, `limit_mail_wblist`, …) are interface-level checks tied to the panel login; the REST API is key-authenticated with no client context (see Assumptions).
- `sys_perm_*` defaults differ per resource: everything uses `riud`/`riud`/`''` except `spamfilter_policy` which uses `perm_other='r'` (policies are readable by all panel users).
- Autoresponder enabled but `autoresponder_end_date` empty → allowed (runs until disabled); dates must be cleared when disabling.
- `PUT` with form-encoded bodies goes through `PutPatchInputMiddleware` (project-wide behavior; JSON recommended).

## API Contract *(mandatory)*

- **Spec files** (all existing under `api/modules/mail/`, registered in `api/modules/mail/_index.yaml` — implement as-is): `users.yaml`, `user-autoresponder.yaml`, `user-cc.yaml`, `user-filters.yaml`, `user-password.yaml`, `user-spamfilter.yaml`, `forwards.yaml`, `alias-domains.yaml`, `get.yaml`, `transports.yaml`, `relay-domains.yaml`, `relay-recipients.yaml`, `access-rules.yaml`, `content-filters.yaml`, `spamfilter-config.yaml`, `spamfilter-policies.yaml`, `spamfilter-users.yaml`, `spamfilter-wblist.yaml`.
- **Shared schemas** (existing, `api/components/schemas/`): `MailUser`, `MailUserAutoresponder`, `MailUserCC`, `MailUserFilter`, `MailUserPassword`, `MailUserSpamFilter`, `MailForwarding`, `MailAliasDomain`, `MailGet`, `MailTransport`, `MailRelayDomain`, `MailRelayRecipient`, `MailAccess`, `MailContentFilter`, `SpamfilterConfig`, `SpamfilterPolicy`, `SpamfilterUser`, `SpamfilterWBList`, plus shared `Pagination`, parameters (`limit`, `offset`, `sort`, `order`) and responses (`BadRequest`, `Unauthorized`, `Forbidden`, `NotFound`, `Conflict`, `UnprocessableEntity`, `InternalServerError`).
- **Endpoints** (77 total; list endpoints return `{data: [...], pagination: {...}}` per the module YAMLs — see Clarification C-9):

### Mail users & nested sub-resources (`users.yaml`, `user-*.yaml`) — 18 endpoints

| Method | Path | Purpose | Success | Declared errors |
|--------|------|---------|---------|-----------------|
| GET | `/api/v1/mail/users` | List mailboxes (filters: `email`, `domain`, `login`, `active`) | 200 | 400 401 403 500 |
| POST | `/api/v1/mail/users` | Create mailbox (datalog `i`) | 201 | 400 401 403 409 422 500 |
| GET | `/api/v1/mail/users/{id}` | Show mailbox | 200 | 401 403 404 500 |
| PUT | `/api/v1/mail/users/{id}` | Update mailbox (email/login immutable) | 200 | 400 401 403 404 409 422 500 |
| DELETE | `/api/v1/mail/users/{id}` | Delete mailbox (datalog `d`) | 204 | 400 401 403 404 500 |
| GET | `/api/v1/mail/users/{id}/autoresponder` | Read autoresponder config (wrapped in `data`) | 200 | 400 401 403 404 500 |
| PUT | `/api/v1/mail/users/{id}/autoresponder` | Update autoresponder (`data` + `message`) | 200 | 400 401 403 404 500 |
| DELETE | `/api/v1/mail/users/{id}/autoresponder` | Disable autoresponder (set `n`, clear dates) | 204 | 400 401 403 404 500 |
| GET | `/api/v1/mail/users/{id}/cc` | Read CC / forward_in_lda settings | 200 | 400 401 403 404 500 |
| PUT | `/api/v1/mail/users/{id}/cc` | Update CC settings | 200 | 400 401 403 404 500 |
| PUT | `/api/v1/mail/users/{id}/password` | Set new password → `{success, message}` | 200 | 400 401 403 404 500 |
| GET | `/api/v1/mail/users/{id}/spamfilter` | Read move_junk/purge/custom_mailfilter | 200 | 400 401 403 404 500 |
| PUT | `/api/v1/mail/users/{id}/spamfilter` | Update those settings | 200 | 400 401 403 404 500 |
| GET | `/api/v1/mail/users/{id}/filters` | List filter rules (filters: `source`, `active`) | 200 | 400 401 403 500 |
| POST | `/api/v1/mail/users/{id}/filters` | Create filter rule | 201 | 400 401 403 409 422 500 |
| GET | `/api/v1/mail/users/{id}/filters/{filter_id}` | Show filter rule | 200 | 401 403 404 500 |
| PUT | `/api/v1/mail/users/{id}/filters/{filter_id}` | Update filter rule | 200 | 400 401 403 404 409 422 500 |
| DELETE | `/api/v1/mail/users/{id}/filters/{filter_id}` | Delete filter rule | 204 | 400 401 403 404 500 |

### Forwarding family (`forwards.yaml`, `alias-domains.yaml`) — 10 endpoints

| Method | Path | Purpose | Success | Declared errors |
|--------|------|---------|---------|-----------------|
| GET | `/api/v1/mail/forwards` | List forwards/catchalls/aliases (filters: `source`, `destination`, `type`, `active`) | 200 | 400 401 403 500 |
| POST | `/api/v1/mail/forwards` | Create forward/catchall/alias | 201 | 400 401 403 409 422 500 |
| GET | `/api/v1/mail/forwards/{id}` | Show (includes read-only `is_catchall`) | 200 | 401 403 404 500 |
| PUT | `/api/v1/mail/forwards/{id}` | Update (source & type immutable) | 200 | 400 401 403 404 409 422 500 |
| DELETE | `/api/v1/mail/forwards/{id}` | Delete | 204 | 400 401 403 404 500 |
| GET | `/api/v1/mail/alias-domains` | List alias domains (filters: `source`, `destination`, `type`, `active`) | 200 | 400 401 403 500 |
| POST | `/api/v1/mail/alias-domains` | Create alias domain | 201 | 400 401 403 409 422 500 |
| GET | `/api/v1/mail/alias-domains/{id}` | Show | 200 | 401 403 404 500 |
| PUT | `/api/v1/mail/alias-domains/{id}` | Update (source immutable) | 200 | 400 401 403 404 409 422 500 |
| DELETE | `/api/v1/mail/alias-domains/{id}` | Delete | 204 | 400 401 403 404 500 |

### Spamfilter (`spamfilter-*.yaml`) — 19 endpoints

| Method | Path | Purpose | Success | Declared errors |
|--------|------|---------|---------|-----------------|
| GET | `/api/v1/mail/spamfilter/config` | List per-server spamfilter/mail configs (filter: `hostname`) | 200 | 401 403 500 |
| POST | `/api/v1/mail/spamfilter/config` | Create server config (see C-8) | 201 | 400 401 403 409 422 500 |
| GET | `/api/v1/mail/spamfilter/config/{server_id}` | Show one server's config | 200 | 401 403 404 500 |
| PUT | `/api/v1/mail/spamfilter/config/{server_id}` | Update server config (**no DELETE endpoint exists**) | 200 | 400 401 403 404 422 500 |
| GET | `/api/v1/mail/spamfilter/policies` | List policies (filter: `policy_name`) | 200 | 401 403 500 |
| POST | `/api/v1/mail/spamfilter/policies` | Create policy | 201 | 400 401 403 409 422 500 |
| GET | `/api/v1/mail/spamfilter/policies/{id}` | Show policy | 200 | 401 403 404 500 |
| PUT | `/api/v1/mail/spamfilter/policies/{id}` | Update policy | 200 | 400 401 403 404 409 422 500 |
| DELETE | `/api/v1/mail/spamfilter/policies/{id}` | Delete policy (400 if in use) | 204 | 400 401 403 404 500 |
| GET | `/api/v1/mail/spamfilter/users` | List policy mappings (filters: `email`, `server_id`, `policy_id`) | 200 | 401 500 |
| POST | `/api/v1/mail/spamfilter/users` | Create mapping | 201 | 400 401 403 404 409 422 500 |
| GET | `/api/v1/mail/spamfilter/users/{id}` | Show mapping | 200 | 401 403 404 500 |
| PUT | `/api/v1/mail/spamfilter/users/{id}` | Update mapping (email/server_id immutable) | 200 | 400 401 403 404 422 500 |
| DELETE | `/api/v1/mail/spamfilter/users/{id}` | Delete mapping | 204 | 401 403 404 500 |
| GET | `/api/v1/mail/spamfilter/wblist` | List W/B entries (filters: `email`, `wb`, `rid`, `active`) | 200 | 401 500 |
| POST | `/api/v1/mail/spamfilter/wblist` | Create W/B entry | 201 | 400 401 403 404 409 422 500 |
| GET | `/api/v1/mail/spamfilter/wblist/{id}` | Show entry | 200 | 401 403 404 500 |
| PUT | `/api/v1/mail/spamfilter/wblist/{id}` | Update entry (email/rid immutable) | 200 | 400 401 403 404 422 500 |
| DELETE | `/api/v1/mail/spamfilter/wblist/{id}` | Delete entry | 204 | 401 403 404 500 |

### Routing & server-level filtering (`transports.yaml`, `relay-domains.yaml`, `relay-recipients.yaml`, `access-rules.yaml`, `content-filters.yaml`, `get.yaml`) — 30 endpoints

| Method | Path | Purpose | Success | Declared errors |
|--------|------|---------|---------|-----------------|
| GET | `/api/v1/mail/transports` | List transports (filters: `domain`, `transport`, `active`) | 200 | 400 401 403 500 |
| POST | `/api/v1/mail/transports` | Create transport | 201 | 400 401 403 409 422 500 |
| GET | `/api/v1/mail/transports/{id}` | Show | 200 | 401 403 404 500 |
| PUT | `/api/v1/mail/transports/{id}` | Update (domain & server_id immutable) | 200 | 400 401 403 404 409 422 500 |
| DELETE | `/api/v1/mail/transports/{id}` | Delete | 204 | 400 401 403 404 500 |
| GET | `/api/v1/mail/relay-domains` | List relay domains (filters: `domain`, `active`) | 200 | 400 401 403 500 |
| POST | `/api/v1/mail/relay-domains` | Create relay domain | 201 | 400 401 403 409 422 500 |
| GET | `/api/v1/mail/relay-domains/{id}` | Show | 200 | 401 403 404 500 |
| PUT | `/api/v1/mail/relay-domains/{id}` | Update (domain immutable; contract: only `active` mutable) | 200 | 400 401 403 404 409 422 500 |
| DELETE | `/api/v1/mail/relay-domains/{id}` | Delete | 204 | 400 401 403 404 500 |
| GET | `/api/v1/mail/relay-recipients` | List relay recipients (filters: `source`, `access`) | 200 | 400 401 403 500 |
| POST | `/api/v1/mail/relay-recipients` | Create relay recipient | 201 | 400 401 403 409 422 500 |
| GET | `/api/v1/mail/relay-recipients/{id}` | Show | 200 | 401 403 404 500 |
| PUT | `/api/v1/mail/relay-recipients/{id}` | Update (source/server_id immutable; only `access` mutable) | 200 | 400 401 403 404 409 422 500 |
| DELETE | `/api/v1/mail/relay-recipients/{id}` | Delete | 204 | 400 401 403 404 500 |
| GET | `/api/v1/mail/access-rules` | List access rules (filters: `source`, `type`, `access`, `active`) | 200 | 400 401 403 500 |
| POST | `/api/v1/mail/access-rules` | Create access rule | 201 | 400 401 403 409 422 500 |
| GET | `/api/v1/mail/access-rules/{id}` | Show | 200 | 401 403 404 500 |
| PUT | `/api/v1/mail/access-rules/{id}` | Update (server_id immutable; source+type unique per server) | 200 | 400 401 403 404 409 422 500 |
| DELETE | `/api/v1/mail/access-rules/{id}` | Delete | 204 | 400 401 403 404 500 |
| GET | `/api/v1/mail/content-filters` | List content filters (filters: `type`, `action`, `active`) | 200 | 401 403 500 |
| POST | `/api/v1/mail/content-filters` | Create content filter | 201 | 400 401 403 422 500 |
| GET | `/api/v1/mail/content-filters/{id}` | Show | 200 | 401 403 404 500 |
| PUT | `/api/v1/mail/content-filters/{id}` | Update (server_id immutable) | 200 | 400 401 403 404 422 500 |
| DELETE | `/api/v1/mail/content-filters/{id}` | Delete | 204 | 401 403 404 500 |
| GET | `/api/v1/mail/get` | List fetchmail configs (filters: `type`, `source_server`, `active`) | 200 | 400 401 403 500 |
| POST | `/api/v1/mail/get` | Create fetchmail config | 201 | 400 401 403 409 422 500 |
| GET | `/api/v1/mail/get/{id}` | Show | 200 | 401 403 404 500 |
| PUT | `/api/v1/mail/get/{id}` | Update (server_id immutable; password only if provided) | 200 | 400 401 403 404 409 422 500 |
| DELETE | `/api/v1/mail/get/{id}` | Delete | 204 | 400 401 403 404 500 |

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference**: `source_code/interface/web/mail/` — `form/*.tform.php` (validators, defaults, filters), `*_edit.php` (side effects), `templates/*.htm` (hidden fields), plus `source_code/interface/lib/plugins/mail_user_filter_plugin.inc.php`, `source_code/interface/lib/classes/tform_base.inc.php` (password encryption dispatch), `source_code/interface/lib/classes/auth.inc.php` (`crypt_password`), and `source_code/server/plugins-available/rspamd_plugin.inc.php` (wblist/access-rule server effects).

### Resource → legacy form → table map (all writes via datalog only)

| API resource | Legacy tform (`form/`) | Edit action | Table (PK) | Datalog actions |
|---|---|---|---|---|
| `mail/users` | `mail_user.tform.php` | `mail_user_edit.php` | `mail_user` (`mailuser_id`) | i/u/d (+ companion `spamfilter_users` i/u) |
| `mail/users/{id}/autoresponder` | `mail_user.tform.php` (autoresponder tab) | `mail_user_edit.php` | `mail_user` | u |
| `mail/users/{id}/cc` | `mail_user.tform.php` (`cc`, `forward_in_lda`) | `mail_user_edit.php` | `mail_user` | u |
| `mail/users/{id}/password` | `mail_user.tform.php` (`password`, `CRYPTMAIL`) | `mail_user_edit.php` | `mail_user` | u |
| `mail/users/{id}/spamfilter` | `mail_user.tform.php` (Mail Filter + Custom Rules tabs) | `mail_user_edit.php` | `mail_user` | u |
| `mail/users/{id}/filters` | `mail_user_filter.tform.php` | `mail_user_filter_edit.php` + `mail_user_filter_plugin.inc.php` | `mail_user_filter` (`filter_id`) | i/u/d (+ `mail_user` u for `custom_mailfilter`) |
| `mail/forwards` | `mail_forward.tform.php`, `mail_alias.tform.php`, `mail_domain_catchall.tform.php` | `mail_forward_edit.php` etc. | `mail_forwarding` (`forwarding_id`), `type` ∈ forward/alias/catchall | i/u/d |
| `mail/alias-domains` | `mail_aliasdomain.tform.php` | `mail_aliasdomain_edit.php` | `mail_forwarding` (`forwarding_id`), `type='aliasdomain'` — **not** a `mail_alias_domain` table (C-1) | i/u/d |
| `mail/get` | `mail_get.tform.php` | `mail_get_edit.php` | `mail_get` (`mailget_id`) | i/u/d |
| `mail/transports` | `mail_transport.tform.php` | `mail_transport_edit.php` | `mail_transport` (`transport_id`) | i/u/d |
| `mail/relay-domains` | `mail_relay_domain.tform.php` | `mail_relay_domain_edit.php` | `mail_relay_domain` (`relay_domain_id`) | i/u/d |
| `mail/relay-recipients` | `mail_relay_recipient.tform.php` | `mail_relay_recipient_edit.php` | `mail_relay_recipient` (`relay_recipient_id`) | i/u/d |
| `mail/access-rules` | `mail_blacklist.tform.php` + `mail_whitelist.tform.php` | `mail_blacklist_edit.php` / `mail_whitelist_edit.php` | `mail_access` (`access_id`) | i/u/d |
| `mail/content-filters` | `mail_content_filter.tform.php` | `mail_content_filter_edit.php` | `mail_content_filter` (`content_filter_id`) | i/u/d |
| `mail/spamfilter/config` | `spamfilter_config.tform.php` (`db_table = server`!) | `spamfilter_config_edit.php` | `server.config` INI column (`server_id`) — see C-8 | legacy: direct `UPDATE server SET config` (no datalog) |
| `mail/spamfilter/policies` | `spamfilter_policy.tform.php` | `spamfilter_policy_edit.php` | `spamfilter_policy` (`id`) | i/u/d |
| `mail/spamfilter/users` | `spamfilter_users.tform.php` (NB: `mail_spamfilter.tform.php` is an ISPConfig-2 leftover referencing a nonexistent `mail_box` table — ignore it) | `spamfilter_users_edit.php` | `spamfilter_users` (`id`) | i/u/d |
| `mail/spamfilter/wblist` | `spamfilter_blacklist.tform.php` + `spamfilter_whitelist.tform.php` | `spamfilter_{black,white}list_edit.php` | `spamfilter_wblist` (`wblist_id`) | i/u/d |

### Legacy behaviors an implementer MUST mirror

1. **Password hashing (CRYPTMAIL)** — `mail_user.password` uses `encryption => 'CRYPTMAIL'` (`form/mail_user.tform.php:140`). `tform_base.inc.php:1372-1376` converts the cleartext **UTF-8 → ISO-8859-1** (`mb_convert_encoding`) and then calls `auth.inc.php::crypt_password()`, which produces a SHA-512 crypt hash: salt prefix `$6$rounds=5000$` + 16 hex chars (fallback `$5$`/`$1$` when SHA-512 crypt is unavailable). Password empty on update ⇒ field untouched; empty on insert ⇒ error (`mail_user_edit.php:188`). Optional global config `mail_password_onlyascii=y` swaps the strength validator for an ASCII-only check (`mail_user.tform.php:350-354`).
2. **Mailbox composition** (`mail_user_edit.php:248-302`) — `server_id` is copied from the `mail_domain` row of the email's domain part; `maildir` = server mail config `maildir_path` with `[domain]`/`[localpart]` substituted; `homedir` = `homedir_path`; `uid`/`gid` = `mailuser_uid`/`mailuser_gid`, or both `-1` when `mailbox_virtual_uidgid_maps=y`; `maildir_format` from server config on insert and **restored (not overwritten) on update**; `login` = email unless global `enable_custom_login=y` (and then a login containing `@` must equal the email). Email/login/cc pass `IDNTOASCII` + `TOLOWER`.
3. **Quota** — UI accepts MB and multiplies by `1024*1024` before storing (`mail_user_edit.php:272`); DB column `mail_user.quota` is bytes (`bigint`, default 0 = unlimited). The tform regex allows only non-negative integers. The REST schema documents bytes with `-1 = domain default` — no such semantic exists in legacy (C-6).
4. **Mailbox ownership** — after insert/update, `sys_groupid` is force-set to the mail domain's `sys_groupid` (`mail_user_edit.php:316-317, 362-365`); `sys_perm_user/group='riud'`, `sys_perm_other=''`.
5. **Duplicate guard** — creating a mailbox whose email equals an **active** `mail_forwarding.source` is rejected (`mail_user_edit.php:290-292`); symmetrically, creating a forward whose source equals an active mailbox (`postfix='y'`) is rejected (`mail_forward_edit.php:144-146`).
6. **spamfilter_users auto-sync** — on mailbox insert/update, a `spamfilter_users` row is datalog-inserted/updated for the email: `priority=7`, `policy_id` (0 = inherit), `local='Y'`, `fullname` = IDN-decoded email, `server_id`/`sys_groupid` from the domain (`mail_user_edit.php:319-343, 435-463`). Legacy email changes also re-point `spamfilter_wblist.rid` and `mail_forwarding.destination` — moot here because the contract freezes `email` (C-7), but the sync on create/update MUST be kept.
7. **Dovecot flag propagation** — legacy copies `disableimap→disablesieve(+disablesieve-filter)`, `disabledeliver→disablelda,disablelmtp` via a direct SQL update (`mail_user_edit.php:346-355, 367-376`). The REST contract does not expose the `disable*` fields, so new mailboxes simply keep the DB defaults (`n`); no propagation logic is required unless the fields are later added to the schema.
8. **Autoresponder** — subject default `Out of office reply`; `autoresponder_start_date`/`end_date` are DATETIMEs validated with `ISDATETIME (allowempty)` and custom `validate_autoresponder::end_date` (end > start); when the autoresponder is disabled both dates are cleared (`mail_user_edit.php:303-307`). The contract's DELETE endpoint = set `autoresponder='n'` + clear dates (datalog `u` on `mail_user`), **not** a row delete.
9. **Mail user filters → sieve regeneration** — `mail_user_filter` stored values are: `source` ∈ {`Subject`, `From`, `To`, `List-Id`, `Header`, `Size`}, `op` ∈ {`contains`, `is`, `begins`, `ends`, `regex`, `localpart`, `domain`}, `action` ∈ {`move`, `delete`, `keep`, `reject`}, plus `target` (regex `/^[\p{Latin}0-9\.\'\-\_\ \&\/]{0,100}$/u`), `rulename` (NOTEMPTY, DB v64), `searchterm` (NOTEMPTY). Every insert/update/delete regenerates the owner's `mail_user.custom_mailfilter`: the block between `### BEGIN FILTER_ID:<id>` and `### END FILTER_ID:<id>` is replaced/removed (inactive rules render nothing; new rules are prepended) and `mail_user` is datalog-updated (`mail_user_filter_plugin.inc.php:61-118`). Rule syntax (sieve vs maildrop) follows server mail config `mail_filter_syntax`. The contract's enums diverge badly from these stored values (C-2).
10. **Forwards** — destination is split on `/[,;\s]+/`, each part validated with `filter_var(FILTER_VALIDATE_EMAIL)`, then re-joined with `', '` (`mail_forward_edit.php:130-141`); `server_id` + `sys_groupid` from the source domain's `mail_domain`. Catchall source regex `/^\@[\w\.\-]{1,255}\.[a-zA-Z\-]{2,63}$/` + UNIQUE (`mail_domain_catchall.tform.php`). Checkbox defaults differ per legacy form: `allow_send_as` default `y` for aliases but `n` for forwards/catchalls; `greylisting` default `n`; `active` default `y`. Alias destinations are picked from existing `mail_user.email` values in the panel (soft constraint worth enforcing for `type=alias`).
11. **Alias domains** — stored as `mail_forwarding` rows with hidden `type=aliasdomain` (`templates/mail_aliasdomain_edit.htm:22`); source and destination are both `@`-prefixed domains (`mail_aliasdomain_edit.php:120-122`), both must exist in `mail_domain` and be readable by the owner, source ≠ destination; `server_id` and (after insert) `sys_groupid` come from the **destination** domain. Domains used as alias-domain sources are excluded from mailbox/forward domain choices (`... domain NOT IN (SELECT SUBSTR(source,2) FROM mail_forwarding WHERE type = 'aliasdomain')`).
12. **Transports** — custom validators `validate_mail_transport::validate_domain` (unique per server) and `validate_isnot_maildomain` (domain must not be a local mail domain); `sort_order` is a 1–10 select defaulting to **5** (DB default 5; contract says 0 — C-5); on update a changed `server_id` is silently reverted (`mail_transport_edit.php:123-127`). The legacy UI composes the `transport` string from type/destination/MX-checkbox; the API takes the raw Postfix transport string.
13. **Relay domains / recipients** — `mail_relay_domain` also has an `access` column (default `OK`) and `mail_relay_recipient` also has an `active` column (default `y`) that the contract omits (C-4); domain validated with `ISDOMAIN` + unique-per-server custom validator; recipient `source` NOTEMPTY; both restrict `server_id` to mail servers (`server.mail_server=1 AND mirror_server_id=0`).
14. **Access rules (mail_access)** — blacklist form defaults `access='REJECT'`, whitelist form `access='OK'`; `type` ∈ recipient/sender/client with **client offered only to admins** (`mail_blacklist.tform.php:115-117`); interface limit `limit_mail_wblist`. **Rspamd side effect**: `mail_access` datalog events are consumed by `rspamd_plugin.inc.php` (lines 115-122, 388-398) to write *global* wblist files `global_wblist_<access_id>.conf` — `access='OK'` ⇒ whitelist, anything else ⇒ blacklist; `sender→from`, `recipient→rcpt`, `client→ip/hostname`.
15. **Spamfilter wblist ↔ rspamd** — `spamfilter_wblist` events generate per-user files `spamfilter_wblist_<wblist_id>.conf`; the plugin resolves `rid` against `spamfilter_users` to obtain the recipient email and **skips the entry entirely if `rid` doesn't resolve or either address is invalid** (`rspamd_plugin.inc.php:399-445`). Consequence: `rid=0` "global" entries (as described in the contract) have **no effect** on Rspamd systems — global rules belong in `mail/access-rules` (C-10). Priority select 1–10, default 5; `wb` ∈ W/B.
16. **Spamfilter users** — `email` NOTEMPTY + `TOLOWER`/`IDNTOASCII`, unique (legacy `mail_spamfilter` form enforces UNIQUE on email); `fullname` NOTEMPTY; `local` Y/N default Y; `priority` select 1–10 default 5 (DB default 7 — the value used by the mailbox auto-sync); `policy_id` from `spamfilter_policy`, 0 = inherit.
17. **Spamfilter policies** — legacy form has ~40 columns (quarantine addresses, tag levels, address extensions, admin addresses, `message_size_limit`, `banned_rulenames`, `rspamd_*` levels…); the contract exposes a 13-field subset; unexposed columns must keep their DB defaults. `sys_perm_other='r'` (unlike every other mail resource). Legacy `spamfilter_policy_del.php` has **no** in-use guard; the contract's 400 "policy in use" is an intentional API improvement.
18. **Spamfilter config** — `spamfilter_config.tform.php` declares `db_table=server` and its edit page reads/writes INI **sections** (`server`, `mail`) of the serialized `server.config` column via `ini_parser`; it is admin-gated and legacy performs a plain `UPDATE server SET config = ?` **without datalog** (`spamfilter_config_edit.php:73-88`). Fields: `ip_address`, `netmask`, `gateway`, `hostname`, `nameservers` (server section); `module` (`postfix_mysql`), `maildir_path`, `homedir_path`, `mailuser_uid/gid`, `mailuser_name/group` (mail section). See C-8 for the create/write-path decision.
19. **Mail get (fetchmail)** — `type` ∈ pop3/imap/pop3ssl/imapssl; `source_server` regex `/^([\w\.\-]{2,64}\.[a-zA-Z\-]{2,10}|(?:[0-9]{1,3}\.){3}[0-9]{1,3})$/` with IDN/lowercase filters; `source_username`/`source_password` NOTEMPTY; **DB column is `destination`** and is validated as an existing `mail_user.email` (panel select + ISEMAIL); `source_read_all` (default `y`) exists in the table but not in the contract (C-3); interface limit `limit_fetchmail`.

- **Tables written (via datalog only)**: `mail_user`, `mail_user_filter`, `mail_forwarding`, `mail_get`, `mail_transport`, `mail_relay_domain`, `mail_relay_recipient`, `mail_access`, `mail_content_filter`, `spamfilter_policy`, `spamfilter_users`, `spamfilter_wblist` — actions i/u/d each. Exception: `server.config` for spamfilter config (C-8).
- **System fields handling**: on create set `sys_userid` (from authenticated context, default 1), `sys_groupid` (domain-derived where legacy does so — mail_user, mail_forwarding incl. alias domains; otherwise caller-supplied/default), `sys_perm_user='riud'`, `sys_perm_group='riud'`, `sys_perm_other=''` (`'r'` for `spamfilter_policy`). `server_id`: derived from the parent `mail_domain` for mailboxes/forwards/alias domains; caller-supplied and validated against mail servers (`mail_server=1`, `mirror_server_id=0`) for the admin resources.
- **Intentional deviations from legacy**: (a) email/login immutable after create (contract) whereas legacy cascades email renames; (b) policy delete guarded by an in-use check absent from legacy; (c) panel-login client limits (`limit_mailbox`, `limit_mailquota`, …) not enforced — the API has no client session (see Assumptions); (d) `disable*` dovecot columns not exposed; (e) legacy UI transport/type composition replaced by raw `transport` string.

### Clarifications (contract ↔ legacy conflicts — resolve before implementation)

- **C-1 [NEEDS CLARIFICATION]**: `MailAliasDomain.yaml` declares `x-db-table: mail_alias_domain` / `x-db-field: id` — that table does not exist in ISPConfig. Legacy stores alias domains in `mail_forwarding` with `type='aliasdomain'` and PK `forwarding_id`. Additionally the schema's `type` enum (`alias`/`forward`) cannot be persisted as such (the row's `type` must stay `aliasdomain`). Recommended: map the resource onto `mail_forwarding`, always write `type='aliasdomain'`, expose `forwarding_id` as `id`, and either drop or repurpose the schema's `type` field.
- **C-2 [NEEDS CLARIFICATION]**: `MailUserFilter.yaml` enums are UI language keys, not stored values — contract `source` `subject_txt|from_txt|…` vs stored `Subject|From|To|List-Id|Header|Size`; contract `op` `contains_txt|…` vs stored `contains|is|begins|ends|regex|localpart|domain`; contract `action` `move_to_folder|forward_to|delete|redirect_to|stop_processing|mark_as_read|flag` vs stored `move|delete|keep|reject` (no legacy equivalent for the other four); contract field `action_value` vs DB column `target`. Storing contract values verbatim would break the server-side sieve generator. Recommended: fix the schema to legacy stored values, or translate at the controller boundary.
- **C-3 [NEEDS CLARIFICATION]**: `MailGet.yaml` names the delivery field `destination_username` ("local username") but the DB column is `destination` and legacy validates it as an existing `mail_user` **email**; `source_read_all` (DB default `y`) is missing from the schema.
- **C-4 [NEEDS CLARIFICATION]**: `MailRelayRecipient.yaml` omits the `active` column (DB default `y`) and `MailRelayDomain.yaml` omits `access` (DB default `OK`); `MailTransport.yaml`/`MailRelayDomain.yaml` declare `created`/`modified` timestamps and several schemas declare `created_at`/`updated_at` — none of these columns exist in the ISPConfig tables (models are timestamp-less). Recommended: set the omitted columns to their legacy defaults server-side and drop/ignore the phantom timestamp fields.
- **C-5**: `MailTransport.sort_order` contract default 0 vs legacy select 1–10 with default 5 (DB default 5). Recommended: default 5, accept 0–10.
- **C-6 [NEEDS CLARIFICATION]**: `MailUser.quota` — contract says bytes with `0 = unlimited, -1 = domain default`; legacy stores bytes, UI enters MB, `0 = unlimited`, and `-1` has no "domain default" meaning for mailboxes. Decide: accept bytes verbatim with `0 = unlimited` and reject `-1`, or document `-1` as unlimited alias.
- **C-7**: Contract freezes `email` and `login` after create; legacy supports email renames with cascading updates (spamfilter_users, wblist `rid`, forwarding destinations). Deviation accepted — reject/ignore attempts to change them (mirrors `spamfilter-users`' "email cannot be changed" too).
- **C-8 [NEEDS CLARIFICATION]**: `spamfilter/config` maps onto the `server.config` INI column, is admin-only in legacy, and legacy writes it **directly without datalog** — conflicting with Constitution II if modeled naively. Also `POST` ("create a config") has no legacy analog: every server row already has a config. Recommended: implement GET/PUT only against existing mail-server rows via a dedicated service (`ini_parser` equivalent), return 404/409 semantics for POST or treat POST as "initialize sections for a server", and record the direct-write exception in the plan's Complexity Tracking.
- **C-9**: The module YAMLs declare shared `limit`/`offset`/`sort`/`order` parameters, describe `page`/`per_page` pagination in prose, and reference `Pagination.yaml` (page-based envelope: `total`, `per_page`, `current_page`, …) — while the constitution (Principle V) prescribes `{items,total,limit,offset}`. The implemented reference `MailDomainController` follows the YAML (`{data, pagination}` via Eloquent `paginate()`, `per_page` param, `sort` with `-` prefix). Per Principle I the YAML wins: follow the MailDomainController pattern and additionally honor `limit`/`offset` as declared. Flag for a future spec/constitution alignment pass.
- **C-10**: Contract describes `spamfilter_wblist.rid=0` as "global rules", but the Rspamd server plugin ignores wblist rows whose `rid` doesn't resolve to a `spamfilter_users` row; global filtering is actually implemented via `mail_access`. Accept `rid=0` (schema default) but document the limitation; recommend `mail/access-rules` for global entries.
- **C-11**: Password min length differs between schemas: `MailUser.password` minLength 5, `MailUserPassword.password` minLength 8. Legacy uses a configurable strength check (`validate_password::password_check`). Recommended: enforce each schema's declared minimum as written (5 on create, 8 on the dedicated password endpoint) until the schemas are harmonized.
- **C-12**: Several schemas name the record permission field `sys_perm` while the DB column is `sys_perm_user` — read-only display fields; map from `sys_perm_user`.

## Requirements *(mandatory)*

### Functional Requirements

**Cross-cutting**

- **FR-001**: System MUST expose all 77 endpoints exactly as declared in the 18 `api/modules/mail/*.yaml` files — paths, methods, parameter names, request/response schema refs, and status codes (201 create / 200 read+update / 204 delete; errors 400/401/403/404/409/422/500 as declared per operation).
- **FR-002**: List endpoints MUST support pagination + sorting per the contract and the `MailDomainController` reference (`{data:[…], pagination:{…}}`, `per_page` capped at 100, `sort` with `-` prefix for descending) plus the resource-specific filter query parameters named in each YAML (resolution of C-9).
- **FR-003**: Every write MUST go through a model extending `App\Models\BaseModel` so `save()`/`delete()` produce `sys_datalog` entries (i/u/d) via `DatalogService`; controllers wrap writes in DB transactions with rollback and contextual error logging; no direct table writes (sole candidate exception: C-8).
- **FR-004**: On create, system fields MUST default to `sys_perm_user='riud'`, `sys_perm_group='riud'`, `sys_perm_other=''` (except `spamfilter_policy`: `'r'`), `sys_userid` from the authenticated context (fallback 1), and `sys_groupid`/`server_id` derived as the legacy edit action does for that resource.
- **FR-005**: Lowercase `y`/`n` flags MUST use the `App\Casts\YesNoBoolean` pattern established by `MailDomain`; uppercase `Y`/`N` (`spamfilter_policy` flags, `spamfilter_users.local`) and `W`/`B` (`wblist.wb`) MUST be stored with exact casing.
- **FR-006**: Email/domain/hostname inputs MUST be normalized as legacy does: IDN → punycode (`idn_to_ascii`) and lowercased before validation/persistence.
- **FR-007**: Missing parent resources MUST yield `404` (`{message}`), validation failures `422` with field errors, bad references `400`, unexpected errors `500` with `{message, error}`.

**Mail users (US1)**

- **FR-008**: `POST /mail/users` MUST validate: `email` valid + unique in `mail_user` (regex/ISEMAIL, normalized per FR-006); `login` unique + regex `/^[_a-z0-9][\w\.\-\+@]{1,63}$/`; `password` required (minLength 5 per schema, C-11); `name` present; `quota` integer ≥ 0 (C-6); the email's domain part MUST exist in `mail_domain` (else 400).
- **FR-009**: Passwords MUST be hashed with the CRYPTMAIL scheme — UTF-8→ISO-8859-1 conversion, then SHA-512 crypt with salt `$6$rounds=5000$` + 16 hex chars — and MUST never appear in any response (write-only).
- **FR-010**: On create the system MUST derive `server_id` from the mail domain, build `maildir` from the server mail config `maildir_path` (`[domain]`, `[localpart]` substitution), set `homedir` from `homedir_path`, set `uid`/`gid` from `mailuser_uid`/`mailuser_gid` (or `-1`/`-1` when `mailbox_virtual_uidgid_maps=y`), set `maildir_format` from server config, and default `login` to the email.
- **FR-011**: On create/update the system MUST set `sys_groupid` to the mail domain's `sys_groupid` and MUST reject a create when an active `mail_forwarding.source` equals the email.
- **FR-012**: On mailbox create/update the system MUST upsert the companion `spamfilter_users` row via datalog (`priority=7`, `local='Y'`, `fullname` = IDN-decoded email, `policy_id` preserved or 0, `server_id`/`sys_groupid` from the domain).
- **FR-013**: `PUT /mail/users/{id}` MUST treat `email` and `login` as immutable (C-7) and MUST re-hash `password` only when a non-empty value is provided; `maildir_format` MUST be preserved on update.
- **FR-014**: `PUT /mail/users/{id}/password` MUST validate per `MailUserPassword.yaml` (minLength 8), update only the password column (datalog `u`), and return `200 {success:true, message}`.

**Forwards & alias domains (US2)**

- **FR-015**: `POST /mail/forwards` MUST accept `type` ∈ `forward|catchall|alias`; source MUST be a full email for forward/alias and match `/^\@[\w\.\-]{1,255}\.[a-zA-Z\-]{2,63}$/` for catchall; source+type uniqueness enforced; the source domain MUST exist in `mail_domain` (server_id/sys_groupid inherited from it).
- **FR-016**: Forward destinations MUST be split on `/[,;\s]+/`, each validated as an email, and stored re-joined with `', '`; empty destination ⇒ 422.
- **FR-017**: System MUST reject a forward/alias/catchall whose source equals an active mailbox email (`mail_user.postfix='y'`).
- **FR-018**: Defaults MUST follow legacy per type: `active=y`, `greylisting=n`; `allow_send_as` default `n` for forward/catchall and `y` for alias; `source` and `type` immutable on update; `GET` responses MUST include computed `is_catchall` (source starts with `@`).
- **FR-019**: `/mail/alias-domains` MUST persist to `mail_forwarding` with `type='aliasdomain'` (C-1): source/destination `@`-prefixed lowercase punycode domains, both existing in `mail_domain`, source ≠ destination, source unique; `server_id` and `sys_groupid` from the **destination** domain; source immutable on update.

**Mailbox sub-resources (US3)**

- **FR-020**: `GET/PUT/DELETE /mail/users/{id}/autoresponder` MUST read/update only the autoresponder columns of `mail_user`; PUT MUST validate datetimes and `end_date > start_date`; disabling (PUT `autoresponder=n` or DELETE) MUST clear both dates; DELETE returns 204; GET/PUT responses use the `data` wrapper declared in the YAML.
- **FR-021**: `GET/PUT /mail/users/{id}/cc` MUST validate `cc` as an optionally-empty comma-separated list of emails (legacy regex), normalize per FR-006, and accept `forward_in_lda` ∈ y/n.
- **FR-022**: `GET/PUT /mail/users/{id}/spamfilter` MUST manage `move_junk` ∈ `y|a|n`, `purge_trash_days`/`purge_junk_days` integers ≥ 0 (default 0), and `custom_mailfilter` text.
- **FR-023**: `/mail/users/{id}/filters` CRUD MUST scope every operation to `mailuser_id = {id}` (mismatch ⇒ 404), require `rulename` (≤64 chars per DB), `source`, `op`, `searchterm`, `action`, validate `target` against `/^[\p{Latin}0-9\.\'\-\_\ \&\/]{0,100}$/u`, and store the legacy value sets (resolution of C-2).
- **FR-024**: Every filter create/update/delete MUST regenerate the owning mailbox's `custom_mailfilter` (replace/insert/remove the `### BEGIN FILTER_ID:<id>` … `### END FILTER_ID:<id>` block; inactive rules render nothing; new rules prepend) in the syntax selected by the server's `mail_filter_syntax`, and datalog-update `mail_user` alongside the `mail_user_filter` datalog entry.

**Spamfilter administration (US4)**

- **FR-025**: `/mail/spamfilter/policies` CRUD MUST require a non-empty unique `policy_name`, persist exactly the contract-exposed subset, and leave all other `spamfilter_policy` columns at DB defaults; `sys_perm_other='r'`.
- **FR-026**: `DELETE /mail/spamfilter/policies/{id}` MUST return 400 when any `spamfilter_users.policy_id` references the policy (contract-added guard).
- **FR-027**: `/mail/spamfilter/users` CRUD MUST require `email` (unique, normalized), `fullname`, `local` ∈ Y/N (default Y), `priority` 1–10 (default 5), `policy_id` referencing an existing policy (404 for a missing reference per the YAML) with 0 allowed as "inherit"; `email`/`server_id` immutable on update.
- **FR-028**: `/mail/spamfilter/wblist` CRUD MUST require `wb` ∈ W/B (default B), `email`, `priority` 1–10 (default 5), `active` y/n (default y), and `rid`; a non-zero `rid` MUST reference an existing `spamfilter_users` row (404 otherwise); `email`/`rid` immutable on update; `rid=0` accepted but documented as Rspamd-inert (C-10).
- **FR-029**: `/mail/spamfilter/config` MUST implement GET (list over mail servers with `hostname` filter), GET by `server_id`, and PUT — reading/writing the `server`+`mail` INI sections of `server.config` per legacy `spamfilter_config_edit.php`; PUT MUST validate NOTEMPTY fields (`ip_address`, `netmask`, `gateway`, `hostname`, `nameservers`, `maildir_path`, `homedir_path`, …) and `module` ∈ `postfix_mysql`; there is NO delete endpoint; POST behavior per C-8 resolution.

**Routing & server-level filtering (US5)**

- **FR-030**: `/mail/transports` CRUD MUST validate `domain` (normalized, unique per server, not an existing `mail_domain`), accept `transport` as a raw Postfix transport string, `sort_order` per C-5, and treat `domain`+`server_id` as immutable on update (legacy reverts server_id changes).
- **FR-031**: `/mail/relay-domains` CRUD MUST validate `domain` (ISDOMAIN, unique per server, not otherwise in use) and set the hidden `access='OK'` default; contract allows updating only `active`.
- **FR-032**: `/mail/relay-recipients` CRUD MUST require `source` (email/domain/pattern per schema regex), default `access='OK'` and hidden `active='y'`; only `access` mutable per contract.
- **FR-033**: `/mail/access-rules` CRUD MUST require `source`, default `access='REJECT'` and `type='recipient'`, accept `type` ∈ recipient/sender/client, enforce source+type uniqueness per server, and keep `server_id` immutable on update.
- **FR-034**: `/mail/content-filters` CRUD MUST require `pattern`, accept `type` ∈ header/body/mime_header/nested_header and `action` ∈ the 10 Postfix actions, optional `data`, `active` default y, `server_id` immutable on update.
- **FR-035**: `/mail/get` CRUD MUST validate `type` ∈ pop3/imap/pop3ssl/imapssl, `source_server` against the legacy host/IP regex, require `source_username`/`source_password` (password write-only; on update only re-set when provided), require the delivery mailbox to be an existing `mail_user` email (C-3), default `source_delete='n'`, `source_read_all='y'`, `active='y'`, and keep `server_id` immutable on update.
- **FR-036**: For all US5 resources plus spamfilter users/wblist/config, caller-supplied `server_id` MUST reference a mail server (`server.mail_server=1`, `mirror_server_id=0`); violations ⇒ 400/422 per the operation's declared codes.
- **FR-037**: Write-only secrets (`mail_user.password`, `mail_get.source_password`) MUST be `$hidden` on models and absent from all responses and datalog-visible plaintext (hash before save; `source_password` is stored as legacy stores it — plaintext column — but never echoed).

### Key Entities *(include if feature involves data)*

| Entity | Represents | Table (PK) | OpenAPI schema | Model (future) |
|---|---|---|---|---|
| **MailUser** | Mailbox account | `mail_user` (`mailuser_id`) | `MailUser.yaml` (+ `MailUserAutoresponder/CC/Password/SpamFilter.yaml` sub-views) | `app/Models/MailUser.php` |
| **MailUserFilter** | Per-mailbox sorting rule | `mail_user_filter` (`filter_id`) | `MailUserFilter.yaml` | `app/Models/MailUserFilter.php` |
| **MailForwarding** | Forward/alias/catchall/alias-domain row | `mail_forwarding` (`forwarding_id`), `type` ∈ alias/aliasdomain/forward/catchall | `MailForwarding.yaml`, `MailAliasDomain.yaml` (C-1) | `app/Models/MailForwarding.php` |
| **MailGet** | Fetchmail (remote POP3/IMAP retrieval) config | `mail_get` (`mailget_id`) | `MailGet.yaml` | `app/Models/MailGet.php` |
| **MailTransport** | Postfix transport-map entry | `mail_transport` (`transport_id`) | `MailTransport.yaml` | `app/Models/MailTransport.php` |
| **MailRelayDomain** | Relay domain | `mail_relay_domain` (`relay_domain_id`) | `MailRelayDomain.yaml` | `app/Models/MailRelayDomain.php` |
| **MailRelayRecipient** | Relay recipient pattern | `mail_relay_recipient` (`relay_recipient_id`) | `MailRelayRecipient.yaml` | `app/Models/MailRelayRecipient.php` |
| **MailAccess** | Sender/recipient/client access (black/white) rule | `mail_access` (`access_id`) | `MailAccess.yaml` | `app/Models/MailAccess.php` |
| **MailContentFilter** | Header/body content filter rule | `mail_content_filter` (`content_filter_id`) | `MailContentFilter.yaml` | `app/Models/MailContentFilter.php` |
| **SpamfilterPolicy** | Amavis/Rspamd policy | `spamfilter_policy` (`id`) | `SpamfilterPolicy.yaml` | `app/Models/SpamfilterPolicy.php` |
| **SpamfilterUser** | Email→policy mapping | `spamfilter_users` (`id`) | `SpamfilterUser.yaml` | `app/Models/SpamfilterUser.php` |
| **SpamfilterWBList** | Per-user white/blacklist entry (`rid` → SpamfilterUser) | `spamfilter_wblist` (`wblist_id`) | `SpamfilterWBList.yaml` | `app/Models/SpamfilterWBList.php` |
| **SpamfilterConfig** | Per-server spamfilter/mail settings | `server.config` INI sections (`server_id`) | `SpamfilterConfig.yaml` | service-backed, no BaseModel (C-8) |

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: All 77 endpoints across the 18 `api/modules/mail/*.yaml` files respond as specified (paths, params, envelopes, status codes) — verified per resource against the YAML.
- **SC-002**: Every write produces a well-formed `sys_datalog` entry (correct `dbtable`, `dbidx`, action, diff-shaped `data`) that a stock ISPConfig server daemon processes without error — including the composite writes (mailbox → `spamfilter_users` sync; filter → `mail_user.custom_mailfilter` regeneration).
- **SC-003**: Swagger UI (`/api/documentation`) renders the whole mail module and "Try it out" succeeds for every new endpoint in a dev environment.
- **SC-004**: Behavior matches legacy ISPConfig for the documented parity cases: CRYPTMAIL hash verifiable with `crypt()` against a known password; maildir/uid/gid/login derivation; destination normalization; catchall/alias-domain source formats; per-resource defaults (`access`, `sort_order`, `priority`, `sys_perm_*`).
- **SC-005**: All Clarification items C-1…C-12 are resolved (schema fix or documented mapping) before their owning resource is marked done; no silent divergence between YAML and stored data remains.
- **SC-006**: `routes/web.php` ordering verified — every `mail/users/{id}/…` nested route registered before the general `mail/users/{id}` routes; no existing route shadowed; `vendor/bin/phpunit` passes.

## Assumptions

- `mail/domains` (specs/003) is implemented and its patterns — `MailDomainController` + `MailDomain` model, `{data, pagination}` list envelope, `getValidationRules($id)` update-relaxation, `YesNoBoolean` casts, transaction+log error handling — are the binding reference for this feature.
- Only the endpoints already specced in `api/modules/mail/` are in scope; mailing lists (`mail_mailinglist`), XMPP, backup lists and mail statistics have no contract files and are out of scope.
- Auth is the existing `X-API-Key` middleware (`api.auth`); the contract's role prose ("admin only", "users can only see their own") describes the legacy panel — the API key grants full module access, and per-record `sys_perm_*`/group filtering is not implemented in this feature (consistent with the implemented modules). The stray `security: basicAuth` entries in `user-cc.yaml`/`user-password.yaml`/`user-spamfilter.yaml` are contract noise inherited from generation and do not override the global API-key scheme.
- Legacy client/reseller limit checks (`limit_mailbox`, `limit_mailquota`, `limit_mailforward`, `limit_fetchmail`, `limit_mail_wblist`, `limit_spamfilter_wblist`, `limit_mailaliasdomain`) are session-bound panel features and are not enforced by the API.
- A populated `dbispconfig` database with at least one mail server row (`server.mail_server=1`) and its serialized `config` (for maildir path derivation and spamfilter config) is available in every environment where writes are exercised.
- Legacy behavior verified against the `source_code/` tree currently vendored in the repo (ISPConfig 3.2.x); `source_code/` remains read-only and untracked.
- Tests are optional per the constitution; this spec does not mandate them (Swagger-driven verification per story instead).
