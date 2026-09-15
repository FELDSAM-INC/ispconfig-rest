---

description: "Task list for spec 035 — hosting capabilities for scoped keys"
---

# Tasks: Hosting Capabilities for Scoped Keys

**Input**: Design documents from `/specs/035-hosting-capabilities/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1158 on `31f9b44`; rebase onto the concurrent 029/032 work before counting).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US4 from spec.md

---

## Phase 1: Setup (contract first)

- [ ] T001 [P] Create `api/components/schemas/AccountSitesCapabilities.yaml` (prefixes, databases, shell, cron per data-model.md); register it in `api/components/schemas/_index.yaml`
- [ ] T002 Add the required `sites` property to `api/components/schemas/AccountCapabilities.yaml` and extend the description and example of `api/modules/me/capabilities.yaml`
- [ ] T003 [P] Add `database_users` to `api/components/schemas/UsageSummary.yaml` (required) and to the example in `api/modules/usage/summary.yaml`
- [ ] T004 [P] Document the two 403 refusals in `api/modules/sites/cron-jobs.yaml` (POST and PUT) and the existing `limit_database_user` refusal in `api/modules/sites/database-users.yaml`
- [ ] T005 Verify the YAML parses and the spec is served (`tests/Feature/SwaggerSpecServerTest.php`)

---

## Phase 2: Tests (write first, must fail)

- [ ] T006 [US1] [P] Extend `tests/Feature/MeCapabilitiesApiTest.php`: `sites.prefixes` for `c[CLIENTID]` and `[CLIENTNAME]` patterns (with legacy name normalization), empty patterns → `""`, `[DOMAINID]` left unresolved, reseller reading a child gets the child's prefixes, admin without `client_id` 422
- [ ] T007 [US4] [P] Same class: `sites.databases` (`quota_limit_mb` value and `null` for `-1`, constant `remote_access`), `sites.shell` (`available` false for `limit_shell_user = 0`, `chroot_options` intersection and `[]`), `sites.cron` (`types` per `limit_cron_type`, `min_interval_minutes` null for 0/1)
- [ ] T008 [US2] [P] Create `tests/Feature/ClientLimitDatabaseUserTest.php`: cap reached → 403 `limit-reached` with `limit.name = limit_database_user` and no `sys_datalog` row; unlimited → created; reseller cap → `scope: reseller`; admin key unaffected
- [ ] T009 [US2] [P] Extend `tests/Feature/UsageSummaryApiTest.php`: `counts.database_users` `{used, limit}`, `null` limit when unlimited, count matches the rows the key may read
- [ ] T010 [US3] [P] Create `tests/Feature/CronScheduleIntervalTest.php` for `CronJob::minIntervalMinutes()`: `*/5 * * * *` → 5; `0 * * * *` → 60 (the 60-minute `run_min` value is discarded, `run_hour` contributes); `0 3 * * *` → 1440; lists and ranges with wrap-around; `@reboot` month contributes nothing; every-minute `*` → 1
- [ ] T011 [US3] Create `tests/Feature/ClientLimitCronTest.php`: too-frequent create and update → 403 `limit-reached` (`limit_cron_frequency`, `used` = the job's interval) with nothing journaled and the stored row unchanged; allowed schedule → 201/200; shell command under `limit_cron_type = url` → 403 `feature-not-allowed` (`feature: limit_cron_type`); `limit_cron_frequency` of 0/1 disables the check; admin key unaffected; invalid expression still 422

---

## Phase 3: Implementation

- [ ] T012 [US1] [US4] Create `app/Services/AccountSitesService.php`: prefixes via `SitesConfigService::resolvePrefix()` with the described client's `sys_groupid`, database options, shell options (chroot intersection), cron rules
- [ ] T013 [US1] [US4] Wire the block into `app/Services/AccountCapabilitiesService.php::capabilities()` as `sites`
- [ ] T014 [US2] Add `database_users => limit_database_user` to `ClientLimitService::USAGE_COUNT_COLUMNS` and the matching case to `countSpecForColumn()`, reusing the `LimitSpec` of `countSpecsFor()`
- [ ] T015 [US3] Add `CronJob::minIntervalMinutes()` — the `validate_cron` accumulation of research R7, including the per-field weights and the `<= max_entry` discard
- [ ] T016 [US3] Add `ClientLimitService::checkCronLimits()`: skip admin scopes and clients without a row; refuse a too-frequent schedule (`limit-reached`) and a kind excluded by `limit_cron_type` (`feature-not-allowed`)
- [ ] T017 [US3] Call the check from `CronJobController::store()` and `update()` after the type derivation and before `save()`; update the class docblock that currently says these limits are out of scope

---

## Phase 4: Documentation

- [ ] T018 [P] `docs/problems.md`: add `limit_cron_frequency` and `limit_database_user` to the `limit-reached` examples and `limit_cron_type` to the `feature-not-allowed` values
- [ ] T019 [P] README: note that `/me/capabilities` also describes databases, FTP, SSH and scheduled tasks, and that `/usage/summary` counts database users

---

## Phase 5: Verification

- [ ] T020 Full suite green in Docker on PHP 8.3; Pint on changed files
- [ ] T021 Deploy the pushed commits to isp-test and run quickstart.md §2 with a temporary client
- [ ] T022 Clean up per quickstart.md §3 and record the results in this file
