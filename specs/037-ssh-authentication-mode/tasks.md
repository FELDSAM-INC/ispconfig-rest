---

description: "Task list for spec 037 — SSH authentication mode for scoped keys"
---

# Tasks: SSH Authentication Mode for Scoped Keys

**Input**: Design documents from `/specs/037-ssh-authentication-mode/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1230 on `c36c127`).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US3 from spec.md

---

## Phase 1: Setup (contract first)

- [ ] T001 [P] Add the required `authentication` property (enum `password_or_key`, `password`, `key`) to `sites.shell` in `api/components/schemas/AccountSitesCapabilities.yaml`, and extend the description and example of `api/modules/me/capabilities.yaml`
- [ ] T002 [P] Correct the *Authentication* section of `api/components/schemas/ShellUser.yaml` (the setting lives in `[sites]`; scoped keys are refused, administrator keys have the other credential cleared) and document the refusal in the POST and PUT descriptions of `api/modules/sites/shell-users.yaml`
- [ ] T003 Verify the YAML parses and the spec is served (`tests/Feature/SwaggerSpecServerTest.php`)

---

## Phase 2: Tests (write first, must fail)

- [ ] T004 [US1] Extend `tests/Feature/MeCapabilitiesApiTest.php`: `sites.shell.authentication` for an empty, `password`, `key` and unknown setting written in the `[sites]` section; the value is reported even when `limit_shell_user = 0`
- [ ] T005 [US2] Create `tests/Feature/ShellUserAuthenticationModeTest.php` with a client key: mode `key` + non-empty `password` → 422 with `errors.password` and `error_types.password` = `feature-not-allowed` and no `sys_datalog` row; mode `password` + non-empty `ssh_rsa` → the mirror case; the allowed credential → 201; the not-allowed field as `null`/`""` → accepted; `PUT` re-sending the stored value unchanged → accepted; `PUT` changing it → 422; reseller key behaves like a client key
- [ ] T006 [US3] Move `tests/Support/SitesApiTestCase::setSshAuthenticationMode()` to write the `[sites]` section (adding the key when absent) so `ShellUserApiTest::test_ssh_authentication_mode_clears_the_other_credential` exercises the real path, and add the `password_or_key` case (both credentials stored)

---

## Phase 3: Implementation

- [ ] T007 [US1] [US3] `app/Services/SitesConfigService.php`: read `ssh_authentication` from the `[sites]` section, with a docblock citing `shell_user_edit.php:100` vs `:131-137` and the dead-code finding
- [ ] T008 [US1] `app/Services/AccountSitesService.php`: report `authentication` in the `shell` block, mapping unknown values to `password_or_key`
- [ ] T009 [US2] Add the refusal to `app/Http/Requests/StoreShellUserRequest.php` and `app/Http/Requests/UpdateShellUserRequest.php` via an `after()` closure (spec 033 pattern): skip administrator scopes, skip empty values, on update accept the stored value, tag the field with `ProblemTypeCollector` and add the message from contracts/ssh-authentication.md

---

## Phase 4: Documentation

- [ ] T010 README: the reported mode next to the other capability fields, the refusal for scoped keys, and the note that the setting is read from the section the administrator's Sites tab writes

---

## Phase 5: Verification

- [ ] T011 Full suite green in Docker on PHP 8.3; Pint clean on the changed files
- [ ] T012 Deploy the pushed commits to isp-test and run quickstart.md §2 with a temporary client, restoring the system configuration afterwards
- [ ] T013 Clean up per quickstart.md §3, verify the `[sites]` section is byte-identical to the backup, and record the results in this file
