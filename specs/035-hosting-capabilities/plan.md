# Implementation Plan: Hosting Capabilities for Scoped Keys

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-16 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/035-hosting-capabilities/spec.md`

## Summary

- `GET /me/capabilities` gains a required `sites` block (prefixes, database options, shell options, task rules),
  built by a new `App\Services\AccountSitesService` and wired into `AccountCapabilitiesService::capabilities()`.
- `GET /usage/summary` gains `counts.database_users`; `ClientLimitService` learns the column in
  `USAGE_COUNT_COLUMNS` and `countSpecForColumn()`, reusing the `LimitSpec` its create-time enforcement already uses.
- `POST`/`PUT /sites/cron-jobs` enforce `limit_cron_frequency` and the "url only" case of `limit_cron_type` for
  client and reseller keys, via `ClientLimitService::checkCronLimits()` and a new
  `CronJob::minIntervalMinutes()` port of legacy `cron_min_freq`.
- Contract first: `AccountSitesCapabilities.yaml`, edits to `AccountCapabilities.yaml`, `capabilities.yaml`,
  `UsageSummary.yaml`, `summary.yaml`, `cron-jobs.yaml`, `database-users.yaml`, `docs/problems.md`.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)
**Primary Dependencies**: laravel/framework ^12; dev: phpunit ^11
**Storage**: MySQL `dbispconfig` — reads `client`, `sys_group`, `sys_ini`, `web_database_user`, `cron`, `web_domain`;
no new writes
**Testing**: PHPUnit feature tests on sqlite in-memory, Docker `php:8.3-cli` (baseline 1158 on `31f9b44`; the
concurrent 029/032 work raises it — rebase before counting)
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)
**Project Type**: Contract-first REST API (monolith)
**Performance Goals**: the capabilities block adds a bounded number of single-row lookups (client row, group row,
`sys_ini`); the cron check adds no query beyond the client row already fetched by the limit service
**Constraints**: read-only additions must not change existing fields; refusals must write no `sys_datalog` row
**Scale/Scope**: 1 extended endpoint, 1 extended summary, 2 enforced limits, 1 new service, 1 new schema

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: every field and refusal is written into the OpenAPI files before the PHP
      (contracts/hosting-capabilities.md, tasks T001–T004).
- [x] **Datalog-only writes (II)**: no writes are added; the two refusals happen before `save()`, so no datalog row
      is produced. Config blobs are read through the existing documented exception (`SitesConfigService`).
- [x] **Legacy parity (III)**: prefixes `tools_sites::replacePrefix`; chroot `tform_base::applyValueLimit`
      (`client:ssh_chroot`); kinds `cron_edit.php:145-160`; interval `validate_cron.inc.php:99-222`; database user cap
      `database_user_edit.php:58-63`. Deviations are owner-delegated decisions in the spec's Assumptions.
- [x] **Route discipline (IV)**: no new route; the enforcement lives in a service called by the existing controller.
- [x] **HTTP contract (V)**: 200 responses extended; refusals are 403 problem+json with existing spec 023 types.
- [x] **Tests required**: feature tests for the capabilities block, the new count, both cron refusals and the pinned
      database-user cap; unit-level coverage of the interval port.
- [x] **No schema changes**: no migrations.

## Project Structure

### Documentation (this feature)

```
specs/035-hosting-capabilities/
├── spec.md
├── checklists/requirements.md
├── plan.md
├── research.md
├── data-model.md
├── contracts/hosting-capabilities.md
├── quickstart.md
└── tasks.md
```

### Source (repository root)

```
api/
├── components/schemas/AccountSitesCapabilities.yaml   # new
├── components/schemas/AccountCapabilities.yaml        # + sites
├── components/schemas/UsageSummary.yaml               # + database_users
├── modules/me/capabilities.yaml                       # description + example
├── modules/usage/summary.yaml                         # example
├── modules/sites/cron-jobs.yaml                       # 403 refusals
└── modules/sites/database-users.yaml                  # documented 403
app/
├── Services/AccountSitesService.php                   # new
├── Services/AccountCapabilitiesService.php            # wire sites
├── Services/ClientLimitService.php                    # count column + checkCronLimits()
├── Models/CronJob.php                                 # minIntervalMinutes()
└── Http/Controllers/Api/V1/CronJobController.php      # call the check
docs/problems.md
tests/Feature/
├── MeCapabilitiesApiTest.php                          # sites block cases
├── UsageSummaryApiTest.php                            # database_users
├── ClientLimitCronTest.php                            # new
├── ClientLimitDatabaseUserTest.php                    # new (pins existing cap)
└── CronScheduleIntervalTest.php                       # new (interval port)
```

## Phases

1. **Phase 0 — research** (done): R1–R10 in research.md.
2. **Phase 1 — contract**: schemas and module files, then `SwaggerSpecServerTest` proves the spec still parses.
3. **Phase 2 — tests first**: the five test files above, failing.
4. **Phase 3 — implementation**: `AccountSitesService`, the capabilities wiring, the count column,
   `minIntervalMinutes()`, `checkCronLimits()` and the controller calls.
5. **Phase 4 — docs**: `docs/problems.md` limit names, README note on the extended capabilities.
6. **Phase 5 — verification**: full suite in Docker, Pint on changed files, deploy to isp-test, quickstart run with a
   temporary client, cleanup.

## Complexity Tracking

No constitution deviation requires justification. The only judgement calls are recorded as owner-delegated decisions
in the spec: the block name, the constant `remote_access`, the `null` interval below 2 minutes, the client-subject
chroot list, and leaving `[DOMAINID]` unresolved.
