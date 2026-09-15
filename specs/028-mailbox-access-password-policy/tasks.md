---

description: "Task list for spec 028 — mailbox access switches and password policy"
---

# Tasks: Mailbox Access Switches and Password Policy

**Input**: Design documents from `/specs/028-mailbox-access-password-policy/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1114 passing).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US3 from spec.md

---

## Phase 1: Setup (contract first)

- [ ] T001 [P] Add `disableimap`, `disablepop3`, `disablesmtp`, `disabledeliver` to `api/components/schemas/MailUser.yaml`; replace the fixed password minimum with the policy description in `MailUser.yaml` and `api/components/schemas/MailUserPassword.yaml`
- [ ] T002 [P] Describe the policy and switches in `api/modules/mail/users.yaml` and `api/modules/mail/user-password.yaml`
- [ ] T003 Verify the YAML parses

---

## Phase 2: User Story 1 — Password policy (P1) 🎯 MVP

### Tests (write first, must fail)

- [ ] T004 [P] [US1] Create `tests/Unit/MailPasswordPolicyTest.php`: strength table (length < 5, class/point branches, byte length) and `violation()` messages for strength, length-only, ASCII-only, empty password, empty settings
- [ ] T005 [US1] Create `tests/Feature/MailboxAccessPasswordPolicyTest.php` policy cases: create/update/password endpoint with weak and compliant passwords for client and admin keys, exact messages, defaults without settings, empty minimum, ASCII-only mode, empty password on update keeps the hash, nothing journaled on refusal

### Implementation

- [ ] T006 [US1] Create `app/Support/MailPasswordPolicy.php` and `app/Rules/MailboxPassword.php`; use the rule instead of `min:5` in `StoreMailUserRequest`, `UpdateMailUserRequest`, `UpdateMailUserPasswordRequest`; fix existing tests that relied on short mailbox passwords

---

## Phase 3: User Story 2 — Access switches (P1)

- [ ] T007 [US2] Switch cases in `tests/Feature/MailboxAccessPasswordPolicyTest.php`: show/list booleans; create with switches (companion columns, datalog `i`); update `disableimap` / `disabledeliver` (companion columns, datalog `u`); update without source switches leaves companion columns; `y`/`n` strings; invalid value 422
- [ ] T008 [US2] Switches in `app/Models/MailUser.php`, `MailUserRequest` normalization, store/update rules, `MailUserService::applyAccessDerivations()` called from `MailUserController::store()`/`update()`

---

## Phase 4: User Story 3 — Lock interaction (P2)

- [ ] T009 [US3] Lock cases in `tests/Feature/MailboxAccessPasswordPolicyTest.php`: locked client, client and reseller `disablesmtp` `y → n` 403 `account-locked` nothing journaled; `n → y` 200; `disablepop3` change 200; admin `y → n` 200
- [ ] T010 [US3] Confirm no guard change is needed (T009 green with T008)

---

## Phase 5: Polish

- [ ] T011 [P] Document switches and password policy in `README.md`
- [ ] T012 Run Pint on changed PHP files and the full suite in Docker
- [ ] T013 Deploy to isp-test and run `specs/028-mailbox-access-password-policy/quickstart.md` §2 with a temporary client; record results here

## Dependencies

Phase 1 → US1 → US2 → US3 → Polish. T001/T002 and T004/T005 parallel.
