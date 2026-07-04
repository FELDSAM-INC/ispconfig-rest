# Tasks: Client-Scoped Permissions

**Input**: Design documents from `/specs/011-client-scoped-permissions/`
**Prerequisites**: plan.md (required), spec.md (required for user stories)

**Status**: P1 + P2 implemented and green (2026-07-05, full suite 608 tests). US3 (P3) is DEFERRED to feature 012 per the pre-authorized deferral gate — T033–T037 stay unchecked; the P2-mandated FR-017 access-gate subset (T028) shipped, with `app/Services/ClientLimitService.php` carrying the gate now and reserved as the home for the full `checkCreate` counting parity in 012. T038 (README) left open for the docs pass.

**Tests**: Tests are REQUIRED (constitution v2). This feature's tests are authorization matrices (admin / owner client / other client / reseller keys) per module plus datalog-side-effect assertions on denials (denied writes must leave `sys_datalog` untouched). Run with `vendor/bin/phpunit`.

**Organization**: Tasks are grouped by user story (US1 = P1 read scoping, US2 = P2 write enforcement + module gates + key ergonomics, US3 = P3 client limits — deferrable as a block to feature 012 per spec Assumptions).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1, US2, US3)

## Path Conventions (this project)

| Artifact | Path |
|----------|------|
| OpenAPI specs (existing — response-map-only amendment pending sign-off) | `api/modules/{server,system,monitor,mail}/*.yaml` (65 flagged ops, spec API Contract table) |
| Auth scope value object | `app/Support/AuthScope.php` (new) |
| Request context | `app/Support/IspContext.php` (modify) |
| Auth middleware | `app/Http/Middleware/ApiKeyAuth.php` (modify); `app/Http/Middleware/RequireAdmin.php`, `app/Http/Middleware/RequireAdminOrReseller.php` (new) |
| Core model hooks | `app/Models/BaseModel.php` (modify: readable scope, binding override, u/d gate, sys-field forcing) |
| List pipeline | `app/Http/Concerns/HandlesListQuery.php` (modify) |
| Middleware aliases + 403 exception mapping | `bootstrap/app.php` (modify) |
| Routes | `routes/api.php` + `routes/api/{client,mail,dns}.php` (middleware wrappers only — no new routes, ordering untouched) |
| Limits service (P3) | `app/Services/ClientLimitService.php` (new) |
| CLI | `app/Console/Commands/CreateApiKey.php` (modify) |
| Test support | `tests/Support/TenantSchema.php`, `tests/Support/TenantFixtures.php` (new) |
| Tests (REQUIRED) | `tests/Feature/AuthScopeTest.php`, `ScopedBindingTest.php`, `ModuleGateTest.php`, `Scoping{Mail,Dns,Sites,Client}ModuleTest.php`, `ClientLimitTest.php`, `CreateApiKeyClientIdTest.php` |

**Legacy references for every behavioral question**: spec.md Parity section (file:line into `source_code/` — read-only, never modify).

---

## Phase 1: Setup

**Purpose**: Clarifications resolved and contract question settled before enforcement code is written

