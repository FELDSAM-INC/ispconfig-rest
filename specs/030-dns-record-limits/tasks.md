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
- [x] T010 Deploy to isp-test and run `specs/030-dns-record-limits/quickstart.md` §2 with a temporary client; record results here

## Dependencies

Phase 1 → US1 → US2 → Polish. T001/T002 parallel.

## Results (T009–T010, 2026-09-16)

- T009: Pint clean on changed files; full suite 1141 passed (baseline 1140; the spec 012 "records are never limited"
  test was replaced by two record cap tests).
- T010: deployed `8386af0` to isp-test (`ispconfig-rest status`: 1.0.0-rc.3 (8386af0)). Temporary client `qa030d93e00`
  (client 29: `dns_servers=1`, `limit_dns_zone=1`, `limit_dns_record=2`), QA admin key 67 and client key 68, zone 4
  `qa030-d93e00.example.test`. Keys were never printed. All checks matched:

| Case | Expected | Got |
|---|---|---|
| client `POST /dns/soa` | 201 | 201 |
| client `GET /usage/summary` before records | `counts.dns_records` `{used: 0, limit: 2}` | as expected |
| client `POST /dns/records` A `www`, A `mail` | 201, 201; summary `{used: 2, limit: 2}` | as expected |
| client `POST /dns/records` A `ftp` at the cap | 403 `limit-reached`, `limit {limit_dns_record, client, 2, 2}`, detail `You have reached the maximum number of DNS records allowed for your account.`, no datalog row | as expected |
| client `PUT /dns/records/{www}` ttl 7200 at the cap | 200 | 200 |
| admin `POST /dns/records` A `ftp` past the cap | 201; client summary `{used: 3, limit: 2}` | as expected |
| client `DELETE /dns/records/{www}` at the cap | 204 | 204 |
| client `POST /dns/records` A `shop` (2 of 2) | 403, `used: 2`, `max: 2` | as expected |
| admin `GET /usage/summary?client_id=29` | `{used: 2, limit: 2}` | as expected |

- Cleanup: records 37, 38, zone 4 and client 29 deleted with the QA admin key (204); datalog processed
  (`server.updated` 774 = last id 774); API keys 67, 68 deleted by SQL (`name LIKE 'qa%'`); no `qa030` client,
  sys_group, sys_user, dns_soa or dns_rr rows, no pending datalog, no zone files in `/etc/bind` or `named.conf*`
  references. Remaining keys: 1, 2, 20, 27, 50 (untouched).
