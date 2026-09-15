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
- [x] T014 Deploy to isp-test and run `specs/026-spamfilter-level/quickstart.md` §2 with a temporary client; record results here

## Dependencies

Phase 1 → Phase 2 → US1 → US2 → US3 → Polish. T001/T002 and T004/T005 parallel.

## Results (T013–T014, 2026-09-15)

- T013: Pint clean on changed files; full suite 1104 passed (baseline 1092).
- T014: deployed `4964be2` to isp-test (`ispconfig-rest status`: 1.0.0-rc.3 (4964be2)). Temporary client `qa026c55d48`
  (client 20, group 21: `mail_servers=1`, `limit_maildomain=1`, `limit_mailbox=1`), temporary QA admin key 51 and client
  key 52. The private-policy step of quickstart §2.3 was not run live: `POST /mail/spamfilter/policies` always stores
  world-readable policies, so a private policy would need a direct SQL change; that case is covered by
  `SpamfilterLevelApiTest`. All other checks matched:

| Case | Expected | Got |
|---|---|---|
| client `GET /mail/spamfilter/policies` | 200, 7 readable policies | 200, total 7 |
| client `POST /mail/domains` `spamfilter_policy_id=5` | 201, level 5; row `@domain` priority 5, policy 5, local Y, server 1, group 21, `riud/riud/` | 201, row as expected |
| client `POST /mail/users`, `GET …/spamfilter` | 201, `policy_id` 0 | 201, 0 |
| client `PUT …/spamfilter` `policy_id=7` | 200, row priority 7, policy 7, fullname = address, group 21 | 200, as expected |
| same `PUT` again | 200, no datalog entry | 200, last datalog id unchanged (556) |
| client `PUT …/spamfilter` `policy_id=999999` | 422 `The selected policy id is invalid.` | 422, as expected |
| client `PUT /mail/domains` level 0, then 6; `GET /mail/domains`; `PUT` `active` only | 200 (row policy 0), 200, list level 6, level kept 6 | as expected |
| admin `GET /mail/domains/8`; admin `PUT …/spamfilter` `policy_id=1`; client `GET` | 6; 200; 1 | 6; 200; 1 |
| server processing | datalog 553–559 `ok`; rspamd user settings files for the address and the domain | `server.updated` 559; `box_qa026-c55d48.example.test.conf` (priority 27) and `qa026-c55d48.example.test.conf` (priority 15) written |

- Cleanup: mailbox 7, mail domain 8 and client 20 deleted with the QA admin key (204); datalog processed (`server.updated`
  564 = last id); API keys 51, 52 deleted by SQL (`name LIKE 'qa%'`); no `qa026` client, sys_group, sys_user,
  mail_domain, mail_user or spamfilter_users rows, no pending datalog, no rspamd user files, `/var/vmail/qa026*` or
  client directory. Remaining keys: 1, 2, 20, 27 and 50 (`WHMCS service #2`, created by the concurrent WHMCS module
  session, untouched).
