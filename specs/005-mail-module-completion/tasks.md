# Tasks: Mail Module Completion

**Input**: Design documents from `/specs/005-mail-module-completion/`
**Prerequisites**: plan.md (required), spec.md (required for user stories — includes Parity notes and Clarifications C-1…C-12)

**Tests**: Tests are OPTIONAL in this project (see constitution) and the spec does not request them — no test tasks below; each story ends with Swagger + `sys_datalog` verification instead.

**Organization**: Tasks are grouped by user story (US1 mailboxes, US2 forwards/alias-domains, US3 mailbox sub-resources, US4 spamfilter admin, US5 routing/filtering admin) to enable independent implementation and verification of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1…US5)
- Include exact file paths in descriptions

## Path Conventions (this project)

| Artifact | Path |
|----------|------|
| OpenAPI endpoint spec | `api/modules/mail/[resource].yaml` (all 18 EXIST, registered in `api/modules/mail/_index.yaml`) |
| OpenAPI schema | `api/components/schemas/[Entity].yaml` (all EXIST; some need C-item corrections) |
| Model | `app/Models/[Entity].php` — **must extend `App\Models\BaseModel`** |
| Controller | `app/Http/Controllers/Api/V1/[Entity]Controller.php` |
| Service | `app/Services/[Name]Service.php` |
| Cast | `app/Casts/YesNoBoolean.php` (exists — reuse; uppercase `Y/N` and `W/B` fields stay strings) |
| Routes | `routes/web.php` — inside the `api.auth` group, specific-before-general order |
| Reference implementation | `app/Http/Controllers/Api/V1/MailDomainController.php` + `app/Models/MailDomain.php` |

**The per-resource implementation flow is always**: spec YAML (exists → verify/correct) → model → (service if needed) → controller → routes → Swagger verification.

---

## Phase 1: Setup (contract verification & clarification resolution)

**Purpose**: Contract and legacy research are locked down before any PHP is written. The legacy extraction itself is already done (spec.md → Parity section); what remains is resolving the contract↔legacy conflicts it exposed.

