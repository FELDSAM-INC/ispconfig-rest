---

description: "Task list for spec 038 — password policy for non-mail users"
---

# Tasks: Password Policy for Non-Mail Users

**Input**: Design documents from `/specs/038-password-policy-non-mail/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1237 on `8d75316`).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US5 from spec.md

---

## Phase 1: Setup (contract first)

- [ ] T001 [P] Create `api/components/schemas/PasswordPolicy.yaml` (`min_length`, `min_strength`); add the required `password_policy` property to `sites` in `api/components/schemas/AccountSitesCapabilities.yaml` and extend the description and example of `api/modules/me/capabilities.yaml`
- [ ] T002 [P] State the rule in the POST and PUT descriptions of `api/modules/client/clients.yaml`, `client/resellers.yaml`, `sites/ftp-users.yaml`, `sites/shell-users.yaml`, `sites/webdav-users.yaml`, `sites/web-folder-users.yaml`, `sites/database-users.yaml` and `sites/web-domains.yaml`
- [ ] T003 Verify the YAML parses and the spec is served (`tests/Feature/SwaggerSpecServerTest.php`)

---

## Phase 2: Tests (write first, must fail)

- [ ] T004 [US1] [US2] Create `tests/Feature/PasswordPolicyNonMailTest.php`: with policy 8/3, a weak password is refused on every field of data-model.md (client create and update, reseller create, FTP, shell, WebDAV, web folder, database user, `stats_password`), with the legacy message, nothing journaled and — for updates — an unchanged stored hash; a compliant password is accepted on each
- [ ] T005 [US3] [P] Same class: the message names length and strength with a strength configured, and length only when `min_password_strength` is 0 or absent; a policy length of 0 accepts any non-empty password
- [ ] T006 [US1] [P] Same class: an absent or empty password is not judged (optional fields stay optional; a client update with a blank password still means "no change"); every key type is enforced (client, reseller and admin keys)
- [ ] T007 [US5] [P] Extend `tests/Feature/MeCapabilitiesApiTest.php`: `sites.password_policy` for 8/3, for an unset strength and for an unset length (default 8), and that it carries no `ascii_only` while `mail.password_policy` still does

---

## Phase 3: Implementation

- [ ] T008 [US1] Create `app/Support/PasswordPolicy.php` with the strength table, message building and `violation()` for length+strength, moved from `MailPasswordPolicy`
- [ ] T009 [US4] Reduce `app/Support/MailPasswordPolicy.php` to the mail-only ASCII branch delegating to `PasswordPolicy`, keeping every spec 028 expectation unchanged
- [ ] T010 [US1] Create `app/Services/PasswordPolicyService.php` reading `[misc] min_password_length` (default 8) and `min_password_strength` (default 0)
- [ ] T011 [US1] Create `app/Rules/InstallationPassword.php` using the service and the support class
- [ ] T012 [US1] [US2] Attach the rule in `StoreClientRequest`, `UpdateClientRequest`, `StoreFtpUserRequest`, `UpdateFtpUserRequest`, `StoreShellUserRequest`, `UpdateShellUserRequest`, `StoreWebdavUserRequest`, `UpdateWebdavUserRequest`, `StoreWebFolderUserRequest`, `UpdateWebFolderUserRequest`, `StoreWebDatabaseUserRequest`, `UpdateWebDatabaseUserRequest` and `WebDomainRequest` (`stats_password`), replacing the hard-coded `min:8` on clients
- [ ] T013 [US5] Report `password_policy` in the `sites` block of `app/Services/AccountSitesService.php`

---

## Phase 4: Fixtures

- [ ] T014 Update the existing suites to compliant passwords (`FtpUserApiTest`, `ShellUserApiTest`, `WebdavUserApiTest`, `WebFolderUserApiTest`, `WebDatabaseUserApiTest`, `WebDomainApiTest`, `ClientApiTest`, `ClientResellerApiTest` and any other suite posting a password), and re-frame `ClientApiTest`'s `short password` case against the policy message

---

## Phase 5: Documentation

- [ ] T015 README: the upgrade note of spec.md — the exact endpoints and fields, what a compliant generator must satisfy, that weak-password integrations begin to fail, and that production rollout is the operator's decision

---

## Phase 6: Verification

- [ ] T016 Full suite green in Docker on PHP 8.3; Pint clean on the changed files
- [ ] T017 Deploy the pushed commits to isp-test and run quickstart.md §2 with a temporary client, without changing the system configuration
- [ ] T018 Clean up per quickstart.md §3 and record the results in this file
