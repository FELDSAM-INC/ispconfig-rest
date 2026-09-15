---

description: "Task list for spec 035 — hosting capabilities for scoped keys"
---

# Tasks: Hosting Capabilities for Scoped Keys

**Input**: Design documents from `/specs/035-hosting-capabilities/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1158 on `31f9b44`; 1207 passing after this feature, together with the concurrent 029/032 work).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US4 from spec.md

---

## Phase 1: Setup (contract first)

- [x] T001 [P] Create `api/components/schemas/AccountSitesCapabilities.yaml` (prefixes, databases, shell, cron per data-model.md); register it in `api/components/schemas/_index.yaml`
- [x] T002 Add the required `sites` property to `api/components/schemas/AccountCapabilities.yaml` and extend the description and example of `api/modules/me/capabilities.yaml`
- [x] T003 [P] Add `database_users` to `api/components/schemas/UsageSummary.yaml` (required) and to the description in `api/modules/usage/summary.yaml`
- [x] T004 [P] Document the two 403 refusals in `api/modules/sites/cron-jobs.yaml` (POST and PUT) and the existing `limit_database_user` refusal in `api/modules/sites/database-users.yaml`
- [x] T005 Verify the YAML parses and the spec is served (`tests/Feature/SwaggerSpecServerTest.php`)

---

## Phase 2: Tests (write first, must fail)

- [x] T006 [US1] [P] Extend `tests/Feature/MeCapabilitiesApiTest.php`: `sites.prefixes` for `c[CLIENTID]` and `[CLIENTNAME]` patterns (with legacy name normalization), empty patterns → `""`, `[DOMAINID]` left unresolved, reseller reading a child gets the child's prefixes
- [x] T007 [US4] [P] Same class: `sites.databases` (`quota_limit_mb` value and `null` for `-1`, constant `remote_access`), `sites.shell` (`available` false for `limit_shell_user = 0`, `chroot_options` intersection and `[]`), `sites.cron` (`types` per `limit_cron_type`, `min_interval_minutes` null for 0/1)
- [x] T008 [US2] [P] Create `tests/Feature/ClientLimitDatabaseUserTest.php`: cap reached → 403 `limit-reached` with `limit.name = limit_database_user` and no `sys_datalog` row; unlimited → created; reseller cap → `scope: reseller`; admin key unaffected
- [x] T009 [US2] [P] Extend `tests/Feature/UsageSummaryApiTest.php`: `counts.database_users` `{used, limit}` and the count total
- [x] T010 [US3] [P] Create `tests/Feature/CronScheduleIntervalTest.php` for `CronJob::minIntervalMinutes()`: `*/5 * * * *` → 5; `0 * * * *` → 60 (the 60-minute `run_min` value is discarded, `run_hour` contributes); `0 3 * * *` → 1440; lists and ranges with wrap-around; `@reboot` month contributes nothing; every-minute `*` → 1; invalid expression → null
- [x] T011 [US3] Create `tests/Feature/ClientLimitCronTest.php`: too-frequent create and update → 403 `limit-reached` (`limit_cron_frequency`, `used` = the job's interval) with nothing journaled and the stored row unchanged; allowed schedule → 201/200; shell command under `limit_cron_type = url` → 403 `feature-not-allowed` (`feature: limit_cron_type`); `limit_cron_frequency` of 0/1 disables the check; admin key unaffected; invalid expression still 422; locked account → `account-locked`
- [x] T011a Test infrastructure: `tests/Support/TenantSchema.php` gains the client columns spec 035 reads — `limit_cron_frequency` (integer, DDL default 5) and the string columns `limit_cron_type` and `ssh_chroot`

---

## Phase 3: Implementation

- [x] T012 [US1] [US4] Create `app/Services/AccountSitesService.php`: prefixes via `SitesConfigService::resolvePrefix()` with the described client's `sys_groupid`, database options, shell options (chroot intersection), cron rules
- [x] T013 [US1] [US4] Wire the block into `app/Services/AccountCapabilitiesService.php::capabilities()` as `sites`
- [x] T014 [US2] Add `database_users => limit_database_user` to `ClientLimitService::USAGE_COUNT_COLUMNS` and the matching case to `countSpecForColumn()`, reusing the `LimitSpec` of `countSpecsFor()`
- [x] T015 [US3] Add `CronJob::minIntervalMinutes()` — the `validate_cron` accumulation of research R7, including the per-field weights and the `<= max_entry` discard
- [x] T016 [US3] Add `ClientLimitService::checkCronLimits()`: skip admin scopes, clients without a row and records of a locked owner; refuse a too-frequent schedule (`limit-reached`) and a kind excluded by `limit_cron_type` (`feature-not-allowed`)
- [x] T017 [US3] Call the check from `CronJobController::store()` and `update()` after the type derivation and before `save()`; update the class docblock that said these limits were out of scope

---

## Phase 4: Documentation

- [x] T018 [P] `docs/problems.md`: `limit-reached` now names database users and the scheduled-task frequency (with the meaning of `max`/`used` there); `feature-not-allowed` lists `limit_cron_type`
- [x] T019 [P] README: `/me/capabilities` also describes databases, FTP, SSH and scheduled tasks, the task rules are enforced, and `/usage/summary` counts database users

---

## Phase 5: Verification

- [x] T020 Full suite green in Docker on PHP 8.3 (1207 passing, 9921 assertions); Pint clean on the 11 changed files
- [x] T021 Deploy the pushed commits to isp-test and run quickstart.md §2 with a temporary client — deployed `1af0e06`, all checks matched (quickstart.md §4)
- [x] T022 Clean up per quickstart.md §3 and record the results in this file — no leftovers, QA keys 78–81 removed

---

## Implementation notes

- **`limit_database_user` was already enforced** (research R8). `countSpecsFor()` has mapped `web_database_user` to it
  since spec 012, so only the `/usage/summary` count was missing. The module's dependency note (WHMCS spec 006 R19)
  assumed otherwise; T008 pins the existing behaviour instead of adding it.
- **Locked accounts outrank the task rules** (owner-delegated decision 2026-09-16). `checkCronLimits()` runs before
  `save()`, so without care its refusal would mask the spec 019 `account-locked` problem — including for a reseller
  key writing for its locked client, since the guard judges the record's owner, not the caller. The check therefore
  skips records whose owning client is locked, resolved exactly like `LockedClientGuard`, and a regression test in
  `ClientLimitCronTest` pins the precedence.
- **The interval port returns null for an invalid schedule.** Validation (spec 013) refuses those with 422 before any
  limit check, so a limit refusal must never be raised from an unparsable expression.
