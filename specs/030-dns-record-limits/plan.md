# Implementation Plan: DNS Record Limit Parity

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-16 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/030-dns-record-limits/spec.md`

## Summary

- `ClientLimitService` maps `dns_rr` to a `limit_dns_record` count spec with the `grp` predicate (records carrying the
  key's group) and no reseller cap — the same shape as `limit_client` and `limit_database_postgresql`. The existing
  create chokepoint (`BaseModel::save()` → `checkCreate()`) then refuses the create with the spec 023
  `limit-reached` problem before any write.
- The same spec feeds `countUsage()`, so `USAGE_COUNT_COLUMNS` gains `dns_records => limit_dns_record` and
  `/usage/summary` reports it without further code.
- Contract: `UsageSummary.yaml` `counts.dns_records` (required), `records.yaml` POST description,
  `summary.yaml` description, `docs/problems.md` example list.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)  
**Primary Dependencies**: laravel/framework ^12; dev: phpunit ^11  
**Storage**: MySQL `dbispconfig` — reads `dns_rr`, `client`; no new writes  
**Testing**: PHPUnit feature tests on sqlite in-memory, Docker `php:8.3-cli` (baseline 1140)  
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: + 1 count query per record create by a scoped key, + 1 per usage summary  
**Constraints**: refusal before any write; datalog-only writes unchanged  
**Scale/Scope**: 1 limit spec, 1 usage count, contract descriptions

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: `UsageSummary.yaml`, `records.yaml`, `summary.yaml` updated before code
  (contracts/dns-record-limits.md).
- [x] **Datalog-only writes (II)**: no new writes; a refusal throws before `parent::save()`.
- [x] **Legacy parity (III)**: `dns_edit_base.php` 105–118 and the five record forms (research R1); deviations
  owner-delegated in the spec.
- [x] **Route discipline (IV)**: no route changes.
- [x] **HTTP contract (V)**: 403 `limit-reached` (spec 023); summary field addition.
- [x] **No schema changes**: no migrations.

**Post-design re-check (after Phase 1)**: all gates pass; no Complexity Tracking entries.

## Project Structure

### Documentation (this feature)

```text
specs/030-dns-record-limits/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/dns-record-limits.md
├── checklists/requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
api/components/schemas/UsageSummary.yaml   # counts.dns_records
api/modules/dns/records.yaml               # POST: record cap, 403 limit-reached
api/modules/usage/summary.yaml             # description
docs/problems.md                           # DNS records in the limit-reached examples
app/Services/ClientLimitService.php        # dns_rr spec, usage column, comments
tests/Feature/ClientLimitDnsTest.php       # record cap matrix (replaces "never limited")
tests/Feature/UsageSummaryApiTest.php      # counts.dns_records (DnsSchema already composed)
README.md                                  # limits paragraph
specs/012-*/spec.md                        # superseded note on FR-021/SC-006
```

**Structure Decision**: reuse the spec 012 resource map; no new service or middleware.

## Legacy Research (Phase 0 focus)

See research.md: R1 legacy record cap, R2 predicate and reseller behaviour, R3 paths without the cap, R4 usage count
and capabilities.

## Complexity Tracking

None.