- [x] T001 Resolve spec NEEDS CLARIFICATION #1 (key bound to `sys_userid` with no `sys_user` row: degrade to `{sys_groupid}`-only scope vs 401) and record the decision in `specs/011-client-scoped-permissions/spec.md` (FR-005) — owner decision: **401, fail-closed**, same problem body as an invalid key; dev key stays a synthetic admin
- [x] T002 Obtain owner sign-off on the additive-403 contract amendment for the 65 flagged operations (spec API Contract table) and record the outcome in `specs/011-client-scoped-permissions/spec.md` (FR-024); if declined, re-scope the module-gate rollout decision before T024
- [x] T003 [P] (ONLY if T002 approves) Append the shared `Forbidden` response ref (`api/components/responses/_index.yaml` → `Forbidden`) to the 65 flagged operations in `api/modules/server/{servers,server-config,ip-addresses,ip-mappings,php-versions,firewall}.yaml`, `api/modules/system/{system-config,sites-config,mail-config,dns-config,domains-config,misc-config,dns-cas,directive-snippets,resync}.yaml`, `api/modules/monitor/{data-logs,server-status,system-logs}.yaml` — response maps only, no path/parameter/shape edits; verify Swagger UI still renders (`/api/documentation`)
- [x] T004 [P] Re-verify the parity citations in `specs/011-client-scoped-permissions/spec.md` against `source_code/interface/lib/classes/tform_base.inc.php:1742-1769` (getAuthSQL), `tform.inc.php:68-108,183-250` (checkPerm, limits), `tform_actions.inc.php:328-332` (delete gate), `listform_actions.inc.php:242-247` (list scoping), `auth.inc.php:42-211` (admin/reseller/module checks) — read-only reference

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: AuthScope + scoping infrastructure + test fixtures that every user story builds on

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [x] T005 Create `app/Support/AuthScope.php`: immutable value object with `sysUserId`, `sysGroupId`, `groupIds` (int[]), `isAdmin`; `isReseller()` lazy via `client.limit_client != 0` joined over `sys_user.client_id` (parity auth.inc.php:69-80); `applyReadPredicate(Builder $q, string $perm)` building the legacy triplet `(sys_userid = ? AND sys_perm_user LIKE '%r%') OR (sys_groupid IN (...) AND sys_perm_group LIKE '%r%') OR sys_perm_other LIKE '%r%'` (parity tform_base.inc.php:1750-1765, containment semantics); `allows(array $rowAttributes, string $perm): bool` in-memory checkPerm equivalent (parity tform.inc.php:81-86)
- [x] T006 Modify `app/Support/IspContext.php`: hold the resolved `AuthScope`; `authScope()` accessor returning an admin 1/1 scope by default (CLI/tests — FR-025); `actAsScope(AuthScope $scope)` setter used by the middleware
- [x] T007 Modify `app/Http/Middleware/ApiKeyAuth.php`: after `actAs()`, resolve the AuthScope — `sys_userid == 1` short-circuits to admin (no query, dev-key path unchanged); otherwise one `sys_user` read (`typ`, `groups`, `client_id`), `typ === 'admin'` ⇒ admin, else groupIds = CSV ∪ `{sys_groupid}`; missing-row behavior per T001 decision — and park it via `IspContext::actAsScope()`
- [x] T008 [P] Create `tests/Support/TenantSchema.php`: guarded (`Schema::hasTable`) SQLite creates for `sys_user` (userid, username, typ, modules, groups, default_group, client_id, active — columns verbatim from `source_code/install/sql/ispconfig3.sql:1852-1880`), `sys_group` (:1734-1740), and the `client` column subset used by scoping/limits (client_id, parent_client_id, limit_client + the FR-020 limit columns, defaults per ispconfig3.sql:139-260)
- [x] T009 Create `tests/Support/TenantFixtures.php` trait: seeds admin (userid 1), client A, client B, reseller R (`groups = "groupR,groupA"`, `client.limit_client = -1`) with their `sys_group`/`client` rows; mints real hashed keys via `ApiKey::mint()` for each; exposes `headersFor('admin'|'clientA'|'clientB'|'reseller')` and `ownedBy('clientA', array $attrs)` sys-field stampers for seeding scoped rows
- [x] T010 [P] Create `tests/Feature/AuthScopeTest.php`: predicate SQL for a client scope (three clauses, group expansion incl. multi-group CSV), admin bypass (userid 1 with no `sys_user` row; `typ='admin'` non-1 user), `allows()` letter containment (`'ru'` grants r+u, denies d), `isReseller()` truth table, T001-decision behavior — RED first, drives T005-T007

**Checkpoint**: AuthScope resolvable per request; fixtures ready — user story implementation can now begin

---

## Phase 3: User Story 1 - Row-level read scoping (Priority: P1) 🎯 MVP

**Goal**: Non-admin keys see only AUTHSQL-visible rows: lists silently filtered, invisible ids 404 everywhere (show/update/delete/nested), admin behavior byte-identical

**Independent Test**: Two seeded tenants + reseller; A's key lists only A's mail domains (`meta.total` = 1), `GET /mail/domains/{B}` → 404, reseller sees A's rows, admin sees all — with zero controller edits

### Tests for User Story 1 (REQUIRED) ⚠️

