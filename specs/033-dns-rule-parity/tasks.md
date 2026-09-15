---

description: "Task list for spec 033 — zone and record rule parity for scoped keys"
---

# Tasks: Zone and Record Rule Parity for Scoped Keys

**Input**: Design documents from `/specs/033-dns-rule-parity/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Tests are written first and must fail before the implementation task.
Run in Docker: `docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app php:8.3-cli php artisan test`
(baseline 1148 passing).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on incomplete tasks)
- **[Story]**: US1–US3 from spec.md

---

## Phase 1: Setup (contract first)

- [x] T001 [P] Describe the administrator-only `update_acl` and zone rename in `api/components/schemas/DnsSoa.yaml` and `api/modules/dns/soa.yaml`
- [x] T002 [P] Describe the MX/TLSA/DKIM duplicate and single-SPF rules in `api/modules/dns/records.yaml`
- [x] T003 Verify the YAML parses and the spec is served (`tests/Feature/SwaggerSpecServerTest.php`)

---

## Phase 2: User Story 1 — Administrator-only zone fields (P1) 🎯 MVP

### Tests (write first, must fail)

- [x] T004 [US1] Create `tests/Feature/DnsRuleParityTest.php` zone cases: client and reseller keys changing `update_acl` → 422 with `errors.update_acl` and `error_types.update_acl` = `feature-not-allowed`, nothing journaled; re-sending the stored value (and `null` for an empty value) → 200; client key changing `origin` → 422 with `error_types.origin`; re-sending the stored origin in another spelling → 200; reseller key renaming → 200; admin key both → 200; `POST /dns/soa` with `update_acl` from a client key → 422; `xfer`/`also_notify`/`dnssec_*` still accepted

### Implementation

- [x] T005 [US1] Add the `update_acl` scope check to `app/Http/Requests/DnsSoaRequest.php` and the origin rename check to `app/Http/Requests/UpdateDnsSoaRequest.php` (tagged through `ProblemTypeCollector`)

**Checkpoint**: US1 tests green

---

## Phase 3: User Story 2 — Record duplicate rules (P1)

- [x] T006 [US2] Record cases in `tests/Feature/DnsRuleParityTest.php`: identical MX (including another priority) / TLSA / DKIM → 422 `errors.name`; second SPF for the same name → 422; different name/data and self-updates → 201/200; admin key refused the same way; nothing journaled on refusal
- [x] T007 [US2] Add `checkIdenticalRecord()` (MX/TLSA/DKIM) and `checkSpfSingleton()` to `app/Http/Requests/DnsRecordRequest.php` and dispatch them in `zoneLevelChecks()`

---

## Phase 4: Polish

- [x] T008 [P] Document both rule groups in `README.md`
- [x] T009 Run Pint on changed PHP files and the full suite in Docker (spec 013 and 016 suites must stay green)
- [x] T010 Deploy to isp-test and run `specs/033-dns-rule-parity/quickstart.md` §2 with a temporary client; record results here

## Dependencies

Phase 1 → US1 → US2 → Polish. T001/T002 parallel; T004 before T005, T006 before T007.

## Results (T009–T010, 2026-09-16)

- T009: Pint clean on the changed files; full suite 1155 passed (baseline 1148 + 7 new tests); the spec 013 and 016
  suites are unchanged and green.
- T010: deployed `1338733` to isp-test (`ispconfig-rest status`: 1.0.0-rc.3 (1338733)). Temporary client
  `qa033eb3caa` (client 32, `dns_servers=1`, `limit_dns_zone=2`), QA admin key 71 and client key 72, zone 5
  `qa033-eb3caa.example.test`. Keys were never printed. All checks matched:

| Case | Expected | Got |
|---|---|---|
| client `PUT /dns/soa/5` `update_acl` | 422, `errors.update_acl`, `error_types.update_acl` = `#feature-not-allowed`, no datalog row | as expected |
| client `PUT` `update_acl: ""` (stored value) | 200 | 200 |
| client `PUT` `origin` rename | 422, `errors.origin`, `error_types.origin` = `#feature-not-allowed` | as expected |
| client `PUT` `origin` unchanged | 200 | 200 |
| client `PUT` `xfer` + `also_notify` | 200 (client-visible fields stay writable) | 200 |
| admin `PUT` `update_acl` set / cleared | 200 / 200 | as expected |
| client `PUT` `update_acl` equal to the admin-set value | 200 | 200 |
| client `POST /dns/records` MX, then identical MX at another priority | 201, then 422 `An identical MX record already exists for this name in the zone.`, no datalog row | as expected |
| admin `POST` the identical MX | 422 (rule applies to every key) | 422 |
| client `POST` MX with another target; `PUT` the first MX priority | 201; 200 | as expected |
| client `POST` SPF, second SPF for the same name, SPF for `sub` | 201; 422 `An SPF record already exists for this name in the zone.`; 201 | as expected |
| client `POST` TLSA then identical TLSA | 201; 422 | as expected |
| client `POST` DKIM then identical DKIM | 201; 422 | as expected |

- Cleanup: 6 records, zone 5 and client 32 deleted with the QA admin key (204); datalog processed
  (`server.updated` 818 = last id 818); API keys 71, 72 deleted by SQL (`name LIKE 'qa%'`); no `qa033` client,
  sys_group, sys_user, dns_soa or dns_rr rows, no pending datalog, no zone files in `/etc/bind`. Remaining keys:
  1, 2, 20, 27, 50 (untouched).

