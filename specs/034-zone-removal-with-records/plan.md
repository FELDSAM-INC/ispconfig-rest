# Implementation Plan: Zone Removal With Records

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-16 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/034-zone-removal-with-records/spec.md`

## Summary

- `DnsSoaController::destroy()` drops the 400 refusal and performs the legacy cascade inside the existing
  transaction: mark the zone inactive (datalog `u`), delete every `dns_rr` of the zone through the model (datalog
  `d` each), delete the zone (datalog `d`). One request = one change set (spec 015).
- Contract and README lose the deviation; spec 002's SC-006 is marked superseded.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)  
**Primary Dependencies**: laravel/framework ^12; dev: phpunit ^11  
**Storage**: MySQL `dbispconfig` — `dns_soa` (`u`, `d`), `dns_rr` (`d`)  
**Testing**: PHPUnit feature tests on sqlite in-memory, Docker `php:8.3-cli` (baseline 1155)  
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: one journal entry per record, as the panel writes  
**Constraints**: datalog-only writes; scoping unchanged; all-or-nothing  
**Scale/Scope**: one controller method, contract and docs

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: `api/modules/dns/soa.yaml` DELETE updated before code (contracts/zone-removal.md).
- [x] **Datalog-only writes (II)**: the cascade goes through the models (`BaseModel::save()`/`delete()`), never raw
  SQL.
- [x] **Legacy parity (III)**: `dns_soa_del.php` 41–51 + `tform_actions::onDelete()` (research R1).
- [x] **Route discipline (IV)**: no route changes.
- [x] **HTTP contract (V)**: 204 with the change-set header; the 400 case disappears.
- [x] **No schema changes**: no migrations.

**Post-design re-check (after Phase 1)**: all gates pass; no Complexity Tracking entries.

## Project Structure

### Documentation (this feature)

```text
specs/034-zone-removal-with-records/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/zone-removal.md
├── checklists/requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
api/modules/dns/soa.yaml                      # DELETE cascade, no 400
app/Http/Controllers/Api/V1/DnsSoaController.php  # cascade
tests/Feature/DnsSoaApiTest.php               # replaces the 400 test
tests/Feature/DnsZoneCascadeDeleteTest.php    # NEW — journal order + scoping
README.md                                     # deviation removed
specs/002-dns-management/spec.md              # SC-006 superseded note
```

**Structure Decision**: the cascade stays in the controller next to the other zone write flows; no service is
needed for three model calls.

## Legacy Research (Phase 0 focus)

See research.md: R1 legacy cascade, R2 journal shape, R3 scoping and locks.

## Complexity Tracking

None.
