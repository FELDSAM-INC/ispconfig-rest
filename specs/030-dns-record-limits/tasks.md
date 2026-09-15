---

description: "Task list for spec 030 — DNS record limit parity"
---

# Tasks: DNS Record Limit Parity

**Input**: Design documents from `/specs/030-dns-record-limits/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1140 passing).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US2 from spec.md

---

## Phase 1: Setup (contract first)

- [x] T001 [P] Add required `dns_records` to `counts` in `api/components/schemas/UsageSummary.yaml`; mention DNS records in `api/modules/usage/summary.yaml`
- [x] T002 [P] Describe the record cap and 403 `limit-reached` on POST in `api/modules/dns/records.yaml`; add DNS records to the `limit-reached` examples in `docs/problems.md`
- [x] T003 Verify the YAML parses and the spec is served (`tests/Feature/SwaggerSpecServerTest.php`)

---

## Phase 2: User Story 1 — Record cap on create (P1) 🎯 MVP

### Tests (write first, must fail)

- [x] T004 [US1] Replace `test_dns_records_are_never_limited` in `tests/Feature/ClientLimitDnsTest.php` with the record cap matrix: client at cap 403 `limit-reached` body (`limit_dns_record`, client, max, used) and no datalog / no `dns_rr` / no serial bump; under cap 201; -1 unlimited; 0 refuses; update and delete at cap 200/204; admin key 201 past the cap; other client's records not counted; reseller key checked against its own limit and group (client's cap not applied), no reseller cap for clients

### Implementation

- [x] T005 [US1] Map `dns_rr` to `LimitSpec('limit_dns_record', 'dns_rr', 'id', null, 'grp', false, 'DNS records')` in `app/Services/ClientLimitService.php` (`countSpecsFor`, shared helper) and update the comments that say the limit has no call site

**Checkpoint**: US1 tests green

---

## Phase 3: User Story 2 — Usage count (P1)

- [x] T006 [US2] Extend `tests/Feature/UsageSummaryApiTest.php`: `counts.dns_records` used/limit for the client key (records of the client's group only), null limit for -1, admin naming the client; 17 counts
- [x] T007 [US2] Add `dns_records => limit_dns_record` to `USAGE_COUNT_COLUMNS` and `countSpecForColumn()` in `app/Services/ClientLimitService.php`

---

## Phase 4: Polish

- [x] T008 [P] Update the limits paragraph in `README.md`; add a superseded note to spec 012 FR-021/SC-006 in `specs/012-*/spec.md`
- [x] T009 Run Pint on changed PHP files and the full suite in Docker
- [ ] T010 Deploy to isp-test and run `specs/030-dns-record-limits/quickstart.md` §2 with a temporary client; record results here

## Dependencies

Phase 1 → US1 → US2 → Polish. T001/T002 parallel.