- [ ] T001 Verify the whole mail module renders in Swagger UI (`/api/documentation`): all 18 files under `api/modules/mail/` resolve their `$ref`s to `api/components/schemas/`, `api/components/parameters/` and `api/components/responses/`; fix any broken refs (no endpoint additions/removals).
- [ ] T002 [P] Resolve **C-1** (alias domains): correct `api/components/schemas/MailAliasDomain.yaml` mapping metadata (`x-db-table: mail_forwarding`, PK `forwarding_id`, persisted `type` is always `aliasdomain`) or record the controller-boundary mapping decision in `specs/005-mail-module-completion/spec.md`; decide fate of the schema's `alias|forward` type field.
- [ ] T003 [P] Resolve **C-2** (mail user filters): align `api/components/schemas/MailUserFilter.yaml` enums/field names with legacy stored values (`source`: Subject/From/To/List-Id/Header/Size; `op`: contains/is/begins/ends/regex/localpart/domain; `action`: move/delete/keep/reject; `action_value` → DB column `target`) or specify the translation table in spec.md.
- [ ] T004 [P] Resolve minor schema corrections **C-3, C-4, C-5, C-11, C-12** in `api/components/schemas/`: `MailGet.yaml` (`destination_username` → `destination` = existing mailbox email; add `source_delete`-sibling `source_read_all`, default y), `MailRelayRecipient.yaml` (add hidden/defaulted `active`), `MailRelayDomain.yaml` (note server-side `access='OK'` default), phantom `created`/`modified`/`created_at`/`updated_at` fields (drop or mark unsupported), `MailTransport.sort_order` default 5, password minLength harmonization (5 vs 8), `sys_perm` → `sys_perm_user` naming.
- [ ] T005 Record maintainer decisions for **C-6** (quota unit + `-1` semantics), **C-8** (`mail/spamfilter/config` write path + POST semantics; no DELETE) and confirm **C-9** (list envelope = `{data, pagination}` per module YAMLs, MailDomainController pattern, honoring `limit`/`offset`/`sort`/`order` declared params) in `specs/005-mail-module-completion/spec.md` → Clarifications.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Shared models/services needed by more than one user story, plus datalog sanity for the highest-traffic entity.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [ ] T006 [P] Create `app/Models/MailUser.php` extending `BaseModel`: `$table='mail_user'`, `$primaryKey='mailuser_id'`, `$fillable` covering contract + derived fields (email, login, password, name, quota, cc, forward_in_lda, sender_cc, sender_bcc, maildir, maildir_format, homedir, uid, gid, autoresponder*, move_junk, purge_*_days, custom_mailfilter, active, sys_*, server_id), `$hidden=['password']`, `YesNoBoolean` casts for y/n flags, `$attributes` defaults (active y, autoresponder n, autoresponder_subject 'Out of office reply', move_junk y, purge days 0, sys_perm riud/riud/''), static `getValidationRules($id)` following `app/Models/MailDomain.php`.
- [ ] T007 [P] Create `app/Models/MailForwarding.php` extending `BaseModel`: `$table='mail_forwarding'`, `$primaryKey='forwarding_id'`, fillable (server_id, source, destination, type, active, allow_send_as, greylisting, sys_*), type enum alias/aliasdomain/forward/catchall, defaults active y / greylisting n, scopes `scopeForwardTypes()` (forward,catchall,alias) and `scopeAliasDomains()` (type=aliasdomain), computed `is_catchall` accessor (source starts with `@`).
- [ ] T008 [P] Create `app/Models/SpamfilterUser.php` extending `BaseModel`: `$table='spamfilter_users'`, `$primaryKey='id'`, fillable (server_id, priority, policy_id, email, fullname, local, sys_*), defaults priority 5 / local 'Y' (note: mailbox auto-sync uses priority 7 — set explicitly by the service, spec Parity #6).
- [ ] T009 [P] Create `app/Services/MailPasswordService.php`: `hash(string $cleartext): string` implementing CRYPTMAIL — `mb_convert_encoding($pw,'ISO-8859-1','UTF-8')` then SHA-512 crypt with salt `$6$rounds=5000$` + 16 random hex chars (fallback `$5$`/`$1$` as `auth.inc.php::crypt_password` does).
- [ ] T010 Create `app/Services/MailUserService.php`: (a) resolve `mail_domain` from an email's domain part (400 source when missing); (b) derive server_id, maildir (`maildir_path` with `[domain]`/`[localpart]`), homedir, uid/gid (`-1/-1` when `mailbox_virtual_uidgid_maps=y`), maildir_format and default login from the server's mail config (read `server.config` INI); (c) force sys_groupid from the domain; (d) upsert the companion `spamfilter_users` row via the `SpamfilterUser` model (`priority=7`, `local='Y'`, `fullname`=IDN-decoded email, policy preserved/0) — mirrors `mail_user_edit.php:248-343`.
- [ ] T011 Confirm datalog behavior in a dev environment: `MailUser` create/update/delete produce `sys_datalog` rows with `dbtable=mail_user`, `dbidx=mailuser_id:<id>`, actions i/u/d and diff-shaped payloads via `App\Services\DatalogService`; companion `spamfilter_users` insert appears as its own datalog row.

**Checkpoint**: Foundation ready — user story implementation can now begin.

---

## Phase 3: User Story 1 — Provision and manage mailboxes (Priority: P1) 🎯 MVP

**Goal**: Full `/mail/users` CRUD + `/mail/users/{id}/password`, with legacy-parity derivation, hashing and spamfilter sync.

**Independent Test**: `POST /api/v1/mail/users` under an existing mail domain → 201, datalog `mail_user` i-row with `$6$rounds=5000$…` password and derived maildir + companion `spamfilter_users` i-row; then password rotate → 200 `{success,message}`; delete → 204 d-row.

### Implementation for User Story 1

- [ ] T012 [US1] Implement `index`/`show` in `app/Http/Controllers/Api/V1/MailUserController.php`: filters `email`, `domain` (email domain-part), `login`, `active`; pagination/sort per `MailDomainController` pattern (C-9 resolution); password never serialized.
- [ ] T013 [US1] Implement `store` in `app/Http/Controllers/Api/V1/MailUserController.php`: validate per spec FR-008 (email unique+normalized, login regex `/^[_a-z0-9][\w\.\-\+@]{1,63}$/`, password required, quota per C-6, domain must exist); reject when an active `mail_forwarding.source` equals the email (FR-011); hash via `MailPasswordService`; derive fields via `MailUserService`; DB transaction + rollback + `\Log::error` context; return 201 with created record.
- [ ] T014 [US1] Implement `update`/`destroy` in `app/Http/Controllers/Api/V1/MailUserController.php`: email/login immutable (C-7 — reject or ignore per decision), password re-hashed only when non-empty, `maildir_format` preserved, sys_groupid re-forced from domain, spamfilter_users re-synced (FR-012/013); destroy → 204 via model `delete()`.
- [ ] T015 [US1] Implement `update` in `app/Http/Controllers/Api/V1/MailUserPasswordController.php`: 404 when mailbox missing; validate `password` per `MailUserPassword.yaml` (minLength 8, C-11); hash + save only the password attribute; return `200 {"success":true,"message":"Password updated successfully"}`.
- [ ] T016 [US1] Register US1 routes in `routes/web.php` inside the `api.auth` group, after the `mail/domains` block: `PUT mail/users/{id}/password` FIRST, then `GET/POST mail/users`, `GET/PUT/DELETE mail/users/{id}` (leave a marked slot above `mail/users/{id}` for the US3 nested routes); verify no shadowing of existing routes.
- [ ] T017 [US1] Verify against Swagger UI: every operation in `api/modules/mail/users.yaml` and `api/modules/mail/user-password.yaml` works via "Try it out" (paths/params/status codes/envelopes exact); inspect `sys_datalog` rows for i/u/d + companion spamfilter_users writes.

**Checkpoint**: Mailboxes fully manageable — MVP delivered.

---

## Phase 4: User Story 2 — Forwards, catchalls, aliases & alias domains (Priority: P2)

**Goal**: `/mail/forwards` and `/mail/alias-domains` CRUD on the shared `mail_forwarding` table with legacy type semantics.

**Independent Test**: Create forward/catchall/alias + one alias domain; verify `mail_forwarding` datalog rows with types `forward`/`catchall`/`alias`/`aliasdomain`, normalized destinations, and server_id/sys_groupid inherited from the correct `mail_domain`.

### Implementation for User Story 2

- [ ] T018 [P] [US2] Implement `app/Http/Controllers/Api/V1/MailForwardingController.php` (index/show/store/update/destroy): scope to `type IN (forward,catchall,alias)`; filters `source`, `destination`, `type`, `active`; store per spec FR-015…FR-018 (source format per type, destination split/validate/re-join `', '`, active-mailbox collision guard, source-domain-derived server_id/sys_groupid, per-type `allow_send_as` defaults); source+type immutable on update; `is_catchall` in responses.
- [ ] T019 [P] [US2] Implement `app/Http/Controllers/Api/V1/MailAliasDomainController.php` (index/show/store/update/destroy): scope/force `type='aliasdomain'` (C-1); `@`-prefix + lowercase + punycode source/destination; both domains must exist in `mail_domain`; source ≠ destination; source unique; server_id + sys_groupid from the **destination** domain (spec Parity #11); source immutable on update.
- [ ] T020 [US2] Register routes in `routes/web.php`: `GET/POST mail/forwards`, `GET/PUT/DELETE mail/forwards/{id}`, `GET/POST mail/alias-domains`, `GET/PUT/DELETE mail/alias-domains/{id}` — placed after the mail/users block; ordering re-checked.
- [ ] T021 [US2] Swagger verification against `api/modules/mail/forwards.yaml` + `api/modules/mail/alias-domains.yaml`; datalog inspection confirms stored `type` values and normalized fields.

**Checkpoint**: US1 and US2 both work independently.

---

## Phase 5: User Story 3 — Mailbox self-service sub-resources (Priority: P3)

**Goal**: Nested autoresponder / CC / spamfilter-settings / filter-rules endpoints under `/mail/users/{id}`.

**Independent Test**: For one mailbox: PUT autoresponder (valid + invalid date window), PUT cc, PUT spamfilter settings, full filter CRUD — verifying the `mail_user` datalog updates and the `### BEGIN/END FILTER_ID` block lifecycle in `custom_mailfilter`.

### Implementation for User Story 3

- [ ] T022 [P] [US3] Create `app/Models/MailUserFilter.php` extending `BaseModel`: `$table='mail_user_filter'`, `$primaryKey='filter_id'`, fillable (mailuser_id, rulename, source, op, searchterm, action, target, active, sys_*), default active y, `YesNoBoolean` cast for active.
- [ ] T023 [US3] Create `app/Services/MailUserFilterService.php`: render a rule block in sieve or maildrop syntax per the owning server's `mail_filter_syntax` (port `mail_user_filter_plugin.inc.php::mail_user_filter_get_rule`), and `regenerate(MailUser $user, MailUserFilter $filter, bool $deleted)` that replaces/prepends/removes the `### BEGIN FILTER_ID:<id>` … `### END FILTER_ID:<id>` block in `custom_mailfilter` (inactive ⇒ block removed) and saves `MailUser` (datalog `u`) — spec Parity #9, FR-024.
- [ ] T024 [P] [US3] Implement `app/Http/Controllers/Api/V1/MailUserAutoresponderController.php`: `show` (autoresponder columns wrapped in `data`), `update` (validate datetimes, `end_date > start_date`; clear dates when `autoresponder=n`; response `{data, message}`), `destroy` (set `autoresponder='n'` + clear both dates, 204) — spec FR-020.
- [ ] T025 [P] [US3] Implement `app/Http/Controllers/Api/V1/MailUserCCController.php`: `show`/`update` for `cc` (comma-separated email regex from `mail_user.tform.php:186`, lowercase/punycode) and `forward_in_lda` — spec FR-021.
- [ ] T026 [P] [US3] Implement `app/Http/Controllers/Api/V1/MailUserSpamFilterController.php`: `show`/`update` for `move_junk` (y/a/n), `purge_trash_days`/`purge_junk_days` (int ≥ 0), `custom_mailfilter` — spec FR-022.
- [ ] T027 [US3] Implement `app/Http/Controllers/Api/V1/MailUserFilterController.php` (index/store/show/update/destroy): every operation scoped to `mailuser_id = {id}` (mismatch ⇒ 404); validate per C-2 resolution + rulename ≤ 64 chars, searchterm required, target regex `/^[\p{Latin}0-9\.\'\-\_\ \&\/]{0,100}$/u`; call `MailUserFilterService` after each write inside the same transaction — spec FR-023/024.
- [ ] T028 [US3] Register nested routes in `routes/web.php` in the reserved slot ABOVE `mail/users/{id}`: `GET/PUT/DELETE mail/users/{id}/autoresponder`, `GET/PUT mail/users/{id}/cc`, `GET/PUT mail/users/{id}/spamfilter`, `GET/POST mail/users/{id}/filters`, `GET/PUT/DELETE mail/users/{id}/filters/{filter_id}` (password route already present from T016); re-verify specific-before-general ordering for the whole `mail/users` block.
- [ ] T029 [US3] Swagger verification against `user-autoresponder.yaml`, `user-cc.yaml`, `user-spamfilter.yaml`, `user-filters.yaml`; datalog inspection confirms paired `mail_user_filter` + `mail_user` (custom_mailfilter) entries.

**Checkpoint**: Mailbox self-service complete; US1–US3 independently verifiable.

---

## Phase 6: User Story 4 — Spam filtering administration (Priority: P4)

**Goal**: `/mail/spamfilter/{policies,users,wblist}` CRUD + `/mail/spamfilter/config` read/update.

**Independent Test**: Create policy → map an email to it → add W/B entries with `rid` referencing the mapping; GET/PUT a server's spamfilter config; verify datalog rows on the three spamfilter tables and the C-8-decided write path for config.

### Implementation for User Story 4

- [ ] T030 [P] [US4] Create `app/Models/SpamfilterPolicy.php` extending `BaseModel`: `$table='spamfilter_policy'`, `$primaryKey='id'`, fillable = contract-exposed subset (policy_name, 4 `*_lover`, 3 `bypass_*_checks`, 5 `*_quarantine_to`) + sys_*; defaults `N` for flags and `sys_perm_other='r'` (spec Parity #17); unexposed legacy columns keep DB defaults.
- [ ] T031 [P] [US4] Create `app/Models/SpamfilterWBList.php` extending `BaseModel`: `$table='spamfilter_wblist'`, `$primaryKey='wblist_id'`, fillable (server_id, wb, rid, email, priority, active, sys_*), defaults wb 'B', priority 5, active y.
- [ ] T032 [P] [US4] Create `app/Services/ServerIniConfigService.php`: parse/serialize the ISPConfig INI format of `server.config` (port `ini_parser` semantics), `getSections(int $serverId, array $sections)` and `putSections(int $serverId, array $data)` restricted to the `server` + `mail` sections used by `spamfilter_config.tform.php`; write path per C-8 decision (documented Complexity Tracking exception).
- [ ] T033 [US4] Implement `app/Http/Controllers/Api/V1/SpamfilterPolicyController.php` (index/store/show/update/destroy): `policy_name` required + unique; `destroy` returns 400 `{message,error}` when any `spamfilter_users.policy_id` references the policy (FR-025/026).
- [ ] T034 [US4] Implement `app/Http/Controllers/Api/V1/SpamfilterUserController.php` (index/store/show/update/destroy): filters `email`, `server_id`, `policy_id`; validate per FR-027 (email unique/normalized, fullname required, local Y/N, priority 1–10, policy_id exists or 0=inherit → 404 for missing reference per YAML); email/server_id immutable on update; server_id must be a mail server (FR-036).
- [ ] T035 [US4] Implement `app/Http/Controllers/Api/V1/SpamfilterWBListController.php` (index/store/show/update/destroy): filters `email`, `wb`, `rid`, `active`; validate per FR-028 (wb W/B, priority 1–10, non-zero rid must reference `spamfilter_users` → 404); email/rid immutable on update; document rid=0 Rspamd-inert behavior (C-10) in the controller docblock.
- [ ] T036 [US4] Implement `app/Http/Controllers/Api/V1/SpamfilterConfigController.php`: `index` (mail servers with `hostname` filter, config sections merged per `SpamfilterConfig.yaml`), `show` by `{server_id}` (404 for non-mail-server), `update` (validate NOTEMPTY fields + `module=postfix_mysql`, write via `ServerIniConfigService`), `store` per C-8 decision — spec FR-029; no destroy method.
- [ ] T037 [US4] Register routes in `routes/web.php`: `GET/POST mail/spamfilter/config`, `GET/PUT mail/spamfilter/config/{server_id}`, then full CRUD for `mail/spamfilter/policies`, `mail/spamfilter/users`, `mail/spamfilter/wblist` (`…/{id}` routes after their collection routes); ordering re-checked.
- [ ] T038 [US4] Swagger verification against `spamfilter-config.yaml`, `spamfilter-policies.yaml`, `spamfilter-users.yaml`, `spamfilter-wblist.yaml` — including the absent DELETE on config and the policy in-use 400.

**Checkpoint**: Spamfilter administration complete.

---

## Phase 7: User Story 5 — Mail routing & server-level filtering (Priority: P5)

**Goal**: `/mail/transports`, `/mail/relay-domains`, `/mail/relay-recipients`, `/mail/access-rules`, `/mail/content-filters`, `/mail/get` CRUD.

**Independent Test**: Create one record per resource and verify each `sys_datalog` insert carries legacy defaults (`sort_order=5`, `access=OK/REJECT`, `source_read_all=y`, `active=y`) and immutability rules hold on update.

### Implementation for User Story 5

- [ ] T039 [P] [US5] Create `app/Models/MailTransport.php` extending `BaseModel` (`mail_transport`/`transport_id`; fillable server_id, domain, transport, sort_order, active, sys_*; default sort_order 5 per C-5, active y).
- [ ] T040 [P] [US5] Create `app/Models/MailRelayDomain.php` (`mail_relay_domain`/`relay_domain_id`; fillable server_id, domain, access, active, sys_*; defaults access 'OK', active y — C-4).
- [ ] T041 [P] [US5] Create `app/Models/MailRelayRecipient.php` (`mail_relay_recipient`/`relay_recipient_id`; fillable server_id, source, access, active, sys_*; defaults access 'OK', active y — C-4).
- [ ] T042 [P] [US5] Create `app/Models/MailAccess.php` (`mail_access`/`access_id`; fillable server_id, source, access, type, active, sys_*; defaults access 'REJECT', type 'recipient', active y).
- [ ] T043 [P] [US5] Create `app/Models/MailContentFilter.php` (`mail_content_filter`/`content_filter_id`; fillable server_id, type, pattern, data, action, active, sys_*; default active y).
- [ ] T044 [P] [US5] Create `app/Models/MailGet.php` (`mail_get`/`mailget_id`; fillable server_id, type, source_server, source_username, source_password, source_delete, source_read_all, destination, active, sys_*; `$hidden=['source_password']`; defaults source_delete n, source_read_all y, active y).
- [ ] T045 [P] [US5] Implement `app/Http/Controllers/Api/V1/MailTransportController.php`: filters `domain`, `transport`, `active`; domain normalized + unique per server + not an existing `mail_domain` (spec Parity #12); domain/server_id immutable on update (FR-030).
- [ ] T046 [P] [US5] Implement `app/Http/Controllers/Api/V1/MailRelayDomainController.php`: filters `domain`, `active`; ISDOMAIN + unique-per-server validation; hidden `access='OK'`; contract allows only `active` on update (FR-031).
- [ ] T047 [P] [US5] Implement `app/Http/Controllers/Api/V1/MailRelayRecipientController.php`: filters `source`, `access`; source required + schema pattern; only `access` mutable per contract (FR-032).
- [ ] T048 [P] [US5] Implement `app/Http/Controllers/Api/V1/MailAccessController.php`: filters `source`, `type`, `access`, `active`; source required; type recipient/sender/client; source+type unique per server; server_id immutable on update (FR-033); note rspamd global-wblist side effect (spec Parity #14) in docblock.
- [ ] T049 [P] [US5] Implement `app/Http/Controllers/Api/V1/MailContentFilterController.php`: filters `type`, `action`, `active`; pattern required; type/action enums per schema; server_id immutable on update (FR-034).
- [ ] T050 [P] [US5] Implement `app/Http/Controllers/Api/V1/MailGetController.php`: filters `type`, `source_server`, `active`; validate per FR-035 (type enum, source_server host/IP regex, credentials required, delivery mailbox must be an existing `mail_user` email per C-3, password re-set only when provided, write-only in responses); server_id immutable on update.
- [ ] T051 [US5] Register routes in `routes/web.php`: full CRUD blocks for `mail/transports`, `mail/relay-domains`, `mail/relay-recipients`, `mail/access-rules`, `mail/content-filters`, `mail/get` (collection routes before `…/{id}`); verify `mail/get` (static path) and overall module ordering; all `server_id` inputs validated as mail servers (FR-036) inside the controllers.
- [ ] T052 [US5] Swagger verification against `transports.yaml`, `relay-domains.yaml`, `relay-recipients.yaml`, `access-rules.yaml`, `content-filters.yaml`, `get.yaml`; datalog inspection confirms defaults and hidden columns (`access`, `active`, `source_read_all`).

**Checkpoint**: All 18 resources live.

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: Consistency and final verification across the whole module.

- [ ] T053 [P] Update `README.md` endpoint list with the 77 new mail endpoints (grouped per resource).
- [ ] T054 Code cleanup: controllers thin (validation + HTTP only), derivations/side effects in `app/Services/` (`MailUserService`, `MailPasswordService`, `MailUserFilterService`, `ServerIniConfigService`), y/n handling via `app/Casts/YesNoBoolean.php` only — no ad-hoc conversions.
- [ ] T055 Re-verify legacy parity for the documented cases in spec.md → Parity #1–#19 (CRYPTMAIL hash checks against `crypt()`, maildir/login/uid derivation, destination normalization, catchall/aliasdomain formats, per-resource defaults, autoresponder date clearing, filter block regeneration) and confirm every C-1…C-12 item is closed in spec.md.
- [ ] T056 Final `routes/web.php` audit: nested `mail/users/{id}/…` before `mail/users/{id}`; no shadowing anywhere in the `api.auth` group; full Swagger render of the module.
- [ ] T057 Run `vendor/bin/phpunit` — full existing suite must pass.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — clarifications C-1…C-12 gate their owning resources (T002→US2/T019; T003→US3/T027; T004→US5/T050 etc.; T005→US1 quota/US4 config/all list envelopes).
- **Foundational (Phase 2)**: Depends on Setup — BLOCKS all user stories (`MailUser`, `MailForwarding`, `SpamfilterUser` models + password/user services are shared).
- **User Stories (Phases 3–7)**: All depend on Phase 2. Proceed in priority order P1→P5; US3 additionally depends on US1's routes slot (T016) and the `MailUser` model; US4's mailbox-sync interplay depends on T010; US2/US5 are otherwise independent of US3/US4.
- **Polish (Phase 8)**: After all desired stories.

### Within Each User Story

- Spec YAML correction (Phase 1 item) before controller work (Principle I)
- Model before service; service before controller; controller before routes
- Swagger + datalog verification is each story's last task

### Parallel Opportunities

- All Phase 1 clarification tasks T002–T004 [P]
- Phase 2 models T006–T009 [P] (T010 waits on T006/T008)
- Within US2: T018 ∥ T019 (different controllers)
- Within US3: T024–T026 [P]; T022 ∥ T024–T026
- Within US4: T030–T032 [P]
- Within US5: T039–T044 [P], then T045–T050 [P]
- **Never parallel**: any two edits to `routes/web.php` (T016, T020, T028, T037, T051, T056 are strictly sequential)

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Phase 1 (at minimum T001 + T005's quota/envelope decisions)
2. Phase 2 completely (models + services + datalog check)
3. Phase 3 (US1)
4. **STOP and VALIDATE**: Swagger "Try it out" for `/mail/users` + `/password`; inspect `sys_datalog` (mail_user + spamfilter_users rows); confirm a stock ISPConfig server provisions the mailbox
5. Deliver; then US2 → US3 → US4 → US5 incrementally, re-checking `routes/web.php` ordering at every story boundary

### Notes

- [P] tasks = different files, no dependencies
- Every write path goes through `BaseModel::save()`/`delete()` — the sole documented exception is `server.config` in T032/T036 (C-8, Complexity Tracking)
- Commit after each task or logical group
- Avoid: endpoints not present in the YAMLs, storing contract enum values that legacy daemons can't process (C-2), silent schema/legacy divergence
