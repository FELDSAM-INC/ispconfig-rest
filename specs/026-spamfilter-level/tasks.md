---

description: "Task list for spec 026 — spam filter level selection for scoped keys"
---

# Tasks: Spam Filter Level Selection for Scoped Keys

**Input**: Design documents from `/specs/026-spamfilter-level/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1092 passing).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US3 from spec.md

---

## Phase 1: Setup (contract first)

- [x] T001 [P] Add `policy_id` to `api/components/schemas/MailUserSpamFilter.yaml` and `spamfilter_policy_id` to `api/components/schemas/MailDomain.yaml`
- [x] T002 [P] Describe the level in `api/modules/mail/user-spamfilter.yaml`, `api/modules/mail/domains.yaml` and the readable list in `api/modules/mail/spamfilter-policies.yaml`
- [x] T003 Verify the YAML parses (Symfony YAML) and `tests/Unit/ChangeSetHeaderContractTest.php` passes

---

## Phase 2: Foundational

- [x] T004 Create `app/Services/SpamfilterUserService.php` (`policyFor`, `policiesFor`, `assignMailbox`, `assignDomain` via `DatalogService`) and `app/Http/Requests/Concerns/ValidatesSpamfilterPolicy.php` (readable non-zero policy rule)
- [x] T005 [P] Give the `spamfilter_users` table in `tests/Support/SystemSchema.php` the real columns (priority, policy_id, fullname, local, sys fields)

**Checkpoint**: full suite green

---

## Phase 3: User Story 1 — Mailbox level (P1) 🎯 MVP

### Tests (write first, must fail)

- [x] T006 [US1] Create `tests/Feature/SpamfilterLevelApiTest.php` mailbox cases: GET `policy_id` 0 without row and stored value with row; PUT inserts a row with the legacy defaults (datalog `i` payload) or updates only `policy_id` (datalog `u`); unchanged value journals nothing; 0 resets; nonexistent and unreadable policy 422 with the same message; negative / non-integer 422; mail filter tab off still accepts `policy_id`; foreign row owner still updated

### Implementation

- [x] T007 [US1] `policy_id` rule and payload split in `app/Http/Requests/UpdateMailUserSpamFilterRequest.php`; view and write in `app/Http/Controllers/Api/V1/MailUserSpamFilterController.php`

---

## Phase 4: User Story 2 — Domain level (P1)

### Tests (write first, must fail)

- [x] T008 [US2] Domain cases in `tests/Feature/SpamfilterLevelApiTest.php`: show/list `spamfilter_policy_id` (list uses one query for the page); PUT inserts/updates `@domain` row (priority 5, fullname `@domain`); PUT without the field leaves the row untouched; POST with the field creates domain and row in one change set; invalid policy on POST creates nothing

### Implementation

- [x] T009 [US2] `spamfilter_policy_id` rules in `StoreMailDomainRequest`/`UpdateMailDomainRequest`, payload split in `MailDomainRequest`; presentation and write in `app/Http/Controllers/Api/V1/MailDomainController.php`

---

## Phase 5: User Story 3 — Tenant matrix (P2)

- [x] T010 [US3] Matrix cases in `tests/Feature/SpamfilterLevelApiTest.php`: client B on A's mailbox/domain 404; A's private policy accepted for A, 422 for B; reseller on A's mailbox/domain 200; readable-but-not-updatable mailbox 403 with nothing journaled; admin any policy; client policy list shows only readable policies
- [x] T011 [US3] Save the mailbox or domain before the companion row so the `BaseModel` update gate refuses readable-but-not-updatable records (make T010 green)

---

## Phase 6: Polish

- [x] T012 [P] Document the spam filter level in `README.md`
- [x] T013 Run Pint on changed PHP files and the full suite in Docker
- [ ] T014 Deploy to isp-test and run `specs/026-spamfilter-level/quickstart.md` §2 with a temporary client; record results here

## Dependencies

Phase 1 → Phase 2 → US1 → US2 → US3 → Polish. T001/T002 and T004/T005 parallel.
