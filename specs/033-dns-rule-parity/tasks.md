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
- [ ] T010 Deploy to isp-test and run `specs/033-dns-rule-parity/quickstart.md` §2 with a temporary client; record results here

## Dependencies

Phase 1 → US1 → US2 → Polish. T001/T002 parallel; T004 before T005, T006 before T007.
