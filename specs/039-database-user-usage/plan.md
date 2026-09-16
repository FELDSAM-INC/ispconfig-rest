# Implementation Plan: Database User Usage and Unlink Safety

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-16 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/039-database-user-usage/spec.md`

## Summary

- `WebDatabaseUserController::destroy()` refuses while any database the key may read references the user
  (`database_user_id` or `database_ro_user_id`), with 409 and the legacy message.
- The database user resource gains `databases_in_use`, computed for a whole page in one grouped query.
- `resource-in-use` joins the spec 023 problem-type registry (`docs/problems.md`), rendered by `Problem` like the
  other typed refusals.
- The DELETE contract is corrected — it currently documents the opposite of the enforced behaviour.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)
**Primary Dependencies**: laravel/framework ^12; dev: phpunit ^11
**Storage**: MySQL `dbispconfig` — reads `web_database`; no new writes
**Testing**: PHPUnit feature tests on sqlite in-memory, Docker `php:8.3-cli` (baseline 1244 on `bfa2e0a`)
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)
**Project Type**: Contract-first REST API (monolith)
**Performance Goals**: one grouped query per list page; one count for a single resource; no per-row query
**Constraints**: the count and the guard must share one scoped definition
**Scale/Scope**: 1 refusal, 1 resource field, 1 new problem type

## Constitution Check

- [x] **Spec-first (I)**: the field, the 409 and the corrected description are written into the contract first.
- [x] **Datalog-only writes (II)**: no writes; the refusal happens before `delete()`, so no datalog row is produced.
- [x] **Legacy parity (III)**: guard and message from `database_user_del.php::onBeforeDelete()` (research R1);
      the only deviation is the machine-readable type, recorded as an owner-delegated decision.
- [x] **Route discipline (IV)**: no new route.
- [x] **HTTP contract (V)**: 409 problem+json via the existing `Conflict` response component.
- [x] **Tests required**: both reference columns, both key types, the count on single and list reads, and an empty
      `sys_datalog` on refusal.
- [x] **No schema changes**: no migrations.

## Project Structure

```
api/
├── components/schemas/DatabaseUser.yaml          # + databases_in_use
└── modules/sites/database-users.yaml             # corrected DELETE description + 409
app/
├── Http/Controllers/Api/V1/WebDatabaseUserController.php   # guard + count attachment
├── Services/WebDatabaseUserUsageService.php                # scoped counts (page and single)
├── Support/ProblemType.php                                 # + RESOURCE_IN_USE
└── Exceptions/ProblemConflictException.php                 # typed 409 (mirrors ProblemAuthorizationException)
docs/problems.md                                            # resource-in-use
tests/Feature/WebDatabaseUserUsageTest.php                  # new
tests/Feature/WebDatabaseUserApiTest.php                    # count in existing shapes
```

## Phases

1. **Phase 0 — research** (done): R1–R7.
2. **Phase 1 — contract**: schema field, corrected DELETE description with the 409, problem-type documentation.
3. **Phase 2 — tests first**: the new usage/refusal class plus the shape assertions, failing.
4. **Phase 3 — implementation**: usage service, controller guard, typed conflict exception, `Problem` rendering.
5. **Phase 4 — verification**: full suite, Pint, deploy, live check on isp-test with a temporary client, cleanup.

## Complexity Tracking

| Deviation | Why | Alternative rejected |
|---|---|---|
| A new `resource-in-use` problem type | the existing types describe limits, permissions and validation; a dependency conflict is none of those, and a panel must map it without reading English | reuse `feature-not-allowed`: wrong meaning, and it would confuse plan refusals with data dependencies |
| Refusing administrator keys too | legacy refuses in the form for every user type, and an administrator delete breaks the same database | admin bypass: provisioning integrations use admin keys, which is exactly where the damage would happen |
