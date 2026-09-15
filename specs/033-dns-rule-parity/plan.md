# Implementation Plan: Zone and Record Rule Parity for Scoped Keys

**Branch**: `main` (owner workflow: committed directly to main) | **Date**: 2026-09-16 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/033-dns-rule-parity/spec.md`

## Summary

- `DnsSoaRequest::after()` gains two scope checks: `update_acl` (admin only) and, on update, `origin` (admin or
  reseller only), each tagged `feature-not-allowed` through `ProblemTypeCollector` and skipped when the submitted
  value equals the stored one.
- `DnsRecordRequest::zoneLevelChecks()` gains the four legacy duplicate rules: `MX`, `TLSA`, `DKIM` (identical record
  by name + composed data) and `SPF` (one `v=spf1` TXT per name), all self-excluded and zone-scoped.
- Contract: `DnsSoa.yaml` field descriptions, `soa.yaml` POST/PUT descriptions, `records.yaml` duplicate rules.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12)  
**Primary Dependencies**: laravel/framework ^12; dev: phpunit ^11  
**Storage**: MySQL `dbispconfig` — reads `dns_rr`, `dns_soa`; writes unchanged  
**Testing**: PHPUnit feature tests on sqlite in-memory, Docker `php:8.3-cli` (baseline 1148)  
**Target Platform**: Linux server alongside ISPConfig 3.3 (isp-test.feldhost.cz)  
**Project Type**: Contract-first REST API (monolith)  
**Performance Goals**: at most one extra count query per record write  
**Constraints**: refusals before any write; spec 013 and 016 behaviour unchanged  
**Scale/Scope**: 2 zone rules, 4 record rules

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] **Spec-first (I)**: `DnsSoa.yaml`, `soa.yaml`, `records.yaml` updated before code
  (contracts/dns-rule-parity.md).
- [x] **Datalog-only writes (II)**: no write path changes; refusals happen in validation.
- [x] **Legacy parity (III)**: `dns_soa.tform.php` 344, `dns_soa_edit.php` 333–344, `dns_mx_edit.php` 50–66,
  `dns_tlsa_edit.php` 110–130, `dns_dkim_edit.php` 128–131, `dns_spf_edit.php` 165–188 (research R1, R2); the
  deviations are owner-delegated in the spec.
- [x] **Route discipline (IV)**: no route changes.
- [x] **HTTP contract (V)**: 422 problem+json with `errors` and `error_types` (spec 023).
- [x] **No schema changes**: no migrations.

**Post-design re-check (after Phase 1)**: all gates pass; no Complexity Tracking entries.

## Project Structure

### Documentation (this feature)

```text
specs/033-dns-rule-parity/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/dns-rule-parity.md
├── checklists/requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
api/components/schemas/DnsSoa.yaml      # update_acl / origin descriptions
api/modules/dns/soa.yaml                # POST/PUT admin-only fields
api/modules/dns/records.yaml            # duplicate rules
app/Http/Requests/DnsSoaRequest.php     # update_acl scope check (shared)
app/Http/Requests/UpdateDnsSoaRequest.php  # origin rename check
app/Http/Requests/DnsRecordRequest.php  # MX/TLSA/DKIM/SPF duplicate checks
tests/Feature/DnsRuleParityTest.php     # NEW — US1–US3
README.md                               # DNS paragraph
```

**Structure Decision**: the checks live where the sibling rules already live (request classes), so no controller or
service changes and no new dependency for the write path.

## Legacy Research (Phase 0 focus)

See research.md: R1 administrator-only zone fields, R2 missing duplicate rules, R3 spec 013 coverage, R4 dead
validate_dns code, R5 refusal shape.

## Complexity Tracking

None.
