---

description: "Task list for spec 037 — SSH authentication mode for scoped keys"
---

# Tasks: SSH Authentication Mode for Scoped Keys

**Input**: Design documents from `/specs/037-ssh-authentication-mode/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1230 on `c36c127`; 1237 passing after this feature).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US3 from spec.md

---

## Phase 1: Setup (contract first)

- [x] T001 [P] Add the required `authentication` property (enum `password_or_key`, `password`, `key`) to `sites.shell` in `api/components/schemas/AccountSitesCapabilities.yaml`, and extend the description and example of `api/modules/me/capabilities.yaml`
- [x] T002 [P] Correct the *Authentication* section of `api/components/schemas/ShellUser.yaml` (the setting lives in `[sites]`; scoped keys are refused, administrator keys have the other credential cleared) and document the refusal in the POST and PUT descriptions of `api/modules/sites/shell-users.yaml`
- [x] T003 Verify the YAML parses and the spec is served (`tests/Feature/SwaggerSpecServerTest.php`)

---

## Phase 2: Tests (write first, must fail)

- [x] T004 [US1] Extend `tests/Feature/MeCapabilitiesApiTest.php`: `sites.shell.authentication` for an empty, `password`, `key` and unknown setting written in the `[sites]` section; the value is reported even when `limit_shell_user = 0`
- [x] T005 [US2] Create `tests/Feature/ShellUserAuthenticationModeTest.php` with a client key: mode `key` + non-empty `password` → 422 with `errors.password` and `error_types.password` = `feature-not-allowed` and no `sys_datalog` row; mode `password` + non-empty `ssh_rsa` → the mirror case; the allowed credential → 201; the not-allowed field as `null`/`""` → accepted; `PUT` re-sending the stored value unchanged → accepted and the credential survives; `PUT` changing it → 422; reseller key behaves like a client key; administrator key is not refused
- [x] T006 [US3] Move the `ssh_authentication` seed of `tests/Support/SitesApiTestCase.php` into the `[sites]` section so `ShellUserApiTest::test_ssh_authentication_mode_clears_the_other_credential` exercises the real path

---

## Phase 3: Implementation

- [x] T007 [US1] [US3] `app/Services/SitesConfigService.php`: read `ssh_authentication` from the `[sites]` section, with a docblock citing `shell_user_edit.php:100` vs `:131-137` and the dead-code finding
- [x] T008 [US1] `app/Services/AccountSitesService.php`: report `authentication` in the `shell` block, mapping unknown values to `password_or_key`, also when SSH access is unavailable
- [x] T009 [US2] Add `app/Http/Requests/Concerns/EnforcesSshAuthenticationMode.php` and apply it to `StoreShellUserRequest` and `UpdateShellUserRequest` (spec 033 pattern): skip administrator scopes, skip empty values, on update accept the stored value, tag the field with `ProblemTypeCollector` and add the message from contracts/ssh-authentication.md
- [x] T009a [US2] [US3] Restrict `ShellUserController::applySshAuthenticationMode()` to administrator scopes, so an accepted client or reseller request never destroys a stored credential

---

## Phase 4: Documentation

- [x] T010 README: the reported mode next to the other capability fields

---

## Phase 5: Verification

- [x] T011 Full suite green in Docker on PHP 8.3 (1237 passing, 10115 assertions); Pint clean on the 9 changed files
- [x] T012 Deploy the pushed commits to isp-test and run quickstart.md §2 with a temporary client, restoring the system configuration afterwards — deployed `cd5638c`, all checks matched (quickstart.md §4)
- [x] T013 Clean up per quickstart.md §3, verify the `[sites]` section is byte-identical to the backup, and record the results in this file — no leftovers, QA keys 94–98 removed, `sys_ini` byte-identical

---

## Implementation notes

- **The refusal lives in a trait, not in the Store request.** `UpdateShellUserRequest` extends `SitesRequest`, not
  `StoreShellUserRequest` (unlike the cron requests), so an `after()` defined only in Store never ran for updates —
  the first test run caught it as an accepted update that should have been refused.
  `Concerns\EnforcesSshAuthenticationMode` now carries the closure and the `storedShellUser()` hook, and the update
  request overrides that hook with its route model.
- **Clearing is the administrator path only** (FR-007, amended during implementation). With clearing left in place
  for every key type, an accepted client request — for example one that re-sends the stored key and changes a quota —
  wiped the credential, which is precisely the silent loss this feature removes. The controller now clears only for
  administrator scopes; scoped keys are refused when they try to *change* the not-allowed credential and their stored
  one is left untouched.
- **The reported value is installation-wide**, so it is returned even when the plan has no SSH access; a panel can
  render the right field before checking the plan.

## Found while verifying (outside this spec)

- **An exposed string setting cannot be cleared through `PUT /system/config/{section}`.** Sending
  `{"ssh_authentication": ""}` is refused with 422 *"The ssh authentication field must be a string."*: the empty
  string is converted to null before validation, and the `string` rule rejects null. Every `[sites]`, `[mail]`,
  `[dns]` and `[misc]` string setting that ISPConfig allows to be empty is affected (`webmail_url`,
  `dns_external_slave_fqdn`, `default_remote_dbserver`, `company_name`, `custom_login_text`, …), so an administrator
  can set such a value through the API but never take it back.
  The live check therefore restored the setting with a targeted SQL replacement and verified the blob byte-identical.
  This belongs to the system-configuration endpoint, not to this feature; it is proposed as its own spec (the next
  free number) rather than widening this diff.
