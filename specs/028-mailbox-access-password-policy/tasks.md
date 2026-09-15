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

- [x] T001 [P] Add `disableimap`, `disablepop3`, `disablesmtp`, `disabledeliver` to `api/components/schemas/MailUser.yaml`; replace the fixed password minimum with the policy description in `MailUser.yaml` and `api/components/schemas/MailUserPassword.yaml`
- [x] T002 [P] Describe the policy and switches in `api/modules/mail/users.yaml` and `api/modules/mail/user-password.yaml`
- [x] T003 Verify the YAML parses

---

## Phase 2: User Story 1 — Password policy (P1) 🎯 MVP

### Tests (write first, must fail)

- [x] T004 [P] [US1] Create `tests/Unit/MailPasswordPolicyTest.php`: strength table (length < 5, class/point branches, byte length) and `violation()` messages for strength, length-only, ASCII-only, empty password, empty settings
- [x] T005 [US1] Create `tests/Feature/MailboxAccessPasswordPolicyTest.php` policy cases: create/update/password endpoint with weak and compliant passwords for client and admin keys, exact messages, defaults without settings, empty minimum, ASCII-only mode, empty password on update keeps the hash, nothing journaled on refusal

### Implementation

- [x] T006 [US1] Create `app/Support/MailPasswordPolicy.php` and `app/Rules/MailboxPassword.php`; use the rule instead of `min:5` in `StoreMailUserRequest`, `UpdateMailUserRequest`, `UpdateMailUserPasswordRequest`; fix existing tests that relied on short mailbox passwords

---

## Phase 3: User Story 2 — Access switches (P1)

- [x] T007 [US2] Switch cases in `tests/Feature/MailboxAccessPasswordPolicyTest.php`: show/list booleans; create with switches (companion columns, datalog `i`); update `disableimap` / `disabledeliver` (companion columns, datalog `u`); update without source switches leaves companion columns; `y`/`n` strings; invalid value 422
- [x] T008 [US2] Switches in `app/Models/MailUser.php`, `MailUserRequest` normalization, store/update rules, `MailUserService::applyAccessDerivations()` called from `MailUserController::store()`/`update()`

---

## Phase 4: User Story 3 — Lock interaction (P2)

- [x] T009 [US3] Lock cases in `tests/Feature/MailboxAccessPasswordPolicyTest.php`: locked client, client and reseller `disablesmtp` `y → n` 403 `account-locked` nothing journaled; `n → y` 200; `disablepop3` change 200; admin `y → n` 200
- [x] T010 [US3] Confirm no guard change is needed (T009 green with T008; `LockedClientGuard` unchanged)

---

## Phase 5: Polish

- [x] T011 [P] Document switches and password policy in `README.md`
- [x] T012 Run Pint on changed PHP files and the full suite in Docker
- [x] T013 Deploy to isp-test and run `specs/028-mailbox-access-password-policy/quickstart.md` §2 with a temporary client; record results here

## Dependencies

Phase 1 → US1 → US2 → US3 → Polish. T001/T002 and T004/T005 parallel.

## Results (T012–T013, 2026-09-15)

- T012: Pint clean on changed files; full suite 1140 passed (baseline 1114; `MailUserApiTest` contract-shape test now
  expects the switches in the resource).
- T013: deployed `db9f5f1` to isp-test (`ispconfig-rest status`: 1.0.0-rc.3 (db9f5f1)). Temporary client `qa028812c5c`
  (client 24: `mail_servers=1`, `limit_maildomain=1`, `limit_mailbox=2`), temporary QA admin key 58 and client key 59,
  mail domain 13 and mailbox 12. System policy unchanged (`min_password_length=8`, `min_password_strength=3`).
  Passwords were generated in the script and never printed; Dovecot was checked with `doveadm auth test`
  (auth cache disabled on isp-test, so changes apply immediately). All checks matched:

| Case | Expected | Got |
|---|---|---|
| client and admin `POST /mail/users` password `abcdefgh` | 422 `… at least 8 chars in length and have a strength of "Good".` | 422, exact message (both keys) |
| client `POST /mail/users` compliant password | 201, switches false, all eight disable columns `n`, IMAP auth succeeds | as expected |
| client `PUT …/password` `abcdefgh` / compliant | 422 same message / 200, hash changed, IMAP auth with the new password succeeds | as expected |
| client `PUT /mail/users/12` `password=""`, `name` | 200, hash unchanged | as expected |
| client `PUT` `disableimap=true`, `disablepop3=true` | 200; `disableimap`, `disablesieve`, `disablesieve-filter`, `disablepop3` = `y`; IMAP and POP3 auth fail | as expected |
| client `PUT` `disableimap=false`, `disablepop3="n"`, `disabledeliver=true` | 200; imap/sieve/pop3 `n`, `disabledeliver`, `disablelda`, `disablelmtp` = `y`; IMAP and POP3 auth succeed | as expected |
| admin `PUT /clients/24` `locked=true` | 200; lock sets `disablesmtp=y`; SMTP auth fails | as expected |
| client `PUT` `disablesmtp=false` while locked | 403 `account-locked` | 403 `account-locked` |
| client `PUT` `disabledeliver=false` while locked; admin `PUT` `disablesmtp=false` | 200; 200 | 200; 200 (SMTP auth still fails: the lock also deactivates the mail domain, which Dovecot's `password_query` requires to be active) |
| admin `PUT /clients/24` `locked=false` | 200; all disable columns `n` | as expected |

- Cleanup: mailbox 12, mail domain 13 and client 24 deleted with the QA admin key (204); datalog processed
  (`server.updated` 694 ≥ last id 692); API keys 58, 59 deleted by SQL (`name LIKE 'qa%'`); no `qa028` client, sys_group,
  sys_user, mail_domain, mail_user or spamfilter_users rows, no pending datalog, no `/var/vmail/qa028*`, rspamd user
  files or client directory. Remaining keys: 1, 2, 20, 27 and 50, 53, 56, 57 (concurrent WHMCS module session,
  untouched).
