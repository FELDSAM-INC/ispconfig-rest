---

description: "Task list for spec 024 — scoped parent references"
---

# Tasks: Scoped Parent References (Mail Domain Ownership and Siblings)

**Input**: Design documents from `/specs/024-mail-domain-ownership/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/reference-checks.md

**Tests**: REQUIRED (constitution v2). Two-tenant feature tests are written first and must fail before the fix.

## Format: `[ID] [P?] [Story] Description`

## Phase 1: Setup (contract notes)

- [x] T001 [P] Add the scoped-reference sentence to the affected operation descriptions in `api/modules/mail/users.yaml`, `api/modules/mail/forwards.yaml`, `api/modules/mail/alias-domains.yaml`, `api/modules/mail/spamfilter-wblist.yaml`
- [x] T002 [P] Add the same sentence in `api/modules/dns/records.yaml` and `api/modules/sites/{web-domains,web-child-domains,ftp-users,shell-users,webdav-users,cron-jobs,web-folders,web-folder-users,databases}.yaml`
- [x] T003 Verify every edited YAML parses and `$ref`s still resolve

---

## Phase 2: Foundational

- [x] T004 Create `app/Http/Requests/Concerns/ScopesReferences.php` with `readable(DatabaseRule $rule): DatabaseRule` (adds `AuthScope::applyReadPredicate($query, 'r')` as a `using` callback) and `readableQuery(string $table): Builder`

---

## Phase 3: User Story 1 — Mail writes only on the key's own mail domains (P1) 🎯 MVP

### Tests for User Story 1 (REQUIRED) ⚠️

- [x] T005 [US1] Write `tests/Feature/ScopedReferenceMailTest.php` (MailCompletionSchema + TenantSchema + TenantFixtures, mail server 1 assigned): client A key vs B's mail domain/mailbox for `POST /mail/users` (400 equal to nonexistent, no row/datalog/spamfilter user), `PUT /mail/users/{id}` on a readable mailbox whose domain is foreign (400), forward/catch-all/alias source on B's domain (400), alias destination B's mailbox (422 `destination`, equal to nonexistent), forward/catch-all external destination still allowed on own domain (201), alias domain source or destination foreign (400, also on PUT destination), allow/deny list `rid` of B's spam filter user (404 equal to nonexistent, `limit_spamfilter_wblist` booked); reseller key on clientA's domain succeeds; admin key succeeds on B's domain. Run and confirm failures.

### Implementation for User Story 1

- [x] T006 [US1] Scope `MailUserService::resolveMailDomain()` in `app/Services/MailUserService.php`
- [x] T007 [US1] Scope `resolveSourceDomain()` in `app/Http/Controllers/Api/V1/MailForwardingController.php`
- [x] T008 [US1] Scope `resolveMailDomain()` in `app/Http/Controllers/Api/V1/MailAliasDomainController.php`
- [x] T009 [US1] Scope `guardRidReference()` in `app/Http/Controllers/Api/V1/SpamfilterWBListController.php`
- [x] T010 [US1] Add `aliasDestinationsRule()` to `app/Http/Requests/MailForwardingRequest.php` (non-admin, type `alias`, every address a readable mailbox) and use it in `StoreMailForwardingRequest.php` (type from input) and `UpdateMailForwardingRequest.php` (stored type); `MailGetRequest.php` keeps its own scoped `readableMailboxQuery` (already correct since 016, no change needed)
- [x] T011 [US1] Run `ScopedReferenceMailTest` and the mail suites (`MailUserApiTest`, `MailForwardingApiTest`, `MailRoutingApiTest`, `ScopingMailModuleTest`, `ClientLimitMailTest`) green

**Checkpoint**: mail writes cannot reach foreign mail domains or mailboxes.

---

## Phase 4: User Story 2 — Sites and DNS writes only under the key's own parents (P2)

### Tests for User Story 2 (REQUIRED) ⚠️

- [x] T012 [P] [US2] Write `tests/Feature/ScopedReferenceSitesTest.php` (SitesSchema + TenantSchema + TenantFixtures): client A key referencing B's vhost/database user/web folder for every create listed in the spec (and the updates that accept the reference) → 422 with the field error equal to a nonexistent id and no datalog; A's own references pass the reference validation; admin unaffected. Run and confirm failures.
- [x] T013 [P] [US2] Write `tests/Feature/ScopedReferenceDnsTest.php` (DnsSchema + TenantSchema): client A creates a record in B's zone and moves an own record into B's zone → 422 `zone` equal to nonexistent; own zone passes; admin unaffected. Run and confirm failures.

### Implementation for User Story 2

- [x] T014 [US2] Apply `readable()` to `parent_domain_id` in `app/Http/Requests/{StoreWebDomainRequest,UpdateWebDomainRequest,StoreWebChildDomainRequest,UpdateWebChildDomainRequest,StoreFtpUserRequest,UpdateFtpUserRequest,StoreShellUserRequest,UpdateShellUserRequest,StoreWebdavUserRequest,StoreCronJobRequest,UpdateCronJobRequest,StoreWebFolderRequest}.php`
- [x] T015 [US2] Apply `readable()` to `parent_domain_id`, `database_user_id`, `database_ro_user_id` in `app/Http/Requests/{StoreWebDatabaseRequest,UpdateWebDatabaseRequest}.php` and to `web_folder_id` in `app/Http/Requests/StoreWebFolderUserRequest.php`
- [x] T016 [US2] Apply `readable()` to `zone` in `app/Http/Requests/{StoreDnsRecordRequest,UpdateDnsRecordRequest}.php`
- [x] T017 [US2] Run `ScopedReferenceSitesTest`, `ScopedReferenceDnsTest`, and the sites/DNS/client-limit suites green; adjust fixtures only where a test used a non-admin key with a parent it cannot read (document each) — done: `tests/Feature/ClientLimitMailTest.php::test_forwarding_types_are_counted_independently` now seeds the alias destination mailbox `box@a-dom.test`, which the alias must reference (FR-002)

---

## Phase 5: Polish & Cross-Cutting Concerns

- [x] T018 Full suite green in Docker (`php artisan test`); Pint on changed files
- [x] T019 [P] README: note under the scoping section that references in write bodies follow the read scope
- [ ] T020 Deploy to isp-test (`ispconfig-rest update`), run quickstart §2 with two temporary clients, record results in `quickstart.md`, cleanup per §3

---

## Dependencies & Execution Order

- Phase 1 → Phase 2 → US1 and US2 (independent of each other) → Polish.
- Within each story, tests (T005, T012, T013) before implementation.
- T010 edits three request files that share the base class — sequential.

## Implementation Strategy

MVP = Phase 1–3 (mail). US2 follows in the same delivery because it reuses the concern and closes the same
vulnerability class.