- [x] T011 [P] [US1] Create `tests/Feature/ScopedBindingTest.php`: on a representative resource (mail/domains) — show/update/delete of another tenant's id → 404 problem+json and NO `sys_datalog` row; own id → 200; nested parent scoping: `mail/users/{B's user}/filters` → 404 for A's key; `sys_perm_other='r'` row visible to both tenants
- [x] T012 [P] [US1] Create `tests/Feature/ScopingMailModuleTest.php`: 4-key matrix (admin/A/B/reseller) over `mail/domains`, `mail/users`, `mail/forwards`, `mail/alias-domains`, `mail/fetchmail`, `mail/spamfilter/wblist`, `mail/transports`, `mail/access-rules` lists + shows; spamfilter policies world-read case (seeded `sys_perm_other='r'`, `sys_groupid=0` per ispconfig3.sql:2523-2527); `meta.total` counts visible rows only
- [x] T013 [P] [US1] Create `tests/Feature/ScopingDnsModuleTest.php`: same matrix over `dns/soa`, `dns/records`, `dns/slaves` (+ `dns/templates` reads row-scoped); zone of B invisible to A incl. its records
- [x] T014 [P] [US1] Create `tests/Feature/ScopingSitesModuleTest.php`: same matrix over `sites/web-domains`, `sites/web-child-domains`, `sites/ftp-users`, `sites/shell-users`, `sites/databases`, `sites/database-users`, `sites/cron-jobs`, `sites/web-folders`(+users), `sites/webdav-users`; nested `sites/web-domains/{id}/ssl` scoping through parent
- [x] T015 [P] [US1] Create `tests/Feature/ScopingClientModuleTest.php` (read half): reseller key lists only its own clients/templates/circles/domains; other clients' rows 404 by id (uses admin+reseller keys; plain-client 403 assertions land in US2's gate tests)

### Implementation for User Story 1

- [x] T016 [US1] Modify `app/Models/BaseModel.php` (read half): `scopeReadable(Builder $q)` delegating to `AuthScope::applyReadPredicate($q, 'r')` — no-op for admin scope or `hasSysFields = false`; override `resolveRouteBindingQuery($query, $value, $field)` to apply `readable()` so implicit bindings 404 on unauthorized ids (FR-008/FR-009); MUST NOT touch `freshRecordFromDatabase()`/datalog paths (FR-010)
- [x] T017 [US1] Modify `app/Http/Concerns/HandlesListQuery.php`: in `listQuery()`, apply `readable()` when the builder's model is a sys-fielded `BaseModel` and `IspContext::authScope()` is non-admin — before filters and the `getCountForPagination()` so `meta.total` counts visible rows only (FR-007)
- [x] T018 [US1] Audit non-listQuery/non-binding reads in client-accessible module controllers (`app/Http/Controllers/Api/V1/` — grep `::query()`/`DB::table` outside admin-gated controllers; known candidates: `MailUserFilterController` child queries, `WebDomainSslController`, `MailUserAutoresponderController`/`CC`/`Password`/`SpamFilter`, `ClientTemplateAssignmentController`): confirm each is reached only through a scoped parent binding, add `readable()` where any stands alone — record findings in the PR description. **Findings (2026-07-05)**: no standalone reads needed `readable()`. Every `X::query()` list feeds `listQuery()` (chokepoint); all `mail/users/{id}/*`, `sites/web-domains/{id}/ssl`, `clients/{id}/templates` sub-resources reach rows only through the scoped parent binding; remaining ad-hoc reads are intentionally-global FR-010 checks (uniqueness: DnsSoa origin, DnsSlave origin+server, MailRelayDomain duplicate, ftp/shell/web-folder-user username collisions; existence: SpamfilterWBList rid→spamfilter_users, SpamfilterUser policy_id, ClientTemplateAssignment template, MailAliasDomain destination-domain lookup, ClientDomain sys_group filter helper). Cross-tenant payload references (e.g. `parent_domain_id` exists-rules accepting another tenant's vhost) are field-level restrictions — explicitly out of scope per spec Assumptions, flagged for the field-level parity follow-up.
- [x] T019 [US1] Run the full suite (`vendor/bin/phpunit`): T011-T015 green; all pre-existing tests still green (admin regression bar, SC-005)

**Checkpoint**: Read confidentiality boundary complete and independently shippable

---

## Phase 4: User Story 2 - Write enforcement, module gates, key ergonomics (Priority: P2)

**Goal**: Non-admin writes stamped and permission-checked (403 visible-but-forbidden / 404 invisible); server/system/monitor/resellers admin-only; /clients/** admin-or-reseller; admin-menu-only mail/dns write surfaces gated; `api:key:create --client-id`

**Independent Test**: A's key: POST /mail/domains with forged `sys_userid` → 201 stamped A; PUT on `perm_other='r'` row → 403; GET /servers → 403; reseller creates a client that lands in its group CSV; `--client-id` mints the right binding

### Tests for User Story 2 (REQUIRED) ⚠️

- [x] T020 [P] [US2] Create `tests/Feature/ModuleGateTest.php`: client and reseller keys get 403 problem+json on ≥ 1 operation per admin-only YAML file (`/servers`, `/servers/{id}/configs/server`, `/servers/{id}/{ip-addresses,ip-mappings,php-versions,firewall}`, `/system/config`, `/system/directive-snippets`, `/system/dns-cas`, `/system/resync`, `/monitor/data-logs`, `/monitor/servers/status`, `/monitor/system-logs`, `/resellers`) (SC-003); client key 403 on `/clients` while reseller passes; admin key unaffected on every one
- [x] T021 [P] [US2] Extend `tests/Feature/ScopingMailModuleTest.php` + `ScopingDnsModuleTest.php` + `ScopingSitesModuleTest.php` (write half): forced sys-field stamping on POST despite forged payload values (SC-002); PUT/DELETE on visible-but-unpermitted row → 403 with no datalog row (SC-006, spamfilter-policy fixture); PUT/DELETE on invisible row → 404; admin-menu-only gates — client POST to `mail/relay-domains`, `mail/relay-recipients`, `mail/content-filters`, `mail/spamfilter/policies`, `mail/spamfilter/users`, `dns/templates` → 403; `mail/transports`/`mail/access-rules`/`mail/spamfilter/wblist` POST → 403 with zero/absent limit, 201 with `limit_mailrouting`/`limit_mail_wblist`/`limit_spamfilter_wblist` = -1 (FR-017)
- [x] T022 [P] [US2] Extend `tests/Feature/ScopingClientModuleTest.php` (write half): reseller `POST /clients` → new client's group appended to reseller's `groups` CSV and `parent_client_id` forced to reseller's client id (FR-018); reseller cannot update/delete another reseller's client (404); `POST /resellers` by reseller → 403
- [x] T023 [P] [US2] Create `tests/Feature/CreateApiKeyClientIdTest.php`: `api:key:create --client-id` resolves the seeded client's sys pair; unknown client id → non-zero exit + message; `--client-id` combined with `--sys-userid` → validation error (SC-008)

### Implementation for User Story 2

- [x] T024 [US2] Create `app/Http/Middleware/RequireAdmin.php` and `app/Http/Middleware/RequireAdminOrReseller.php` (403 problem+json via `App\Support\Problem` when `IspContext::authScope()` fails the check); register aliases `scope.admin`/`scope.reseller` and the `AuthorizationException` → 403 problem+json rendering in `bootstrap/app.php`
- [x] T025 [US2] Modify `routes/api.php`: wrap the `require` lines for `routes/api/server.php`, `routes/api/system.php`, `routes/api/monitor.php` in `Route::middleware('scope.admin')->group(...)` (FR-013/FR-014) — no route reordering
- [x] T026 [US2] Modify `routes/api/client.php`: `scope.admin` on the `resellers*` registrations (FR-015); `scope.reseller` on all remaining client-module registrations (FR-016)
- [x] T027 [US2] Modify `routes/api/mail.php` and `routes/api/dns.php`: `scope.admin` on write registrations of `mail/relay-domains`, `mail/relay-recipients`, `mail/content-filters`, `mail/spamfilter/config*`, `mail/spamfilter/policies`, `mail/spamfilter/users`, `dns/templates` (FR-017 hard gates; reads stay row-scoped); leave `mail/transports`/`mail/access-rules`/`mail/spamfilter/wblist` writes to the limit-gate in T028
- [x] T028 [US2] Implement the FR-017 limit-access gate for `mail/transports`, `mail/access-rules`, `mail/spamfilter/wblist` creates (non-admin: acting client's `limit_mailrouting`/`limit_mail_wblist`/`limit_spamfilter_wblist` must be non-zero; parity mail/lib/module.conf.php:58-77,104-113 + mail_transport_edit.php:60) — smallest faithful placement per plan (store()-path check or a `scope.limit:{name}` middleware parameter; keys without a client row are unlimited per auth.inc.php:139-141)
- [x] T029 [US2] Modify `app/Models/BaseModel.php` (write half): `save()` on existing rows and `delete()` throw `AuthorizationException` for non-admin scopes when `AuthScope::allows($this->getRawOriginal(), 'u'|'d')` fails (before any DB write, so denials leave no datalog row — parity tform_base.inc.php:1626/1574, tform_actions.inc.php:328-332); `applySysFieldDefaults()` forces `sys_userid`/`sys_groupid` from the scope for non-admin (perm letters keep defaults-if-absent) (FR-011/FR-012)
- [x] T030 [US2] Modify `app/Services/ClientService.php`: when the acting scope is a non-admin reseller, force `parent_client_id` to the reseller's own client id before the existing reseller-stamping/group-append logic runs (FR-018, parity client_edit.php:349-362)
- [x] T031 [US2] Modify `app/Console/Commands/CreateApiKey.php`: add `--client-id=` (resolve `sys_group.groupid` by `client_id`, then `sys_user.userid` by `default_group`; abort non-zero with a clear message when either is missing; reject combining with `--sys-userid`/`--sys-groupid`) (FR-019)
- [x] T032 [US2] Run the full suite: T020-T023 green, pre-existing suite green; spot-check via Swagger UI with a real non-admin key (local env): 403s render as problem+json, gated modules blocked, own-resource CRUD works

**Checkpoint**: Integrity boundary complete — tenant keys are safe to hand to customers

---

## Phase 5: User Story 3 - Client resource limits on create (Priority: P3 — DEFERRABLE)

**Goal**: `checkClientLimit`/`checkResellerLimit` parity on create paths (-1 unlimited / 0 disabled / n counted / reseller cap)

**Independent Test**: `limit_maildomain = 1` + one existing domain → POST 403 "limit reached", no datalog row; limit -1 → 201; reseller cap enforced across its clients

> **Deferral gate (pre-authorized in spec Assumptions)**: if after T029 the per-resource store()-hook wiring is estimated at > ~15 touchpoints of real logic, STOP here and move T033-T037 into feature 012 unchanged.
>
> **GATE TAKEN (2026-07-05)**: the FR-020 wiring spans 20+ store() paths across four modules (T035/T036 lists) — well past the ~15-touchpoint bar. T033–T037 move to feature 012 unchanged. What P2 already ships: `ClientLimitService::resourceEnabled()` + the `scope.limit:{column}` middleware enforcing the FR-017 zero-default gates (transports / access-rules / spamfilter wblist), and the AuthScope `u`-predicate machinery the 012 counting logic needs.

### Tests for User Story 3 (REQUIRED) ⚠️

- [ ] T033 [P] [US3] Create `tests/Feature/ClientLimitTest.php`: for `mail/domains`, `dns/soa`, `sites/web-domains` (type filter `type='vhost'`), and reseller `POST /clients` — the -1/0/n matrix (SC-007), reseller-cap denial (parent client limit counted over reseller groups ∪ userid), no-client-row key unlimited, denied create leaves no `sys_datalog` row, 403 problem+json detail "limit reached"

### Implementation for User Story 3

- [ ] T034 [US3] Create `app/Services/ClientLimitService.php`: `checkCreate(AuthScope $scope, string $limitColumn, string $table, string $pk, ?string $typeWhere)` per plan Design (client via `sys_group.client_id`; -1 skip; count rows matching the scope's `u` predicate + type filter; reseller pass via `parent_client_id` — parity tform.inc.php:183-250); throws the 403 "limit reached" authorization exception
- [ ] T035 [US3] Wire `ClientLimitService` into the store() paths of the FR-020 mail resources (`MailDomainController`, `MailUserController`, `MailForwardingController` (per-type limit), `MailAliasDomainController`, `MailGetController`, `SpamfilterWBListController`, `SpamfilterUserController`, `SpamfilterPolicyController`, `MailTransportController`, `MailAccessController` — the last two replacing/absorbing the T028 non-zero gate with the full count check)
- [ ] T036 [US3] Wire it into the FR-020 dns + sites + client resources (`DnsSoaController` (`limit_dns_zone`), `DnsRecordController` (`limit_dns_record`), `DnsSlaveController` (`limit_dns_slave_zone`), `WebDomainController` (`limit_web_domain`, `type='vhost'`), `WebChildDomainController` (subdomain/aliasdomain split per web_vhost_domain_edit.php:98-116), `FtpUserController`, `ShellUserController`, `WebdavUserController`, `CronJobController`, `WebDatabaseController`, `WebDatabaseUserController`, `ClientController` (`limit_client` for reseller keys))
- [ ] T037 [US3] Extend `tests/Support/TenantSchema.php`/`TenantFixtures.php` with the remaining FR-020 limit columns + per-identity limit setters; run the full suite

**Checkpoint**: All three stories independently functional

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Regression assurance and documentation of the new security posture

- [ ] T038 [P] Update `README.md`: authentication section documents key classes (admin/reseller/client), the 403/404 semantics, and `api:key:create --client-id`
- [x] T039 [P] Cross-tenant mutation sweep (SC-004): parameterized test (or extension of `ScopedBindingTest`) driving `PUT`/`DELETE` with client A's key against a B-owned id of every scoped resource route, asserting 404 + `sys_datalog` count unchanged
- [x] T040 Re-verify parity for the documented cases against `source_code/` (spec Parity list): predicate SQL letter-containment vs tform_base.inc.php:1755-1764; 404-not-403 for unreadable rows; admin bypass; reseller CSV expansion
- [x] T041 Code cleanup: predicate logic exists exactly once (`AuthScope`), no controller re-implements a check; middleware thin; no leftover debug scope bypasses
- [x] T042 Run `vendor/bin/phpunit` (full suite green — 564 pre-existing + all new matrices) and confirm Swagger UI renders (contract untouched except the T003 amendment if approved)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: T001/T002 are decision gates — T001 blocks T007's missing-row branch; T002 blocks T003 and is required context for T024/T025 (module-gate 403s)
- **Foundational (Phase 2)**: Blocks all stories. T005 → T006 → T007 sequential (same object flows through); T008/T010 parallel with each other; T009 depends on T008
- **US1 (Phase 3)**: Depends on Phase 2. Tests T011-T015 first (RED), then T016 → T017 → T018 → T019
- **US2 (Phase 4)**: Depends on Phase 2; T021/T022 build on US1's test files (extend, don't rewrite). T024 before T025-T028; T029 is independent of T024-T028 (different files); T030 after T029 (stamping behavior settled); T032 last
- **US3 (Phase 5)**: Depends on US2's T029 (u-predicate counting + 403 plumbing). Deferral gate sits before T033
- **Polish (Phase 6)**: After all shipped stories

### Within Each User Story

- Tests are written first and MUST fail before implementation
- Core-file edits before route wiring; route wiring before suite runs
- Full-suite run (admin regression bar) closes every story

### Parallel Opportunities

- T003/T004 parallel after T002/T001
- T008 ∥ T010; T011-T015 all parallel (different files); T020-T023 all parallel
- T016 (BaseModel) ∥ T017 (HandlesListQuery) — different files; T018 after both
- T024 ∥ T029 ∥ T031; T025/T026/T027 are three different route files — parallel with each other, but each is a single sequential edit (never two edits to the same route file marked [P])
- T035 ∥ T036 (different controller sets)

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1 (decisions) + Phase 2 (AuthScope + fixtures)
2. Complete Phase 3 — read scoping with zero controller edits
3. **STOP and VALIDATE**: mint a real client key against a staging `dbispconfig`, verify lists/shows across mail/dns/sites shrink to the tenant, admin key output diff-identical to pre-feature responses
4. Ship — read confidentiality alone already permits handing read-only keys to customers

### Incremental Delivery

1. US2 adds the write/module boundary — after it, tenant keys are fully distributable
2. US3 (or feature 012) adds commercial quotas — nothing in US1/US2 changes when it lands
3. The pre-existing 564-test suite is run at every story boundary as the admin-scope regression bar

---

## Notes

- [P] tasks = different files, no dependencies — never mark two edits to the same route file as [P]
- Denied writes MUST short-circuit **before** any DB write: a 403/404 that still produced a `sys_datalog` row is a test failure (T011/T021/T033 assert this)
- Never scope the datalog/validation internals (FR-010): `freshRecordFromDatabase()`, `DatalogService`, cross-tenant uniqueness checks stay global
- `source_code/` is read-only reference; every behavioral dispute resolves against the file:line citations in spec.md's Parity section
- Commit after each task or logical group
- Avoid: per-controller predicate reimplementations, new endpoints, `api_keys` schema changes, response-shape drift
